<?php
// phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- curl_multi required for parallel image generation; no WP HTTP equivalent.

/**
 * Parallel image generation via curl_multi for Runware.
 *
 * Fires multiple POST /v1 inference calls concurrently, then fetches
 * the returned signed image URLs in a second parallel round. Cuts
 * wall-clock time from N×2s to ~2s regardless of batch size — matches
 * the Image A/B parallelism pattern already in use for OpenRouter.
 *
 * Triggered by: PRAutoBlogger_Runware_Image_Provider::generate_image_batch().
 * Dependencies: PRAutoBlogger_Runware_Image_Support, PRAutoBlogger_Runware_Image_Pricing,
 *               PRAutoBlogger_Logger.
 *
 * @see class-runware-image-provider.php — Sole caller.
 * @see class-runware-image-support.php  — Response parsing + image URL fetch.
 * @see class-open-router-image-batch.php — Sibling pattern for OpenRouter.
 */
class PRAutoBlogger_Runware_Image_Batch {

	/** @var PRAutoBlogger_Runware_Image_Pricing */
	private PRAutoBlogger_Runware_Image_Pricing $pricing;

	/** @var PRAutoBlogger_Runware_Image_Support */
	private PRAutoBlogger_Runware_Image_Support $support;

	public function __construct(
		PRAutoBlogger_Runware_Image_Pricing $pricing,
		PRAutoBlogger_Runware_Image_Support $support
	) {
		$this->pricing = $pricing;
		$this->support = $support;
	}

	/**
	 * Execute multiple image generation requests concurrently via curl_multi.
	 *
	 * Two parallel rounds: (1) POST /v1 returns signed imageURL per request;
	 * (2) GET each signed imageURL returns PNG bytes. Each round runs
	 * concurrently. No retry — single-attempt success rate on schnell >99%.
	 *
	 * @param array<string, array{prompt: string, width: int, height: int, options?: array}> $requests Keyed requests.
	 * @return array<string, array> Image data or `{error}` keyed identically.
	 */
	public function execute( array $requests ): array {
		if ( empty( $requests ) ) {
			return array();
		}

		$api_key     = $this->support->get_api_key();
		$round1      = $this->round_one_inference( $api_key, $requests );
		$results     = array();
		$to_download = array();

		foreach ( $round1 as $key => $entry ) {
			if ( isset( $entry['error'] ) ) {
				$results[ $key ] = array( 'error' => $entry['error'] );
				continue;
			}
			$to_download[ $key ] = $entry;
		}

		if ( empty( $to_download ) ) {
			return $results;
		}

		$bytes_map = $this->round_two_download( $to_download );
		foreach ( $to_download as $key => $entry ) {
			if ( isset( $bytes_map[ $key ]['error'] ) ) {
				$results[ $key ] = array( 'error' => $bytes_map[ $key ]['error'] );
				continue;
			}
			$results[ $key ] = array(
				'bytes'      => $bytes_map[ $key ]['bytes'],
				'mime_type'  => 'image/png',
				'width'      => $entry['width'],
				'height'     => $entry['height'],
				'model'      => $entry['model'],
				'seed'       => $entry['seed'],
				'cost_usd'   => $this->pricing->estimate_cost( $entry['width'], $entry['height'], $entry['model'] ),
				'latency_ms' => (int) ( ( microtime( true ) - $entry['started_at'] ) * 1000 ),
			);
		}

		return $results;
	}

