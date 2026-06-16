<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Parallel image generation via curl_multi for OpenRouter.
 *
 * Fires multiple chat/completions requests concurrently using PHP's
 * curl_multi interface, cutting wall-clock time from N×20s to ~20s
 * regardless of how many images are requested. This solves the Image B
 * timeout problem on Hostinger where max_execution_time kills the
 * cron process before a sequential second request can finish.
 *
 * Triggered by: PRAutoBlogger_OpenRouter_Image_Provider::generate_image_batch().
 * Dependencies: PRAutoBlogger_OpenRouter_Image_Support (key, parsing),
 *               PRAutoBlogger_OpenRouter_Image_Pricing (model, cost),
 *               PRAutoBlogger_OpenRouter_Config (base URL, gateway),
 *               PRAutoBlogger_OpenRouter_Request_Builder (headers).
 *
 * @see class-open-router-image-provider.php — Sole caller.
 * @see class-open-router-image-support.php  — Response parsing.
 * @see ARCHITECTURE.md                      — Image pipeline data flow.
 */
class PRAutoBlogger_OpenRouter_Image_Batch {

	/** @var PRAutoBlogger_OpenRouter_Image_Pricing */
	private PRAutoBlogger_OpenRouter_Image_Pricing $pricing;

	/** @var PRAutoBlogger_OpenRouter_Image_Support */
	private PRAutoBlogger_OpenRouter_Image_Support $support;

	/** @var PRAutoBlogger_OpenRouter_Config */
	private PRAutoBlogger_OpenRouter_Config $config;

	/** @var PRAutoBlogger_OpenRouter_Request_Builder */
	private PRAutoBlogger_OpenRouter_Request_Builder $request_builder;

	/**
	 * @param PRAutoBlogger_OpenRouter_Image_Pricing   $pricing         Cost + model resolver.
	 * @param PRAutoBlogger_OpenRouter_Image_Support    $support         Key + response parsing.
	 * @param PRAutoBlogger_OpenRouter_Config           $config          API URL resolution.
	 * @param PRAutoBlogger_OpenRouter_Request_Builder  $request_builder Header builder.
	 */
	public function __construct(
		PRAutoBlogger_OpenRouter_Image_Pricing $pricing,
		PRAutoBlogger_OpenRouter_Image_Support $support,
		PRAutoBlogger_OpenRouter_Config $config,
		PRAutoBlogger_OpenRouter_Request_Builder $request_builder
	) {
		$this->pricing         = $pricing;
		$this->support         = $support;
		$this->config          = $config;
		$this->request_builder = $request_builder;
	}

