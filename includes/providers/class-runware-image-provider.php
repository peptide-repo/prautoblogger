<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Runware image provider — FLUX.1 schnell / dev via runware.ai.
 *
 * Default image backend as of 2026-04-21: schnell (runware:100@1) at
 * ~$0.0006/image, ~2s latency. 65× cheaper than Gemini Nano Banana with
 * quality that reads as "stylistic looseness" for our comic aesthetic.
 *
 * Triggered by: PRAutoBlogger_Image_Pipeline during content generation.
 * Dependencies: Runware_Image_Support (key/parsing/backoff),
 *               Runware_Image_Pricing (model/cost),
 *               Runware_Image_Batch (parallel curl_multi), Logger.
 *
 * @see interface-image-provider.php            — Contract.
 * @see class-runware-image-batch.php           — Parallel generation via curl_multi.
 * @see class-runware-image-support.php         — Key, response parsing, retry.
 * @see class-runware-image-pricing.php         — Model + cost table.
 * @see class-open-router-image-provider.php    — Sibling pattern for OpenRouter.
 * @see ARCHITECTURE.md                         — External API Integrations table.
 */
class PRAutoBlogger_Runware_Image_Provider implements PRAutoBlogger_Image_Provider_Interface {

	private const PROVIDER_ID      = 'runware';
	private const MIN_DIMENSION_PX = 64;
	private const MAX_DIMENSION_PX = 2048;

	/** @var PRAutoBlogger_Runware_Image_Pricing */
	private PRAutoBlogger_Runware_Image_Pricing $pricing;

	/** @var PRAutoBlogger_Runware_Image_Support */
	private PRAutoBlogger_Runware_Image_Support $support;

