<?php
/**
 * Tests for PRAutoBlogger_Image_Attacher.
 *
 * Validates primary sideload + cost logging (including the v0.17.0 alt-text
 * fix: the caption, not the model name, becomes alt text) and composed
 * variant persistence with role/base meta-linking at $0 cost.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class ImageAttacherTest extends BaseTestCase {

	/** @var array Captured update_post_meta calls: [object_id, key, value]. */
	private array $meta_calls = [];

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		$this->meta_calls = [];
		Functions\when( 'update_post_meta' )->alias(
			function ( $object_id, $key, $value ) {
				$this->meta_calls[] = [ $object_id, $key, $value ];
				return true;
			}
		);
		$this->stub_get_option( [] );
	}

	/** Helper: find a captured meta value by object id + key. */
	private function meta_value( int $object_id, string $key ) {
		foreach ( $this->meta_calls as $call ) {
			if ( $call[0] === $object_id && $call[1] === $key ) {
				return $call[2];
			}
		}
		return null;
	}

	/** Helper: primary image fixture. */
	private function image_data(): array {
		return [
			'bytes'      => 'featured_bytes',
			'mime_type'  => 'image/png',
			'width'      => 1200,
			'height'     => 632,
			'model'      => 'flux-1-schnell',
			'cost_usd'   => 0.0006,
			'latency_ms' => 1500,
		];
	}

	/**
	 * sideload_and_log() forwards the caption as alt text (bug fix: the model
	 * name used to leak into alt text) and logs the generation cost once.
	 */
	public function test_sideload_and_log_forwards_caption_as_alt_and_logs_cost(): void {
		$sideloader = $this->createMock( \PRAutoBlogger_Image_Media_Sideloader::class );
		$sideloader->expects( $this->once() )
			->method( 'sideload_image' )
			->with( $this->image_data(), 7, 'Collagen fragments may aid joint repair' )
			->willReturn( 42 );

		$cost_tracker = $this->createMock( \PRAutoBlogger_Cost_Tracker::class );
		$cost_tracker->expects( $this->once() )
			->method( 'log_image_generation' )
			->with( 0.0006, 'flux-1-schnell', 7, 'image_a' );

		$attacher = new \PRAutoBlogger_Image_Attacher( $sideloader, $cost_tracker );
		$result   = $attacher->sideload_and_log( $this->image_data(), 7, 'image_a', 'Image A', 'Collagen fragments may aid joint repair' );

		$this->assertSame( 42, $result );
	}

	/**
	 * A failed primary sideload returns the WP_Error untouched and logs no cost.
	 */
	public function test_sideload_and_log_propagates_error_without_cost_row(): void {
		$error      = new \WP_Error( 'sideload_failed', 'disk full' );
		$sideloader = $this->createMock( \PRAutoBlogger_Image_Media_Sideloader::class );
		$sideloader->method( 'sideload_image' )->willReturn( $error );

		$cost_tracker = $this->createMock( \PRAutoBlogger_Cost_Tracker::class );
		$cost_tracker->expects( $this->never() )->method( 'log_image_generation' );

		$attacher = new \PRAutoBlogger_Image_Attacher( $sideloader, $cost_tracker );

		$this->assertSame( $error, $attacher->sideload_and_log( $this->image_data(), 7, 'image_a', 'Image A', 'cap' ) );
	}

	/**
	 * attach_variants() sideloads og + square with the role as filename
	 * suffix and the caption as alt text, writes role/base attachment meta
	 * and the role-specific post meta, and never logs composition cost.
	 */
	public function test_attach_variants_meta_links_og_and_square(): void {
		$variants = [
			[
				'bytes'     => 'og_bytes',
				'mime_type' => 'image/png',
				'width'     => 1200,
				'height'    => 630,
				'role'      => 'og',
			],
			[
				'bytes'     => 'square_bytes',
				'mime_type' => 'image/png',
				'width'     => 1080,
				'height'    => 1080,
				'role'      => 'square',
			],
		];

		$returned_ids = [ 'og' => 43, 'square' => 44 ];
		$sideloader   = $this->createMock( \PRAutoBlogger_Image_Media_Sideloader::class );
		$sideloader->expects( $this->exactly( 2 ) )
			->method( 'sideload_image' )
			->willReturnCallback(
				function ( array $image_data, int $post_id, string $alt, string $suffix ) use ( $returned_ids ) {
					$this->assertSame( 7, $post_id );
					$this->assertSame( 'A short caption', $alt );
					$this->assertSame( 0.0, $image_data['cost_usd'] );
					$this->assertSame( 'flux-1-schnell', $image_data['model'] );
					$this->assertArrayHasKey( $suffix, $returned_ids, 'filename suffix must be the role' );
					return $returned_ids[ $suffix ];
				}
			);

		$cost_tracker = $this->createMock( \PRAutoBlogger_Cost_Tracker::class );
		$cost_tracker->expects( $this->never() )->method( 'log_image_generation' );

		$attacher = new \PRAutoBlogger_Image_Attacher( $sideloader, $cost_tracker );
		$attacher->attach_variants( $variants, 7, 42, 'A short caption', 'flux-1-schnell' );

		$this->assertSame( 'og', $this->meta_value( 43, '_prautoblogger_image_role' ) );
		$this->assertSame( 42, $this->meta_value( 43, '_prautoblogger_base_attachment_id' ) );
		$this->assertSame( 43, $this->meta_value( 7, '_prautoblogger_og_image_id' ) );

		$this->assertSame( 'square', $this->meta_value( 44, '_prautoblogger_image_role' ) );
		$this->assertSame( 42, $this->meta_value( 44, '_prautoblogger_base_attachment_id' ) );
		$this->assertSame( 44, $this->meta_value( 7, '_prautoblogger_square_image_id' ) );
	}

	/**
	 * Unknown roles (e.g. a stray featured entry) are ignored, and a failed
	 * variant sideload is skipped without meta writes or exceptions.
	 */
	public function test_attach_variants_skips_unknown_roles_and_failed_sideloads(): void {
		$variants = [
			[ 'bytes' => 'f', 'mime_type' => 'image/png', 'width' => 1, 'height' => 1, 'role' => 'featured' ],
			[ 'bytes' => 'o', 'mime_type' => 'image/png', 'width' => 1200, 'height' => 630, 'role' => 'og' ],
		];

		$sideloader = $this->createMock( \PRAutoBlogger_Image_Media_Sideloader::class );
		$sideloader->expects( $this->once() ) // Only og — featured is not a variant role here.
			->method( 'sideload_image' )
			->willReturn( new \WP_Error( 'sideload_failed', 'nope' ) );

		$cost_tracker = $this->createMock( \PRAutoBlogger_Cost_Tracker::class );

		$attacher = new \PRAutoBlogger_Image_Attacher( $sideloader, $cost_tracker );
		$attacher->attach_variants( $variants, 7, 42, 'cap', 'model' );

		$this->assertSame( [], $this->meta_calls );
	}
}
