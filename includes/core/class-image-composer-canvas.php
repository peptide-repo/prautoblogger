<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Low-level Imagick canvas primitives for the deterministic image composer.
 *
 * Capability smoke test, base-image loading, cover-cropping, logo placement,
 * and deterministic PNG encoding. No WordPress functions anywhere in this
 * class — it runs unchanged inside the standalone render harness used to
 * produce the PR sample images.
 *
 * Triggered by: PRAutoBlogger_Image_Composer_Imagick (renderer) per variant.
 * Dependencies: ext-imagick (callers must gate on is_imagick_usable()).
 *
 * @see core/class-image-composer-imagick.php — Sole consumer.
 * @see core/class-image-composer.php         — Caches the capability probe result.
 */
class PRAutoBlogger_Image_Composer_Canvas {

	/**
	 * Probe whether Imagick can do everything the renderer needs: PNG
	 * encode plus TTF text annotation (the smoke test draws one glyph on a
	 * tiny in-memory canvas). Cheap to run but callers should cache it.
	 *
	 * Side effects: none beyond a transient in-memory Imagick object.
	 *
	 * @param string $font_path Absolute path to a bundled TTF used for the smoke test.
	 *
	 * @return bool True when full compositing is available.
	 */
	public static function is_imagick_usable( string $font_path ): bool {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( '\Imagick' ) ) {
			return false;
		}

		try {
			$formats = \Imagick::queryFormats( 'PNG' );
			if ( empty( $formats ) ) {
				return false;
			}

			if ( ! is_readable( $font_path ) ) {
				return false;
			}

			$probe = new \Imagick();
			$probe->newImage( 8, 8, new \ImagickPixel( '#ffffff' ), 'png' );
			$draw = new \ImagickDraw();
			$draw->setFont( $font_path );
			$draw->setFontSize( 6 );
			$probe->annotateImage( $draw, 1, 6, 0.0, 'P' );
			$probe->clear();

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Load base image bytes into an Imagick object (first frame only).
	 *
	 * @param string $bytes Raw image bytes (PNG/JPEG/WebP — any readable format).
	 *
	 * @return \Imagick Loaded image.
	 * @throws \RuntimeException When the bytes cannot be decoded.
	 */
	public static function load_base( string $bytes ): \Imagick {
		$image = new \Imagick();
		if ( ! $image->readImageBlob( $bytes ) ) {
			throw new \RuntimeException( 'Composer could not decode base image bytes.' );
		}
		$image->setIteratorIndex( 0 );
		$image = $image->coalesceImages()->current();
		$image->setImagePage( 0, 0, 0, 0 );

		return $image;
	}

	/**
	 * Center cover-crop an image to exact target dimensions without upscaling.
	 *
	 * Crops the largest centered region matching the target aspect ratio,
	 * then scales DOWN to the target. Refuses (returns false) when the source
	 * is too small to fill the target without upscaling.
	 *
	 * Side effects: mutates $image in place on success.
	 *
	 * @param \Imagick $image  Source image (mutated).
	 * @param int      $target_w Target width in px.
	 * @param int      $target_h Target height in px.
	 *
	 * @return bool False when the crop would require upscaling.
	 */
	public static function cover_crop( \Imagick $image, int $target_w, int $target_h ): bool {
		$width  = $image->getImageWidth();
		$height = $image->getImageHeight();
		if ( $width < 1 || $height < 1 ) {
			return false;
		}

		$ratio = max( $target_w / $width, $target_h / $height );
		if ( $ratio > 1.0 ) {
			return false;
		}

		$src_w = min( $width, (int) round( $target_w / $ratio ) );
		$src_h = min( $height, (int) round( $target_h / $ratio ) );
		$x     = (int) floor( ( $width - $src_w ) / 2 );
		$y     = (int) floor( ( $height - $src_h ) / 2 );

		$image->cropImage( $src_w, $src_h, $x, $y );
		$image->setImagePage( 0, 0, 0, 0 );
		$image->resizeImage( $target_w, $target_h, \Imagick::FILTER_LANCZOS, 1 );

		return true;
	}

	/**
	 * Composite a brand PNG onto the canvas at a target height and opacity.
	 *
	 * @param \Imagick $canvas     Destination canvas (mutated).
	 * @param string   $asset_path Absolute path to the brand PNG.
	 * @param int      $target_h   Logo height in px (width keeps aspect).
	 * @param int      $x          Left position on the canvas.
	 * @param int      $y          Top position on the canvas.
	 * @param float    $opacity    1.0 = opaque, 0.55 = subtle corner mark.
	 *
	 * @return int Rendered logo width in px (useful for caption x-offsets).
	 * @throws \RuntimeException When the asset is missing or unreadable.
	 */
	public static function place_logo( \Imagick $canvas, string $asset_path, int $target_h, int $x, int $y, float $opacity = 1.0 ): int {
		if ( ! is_readable( $asset_path ) ) {
			throw new \RuntimeException( 'Composer brand asset missing: ' . basename( $asset_path ) );
		}

		$logo = new \Imagick( $asset_path );
		$logo->setImagePage( 0, 0, 0, 0 );
		$scale    = $target_h / max( 1, $logo->getImageHeight() );
		$target_w = max( 1, (int) round( $logo->getImageWidth() * $scale ) );
		$logo->resizeImage( $target_w, $target_h, \Imagick::FILTER_LANCZOS, 1 );

		if ( $opacity < 1.0 ) {
			$logo->evaluateImage( \Imagick::EVALUATE_MULTIPLY, max( 0.0, $opacity ), \Imagick::CHANNEL_ALPHA );
		}

		$canvas->compositeImage( $logo, \Imagick::COMPOSITE_OVER, $x, $y );
		$logo->clear();

		return $target_w;
	}

	/**
	 * Encode a canvas as a deterministic PNG variant payload.
	 *
	 * Strips all metadata and excludes PNG date/time chunks so identical
	 * inputs produce byte-identical output in the same environment.
	 *
	 * Side effects: destroys the Imagick object after encoding.
	 *
	 * @param \Imagick $image Finished canvas.
	 * @param string   $role  Variant role: 'featured', 'og', or 'square'.
	 *
	 * @return array{bytes: string, mime_type: string, width: int, height: int, role: string}
	 */
	public static function finalize_png( \Imagick $image, string $role ): array {
		$image->setImageFormat( 'png' );
		$image->setImageDepth( 8 );
		$image->stripImage();
		$image->setOption( 'png:exclude-chunks', 'date,time' );
		$image->setOption( 'png:compression-level', '9' );

		$variant = array(
			'bytes'     => $image->getImageBlob(),
			'mime_type' => 'image/png',
			'width'     => $image->getImageWidth(),
			'height'    => $image->getImageHeight(),
			'role'      => $role,
		);
		$image->clear();

		return $variant;
	}
}
