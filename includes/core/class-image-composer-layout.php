<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Geometry defaults and caption clamping for the deterministic image composer.
 *
 * Pure functions and constants only — no WordPress, no Imagick. This keeps
 * the class unit-testable and usable from a standalone render harness. The
 * orchestrator exposes defaults() through the `prautoblogger_image_compose_layout`
 * filter; geometry is intentionally NOT a settings-page surface (promote to
 * settings only if the CEO asks to tune it — see ARCHITECTURE.md #21).
 *
 * Triggered by: PRAutoBlogger_Image_Composer (orchestrator) at compose time.
 * Dependencies: mbstring (bundled with WordPress via compat polyfills).
 *
 * @see core/class-image-composer.php          — Applies the layout filter.
 * @see core/class-image-composer-imagick.php  — Consumes the geometry values.
 * @see tests/unit/Core/ImageComposerLayoutTest.php — Clamp boundary cases.
 */
class PRAutoBlogger_Image_Composer_Layout {

	/** Brand tokens (locked — see peptide-repo-brand README). */
	public const COLOR_TEAL   = '#1B8A92';
	public const COLOR_LIME   = '#7FD600';
	public const COLOR_ORANGE = '#FF8A3D';
	public const COLOR_INK    = '#0D0D0D';
	public const COLOR_CREAM  = '#FAFAF7';

	/** Ellipsis appended when a caption overflows its line budget. */
	public const ELLIPSIS = '…';

	/**
	 * Default geometry for every composed variant.
	 *
	 * Asset filenames resolve against assets/brand/ and assets/fonts/ in the
	 * plugin; sizes are px. Values were tuned against rendered samples (see
	 * PR #thread 2026-06-image-composer assets).
	 *
	 * Note: 'caption_margin_right' (og) and 'caption_side_padding' (square) were removed
	 * in v0.19.4 — the Imagick renderer enforces right-edge geometry via caption_chars_per_line
	 * and centered x positioning respectively, so those keys had no consumer.
	 *
	 * @return array<string, array<string, mixed>> Keyed by role: featured, og, square.
	 */
	public static function defaults(): array {
		return array(
			'featured' => array(
				'mark_asset'   => 'logo-mark-small-56.png',
				'mark_height'  => 28,
				'mark_inset'   => 16,
				'mark_opacity' => 0.55,
			),
			'og'       => array(
				'width'                 => 1200,
				'height'                => 630,
				'band_height'           => 120,
				'band_color'            => self::COLOR_TEAL,
				'logo_asset'            => 'logo-mark-small-112.png',
				'logo_height'           => 64,
				'logo_x'                => 40,
				'caption_font'          => 'Poppins-SemiBold.ttf',
				'caption_size'          => 32,
				'caption_color'         => '#FFFFFF',
				'caption_gap'           => 24,
				'caption_max_lines'     => 2,
				'caption_chars_per_line' => 52,
				'caption_line_height'   => 1.3,
			),
			'square'   => array(
				'width'                 => 1080,
				'height'                => 1080,
				'slice_height'          => 569,
				'panel_color'           => self::COLOR_CREAM,
				'footer_height'         => 96,
				'footer_color'          => self::COLOR_TEAL,
				'footer_logo_asset'     => 'logo-horizontal-reverse-128.png',
				'footer_logo_height'    => 48,
				'caption_font'          => 'Poppins-Bold.ttf',
				'caption_size'          => 48,
				'caption_color'         => self::COLOR_INK,
				'caption_max_lines'     => 3,
				'caption_chars_per_line' => 36,
				'caption_line_height'   => 1.3,
			),
		);
	}

	/**
	 * Clamp and word-wrap a caption to a hard per-variant budget.
	 *
	 * Whitespace is collapsed, words wrap at word boundaries, words longer
	 * than a full line are hard-split, and overflow beyond max_lines is cut
	 * with a trailing ellipsis. Multibyte-safe (mb_* functions).
	 *
	 * Side effects: none (pure).
	 *
	 * @param string $caption        Raw caption text (any length, any script).
	 * @param int    $chars_per_line Hard character budget per line (min 1).
	 * @param int    $max_lines      Maximum number of lines (min 1).
	 *
	 * @return string[] Wrapped lines; empty array when the caption is blank.
	 */
	public static function clamp_caption( string $caption, int $chars_per_line, int $max_lines ): array {
		$chars_per_line = max( 1, $chars_per_line );
		$max_lines      = max( 1, $max_lines );
		$normalized     = trim( (string) preg_replace( '/\s+/u', ' ', $caption ) );

		if ( '' === $normalized ) {
			return array();
		}

		$lines = array();
		$line  = '';

		foreach ( explode( ' ', $normalized ) as $word ) {
			// Hard-split words longer than a full line so they cannot overflow.
			while ( mb_strlen( $word ) > $chars_per_line ) {
				if ( '' !== $line ) {
					$lines[] = $line;
					$line    = '';
					if ( count( $lines ) >= $max_lines ) {
						return self::ellipsize( $lines, $chars_per_line );
					}
				}
				$lines[] = mb_substr( $word, 0, $chars_per_line );
				$word    = mb_substr( $word, $chars_per_line );
				if ( count( $lines ) >= $max_lines ) {
					return self::ellipsize( $lines, $chars_per_line );
				}
			}

			$candidate = ( '' === $line ) ? $word : $line . ' ' . $word;
			if ( mb_strlen( $candidate ) <= $chars_per_line ) {
				$line = $candidate;
				continue;
			}

			$lines[] = $line;
			$line    = $word;
			if ( count( $lines ) >= $max_lines ) {
				return self::ellipsize( $lines, $chars_per_line );
			}
		}

		if ( '' !== $line ) {
			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * Append an ellipsis to the final line, trimming first if the line is
	 * already at the character budget so the result never exceeds it.
	 *
	 * @param string[] $lines          Lines already at the max-line count.
	 * @param int      $chars_per_line Hard character budget per line.
	 *
	 * @return string[] Lines with a trailing ellipsis on the last one.
	 */
	private static function ellipsize( array $lines, int $chars_per_line ): array {
		$last_index = count( $lines ) - 1;
		$last       = $lines[ $last_index ];

		if ( mb_strlen( $last ) + 1 > $chars_per_line ) {
			$last = rtrim( mb_substr( $last, 0, max( 0, $chars_per_line - 1 ) ) );
		}

		$lines[ $last_index ] = $last . self::ELLIPSIS;

		return $lines;
	}
}
