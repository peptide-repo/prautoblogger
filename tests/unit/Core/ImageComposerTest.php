<?php
/**
 * Tests for PRAutoBlogger_Image_Composer (orchestrator).
 *
 * Validates the degradation ladder (Imagick mocked present/absent via the
 * capability filter), pass-through guarantees, variant config whitelisting,
 * capability caching, and the uninstall prefix contract.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;

class ImageComposerTest extends BaseTestCase {

	/** @var array Default provider-result fixture fed into compose(). */
	private array $image_data = [
		'bytes'      => 'raw_base_bytes',
		'mime_type'  => 'image/png',
		'width'      => 1200,
		'height'     => 632,
		'model'      => 'flux-1-schnell',
		'cost_usd'   => 0.0006,
		'latency_ms' => 1500,
	];

	protected function setUp(): void {
		parent::setUp();
		\PRAutoBlogger_Image_Composer::reset_run_state();
		Functions\when( 'update_option' )->justReturn( true );
		$this->stub_get_option( [] ); // All composer options fall back to defaults.
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Image_Composer::reset_run_state();
		parent::tearDown();
	}

	/** Helper: composer context for Image A. */
	private function context( string $slot = 'image_a' ): array {
		return [
			'post_id' => 7,
			'caption' => 'Collagen fragments may aid joint repair',
			'title'   => 'Collagen Peptides Explained',
			'slot'    => $slot,
		];
	}

	/** Helper: a fake rendered variant payload. */
	private function variant( string $role, int $w, int $h ): array {
		return [
			'bytes'     => "rendered_{$role}",
			'mime_type' => 'image/png',
			'width'     => $w,
			'height'    => $h,
			'role'      => $role,
		];
	}

	/**
	 * Compose disabled in settings → single pass-through featured variant,
	 * renderer untouched.
	 */
	public function test_disabled_setting_returns_passthrough_only(): void {
		$this->stub_get_option( [ \PRAutoBlogger_Image_Composer::OPTION_ENABLED => '0' ] );

		$renderer = $this->createMock( \PRAutoBlogger_Image_Composer_Imagick::class );
		$renderer->expects( $this->never() )->method( 'compose_featured' );
		$renderer->expects( $this->never() )->method( 'compose_og' );

		$composer = new \PRAutoBlogger_Image_Composer( $renderer );
		$variants = $composer->compose( $this->image_data, $this->context() );

		$this->assertCount( 1, $variants );
		$this->assertSame( 'featured', $variants[0]['role'] );
		$this->assertSame( 'raw_base_bytes', $variants[0]['bytes'] );
	}

	/**
	 * Empty provider bytes short-circuit to pass-through (no probe, no render).
	 */
	public function test_empty_bytes_return_passthrough(): void {
		$composer = new \PRAutoBlogger_Image_Composer(
			$this->createMock( \PRAutoBlogger_Image_Composer_Imagick::class )
		);
		$variants = $composer->compose( [ 'bytes' => '' ], $this->context() );

		$this->assertCount( 1, $variants );
		$this->assertSame( 'featured', $variants[0]['role'] );
	}

	/**
	 * Ladder rung 1 (Imagick "present" via filter + mocked renderer):
	 * Image A yields marked featured + og + square, featured first, with the
	 * caption forwarded to both text-bearing variants.
	 */
	public function test_imagick_rung_renders_featured_plus_variants(): void {
		Filters\expectApplied( 'prautoblogger_image_compose_capability' )->andReturn( 'imagick' );

		$caption  = $this->context()['caption'];
		$renderer = $this->createMock( \PRAutoBlogger_Image_Composer_Imagick::class );
		$renderer->expects( $this->once() )->method( 'compose_featured' )
			->with( 'raw_base_bytes' )->willReturn( $this->variant( 'featured', 1200, 632 ) );
		$renderer->expects( $this->once() )->method( 'compose_og' )
			->with( 'raw_base_bytes', $caption )->willReturn( $this->variant( 'og', 1200, 630 ) );
		$renderer->expects( $this->once() )->method( 'compose_square' )
			->with( 'raw_base_bytes', $caption )->willReturn( $this->variant( 'square', 1080, 1080 ) );

		$composer = new \PRAutoBlogger_Image_Composer( $renderer );
		$variants = $composer->compose( $this->image_data, $this->context() );

		$this->assertSame( [ 'featured', 'og', 'square' ], array_column( $variants, 'role' ) );
		$this->assertSame( 'rendered_featured', $variants[0]['bytes'] );
	}

	/**
	 * Featured corner mark toggled off → featured stays the untouched base,
	 * but social variants still render.
	 */
	public function test_mark_disabled_passes_featured_through_but_keeps_variants(): void {
		Filters\expectApplied( 'prautoblogger_image_compose_capability' )->andReturn( 'imagick' );
		$this->stub_get_option( [ \PRAutoBlogger_Image_Composer::OPTION_MARK_ENABLED => '0' ] );

		$renderer = $this->createMock( \PRAutoBlogger_Image_Composer_Imagick::class );
		$renderer->expects( $this->never() )->method( 'compose_featured' );
		$renderer->method( 'compose_og' )->willReturn( $this->variant( 'og', 1200, 630 ) );
		$renderer->method( 'compose_square' )->willReturn( $this->variant( 'square', 1080, 1080 ) );

		$composer = new \PRAutoBlogger_Image_Composer( $renderer );
		$variants = $composer->compose( $this->image_data, $this->context() );

		$this->assertSame( 'raw_base_bytes', $variants[0]['bytes'] );
		$this->assertSame( [ 'featured', 'og', 'square' ], array_column( $variants, 'role' ) );
	}

	/**
	 * Image B slot gets the corner mark only — no og/square renders ever.
	 */
	public function test_image_b_slot_yields_mark_only(): void {
		Filters\expectApplied( 'prautoblogger_image_compose_capability' )->andReturn( 'imagick' );

		$renderer = $this->createMock( \PRAutoBlogger_Image_Composer_Imagick::class );
		$renderer->expects( $this->once() )->method( 'compose_featured' )
			->willReturn( $this->variant( 'featured', 1200, 632 ) );
		$renderer->expects( $this->never() )->method( 'compose_og' );
		$renderer->expects( $this->never() )->method( 'compose_square' );

		$composer = new \PRAutoBlogger_Image_Composer( $renderer );
		$variants = $composer->compose( $this->image_data, $this->context( 'image_b' ) );

		$this->assertCount( 1, $variants );
		$this->assertSame( 'featured', $variants[0]['role'] );
	}

	/**
	 * The variant option is whitelisted at point of use: unknown tokens are
	 * dropped, duplicates deduped, config order preserved.
	 */
	public function test_variant_config_is_whitelisted_and_ordered(): void {
		Filters\expectApplied( 'prautoblogger_image_compose_capability' )->andReturn( 'imagick' );
		$this->stub_get_option(
			[ \PRAutoBlogger_Image_Composer::OPTION_VARIANTS => ' square, banana ,og, og' ]
		);

		$renderer = $this->createMock( \PRAutoBlogger_Image_Composer_Imagick::class );
		$renderer->method( 'compose_featured' )->willReturn( $this->variant( 'featured', 1200, 632 ) );
		$renderer->expects( $this->once() )->method( 'compose_square' )
			->willReturn( $this->variant( 'square', 1080, 1080 ) );
		$renderer->expects( $this->once() )->method( 'compose_og' )
			->willReturn( $this->variant( 'og', 1200, 630 ) );

		$composer = new \PRAutoBlogger_Image_Composer( $renderer );
		$variants = $composer->compose( $this->image_data, $this->context() );

		$this->assertSame( [ 'featured', 'square', 'og' ], array_column( $variants, 'role' ) );
	}

	/**
	 * Acceptance: with every render throwing (e.g. probe lied / Imagick
	 * flipped mid-run), compose() still returns the publishable pass-through
	 * featured variant and never lets the exception escape.
	 */
	public function test_renderer_failure_degrades_to_passthrough(): void {
		Filters\expectApplied( 'prautoblogger_image_compose_capability' )->andReturn( 'imagick' );

		$renderer = $this->createMock( \PRAutoBlogger_Image_Composer_Imagick::class );
		$renderer->method( 'compose_featured' )->willThrowException( new \RuntimeException( 'no imagick after all' ) );
		$renderer->method( 'compose_og' )->willThrowException( new \RuntimeException( 'no imagick after all' ) );
		$renderer->method( 'compose_square' )->willThrowException( new \RuntimeException( 'no imagick after all' ) );

		$composer = new \PRAutoBlogger_Image_Composer( $renderer );
		$variants = $composer->compose( $this->image_data, $this->context() );

		$this->assertCount( 1, $variants );
		$this->assertSame( 'featured', $variants[0]['role'] );
		$this->assertSame( 'raw_base_bytes', $variants[0]['bytes'] );
	}

	/**
	 * Ladder rung 3 forced via the override filter: pass-through, no renderer
	 * involvement, publish data intact.
	 */
	public function test_capability_none_passes_through(): void {
		Filters\expectApplied( 'prautoblogger_image_compose_capability' )->andReturn( 'none' );

		$renderer = $this->createMock( \PRAutoBlogger_Image_Composer_Imagick::class );
		$renderer->expects( $this->never() )->method( 'compose_featured' );

		$composer = new \PRAutoBlogger_Image_Composer( $renderer );
		$variants = $composer->compose( $this->image_data, $this->context() );

		$this->assertCount( 1, $variants );
		$this->assertSame( 'raw_base_bytes', $variants[0]['bytes'] );
		$this->assertSame( 1200, $variants[0]['width'] );
		$this->assertSame( 632, $variants[0]['height'] );
	}

	/**
	 * A garbage filter return is coerced to 'none' (pass-through), never an
	 * undefined rung.
	 */
	public function test_unknown_capability_value_is_treated_as_none(): void {
		Filters\expectApplied( 'prautoblogger_image_compose_capability' )->andReturn( 'webgpu' );

		$composer = new \PRAutoBlogger_Image_Composer(
			$this->createMock( \PRAutoBlogger_Image_Composer_Imagick::class )
		);
		$variants = $composer->compose( $this->image_data, $this->context() );

		$this->assertCount( 1, $variants );
		$this->assertSame( 'featured', $variants[0]['role'] );
	}

	/**
	 * A cached probe with a matching environment fingerprint is reused —
	 * no re-probe, no option write. (update_option calls are captured via a
	 * when() alias: an expect() here would be shadowed by the setUp stub.)
	 */
	public function test_matching_capability_cache_skips_probe_write(): void {
		$fingerprint = md5(
			PHP_VERSION . '|' . ( extension_loaded( 'imagick' ) ? 'im1' : 'im0' ) . '|' . ( extension_loaded( 'gd' ) ? 'gd1' : 'gd0' )
		);
		$this->stub_get_option(
			[
				\PRAutoBlogger_Image_Composer::OPTION_CAPABILITY => [
					'fingerprint' => $fingerprint,
					'capability'  => 'none',
				],
			]
		);
		$writes = [];
		Functions\when( 'update_option' )->alias(
			function ( $key, $value = null, $autoload = null ) use ( &$writes ) {
				$writes[] = $key;
				return true;
			}
		);

		$composer = new \PRAutoBlogger_Image_Composer(
			$this->createMock( \PRAutoBlogger_Image_Composer_Imagick::class )
		);
		$variants = $composer->compose( $this->image_data, $this->context() );

		$this->assertSame( 'featured', $variants[0]['role'] );
		$this->assertSame( [], $writes, 'A matching cache fingerprint must not trigger a probe rewrite.' );
	}

	/**
	 * A stale fingerprint (host changed) triggers a fresh probe and a cache
	 * rewrite — the auto-invalidation the spec requires. (Captured via a
	 * when() alias: an expect() here would be shadowed by the setUp stub.)
	 */
	public function test_stale_capability_cache_reprobes_and_rewrites(): void {
		$this->stub_get_option(
			[
				\PRAutoBlogger_Image_Composer::OPTION_CAPABILITY => [
					'fingerprint' => 'old-host-fingerprint',
					'capability'  => 'imagick',
				],
			]
		);
		$writes = [];
		Functions\when( 'update_option' )->alias(
			function ( $key, $value = null, $autoload = null ) use ( &$writes ) {
				$writes[] = [ $key, $value, $autoload ];
				return true;
			}
		);

		$composer = new \PRAutoBlogger_Image_Composer(
			$this->createMock( \PRAutoBlogger_Image_Composer_Imagick::class )
		);
		$variants = $composer->compose( $this->image_data, $this->context() );

		$this->assertSame( 'featured', $variants[0]['role'] );
		$this->assertCount( 1, $writes, 'A stale fingerprint must rewrite the capability cache.' );
		$this->assertSame( \PRAutoBlogger_Image_Composer::OPTION_CAPABILITY, $writes[0][0] );
		$this->assertIsArray( $writes[0][1] );
		$this->assertArrayHasKey( 'capability', $writes[0][1] );
		$this->assertNotSame( 'old-host-fingerprint', $writes[0][1]['fingerprint'] ?? '' );
		$this->assertFalse( $writes[0][2], 'Capability cache must not autoload.' );
	}

	/**
	 * QA note for the uninstall contract: every new option key keeps the
	 * `prautoblogger_` prefix so uninstall.php's prefix-sweep purges it.
	 * (Per spec, uninstall.php itself is intentionally untouched.)
	 */
	public function test_new_option_keys_keep_uninstall_sweep_prefix(): void {
		$keys = [
			\PRAutoBlogger_Image_Composer::OPTION_ENABLED,
			\PRAutoBlogger_Image_Composer::OPTION_VARIANTS,
			\PRAutoBlogger_Image_Composer::OPTION_MARK_ENABLED,
			\PRAutoBlogger_Image_Composer::OPTION_CAPABILITY,
		];

		foreach ( $keys as $key ) {
			$this->assertStringStartsWith( 'prautoblogger_', $key );
		}
	}
}
