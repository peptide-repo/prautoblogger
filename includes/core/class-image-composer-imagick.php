<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Imagick renderer for the deterministic image composer (full-fidelity rung).
 *
 * Renders the three branded variants from one base image: corner-marked
 * featured (1200×632), teal-band OG (1200×630), and card-layout square
 * (1080×1080). No WordPress functions anywhere — layout geometry and asset
 * directories arrive via the constructor, so the class runs unchanged inside
 * the standalone render harness that produced the PR samples. Methods throw
 * on failure; the orchestrator catches and degrades, so a renderer error can
 * never fail a publish.
 *
 * Triggered by: PRAutoBlogger_Image_Composer when the capability probe says 'imagick'.
 * Dependencies: ext-imagick, bundled brand PNGs + OFL fonts,
 *               PRAutoBlogger_Image_Composer_Canvas (primitives),
 *               PRAutoBlogger_Image_Composer_Layout (caption clamp).
 *
 * @see core/class-image-composer.php        — Orchestrator + degradation ladder.
 * @see core/class-image-composer-canvas.php — Crop/logo/PNG primitives.
 * @see core/class-image-composer-layout.php — Geometry defaults + clamp.
 */
class PRAutoBlogger_Image_Composer_Imagick {

	/** @var array<string, array<string, mixed>> Layout geometry keyed by role. */
	private array $layout;

	/** @var string Absolute path to the vendored brand PNG directory (trailing slash). */
	private string $brand_dir;

	/** @var string Absolute path to the bundled font directory (trailing slash). */
	private string $fonts_dir;

	/**
	 * Construct with resolved layout + asset locations.
	 *
	 * @param array<string, array<string, mixed>> $layout    Filtered layout (see Layout::defaults()).
	 * @param string                              $brand_dir Brand PNG directory, trailing slash.
	 * @param string                              $fonts_dir Font directory, trailing slash.
	 */
	public function __construct( array $layout, string $brand_dir, string $fonts_dir ) {
		$this->layout    = $layout;
		$this->brand_dir = $brand_dir;
		$this->fonts_dir = $fonts_dir;
	}

	/**
	 * Featured variant: base image untouched except a subtle corner mark.
	 *
	 * @param string $bytes Base image bytes.
	 *
	 * @return array{bytes: string, mime_type: string, width: int, height: int, role: string}
	 * @throws \RuntimeException|\ImagickException On decode/asset failure.
	 */
	public function compose_featured( string $bytes ): array {
		$conf   = $this->layout['featured'];
		$canvas = PRAutoBlogger_Image_Composer_Canvas::load_base( $bytes );

		$mark_h = (int) $conf['mark_height'];
		$inset  = (int) $conf['mark_inset'];
		// The mark asset is square, so rendered width equals the height.
		PRAutoBlogger_Image_Composer_Canvas::place_logo(
			$canvas,
			$this->brand_dir . $conf['mark_asset'],
			$mark_h,
			$canvas->getImageWidth() - $mark_h - $inset,
			$canvas->getImageHeight() - $mark_h - $inset,
			(float) $conf['mark_opacity']
		);

		return PRAutoBlogger_Image_Composer_Canvas::finalize_png( $canvas, 'featured' );
	}

	/**
	 * OG variant (1200×630): center-cropped base, bottom teal band with the
	 * brand mark and the clamped caption in white.
	 *
	 * @param string $bytes   Base image bytes.
	 * @param string $caption Editorial caption (clamped to 2 lines).
	 *
	 * @return array{bytes: string, mime_type: string, width: int, height: int, role: string}
	 * @throws \RuntimeException|\ImagickException On decode/crop/asset failure.
	 */
	public function compose_og( string $bytes, string $caption ): array {
		$conf   = $this->layout['og'];
		$width  = (int) $conf['width'];
		$height = (int) $conf['height'];
		$band_h = (int) $conf['band_height'];
		$band_y = $height - $band_h;

		$canvas = PRAutoBlogger_Image_Composer_Canvas::load_base( $bytes );
		if ( ! PRAutoBlogger_Image_Composer_Canvas::cover_crop( $canvas, $width, $height ) ) {
			$canvas->clear();
			throw new \RuntimeException( 'OG variant would require upscaling the base image.' );
		}

		$this->draw_rect( $canvas, 0, $band_y, $width, $height, (string) $conf['band_color'] );

		$logo_h = (int) $conf['logo_height'];
		$logo_w = PRAutoBlogger_Image_Composer_Canvas::place_logo(
			$canvas,
			$this->brand_dir . $conf['logo_asset'],
			$logo_h,
			(int) $conf['logo_x'],
			$band_y + (int) floor( ( $band_h - $logo_h ) / 2 )
		);

		$lines = PRAutoBlogger_Image_Composer_Layout::clamp_caption(
			$caption,
			(int) $conf['caption_chars_per_line'],
			(int) $conf['caption_max_lines']
		);
		$this->draw_lines(
			$canvas,
			$lines,
			array(
				'font'        => $this->fonts_dir . $conf['caption_font'],
				'size'        => (float) $conf['caption_size'],
				'color'       => (string) $conf['caption_color'],
				'line_height' => (float) $conf['caption_line_height'],
				'x'           => (int) $conf['logo_x'] + $logo_w + (int) $conf['caption_gap'],
				'center_y'    => $band_y + (int) floor( $band_h / 2 ),
				'align'       => \Imagick::ALIGN_LEFT,
			)
		);

		return PRAutoBlogger_Image_Composer_Canvas::finalize_png( $canvas, 'og' );
	}

