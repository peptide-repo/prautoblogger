<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Support helpers for the Runware image provider — API key lookup,
 * response parsing (URL fetch), retry backoff, and logging.
 *
 * Split from the provider to keep both files under the 300-line cap.
 *
 * Triggered by: PRAutoBlogger_Runware_Image_Provider, PRAutoBlogger_Runware_Image_Batch.
 * Dependencies: PRAutoBlogger_Encryption, PRAutoBlogger_Logger.
 *
 * @see class-runware-image-provider.php — Primary caller.
 * @see class-runware-image-batch.php    — Parallel caller.
 */
class PRAutoBlogger_Runware_Image_Support {

	/**
	 * Runware task-based single endpoint. Unlike OpenRouter /chat/completions,
	 * Runware accepts a JSON array of tasks (auth + inference) at this URL.
	 */
	public const API_URL = 'https://api.runware.ai/v1';

	/**
	 * Get the decrypted Runware API key from settings.
	 *
	 * @return string Plaintext key.
	 * @throws \RuntimeException If key is not configured or decrypt fails.
	 */
	public function get_api_key(): string {
		$stored = (string) get_option( 'prautoblogger_runware_api_key', '' );
		if ( '' === $stored ) {
			throw new \RuntimeException(
				__( 'Runware API key is not configured. Go to PRAutoBlogger → Settings → Images.', 'prautoblogger' )
			);
		}

		if ( PRAutoBlogger_Encryption::is_encrypted( $stored ) ) {
			$decrypted = PRAutoBlogger_Encryption::decrypt( $stored );
			if ( '' === $decrypted ) {
				throw new \RuntimeException(
					__( 'Runware API key could not be decrypted. Re-enter in Settings.', 'prautoblogger' )
				);
			}
			return $decrypted;
		}

		return $stored;
	}

	/**
	 * Extract the image URL from Runware's JSON response body.
	 *
	 * The response is `{"data": [{"taskType":"imageInference","imageURL":"..."}]}`.
	 * We scan `data[]` for the first imageInference entry with a non-empty URL.
	 *
	 * @param string $raw Raw JSON response body from POST /v1.
	 * @return string The imageURL to fetch bytes from.
	 * @throws \RuntimeException If the response is malformed or contains no image.
	 */
	public function extract_image_url( string $raw ): string {
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException(
				esc_html__( 'Runware returned invalid JSON.', 'prautoblogger' )
			);
		}

		// Runware wraps errors in an `errors` array.
		if ( isset( $decoded['errors'] ) && is_array( $decoded['errors'] ) && ! empty( $decoded['errors'] ) ) {
			$first = $decoded['errors'][0] ?? array();
			$msg   = is_array( $first )
				? (string) ( $first['message'] ?? wp_json_encode( $first ) )
				: (string) $first;
			throw new \RuntimeException(
				sprintf(
					esc_html__( 'Runware upstream error: %s', 'prautoblogger' ),
					esc_html( substr( $msg, 0, 300 ) )
				)
			);
		}

		$data = $decoded['data'] ?? array();
		if ( ! is_array( $data ) || empty( $data ) ) {
			throw new \RuntimeException(
				esc_html__( 'Runware response contained no data.', 'prautoblogger' )
			);
		}

		foreach ( $data as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( 'imageInference' === ( $entry['taskType'] ?? '' ) ) {
				$url = (string) ( $entry['imageURL'] ?? '' );
				if ( '' !== $url ) {
					return $url;
				}
			}
		}

		throw new \RuntimeException(
			esc_html__( 'Runware response contained no imageInference imageURL.', 'prautoblogger' )
		);
	}

	/**
	 * Download the image bytes from a signed Runware delivery URL.
	 *
	 * Runware returns the image URL separately and bills the inference call;
	 * downloading is a regular GET against `im.runware.ai`.
	 *
	 * @param string $image_url Signed image URL from extract_image_url().
	 * @return string Raw PNG bytes.
	 * @throws \RuntimeException On HTTP error or empty body.
	 */
	public function download_image_bytes( string $image_url ): string {
		$response = wp_remote_get(
			$image_url,
			array(
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException(
				sprintf(
					esc_html__( 'Runware image download failed: %s', 'prautoblogger' ),
					esc_html( $response->get_error_message() )
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			throw new \RuntimeException(
				sprintf(
					esc_html__( 'Runware image download HTTP %d.', 'prautoblogger' ),
					$status
				)
			);
		}

		$bytes = (string) wp_remote_retrieve_body( $response );
		if ( '' === $bytes ) {
			throw new \RuntimeException(
				esc_html__( 'Runware image download returned empty body.', 'prautoblogger' )
			);
		}

		return $bytes;
	}

	/**
	 * Snap a target pixel dimension to Runware's FLUX constraints.
	 *
	 * FLUX.1 (schnell + dev) requires dimensions divisible by 64, in the
	 * range [512, 2048]. We round to the nearest multiple of 64 and clamp.
	 *
	 * @param int $px Caller-requested pixel dimension.
	 * @return int Snapped dimension.
	 */
	public function snap_dimension( int $px ): int {
		$snapped = (int) round( $px / 64 ) * 64;
		if ( $snapped < 512 ) {
			return 512;
		}
		if ( $snapped > 2048 ) {
			return 2048;
		}
		return $snapped;
	}

	/**
	 * Normalize a caller seed to Runware's constraint (must be > 0).
	 *
	 * @param int|null $seed Caller-supplied seed or null for "random".
	 * @return int A positive integer seed.
	 */
	public function normalize_seed( ?int $seed ): int {
		if ( null === $seed || $seed < 1 ) {
			// Cast to int before modulo — float % int is deprecated in PHP 8 (v0.18.2).
			$derived = (int) round( microtime( true ) * 1000 ) % 2147483647;
			return $derived > 0 ? $derived : 1;
		}
		return $seed;
	}

	/**
	 * Emit a WARN line for a retryable image-gen failure.
	 *
	 * @param int    $attempt 1-based attempt number.
	 * @param string $class   'network' or HTTP status code string.
	 * @param string $detail  Short failure detail.
	 */
	public function log_retry( int $attempt, string $class, string $detail ): void {
		PRAutoBlogger_Logger::instance()->warning(
			sprintf(
				'Runware image gen %s failure (attempt %d/%d): %s',
				$class,
				$attempt,
				PRAUTOBLOGGER_MAX_RETRIES,
				$detail
			),
			'runware-image'
		);
	}

	/**
	 * Sleep between retries with exponential backoff.
	 *
	 * @param int $attempt     1-based attempt that just failed.
	 * @param int $retry_after Retry-After header value (0 if absent).
	 */
	public function backoff( int $attempt, int $retry_after = 0 ): void {
		$delay = $retry_after > 0
			? min( $retry_after, 60 )
			: PRAUTOBLOGGER_RETRY_BASE_DELAY_SECONDS * (int) pow( 2, $attempt - 1 );
		if ( $delay > 0 ) {
			sleep( $delay );
		}
	}
}
