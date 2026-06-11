<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Persists generated images and composed variants for a post.
 *
 * Owns the media-library side of the image pipeline: sideloading the primary
 * (featured) image with cost logging, sideloading composed OG/square variants
 * with role/base meta-linking (no cost-tracker entries — composition is $0
 * local CPU), and prepending the editable HTML caption to the post. Extracted
 * from PRAutoBlogger_Image_Pipeline in v0.17.0 for 300-line compliance.
 *
 * Triggered by: PRAutoBlogger_Image_Pipeline during content generation run.
 * Dependencies: PRAutoBlogger_Image_Media_Sideloader, PRAutoBlogger_Cost_Tracker,
 *               PRAutoBlogger_Logger.
 *
 * @see core/class-image-pipeline.php         — Sole caller.
 * @see core/class-image-composer.php         — Produces the variants this persists.
 * @see core/class-image-media-sideloader.php — Media library import.
 */
class PRAutoBlogger_Image_Attacher {

	/** Post meta key per composed variant role. */
	private const ROLE_POST_META = array(
		'og'     => '_prautoblogger_og_image_id',
		'square' => '_prautoblogger_square_image_id',
	);

	/** @var PRAutoBlogger_Image_Media_Sideloader Imports bytes into the media library. */
	private PRAutoBlogger_Image_Media_Sideloader $sideloader;

	/** @var PRAutoBlogger_Cost_Tracker Logs primary image generation spend. */
	private PRAutoBlogger_Cost_Tracker $cost_tracker;

	/**
	 * Construct with the pipeline's sideloader + cost tracker.
	 *
	 * @param PRAutoBlogger_Image_Media_Sideloader $sideloader   Media importer.
	 * @param PRAutoBlogger_Cost_Tracker           $cost_tracker Spend logger.
	 */
	public function __construct( PRAutoBlogger_Image_Media_Sideloader $sideloader, PRAutoBlogger_Cost_Tracker $cost_tracker ) {
		$this->sideloader   = $sideloader;
		$this->cost_tracker = $cost_tracker;
	}

	/**
	 * Sideload a primary image into the media library and log its cost.
	 *
	 * Side effects: media import, cost-tracker row, info log.
	 *
	 * @param array  $image_data Provider result with bytes, model, cost_usd.
	 * @param int    $post_id    Post ID.
	 * @param string $slot       Cost-tracking slot ('image_a' or 'image_b').
	 * @param string $label      Human-readable label for logs.
	 * @param string $alt_text   Alt text — the editorial caption (v0.17.0 bug
	 *                           fix: previously the model name leaked here).
	 *
	 * @return int|\WP_Error Attachment ID on success.
	 */
	public function sideload_and_log( array $image_data, int $post_id, string $slot, string $label, string $alt_text = '' ) {
		$attachment_id = $this->sideloader->sideload_image( $image_data, $post_id, $alt_text );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$this->cost_tracker->log_image_generation(
			$image_data['cost_usd'],
			$image_data['model'] ?? 'unknown',
			$post_id,
			$slot
		);

		PRAutoBlogger_Logger::instance()->info(
			sprintf( '%s for post %d (att %d, $%.4f)', $label, $post_id, $attachment_id, $image_data['cost_usd'] ),
			'image_pipeline'
		);

		return $attachment_id;
	}

	/**
	 * Sideload composed variants and meta-link them to post + base image.
	 *
	 * Each variant attachment gets `_prautoblogger_image_role` and
	 * `_prautoblogger_base_attachment_id`; the post gets the role-specific
	 * ID meta (see ROLE_POST_META). No cost-tracker entries — composition is
	 * a $0 local stage. A failed variant sideload logs a WARNING and is
	 * skipped; it never blocks the publish.
	 *
	 * @param array  $variants           Composed variants (role 'og'/'square' only are persisted).
	 * @param int    $post_id            Post ID.
	 * @param int    $base_attachment_id Featured attachment the variants derive from.
	 * @param string $caption            Editorial caption (used as variant alt text).
	 * @param string $model              Generation model recorded on the variant attachment.
	 */
	public function attach_variants( array $variants, int $post_id, int $base_attachment_id, string $caption, string $model ): void {
		foreach ( $variants as $variant ) {
			$role = (string) ( $variant['role'] ?? '' );
			if ( ! isset( self::ROLE_POST_META[ $role ] ) ) {
				continue;
			}

			$attachment_id = $this->sideloader->sideload_image(
				array(
					'bytes'      => (string) ( $variant['bytes'] ?? '' ),
					'mime_type'  => (string) ( $variant['mime_type'] ?? 'image/png' ),
					'width'      => (int) ( $variant['width'] ?? 0 ),
					'height'     => (int) ( $variant['height'] ?? 0 ),
					'model'      => $model,
					'cost_usd'   => 0.0,
					'latency_ms' => 0,
				),
				$post_id,
				$caption,
				$role
			);

			if ( is_wp_error( $attachment_id ) ) {
				PRAutoBlogger_Logger::instance()->warning(
					sprintf( 'Failed to sideload %s variant for post %d: %s', $role, $post_id, $attachment_id->get_error_message() ),
					'image_composer'
				);
				continue;
			}

			update_post_meta( $attachment_id, '_prautoblogger_image_role', $role );
			update_post_meta( $attachment_id, '_prautoblogger_base_attachment_id', $base_attachment_id );
			update_post_meta( $post_id, self::ROLE_POST_META[ $role ], $attachment_id );

			PRAutoBlogger_Logger::instance()->info(
				sprintf( '%s variant for post %d (att %d, %dx%d)', $role, $post_id, $attachment_id, (int) $variant['width'], (int) $variant['height'] ),
				'image_composer'
			);
		}
	}

	/**
	 * Prepend the editorial caption as styled text at the top of the post.
	 *
	 * Only inserts the caption — NOT the image. The theme already displays
	 * the featured image, so embedding it again would cause a duplicate.
	 *
	 * @param int    $post_id       Post ID.
	 * @param int    $attachment_id Featured image attachment ID (unused; kept for parity with meta storage).
	 * @param string $caption       Caption text shown below the image.
	 */
	public function prepend_caption_to_post( int $post_id, int $attachment_id, string $caption ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		// Caption-only block — the theme handles the featured image display.
		$caption_html = sprintf(
			'<p class="prautoblogger-comic-caption" style="text-align:center;font-style:italic;color:#555;font-size:1.1em;margin:0 0 2em 0;">— "%s"</p>',
			esc_html( $caption )
		);

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $caption_html . "\n\n" . $post->post_content,
			)
		);
	}
}