	/**
	 * Execute multiple image generation requests concurrently via curl_multi.
	 *
	 * Each request runs in its own cURL handle. All handles execute in
	 * parallel — the total wall-clock time equals the slowest single
	 * request, not the sum of all requests.
	 *
	 * No retry logic here — each request gets one attempt. Retries would
	 * complicate the multi-handle lifecycle and the single-attempt success
	 * rate on OpenRouter is >98%. If a request fails, its key maps to an
	 * `{error: string}` array.
	 *
	 * @param array<string, array{prompt: string, width: int, height: int, options?: array}> $requests Keyed requests.
	 *
	 * @return array<string, array{
	 *     bytes: string, mime_type: string, width: int, height: int,
	 *     model: string, seed: ?int, cost_usd: float, latency_ms: int,
	 * }|array{error: string}> Results indexed by the same keys as $requests.
	 */
	public function execute( array $requests ): array {
		if ( empty( $requests ) ) {
			return array();
		}

		$api_key  = $this->support->get_api_key();
		$base_url = $this->config->get_api_base_url();
		$url      = $base_url . '/chat/completions';
		$headers  = $this->request_builder->build_headers(
			$api_key,
			$this->config->is_via_gateway(),
			0
		);

		// Convert associative headers to curl-style indexed array.
		$curl_headers = array();
		foreach ( $headers as $name => $value ) {
			$curl_headers[] = $name . ': ' . $value;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_init
		$multi_handle = curl_multi_init();
		$handles      = array();
		$start_times  = array();

		foreach ( $requests as $key => $req ) {
			$model   = $this->pricing->resolve_model( (string) ( $req['options']['model'] ?? '' ) );
			$payload = $this->build_request_body(
				$req['prompt'],
				$req['width'],
				$req['height'],
				$model
			);

			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
			$ch = curl_init();
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt( $ch, CURLOPT_URL, $url );
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, wp_json_encode( $payload ) );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $curl_headers );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_TIMEOUT, PRAUTOBLOGGER_API_TIMEOUT_SECONDS );
			curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
			// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_setopt

			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_add_handle
			curl_multi_add_handle( $multi_handle, $ch );
			$handles[ $key ]     = array(
				'handle' => $ch,
				'model'  => $model,
				'req'    => $req,
			);
			$start_times[ $key ] = microtime( true );
		}

		// Execute all handles concurrently.
		$this->run_multi( $multi_handle );

		// Collect results.
		$results = array();
		foreach ( $handles as $key => $entry ) {
			$results[ $key ] = $this->collect_result(
				(string) $key,
				$entry['handle'],
				$entry['model'],
				$entry['req'],
				$start_times[ $key ]
			);

			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle
			curl_multi_remove_handle( $multi_handle, $entry['handle'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
			curl_close( $entry['handle'] );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_close
		curl_multi_close( $multi_handle );

		return $results;
	}

	/**
	 * Drive curl_multi_exec to completion.
	 *
	 * @param \CurlMultiHandle $mh Multi handle.
	 */
	private function run_multi( $mh ): void {
		$running = 0;
		do {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_exec
			$status = curl_multi_exec( $mh, $running );
			if ( $running > 0 ) {
				// Block until activity on any handle (up to 1s).
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_select
				curl_multi_select( $mh, 1.0 );
			}
		} while ( $running > 0 && CURLM_OK === $status );
	}

	/**
	 * Parse the result of one cURL handle into image data or error.
	 *
	 * All failure paths emit `Logger::error` at the point they fold the
	 * request into an `['error' => ...]` array — tagged with the request
	 * `$key` so the pipeline can attribute which of the parallel images
	 * failed (image_a vs image_b) without cross-referencing timestamps.
	 *
	 * @param string      $key    Request key from the batch map (e.g. "image_a").
	 * @param \CurlHandle $ch     Individual cURL handle (completed).
	 * @param string      $model  Resolved model id.
	 * @param array       $req    Original request array.
	 * @param float       $start  Microtime when this handle was created.
	 *
	 * @return array Image data array or `{error: string}`.
	 *
	 * Side effects: writes `Logger::error` on any failure path.
	 */
	private function collect_result( string $key, $ch, string $model, array $req, float $start ): array {
		$latency_ms = (int) ( ( microtime( true ) - $start ) * 1000 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno
		$curl_errno = curl_errno( $ch );
		if ( 0 !== $curl_errno ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error
			$detail = curl_error( $ch );
			$msg    = sprintf(
				'OpenRouter batch image gen cURL error %d for key "%s": %s',
				$curl_errno,
				$key,
				$detail
			);
			PRAutoBlogger_Logger::instance()->error( $msg, 'openrouter-image-batch' );
			return array( 'error' => $msg );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		$http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_getcontent
		$raw = (string) curl_multi_getcontent( $ch );

		if ( $http_code >= 400 ) {
			$msg = sprintf(
				'OpenRouter batch image gen HTTP %d for key "%s": %s',
				$http_code,
				$key,
				substr( $raw, 0, 300 )
			);
			PRAutoBlogger_Logger::instance()->error( $msg, 'openrouter-image-batch' );
			return array( 'error' => $msg );
		}

		try {
			$image_bytes = $this->support->extract_image_bytes( $raw );
		} catch ( \Throwable $e ) {
			$msg = sprintf(
				'OpenRouter batch image gen parse error for key "%s": %s',
				$key,
				$e->getMessage()
			);
			PRAutoBlogger_Logger::instance()->error( $msg, 'openrouter-image-batch' );
			return array( 'error' => $msg );
		}

		return array(
			'bytes'      => $image_bytes,
			'mime_type'  => 'image/png',
			'width'      => $req['width'],
			'height'     => $req['height'],
			'model'      => $model,
			'seed'       => null,
			'cost_usd'   => $this->pricing->estimate_cost( $req['width'], $req['height'], $model ),
			'latency_ms' => $latency_ms,
		);
	}

	/**
	 * Build the chat/completions payload for one image request.
	 *
	 * Duplicated from the provider class to keep this file self-contained
	 * without adding a public method to the provider's API surface.
	 *
	 * @param string $prompt Prompt text.
	 * @param int    $width  Width in pixels.
	 * @param int    $height Height in pixels.
	 * @param string $model  Model id.
	 * @return array<string, mixed>
	 */
	private function build_request_body( string $prompt, int $width, int $height, string $model ): array {
		$aspect = PRAutoBlogger_Image_Aspect_Ratio::snap( $width, $height );

		return array(
			'model'        => $model,
			'messages'     => array(
				array(
					'role'    => 'user',
					'content' => 'Generate an image: ' . $prompt,
				),
			),
			'modalities'   => array( 'image', 'text' ),
			'image_config' => array( 'aspect_ratio' => $aspect ),
		);
	}
}
