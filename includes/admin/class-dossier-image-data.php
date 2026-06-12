<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Resolves a post's pipeline image attachments for the dossier (M3/F3).
 *
 * What: The image sections' REAL data source — the attachments the
 *       image pipeline produced (featured + composed og/square variants,
 *       discovered via the `_prautoblogger_image_role` attachment meta
 *       and the post thumbnail), paired in the template with the
 *       image_a/image_b generation_log rows. Replaces the M2 sections
 *       that keyed off run_stages rows the image pipeline never writes
 *       (QA M2 F3 — wiring the dossier to real data was the smaller,
 *       safer change vs. teaching the live image pipeline to write
 *       run_stages; the pipeline restructure is Phase 2b).
 * Who triggers it: PRAutoBlogger_Dossier_Data_Assembler::assemble().
 * Dependencies: get_children(), attachment meta, wp_get_attachment_image_url().
 *
 * @see admin/class-dossier-data-assembler.php   — Caller.
 * @see templates/admin/dossier-log-stage-section.php — Renders the pairs.
 * @see ARCHITECTURE.md #24                      — M3 design (F3 note).
 */
class PRAutoBlogger_Dossier_Image_Data {

	/**
	 * The post's pipeline attachments (featured/og/square) via the
	 * `_prautoblogger_image_role` attachment meta — the image sections'
	 * real data source (QA M2 F3).
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array{id: int, role: string, url: string, is_featured: bool}>
	 */
	public static function for_post( int $post_id ): array {
		$featured_id = (int) get_post_thumbnail_id( $post_id );
		$children    = get_children(
			array(
				'post_parent' => $post_id,
				'post_type'   => 'attachment',
				'numberposts' => 12,
			)
		);

		$images = array();
		$seen   = array();
		foreach ( (array) $children as $attachment ) {
			$att_id = (int) ( $attachment->ID ?? 0 );
			if ( $att_id <= 0 ) {
				continue;
			}
			$role = (string) get_post_meta( $att_id, '_prautoblogger_image_role', true );
			if ( '' === $role && $att_id !== $featured_id ) {
				continue; // Not a pipeline attachment.
			}
			$url = (string) wp_get_attachment_image_url( $att_id, 'medium' );
			if ( '' === $url ) {
				continue;
			}
			$seen[ $att_id ] = true;
			$images[]        = array(
				'id'          => $att_id,
				'role'        => '' !== $role ? $role : 'featured',
				'url'         => $url,
				'is_featured' => $att_id === $featured_id,
			);
		}
		// Featured image attached elsewhere (not a child) still renders.
		if ( $featured_id > 0 && ! isset( $seen[ $featured_id ] ) ) {
			$url = (string) wp_get_attachment_image_url( $featured_id, 'medium' );
			if ( '' !== $url ) {
				$images[] = array(
					'id'          => $featured_id,
					'role'        => 'featured',
					'url'         => $url,
					'is_featured' => true,
				);
			}
		}
		return $images;
	}
}
