<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Resize-only composer rung backed by wp_get_image_editor() (GD path).
 *
 * Used when Imagick is unusable: emits unbranded, caption-free center-crops
 * at each variant's exact dimensions. Variants that would require upscaling
 * (e.g. a 1080×1080 square from a 1200×632 base — WP's editor never upscales
 * on crop-resize) are skipped; the OG crop is always achievable from the
 * standard base. Output is GD PNG, which embeds no timestamps, so this rung
 * is deterministic too.
 *
 * Triggered by: PRAutoBlogger_Image_Composer when the capability probe says 'gd'.
 * Dependencies: wp_get_image_editor(), get_temp_dir(), PRAutoBlogger_Logger.
 *
 * @see core/class-image-composer.php — Orchestrator + degradation ladder.
 * @see core/class-image-composer-imagick.php — The full-fidelity rung this replaces.
 */
class PRAutoBlogger_Image_Composer_Editor {

	/**
	 * Render resize-only variants for the requested roles.
	 *
	 * Side effects: temp file write/delete per variant; debug log on skips.
	 *
	 * @param string                               $bytes  Base image bytes.
	 * @param string[]                             $roles  Variant roles to attempt ('og', 'square').
	 * @param array<string, array<string, mixed>>  $layout Layout geometry (for target dimensions).
	 *
	 * @return array<int, array{bytes: string, mime_type: string, width: int, height: int, role: string}>
	 *               Successfully resized variants only (possibly empty).
	 */
	public function compose_variants( string $bytes, array $roles, array $layout ): array {
		$variants = array();

		foreach ( $roles as $role ) {
			$target_w = (int) ( $layout[ $role ]['width'] ?? 0 );
			$target_h = (int) ( $layout[ $role ]['height'] ?? 0 );
			if ( $target_w < 1 || $target_h < 1 ) {
				continue;
			}

			$resized = $this->cover_crop( $bytes, $target_w, $target_h );
			if ( null === $resized ) {
				PRAutoBlogger_Logger::instance()->debug(
					sprintf( 'Resize-only %s variant skipped (editor failure or would upscale).', $role ),
					'image_composer'
				);
				continue;
			}

			$resized['role'] = $role;
			$variants[]      = $resized;
		}

		return $variants;
	}

	/**
	 * Center-crop bytes to exact dimensions with the portable WP editor.
	 *
	 * @param string $bytes    Source image bytes.
	 * @param int    $target_w Target width in px.
	 * @param int    $target_h Target height in px.
	 *
	 * @return array{bytes: string, mime_type: string, width: int, height: int}|null
	 *               Null when the editor fails or cannot hit the exact size
	 *               without upscaling.
	 */
	private function cover_crop( string $bytes, int $target_w, int $target_h ): ?array {
		$temp = get_temp_dir() . uniqid( 'prab_compose_', true ) . '.png';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file, same pattern as the sideloader.
		if ( false === file_put_contents( $temp, $bytes ) ) {
			return null;
		}

		try {
			$editor = wp_get_image_editor( $temp );
			if ( is_wp_error( $editor ) ) {
				return null;
			}
			if ( is_wp_error( $editor->resize( $target_w, $target_h, true ) ) ) {
				return null;
			}

			$size = $editor->get_size();
			if ( (int) $size['width'] !== $target_w || (int) $size['height'] !== $target_h ) {
				return null; // Exact size not reachable without upscaling.
			}

			if ( is_wp_error( $editor->save( $temp, 'image/png' ) ) ) {
				return null;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- temp file we just wrote.
			$out = file_get_contents( $temp );

			return false === $out ? null : array(
				'bytes'     => $out,
				'mime_type' => 'image/png',
				'width'     => $target_w,
				'height'    => $target_h,
			);
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->debug( 'WP image editor crop threw: ' . $e->getMessage(), 'image_composer' );
			return null;
		} finally {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort temp cleanup.
			@unlink( $temp );
		}
	}
}
