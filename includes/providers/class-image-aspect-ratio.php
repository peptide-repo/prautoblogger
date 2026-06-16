<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Aspect-ratio snap utility for image generation providers.
 *
 * Converts pixel dimensions to the nearest supported W:H string for APIs
 * that require an aspect-ratio parameter rather than raw pixel sizes.
 *
 * What: Static utility; no state.
 * Who triggers: PRAutoBlogger_OpenRouter_Image_Provider, PRAutoBlogger_OpenRouter_Image_Batch,
 *               PRAutoBlogger_Runware_Image_Batch.
 * Dependencies: none.
 *
 * @see providers/class-open-router-image-provider.php -- generate_image() path.
 * @see providers/class-open-router-image-batch.php    -- parallel batch path.
 * @see providers/class-runware-image-batch.php        -- Runware parallel batch.
 */
class PRAutoBlogger_Image_Aspect_Ratio {

	/**
	 * Standard aspect ratios supported by OpenRouter / Runware image models.
	 *
	 * @var array<int, array{w: int, h: int, ratio: float}>
	 */
	private const STANDARD_ASPECTS = array(
		array( 'w' => 1,  'h' => 1,  'ratio' => 1.0 ),
		array( 'w' => 3,  'h' => 2,  'ratio' => 1.5 ),
		array( 'w' => 2,  'h' => 3,  'ratio' => 0.6667 ),
		array( 'w' => 4,  'h' => 3,  'ratio' => 1.3333 ),
		array( 'w' => 3,  'h' => 4,  'ratio' => 0.75 ),
		array( 'w' => 16, 'h' => 9,  'ratio' => 1.7778 ),
		array( 'w' => 9,  'h' => 16, 'ratio' => 0.5625 ),
	);

	/**
	 * Snap pixel dimensions to the nearest supported aspect ratio string.
	 *
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels (must be > 0 to avoid division by zero).
	 * @return string Ratio string e.g. "16:9". Returns "1:1" when height is 0.
	 */
	public static function snap( int $width, int $height ): string {
		$target = $height > 0 ? (float) $width / (float) $height : 1.0;
		$best   = self::STANDARD_ASPECTS[0];
		$best_d = abs( $target - $best['ratio'] );

		foreach ( self::STANDARD_ASPECTS as $candidate ) {
			$d = abs( $target - $candidate['ratio'] );
			if ( $d < $best_d ) {
				$best   = $candidate;
				$best_d = $d;
			}
		}

		return $best['w'] . ':' . $best['h'];
	}
}