	/**
	 * Square variant (1080×1080, card layout, no upscaling): full base
	 * downscaled into the top slice, cream caption panel in Poppins Bold,
	 * teal footer band with the horizontal reverse lockup.
	 *
	 * @param string $bytes   Base image bytes.
	 * @param string $caption Editorial caption (clamped to 3 lines).
	 *
	 * @return array{bytes: string, mime_type: string, width: int, height: int, role: string}
	 * @throws \RuntimeException|\ImagickException On decode/crop/asset failure.
	 */
	public function compose_square( string $bytes, string $caption ): array {
		$conf     = $this->layout['square'];
		$width    = (int) $conf['width'];
		$height   = (int) $conf['height'];
		$slice_h  = (int) $conf['slice_height'];
		$footer_h = (int) $conf['footer_height'];
		$footer_y = $height - $footer_h;

		$slice = PRAutoBlogger_Image_Composer_Canvas::load_base( $bytes );
		if ( ! PRAutoBlogger_Image_Composer_Canvas::cover_crop( $slice, $width, $slice_h ) ) {
			$slice->clear();
			throw new \RuntimeException( 'Square variant would require upscaling the base image.' );
		}

		$canvas = new \Imagick();
		$canvas->newImage( $width, $height, new \ImagickPixel( (string) $conf['panel_color'] ), 'png' );
		$canvas->compositeImage( $slice, \Imagick::COMPOSITE_OVER, 0, 0 );
		$slice->clear();

		$this->draw_rect( $canvas, 0, $footer_y, $width, $height, (string) $conf['footer_color'] );

		$logo_h = (int) $conf['footer_logo_height'];
		$logo   = new \Imagick( $this->brand_dir . $conf['footer_logo_asset'] );
		$logo_w = (int) round( $logo->getImageWidth() * ( $logo_h / max( 1, $logo->getImageHeight() ) ) );
		$logo->clear();
		PRAutoBlogger_Image_Composer_Canvas::place_logo(
			$canvas,
			$this->brand_dir . $conf['footer_logo_asset'],
			$logo_h,
			(int) floor( ( $width - $logo_w ) / 2 ),
			$footer_y + (int) floor( ( $footer_h - $logo_h ) / 2 )
		);

		$lines = PRAutoBlogger_Image_Composer_Layout::clamp_caption(
			$caption,
			(int) $conf['caption_chars_per_line'],
			(int) $conf['caption_max_lines']
		);
		$this->draw_lines(
			$canvas,
			$lines,
			array(
				'font'        => $this->fonts_dir . $conf['caption_font'],
				'size'        => (float) $conf['caption_size'],
				'color'       => (string) $conf['caption_color'],
				'line_height' => (float) $conf['caption_line_height'],
				'x'           => (int) floor( $width / 2 ),
				'center_y'    => $slice_h + (int) floor( ( $footer_y - $slice_h ) / 2 ),
				'align'       => \Imagick::ALIGN_CENTER,
			)
		);

		return PRAutoBlogger_Image_Composer_Canvas::finalize_png( $canvas, 'square' );
	}

	/**
	 * Fill an axis-aligned rectangle on the canvas.
	 *
	 * @param \Imagick $canvas Destination canvas (mutated).
	 * @param int      $x1     Left.
	 * @param int      $y1     Top.
	 * @param int      $x2     Right.
	 * @param int      $y2     Bottom.
	 * @param string   $color  Hex fill color.
	 */
	private function draw_rect( \Imagick $canvas, int $x1, int $y1, int $x2, int $y2, string $color ): void {
		$draw = new \ImagickDraw();
		$draw->setFillColor( new \ImagickPixel( $color ) );
		$draw->rectangle( (float) $x1, (float) $y1, (float) $x2, (float) $y2 );
		$canvas->drawImage( $draw );
	}

	/**
	 * Draw pre-clamped caption lines vertically centered around center_y.
	 *
	 * @param \Imagick $canvas Destination canvas (mutated).
	 * @param string[] $lines  Already-clamped lines (may be empty — no-op).
	 * @param array{font: string, size: float, color: string, line_height: float, x: int, center_y: int, align: int} $opts Text options.
	 *
	 * @throws \RuntimeException When the bundled font file is missing.
	 */
	private function draw_lines( \Imagick $canvas, array $lines, array $opts ): void {
		if ( empty( $lines ) ) {
			return;
		}
		if ( ! is_readable( $opts['font'] ) ) {
			throw new \RuntimeException( 'Composer font missing: ' . basename( $opts['font'] ) );
		}

		$draw = new \ImagickDraw();
		$draw->setFont( $opts['font'] );
		$draw->setFontSize( $opts['size'] );
		$draw->setFillColor( new \ImagickPixel( $opts['color'] ) );
		$draw->setTextAlignment( $opts['align'] );
		$draw->setTextAntialias( true );

		$metrics     = $canvas->queryFontMetrics( $draw, 'Hg' );
		$line_step   = (int) round( $opts['size'] * $opts['line_height'] );
		$block_h     = ( count( $lines ) - 1 ) * $line_step + (int) round( $metrics['ascender'] - $metrics['descender'] );
		$baseline    = $opts['center_y'] - (int) floor( $block_h / 2 ) + (int) round( $metrics['ascender'] );

		foreach ( $lines as $line ) {
			$canvas->annotateImage( $draw, (float) $opts['x'], (float) $baseline, 0.0, $line );
			$baseline += $line_step;
		}
	}
}