	public function __construct() {
		$this->pricing = new PRAutoBlogger_Runware_Image_Pricing();
		$this->support = new PRAutoBlogger_Runware_Image_Support();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Two-step HTTP flow: POST /v1 returns a signed imageURL, then GET that
	 * URL for the bytes. Retries 5xx/429 on the POST with exponential
	 * backoff; 4xx (except 429) fail immediately. The image download GET
	 * is not retried — signed URLs are short-lived.
	 */
	public function generate_image( string $prompt, int $width, int $height, array $options = array() ): array {
		$this->assert_valid_dimensions( $width, $height );
		$prompt = trim( $prompt );
		if ( '' === $prompt ) {
			throw new \InvalidArgumentException(
				esc_html__( 'Image prompt cannot be empty.', 'prautoblogger' )
			);
		}

		$api_key    = $this->support->get_api_key();
		$model      = $this->pricing->resolve_model( (string) ( $options['model'] ?? '' ) );
		$snap_w     = $this->support->snap_dimension( $width );
		$snap_h     = $this->support->snap_dimension( $height );
		$seed       = $this->support->normalize_seed( isset( $options['seed'] ) ? (int) $options['seed'] : null );
		$steps      = isset( $options['steps'] ) ? (int) $options['steps'] : $this->pricing->default_steps( $model );
		$body       = $this->build_request_body( $api_key, $prompt, $snap_w, $snap_h, $model, $seed, $steps );
		$headers    = array( 'Content-Type' => 'application/json' );
		$started_at = microtime( true );
		$last_error = '';

		for ( $attempt = 1; $attempt <= PRAUTOBLOGGER_MAX_RETRIES; $attempt++ ) {
			$response = wp_remote_post(
				PRAutoBlogger_Runware_Image_Support::API_URL,
				array(
					'timeout' => PRAUTOBLOGGER_API_TIMEOUT_SECONDS,
					'headers' => $headers,
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_error = $response->get_error_message();
				$this->support->log_retry( $attempt, 'network', $last_error );
				$this->support->backoff( $attempt );
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$raw    = (string) wp_remote_retrieve_body( $response );

			if ( 429 === $status || $status >= 500 ) {
				$last_error = sprintf( 'HTTP %d: %s', $status, substr( $raw, 0, 300 ) );
				$this->support->log_retry( $attempt, (string) $status, $last_error );
				if ( $attempt < PRAUTOBLOGGER_MAX_RETRIES ) {
					$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
					$this->support->backoff( $attempt, $retry_after );
				}
				continue;
			}

			if ( $status >= 400 ) {
				PRAutoBlogger_Logger::instance()->error(
					sprintf( 'Runware image gen HTTP %d: %s', $status, substr( $raw, 0, 300 ) ),
					'runware-image'
				);
				throw new \RuntimeException(
					sprintf(
						esc_html__( 'Runware image error (HTTP %1$d): %2$s', 'prautoblogger' ),
						$status,
						esc_html( substr( $raw, 0, 300 ) )
					)
				);
			}

			$image_url   = $this->support->extract_image_url( $raw );
			$image_bytes = $this->support->download_image_bytes( $image_url );

			return array(
				'bytes'      => $image_bytes,
				'mime_type'  => 'image/png',
				'width'      => $snap_w,
				'height'     => $snap_h,
				'model'      => $model,
				'seed'       => $seed,
				'cost_usd'   => $this->pricing->estimate_cost( $snap_w, $snap_h, $model ),
				'latency_ms' => (int) ( ( microtime( true ) - $started_at ) * 1000 ),
			);
		}

		throw new \RuntimeException(
			sprintf(
				esc_html__( 'Runware image gen failed after %1$d attempts. Last error: %2$s', 'prautoblogger' ),
				PRAUTOBLOGGER_MAX_RETRIES,
				esc_html( $last_error )
			)
		);
	}

	/**
	 * Generate multiple images concurrently via curl_multi.
	 *
	 * Delegates to PRAutoBlogger_Runware_Image_Batch, which fires all
	 * inference POSTs in parallel, then fetches the signed image URLs
	 * in parallel as well. Wall-clock time ≈ slowest single image.
	 *
	 * {@inheritDoc}
	 */
	public function generate_image_batch( array $requests ): array {
		foreach ( $requests as $key => $req ) {
			$this->assert_valid_dimensions( $req['width'], $req['height'] );
			if ( '' === trim( $req['prompt'] ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Image prompt for key "%s" cannot be empty.', esc_html( $key ) )
				);
			}
		}

		$batch = new PRAutoBlogger_Runware_Image_Batch( $this->pricing, $this->support );

		PRAutoBlogger_Logger::instance()->info(
			sprintf( 'Starting parallel Runware image batch (%d images)', count( $requests ) ),
			'runware-image'
		);

		return $batch->execute( $requests );
	}

	/** {@inheritDoc} */
	public function estimate_cost( int $width, int $height, array $options = array() ): float {
		$model = $this->pricing->resolve_model( (string) ( $options['model'] ?? '' ) );
		return $this->pricing->estimate_cost( $width, $height, $model );
	}

	/** {@inheritDoc} */
	public function get_provider_name(): string {
		return self::PROVIDER_ID;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Delegates to PRAutoBlogger_Runware_Validator (extracted for 300-line compliance).
	 *
	 * @see providers/class-runware-image-validator.php
	 */
	public function validate_credentials_detailed(): array {
		return PRAutoBlogger_Runware_Validator::validate( $this->support );
	}


	/**
	 * Build the JSON task-array body for a single imageInference request.
	 *
	 * Runware's single endpoint consumes [auth, inference] in one POST.
	 *
	 * @param string $api_key Plaintext API key.
	 * @param string $prompt  Full prompt text.
	 * @param int    $width   Snapped width.
	 * @param int    $height  Snapped height.
	 * @param string $model   Resolved model id.
	 * @param int    $seed    Positive seed.
	 * @param int    $steps   Inference steps.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_request_body(
		string $api_key,
		string $prompt,
		int $width,
		int $height,
		string $model,
		int $seed,
		int $steps
	): array {
		return array(
			array(
				'taskType' => 'authentication',
				'apiKey'   => $api_key,
			),
			array(
				'taskType'       => 'imageInference',
				'taskUUID'       => wp_generate_uuid4(),
				'positivePrompt' => $prompt,
				'width'          => $width,
				'height'         => $height,
				'model'          => $model,
				'steps'          => $steps,
				'numberResults'  => 1,
				'outputFormat'   => 'PNG',
				'outputType'     => 'URL',
				'seed'           => $seed,
			),
		);
	}

	/**
	 * Enforce the interface's dimension contract.
	 *
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels.
	 * @throws \InvalidArgumentException If outside [MIN, MAX].
	 */
	private function assert_valid_dimensions( int $width, int $height ): void {
		if (
			$width < self::MIN_DIMENSION_PX || $width > self::MAX_DIMENSION_PX ||
			$height < self::MIN_DIMENSION_PX || $height > self::MAX_DIMENSION_PX
		) {
			throw new \InvalidArgumentException(
				sprintf(
					esc_html__( 'Image dimensions %1$dx%2$d outside supported range (%3$d–%4$d px).', 'prautoblogger' ),
					$width,
					$height,
					self::MIN_DIMENSION_PX,
					self::MAX_DIMENSION_PX
				)
			);
		}
	}
}
