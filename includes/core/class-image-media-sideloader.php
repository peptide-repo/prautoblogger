<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Downloads generated images and imports them into the WordPress media library.
 *
 * Handles sideloading (importing external images as WordPress attachments),
 * sets metadata (alt text, title), and cleans up temporary files on failure.
 *
 * Triggered by: PRAutoBlogger_Image_Pipeline during content generation run.
 * Dependencies: WordPress media functions (media_handle_sideload, wp_insert_attachment).
 *
 * @see core/class-image-pipeline.php      — Consumes sideload_image().
 * @see providers/interface-image-provider.php — Image metadata (bytes, mime_type, etc).
 * @see ARCHITECTURE.md                    — Media library integration.
 */
class PRAutoBlogger_Image_Media_Sideloader {

	/**
	 * Download and sideload a generated image into WordPress media library.
	 *
	 * Creates a temporary file, imports it via media_handle_sideload(), and
	 * cleans up the temp file on success or failure. Sets alt text and title
	 * from the prompt if provided.
	 *
	 * @param array{
	 *     bytes: string,
	 *     mime_type: string,
	 *     width: int,
	 *     height: int,
	 *     model: string,
	 *     seed?: ?int,
	 *     cost_usd: float,
	 *     latency_ms: int,
	 * } $image_data Raw image bytes + metadata from PRAutoBlogger_Image_Provider_Interface.
	 * @param int    $post_id Target post ID to attach the image to.
	 * @param string $alt_text Alt text for the image (the editorial caption).
	 * @param string $filename_suffix Optional role hint appended to the filename
	 *                                (e.g. 'og', 'square' for composed variants).
	 *
	 * @return int|\WP_Error Attachment ID on success, WP_Error on failure.
	 */
	public function sideload_image( array $image_data, int $post_id, string $alt_text = '', string $filename_suffix = '' ) {
		// WordPress media functions live in wp-admin/includes/ and are NOT
		// loaded automatically in cron or REST contexts. Load them if missing.
		// Why: WP-Cron runs as a frontend request, so admin-only files like
		// media.php, file.php, and image.php are not included by default.
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Validate input.
		if ( empty( $image_data['bytes'] ) ) {
			return new \WP_Error( 'invalid_image_data', 'Image data (bytes) is empty.' );
		}

		// Create a temporary file.
		$temp_file = $this->create_temp_file( $image_data['bytes'], $image_data['mime_type'] );
		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		// Prepare file array for media_handle_sideload().
		$file_array = array(
			'name'     => $this->generate_filename( $image_data['model'], $image_data['width'], $image_data['height'], $filename_suffix ),
			'tmp_name' => $temp_file,
		);

		// Import the file into media library.
		$attachment_id = media_handle_sideload( $file_array, $post_id );

		// Clean up temp file.
		@unlink( $temp_file );

		if ( is_wp_error( $attachment_id ) ) {
			PRAutoBlogger_Logger::instance()->error(
				'Failed to sideload image: ' . $attachment_id->get_error_message(),
				'image_media_sideloader'
			);
			return $attachment_id;
		}

		// Set alt text and metadata.
		if ( ! empty( $alt_text ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
		}

		// Store image generation metadata.
		update_post_meta( $attachment_id, '_prautoblogger_image_model', sanitize_text_field( $image_data['model'] ) );
		update_post_meta( $attachment_id, '_prautoblogger_image_width', (int) $image_data['width'] );
		update_post_meta( $attachment_id, '_prautoblogger_image_height', (int) $image_data['height'] );
		update_post_meta( $attachment_id, '_prautoblogger_image_cost_usd', (float) $image_data['cost_usd'] );
		update_post_meta( $attachment_id, '_prautoblogger_image_latency_ms', (int) $image_data['latency_ms'] );

		if ( isset( $image_data['seed'] ) && null !== $image_data['seed'] ) {
			update_post_meta( $attachment_id, '_prautoblogger_image_seed', (int) $image_data['seed'] );
		}

		return $attachment_id;
	}

	/**
	 * Create a temporary file containing the image bytes.
	 *
	 * @param string $bytes Raw image bytes.
	 * @param string $mime_type MIME type (e.g., 'image/png').
	 *
	 * @return string|\WP_Error Path to temporary file on success, WP_Error on failure.
	 */
	private function create_temp_file( string $bytes, string $mime_type ) {
		$temp_dir  = get_temp_dir();
		$filename  = 'prautoblogger_img_' . wp_generate_uuid4() . $this->get_extension_for_mime( $mime_type );
		$temp_path = $temp_dir . $filename;

		$written = file_put_contents( $temp_path, $bytes );
		if ( false === $written ) {
			return new \WP_Error(
				'temp_file_write_failed',
				sprintf(
					/* translators: %s: path to temp file */
					esc_html__( 'Could not write temporary image file to %s', 'prautoblogger' ),
					$temp_path
				)
			);
		}

		return $temp_path;
	}

	/**
	 * Generate a descriptive filename for the attachment.
	 *
	 * @param string $model Model name (e.g., 'flux-1-schnell').
	 * @param int    $width Image width.
	 * @param int    $height Image height.
	 * @param string $suffix Optional role hint (e.g. 'og', 'square'); back-compat default ''.
	 *
	 * @return string Sanitized filename.
	 */
	private function generate_filename( string $model, int $width, int $height, string $suffix = '' ): string {
		$base = sprintf(
			'prautoblogger_%s_%dx%d_%s%s',
			sanitize_title( $model ),
			$width,
			$height,
			gmdate( 'Y-m-d_H-i-s' ),
			'' !== $suffix ? '_' . sanitize_title( $suffix ) : ''
		);

		return $base . '.png'; // Default to PNG — most image models output PNG.
	}

	/**
	 * Get file extension for a MIME type.
	 *
	 * @param string $mime_type MIME type string.
	 *
	 * @return string File extension with leading dot (e.g., '.png').
	 */
	private function get_extension_for_mime( string $mime_type ): string {
		$map = array(
			'image/png'  => '.png',
			'image/jpeg' => '.jpg',
			'image/jpg'  => '.jpg',
		);

		return $map[ strtolower( $mime_type ) ] ?? '.png';
	}
}
