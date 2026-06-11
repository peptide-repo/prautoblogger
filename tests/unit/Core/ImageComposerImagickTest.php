<?php
/**
 * Tests for PRAutoBlogger_Image_Composer_Imagick (real renders).
 *
 * These run only where ext-imagick is loaded (skipped on the standard CI
 * matrix); the same renders are exercised by the standalone harness that
 * produced the PR samples. Asserts the determinism contract — identical
 * inputs yield byte-identical output in the same environment — plus the
 * exact variant geometry.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;

class ImageComposerImagickTest extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'ext-imagick not available — renderer covered by the server-side harness.' );
		}
	}

	/** Helper: renderer wired to the real bundled assets. */
	private function renderer(): \PRAutoBlogger_Image_Composer_Imagick {
		$root = dirname( __DIR__, 3 );

		return new \PRAutoBlogger_Image_Composer_Imagick(
			\PRAutoBlogger_Image_Composer_Layout::defaults(),
			$root . '/assets/brand/',
			$root . '/assets/fonts/'
		);
	}

	/** Helper: deterministic 1200×632 fixture base image (gradient + disc). */
	private function fixture_bytes(): string {
		$image = new \Imagick();
		$image->newPseudoImage( 1200, 632, 'gradient:#1B8A92-#FAFAF7' );
		$draw = new \ImagickDraw();
		$draw->setFillColor( new \ImagickPixel( '#FF8A3D' ) );
		$draw->circle( 600, 316, 600, 470 );
		$image->drawImage( $draw );
		$image->setImageFormat( 'png' );

		return $image->getImageBlob();
	}

	/**
	 * Determinism: rendering the same input twice yields byte-identical
	 * variants (metadata stripped, PNG date/time chunks excluded).
	 */
	public function test_same_input_renders_byte_identical_output(): void {
		$bytes   = $this->fixture_bytes();
		$caption = 'Collagen fragments may aid joint repair';

		$first  = $this->renderer()->compose_og( $bytes, $caption );
		$second = $this->renderer()->compose_og( $bytes, $caption );
		$this->assertSame( hash( 'sha256', $first['bytes'] ), hash( 'sha256', $second['bytes'] ), 'OG render must be byte-stable' );

		$first  = $this->renderer()->compose_square( $bytes, $caption );
		$second = $this->renderer()->compose_square( $bytes, $caption );
		$this->assertSame( hash( 'sha256', $first['bytes'] ), hash( 'sha256', $second['bytes'] ), 'Square render must be byte-stable' );

		$first  = $this->renderer()->compose_featured( $bytes );
		$second = $this->renderer()->compose_featured( $bytes );
		$this->assertSame( hash( 'sha256', $first['bytes'] ), hash( 'sha256', $second['bytes'] ), 'Featured render must be byte-stable' );
	}

	/**
	 * Variant geometry matches the spec: featured keeps base dimensions,
	 * OG is exactly 1200×630, square exactly 1080×1080, all PNG.
	 */
	public function test_variant_dimensions_and_mime(): void {
		$bytes   = $this->fixture_bytes();
		$caption = 'Collagen fragments may aid joint repair';

		$featured = $this->renderer()->compose_featured( $bytes );
		$this->assertSame( [ 1200, 632, 'image/png', 'featured' ], [ $featured['width'], $featured['height'], $featured['mime_type'], $featured['role'] ] );

		$og = $this->renderer()->compose_og( $bytes, $caption );
		$this->assertSame( [ 1200, 630, 'image/png', 'og' ], [ $og['width'], $og['height'], $og['mime_type'], $og['role'] ] );

		$square = $this->renderer()->compose_square( $bytes, $caption );
		$this->assertSame( [ 1080, 1080, 'image/png', 'square' ], [ $square['width'], $square['height'], $square['mime_type'], $square['role'] ] );
	}

	/**
	 * Output PNG bytes embed no date text chunks or tIME chunk, so a render
	 * is stable across wall-clock time, not just within one process. (We
	 * scan the raw bytes: Imagick synthesizes `date:*` image properties at
	 * read time even for chunk-free PNGs, so getImageProperties() can't
	 * distinguish embedded dates from synthesized ones.)
	 */
	public function test_output_contains_no_date_chunks(): void {
		$og = $this->renderer()->compose_og( $this->fixture_bytes(), 'Short caption' );

		$this->assertFalse( strpos( $og['bytes'], 'date:create' ), 'PNG must not embed a date:create text chunk' );
		$this->assertFalse( strpos( $og['bytes'], 'date:modify' ), 'PNG must not embed a date:modify text chunk' );
		$this->assertFalse( strpos( $og['bytes'], 'tIME' ), 'PNG must not embed a tIME chunk' );
	}
}