	/**
	 * Round 1: fire all inference POSTs in parallel.
	 *
	 * @param string $api_key  Plaintext API key.
	 * @param array  $requests Batch requests.
	 * @return array<string, array> Map of key => {error} | {image_url, width, height, model, seed, started_at}.
	 */
	private function round_one_inference( string $api_key, array $requests ): array {
		$headers     = array( 'Content-Type: application/json' );
		$mh          = curl_multi_init();
		$handles     = array();
		$start_times = array();

		foreach ( $requests as $key => $req ) {
			$model  = $this->pricing->resolve_model( (string) ( $req['options']['model'] ?? '' ) );
			$snap_w = $this->support->snap_dimension( (int) $req['width'] );
			$snap_h = $this->support->snap_dimension( (int) $req['height'] );
			$seed   = $this->support->normalize_seed( isset( $req['options']['seed'] ) ? (int) $req['options']['seed'] : null );
			$steps  = isset( $req['options']['steps'] ) ? (int) $req['options']['steps'] : $this->pricing->default_steps( $model );

			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, PRAutoBlogger_Runware_Image_Support::API_URL );
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt(
				$ch,
				CURLOPT_POSTFIELDS,
				wp_json_encode(
					$this->build_payload( $api_key, (string) $req['prompt'], $snap_w, $snap_h, $model, $seed, $steps )
				)
			);
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_TIMEOUT, PRAUTOBLOGGER_API_TIMEOUT_SECONDS );
			curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );

			curl_multi_add_handle( $mh, $ch );
			$handles[ $key ]     = array(
				'handle' => $ch,
				'model'  => $model,
				'width'  => $snap_w,
				'height' => $snap_h,
				'seed'   => $seed,
			);
			$start_times[ $key ] = microtime( true );
		}

		$this->run_multi( $mh );

		$out = array();
		foreach ( $handles as $key => $entry ) {
			$out[ $key ] = $this->collect_inference_result( (string) $key, $entry, $start_times[ $key ] );
			curl_multi_remove_handle( $mh, $entry['handle'] );
			curl_close( $entry['handle'] );
		}
		curl_multi_close( $mh );

		return $out;
	}

	/**
	 * Round 2: fetch all signed image URLs in parallel.
	 *
	 * @param array<string, array> $inferences Map from round one.
	 * @return array<string, array{bytes?: string, error?: string}>
	 */
	private function round_two_download( array $inferences ): array {
		$mh      = curl_multi_init();
		$handles = array();

		foreach ( $inferences as $key => $entry ) {
			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, $entry['image_url'] );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
			curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
			curl_multi_add_handle( $mh, $ch );
			$handles[ $key ] = $ch;
		}

		$this->run_multi( $mh );

		$out = array();
		foreach ( $handles as $key => $ch ) {
			$errno = curl_errno( $ch );
			if ( 0 !== $errno ) {
				$msg = sprintf( 'Runware image download cURL error %d for "%s": %s', $errno, $key, curl_error( $ch ) );
				PRAutoBlogger_Logger::instance()->error( $msg, 'runware-image-batch' );
				$out[ $key ] = array( 'error' => $msg );
			} else {
				$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
				$body = (string) curl_multi_getcontent( $ch );
				if ( 200 !== $code || '' === $body ) {
					$msg = sprintf( 'Runware image download HTTP %d for "%s" (%d bytes)', $code, $key, strlen( $body ) );
					PRAutoBlogger_Logger::instance()->error( $msg, 'runware-image-batch' );
					$out[ $key ] = array( 'error' => $msg );
				} else {
					$out[ $key ] = array( 'bytes' => $body );
				}
			}
			curl_multi_remove_handle( $mh, $ch );
			curl_close( $ch );
		}
		curl_multi_close( $mh );

		return $out;
	}

	/**
	 * Drive curl_multi_exec to completion.
	 *
	 * @param \CurlMultiHandle $mh Multi handle.
	 */
	private function run_multi( $mh ): void {
		$running = 0;
		do {
			$status = curl_multi_exec( $mh, $running );
			if ( $running > 0 ) {
				curl_multi_select( $mh, 1.0 );
			}
		} while ( $running > 0 && CURLM_OK === $status );
	}

	/**
	 * Build the auth+inference task-array body for one image request.
	 *
	 * @param string $api_key Plaintext API key.
	 * @param string $prompt  Prompt text.
	 * @param int    $width   Snapped width.
	 * @param int    $height  Snapped height.
	 * @param string $model   Resolved model id.
	 * @param int    $seed    Positive seed.
	 * @param int    $steps   Inference steps.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_payload( string $api_key, string $prompt, int $width, int $height, string $model, int $seed, int $steps ): array {
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
	 * Parse one round-1 handle into either an error or an imageURL entry.
	 *
	 * @param string $key   Request key.
	 * @param array  $entry Handle entry (handle + model + dims + seed).
	 * @param float  $start Microtime when this handle was queued.
	 * @return array Either {error} or {image_url, width, height, model, seed, started_at}.
	 */
	private function collect_inference_result( string $key, array $entry, float $start ): array {
		$ch    = $entry['handle'];
		$errno = curl_errno( $ch );
		if ( 0 !== $errno ) {
			$msg = sprintf( 'Runware batch inference cURL error %d for "%s": %s', $errno, $key, curl_error( $ch ) );
			PRAutoBlogger_Logger::instance()->error( $msg, 'runware-image-batch' );
			return array( 'error' => $msg );
		}

		$http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$raw       = (string) curl_multi_getcontent( $ch );
		if ( $http_code >= 400 ) {
			$msg = sprintf( 'Runware batch inference HTTP %d for "%s": %s', $http_code, $key, substr( $raw, 0, 300 ) );
			PRAutoBlogger_Logger::instance()->error( $msg, 'runware-image-batch' );
			return array( 'error' => $msg );
		}

		try {
			$image_url = $this->support->extract_image_url( $raw );
		} catch ( \Throwable $e ) {
			$msg = sprintf( 'Runware batch inference parse error for "%s": %s', $key, $e->getMessage() );
			PRAutoBlogger_Logger::instance()->error( $msg, 'runware-image-batch' );
			return array( 'error' => $msg );
		}

		return array(
			'image_url'  => $image_url,
			'width'      => (int) $entry['width'],
			'height'     => (int) $entry['height'],
			'model'      => (string) $entry['model'],
			'seed'       => (int) $entry['seed'],
			'started_at' => $start,
		);
	}
}
