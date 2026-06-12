<?php
/**
 * Tests for PRAutoBlogger_Image_Composer_Editor (GD/WP-editor rung).
 *
 * Validates the three main branches of cover_crop():
 *   - wp_get_image_editor() returns WP_Error → null returned.
 *   - Resize returns WP_Error → null returned.
 *   - Exact size not reachable (would upscale) → null returned.
 *   - Happy path: successful resize/save returns the expected shape.
 * And compose_variants() iteration:
 *   - Roles with bad geometry (0-width target) are skipped.
 *   - Null cover_crop result for a role skips that role with a debug log.
 *
 * Brain Monkey stubs wp_get_image_editor, get_temp_dir so no filesystem or
 * WP runtime is needed.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class ImageComposerEditorTest extends BaseTestCase {

	/** Minimal layout geometry mirroring Layout::defaults() relevant keys. */
	private function layout( int $og_w = 1200, int $og_h = 630, int $sq_w = 1080, int $sq_h = 1080 ): array {
		return array(
			'og'     => array( 'width' => $og_w, 'height' => $og_h ),
			'square' => array( 'width' => $sq_w, 'height' => $sq_h ),
		);
	}

	/** Helper: mock WP image editor whose resize returns a WP_Error. */
	private function mock_editor_resize_error(): object {
		$editor = $this->getMockBuilder( \stdClass::class )
			->addMethods( array( 'resize', 'get_size', 'save' ) )
			->getMock();
		$editor->method( 'resize' )->willReturn( new \WP_Error( 'resize_fail', 'Mock resize failure' ) );

		return $editor;
	}

	/** Helper: mock WP image editor whose save returns a WP_Error. */
	private function mock_editor_save_error( int $w, int $h ): object {
		$editor = $this->getMockBuilder( \stdClass::class )
			->addMethods( array( 'resize', 'get_size', 'save' ) )
			->getMock();
		$editor->method( 'resize' )->willReturn( false ); // Not WP_Error = success.
		$editor->method( 'get_size' )->willReturn( array( 'width' => $w, 'height' => $h ) );
		$editor->method( 'save' )->willReturn( new \WP_Error( 'save_fail', 'Mock save failure' ) );

		return $editor;
	}

	/** Helper: mock WP image editor that succeeds at the requested size. */
	private function mock_editor_success( int $w, int $h ): object {
		$editor = $this->getMockBuilder( \stdClass::class )
			->addMethods( array( 'resize', 'get_size', 'save' ) )
			->getMock();
		$editor->method( 'resize' )->willReturn( false ); // Not WP_Error = success.
		$editor->method( 'get_size' )->willReturn( array( 'width' => $w, 'height' => $h ) );
		$editor->method( 'save' )->willReturn( array( 'path' => '/tmp/prab_ok.png' ) );

		return $editor;
	}

	/** Helper: mock WP image editor that resizes but returns a different size (upscale guard). */
	private function mock_editor_wrong_size( int $requested_w, int $requested_h, int $actual_w, int $actual_h ): object {
		$editor = $this->getMockBuilder( \stdClass::class )
			->addMethods( array( 'resize', 'get_size', 'save' ) )
			->getMock();
		$editor->method( 'resize' )->willReturn( false ); // Not WP_Error = success.
		$editor->method( 'get_size' )->willReturn( array( 'width' => $actual_w, 'height' => $actual_h ) );

		return $editor;
	}

	// ------------------------------------------------------------------
	// cover_crop() failure paths (tested via compose_variants interface).
	// ------------------------------------------------------------------

	/**
	 * wp_get_image_editor() returns WP_Error → role skipped,
	 * no variants emitted.
	 */
	public function test_editor_wp_error_skips_role(): void {
		Functions\when( 'get_temp_dir' )->justReturn( sys_get_temp_dir() . '/' );
		Functions\when( 'wp_get_image_editor' )->justReturn(
			new \WP_Error( 'no_editor', 'No image editor available' )
		);
		Functions\when( 'is_wp_error' )->alias( static function ( $val ) {
			return $val instanceof \WP_Error;
		} );

		$editor   = new \PRAutoBlogger_Image_Composer_Editor();
		$variants = $editor->compose_variants( 'fake_bytes', array( 'og' ), $this->layout() );

		$this->assertSame( array(), $variants, 'wp_get_image_editor WP_Error must yield no variants' );
	}

	/**
	 * Resize step returns WP_Error → role skipped.
	 */
	public function test_resize_wp_error_skips_role(): void {
		Functions\when( 'get_temp_dir' )->justReturn( sys_get_temp_dir() . '/' );
		Functions\when( 'wp_get_image_editor' )->justReturn( $this->mock_editor_resize_error() );
		Functions\when( 'is_wp_error' )->alias( static function ( $val ) {
			return $val instanceof \WP_Error;
		} );

		$editor   = new \PRAutoBlogger_Image_Composer_Editor();
		$variants = $editor->compose_variants( 'fake_bytes', array( 'og' ), $this->layout() );

		$this->assertSame( array(), $variants, 'Resize WP_Error must yield no variants' );
	}

	/**
	 * Exact size not reachable (WP editor silently gives a different size
	 * instead of upscaling) → role skipped via the size-mismatch guard.
	 */
	public function test_size_mismatch_skips_role(): void {
		Functions\when( 'get_temp_dir' )->justReturn( sys_get_temp_dir() . '/' );
		// Editor resizes to 900×473 when 1080×1080 square was requested (would upscale).
		Functions\when( 'wp_get_image_editor' )->justReturn(
			$this->mock_editor_wrong_size( 1080, 1080, 900, 473 )
		);
		Functions\when( 'is_wp_error' )->alias( static function ( $val ) {
			return $val instanceof \WP_Error;
		} );

		$editor   = new \PRAutoBlogger_Image_Composer_Editor();
		$variants = $editor->compose_variants( 'fake_bytes', array( 'square' ), $this->layout() );

		$this->assertSame( array(), $variants, 'Size mismatch (upscale guard) must yield no variants' );
	}

	/**
	 * Save step returns WP_Error → role skipped.
	 */
	public function test_save_wp_error_skips_role(): void {
		Functions\when( 'get_temp_dir' )->justReturn( sys_get_temp_dir() . '/' );
		Functions\when( 'wp_get_image_editor' )->justReturn(
			$this->mock_editor_save_error( 1200, 630 )
		);
		Functions\when( 'is_wp_error' )->alias( static function ( $val ) {
			return $val instanceof \WP_Error;
		} );

		$editor   = new \PRAutoBlogger_Image_Composer_Editor();
		$variants = $editor->compose_variants( 'fake_bytes', array( 'og' ), $this->layout() );

		$this->assertSame( array(), $variants, 'Save WP_Error must yield no variants' );
	}

	// ------------------------------------------------------------------
	// Happy path — OG resizes successfully.
	// ------------------------------------------------------------------

	/**
	 * Successful cover_crop for 'og': variant shape matches expectations.
	 * file_put_contents and file_get_contents are exercised via temp file
	 * in the test runner's writable temp dir; the editor mock does not
	 * actually modify the file, but the class reads it back and returns its
	 * bytes.
	 */
	public function test_successful_og_resize_returns_correct_shape(): void {
		$temp_dir = sys_get_temp_dir() . '/';
		Functions\when( 'get_temp_dir' )->justReturn( $temp_dir );

		$og_w = 1200;
		$og_h = 630;

		Functions\when( 'wp_get_image_editor' )->alias(
			function () use ( $og_w, $og_h ) {
				return $this->mock_editor_success( $og_w, $og_h );
			}
		);
		Functions\when( 'is_wp_error' )->alias( static function ( $val ) {
			return $val instanceof \WP_Error;
		} );

		$editor   = new \PRAutoBlogger_Image_Composer_Editor();
		$variants = $editor->compose_variants( 'fake_source_bytes', array( 'og' ), $this->layout() );

		$this->assertCount( 1, $variants, 'Successful resize must emit exactly one variant' );
		$variant = $variants[0];
		$this->assertSame( 'og', $variant['role'] );
		$this->assertSame( 'image/png', $variant['mime_type'] );
		$this->assertSame( $og_w, $variant['width'] );
		$this->assertSame( $og_h, $variant['height'] );
		$this->assertIsString( $variant['bytes'] );
	}

	// ------------------------------------------------------------------
	// compose_variants() geometry guard.
	// ------------------------------------------------------------------

	/**
	 * A role whose layout target has width=0 is skipped before any I/O —
	 * no editor call, no variant emitted.
	 */
	public function test_zero_width_target_skips_role_before_editor(): void {
		// Make the 'og' slot have zero width so the guard fires before any I/O.
		$layout = array(
			'og' => array( 'width' => 0, 'height' => 630 ),
		);

		// wp_get_image_editor must never be called.
		Functions\expect( 'wp_get_image_editor' )->never();
		Functions\when( 'is_wp_error' )->alias( static function ( $val ) {
			return $val instanceof \WP_Error;
		} );

		$editor   = new \PRAutoBlogger_Image_Composer_Editor();
		$variants = $editor->compose_variants( 'fake_bytes', array( 'og' ), $layout );

		$this->assertSame( array(), $variants );
	}

	/**
	 * Role not present in layout skips cleanly.
	 */
	public function test_missing_role_in_layout_emits_no_variant(): void {
		Functions\expect( 'wp_get_image_editor' )->never();
		Functions\when( 'is_wp_error' )->alias( static function ( $val ) {
			return $val instanceof \WP_Error;
		} );

		$editor   = new \PRAutoBlogger_Image_Composer_Editor();
		$variants = $editor->compose_variants( 'fake_bytes', array( 'nonexistent_role' ), array() );

		$this->assertSame( array(), $variants );
	}
}
