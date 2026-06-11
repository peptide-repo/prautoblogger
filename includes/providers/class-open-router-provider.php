<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * OpenRouter API integration for all LLM calls (analysis, writing, editing).
 *
 * OpenRouter provides a unified API to access models from Anthropic, OpenAI,
 * Google, Meta, and others. This lets users pick any model without us maintaining
 * separate provider integrations.
 *
 * Triggered by: Content_Analyzer, Content_Generator, Chief_Editor, Metrics_Collector.
 * Dependencies: PRAutoBlogger_Encryption (for API key decryption), wp_remote_post(),
 *               PRAutoBlogger_OpenRouter_Pricing, PRAutoBlogger_Cost_Governor (per-run
 *               reserve-before-call guard around every chat completion).
 *
 * @see interface-llm-provider.php            — Interface this class implements.
 * @see class-open-router-config.php          — Config helpers (base URL, cache TTL).
 * @see class-open-router-pricing.php         — Pricing and model list lookups.
 * @see class-open-router-validator.php       — Credential validation (delegated).
 * @see class-open-router-request-builder.php — Request header building + cURL filter.
 * @see class-open-router-response-parser.php — Response parsing + error classification.
 * @see class-cost-tracker.php                — Called after every request to log token usage.
 * @see ARCHITECTURE.md                       — Data flow diagram showing where this fits.
 */
class PRAutoBlogger_OpenRouter_Provider implements PRAutoBlogger_LLM_Provider_Interface {

	/**
	 * Send a chat completion request to OpenRouter.
	 *
	 * Retries with exponential backoff on transient failures (5xx, timeout).
	 * Logs every attempt. Fails loudly after exhausting retries.
	 *
	 * Side effects: HTTP request to OpenRouter API.
	 *
	 * @param array<int, array{role: string, content: string}> $messages Chat messages.
	 * @param string $model   Model identifier (e.g., 'anthropic/claude-sonnet-4').
	 * @param array{
	 *     temperature?: float,
	 *     max_tokens?: int,
	 *     response_format?: array{type: string},
	 *     reasoning?: array{enabled: bool, effort?: string},
	 * } $options Optional parameters. Pass 'reasoning' to override the global setting.
	 *
	 * @return array{
	 *     content: string,
	 *     model: string,
	 *     prompt_tokens: int,
	 *     completion_tokens: int,
	 *     total_tokens: int,
	 *     finish_reason: string,
	 * }
	 *
	 * @throws \RuntimeException On API error after retries exhausted.
	 * @throws PRAutoBlogger_Cost_Ceiling_Exception When the per-run cost governor blocks
	 *                                              the call (run already halted).
	 */
	public function send_chat_completion( array $messages, string $model, array $options = array() ): array {
		// Fetch + format-validate the key (moved to Request_Builder in
		// v0.18.0 for the 300-line cap; behavior unchanged — throws the
		// same messages on missing/corrupted keys).
		$api_key = ( new PRAutoBlogger_OpenRouter_Request_Builder() )->resolve_api_key();

		$body = array(
			'model'    => $model,
			'messages' => $messages,
		);

		if ( isset( $options['temperature'] ) ) {
			$body['temperature'] = $options['temperature'];
		}
		if ( isset( $options['max_tokens'] ) ) {
			$body['max_tokens'] = $options['max_tokens'];
		}
		if ( isset( $options['response_format'] ) ) {
			$body['response_format'] = $options['response_format'];
		}

		// Reasoning mode: explicit caller override takes priority, then global setting.
		if ( isset( $options['reasoning'] ) ) {
			$body['reasoning'] = $options['reasoning'];
		} elseif ( '1' === get_option( 'prautoblogger_reasoning_enabled', '0' ) ) {
			$body['reasoning'] = array(
				'enabled' => true,
				'effort'  => get_option( 'prautoblogger_reasoning_effort', 'medium' ),
			);
		}

		// v0.18.0 — per-run cost governor: reserve the worst-case estimate
		// BEFORE any dispatch. Throws (and the run is already halted) when
		// the reservation would breach the run ceiling; returns null when
		// this process is not inside a governed run (historical behavior).
		$reservation = PRAutoBlogger_Cost_Governor::open_chat_reservation( $model, $messages, $options );

		$last_error = '';

		$config      = new PRAutoBlogger_OpenRouter_Config();
		$base_url    = $config->get_api_base_url();
		$base_host   = (string) wp_parse_url( $base_url, PHP_URL_HOST );
		$cache_ttl   = $config->get_cache_ttl_seconds();
		$via_gateway = $config->is_via_gateway();

		$builder          = new PRAutoBlogger_OpenRouter_Request_Builder();
		$request_headers  = $builder->build_headers( $api_key, $via_gateway, $cache_ttl );
		$curl_auth_filter = $builder->register_curl_auth_filter( $request_headers, $base_host );

		try {
			$parser = new PRAutoBlogger_OpenRouter_Response_Parser();

			for ( $attempt = 1; $attempt <= PRAUTOBLOGGER_MAX_RETRIES; $attempt++ ) {
				$response = wp_remote_post(
					$base_url . '/chat/completions',
					array(
						'timeout' => PRAUTOBLOGGER_API_TIMEOUT_SECONDS,
						'headers' => $request_headers,
						'body'    => wp_json_encode( $body ),
					)
				);

				if ( is_wp_error( $response ) ) {
					$last_error = $response->get_error_message();
					PRAutoBlogger_Logger::instance()->warning(
						sprintf( 'OpenRouter request failed (attempt %d/%d): %s', $attempt, PRAUTOBLOGGER_MAX_RETRIES, $last_error ),
						'openrouter'
					);

					if ( $attempt < PRAUTOBLOGGER_MAX_RETRIES ) {
						$delay = PRAUTOBLOGGER_RETRY_BASE_DELAY_SECONDS * pow( 2, $attempt - 1 );
						sleep( (int) $delay );
					}
					continue;
				}

				$status_code = wp_remote_retrieve_response_code( $response );
				$body_raw    = wp_remote_retrieve_body( $response );
				$data        = json_decode( $body_raw, true );

				// Rate limited (429) or server error (5xx) — retry with backoff.
				if ( $parser->is_retryable( $status_code ) ) {
					$last_error = sprintf( 'HTTP %d: %s', $status_code, $body_raw );
					PRAutoBlogger_Logger::instance()->warning(
						sprintf( 'OpenRouter HTTP %d (attempt %d/%d): %s', $status_code, $attempt, PRAUTOBLOGGER_MAX_RETRIES, substr( $body_raw, 0, 500 ) ),
						'openrouter'
					);

					if ( $attempt < PRAUTOBLOGGER_MAX_RETRIES ) {
						// Respect Retry-After header if present.
						$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
						$delay       = $retry_after
							? min( (int) $retry_after, 60 )
							: PRAUTOBLOGGER_RETRY_BASE_DELAY_SECONDS * pow( 2, $attempt - 1 );
						sleep( (int) $delay );
					}
					continue;
				}

				// Client error — don't retry, fail immediately.
				if ( $status_code >= 400 ) {
					$error_msg = $parser->get_error_message( $data, $status_code );

					// Log detailed diagnostic info for auth errors to aid debugging.
					if ( 401 === $status_code || 403 === $status_code ) {
						PRAutoBlogger_Logger::instance()->error(
							sprintf(
								'Auth failure HTTP %d: key_prefix=%s, key_len=%d, error=%s',
								$status_code,
								substr( $api_key, 0, 8 ),
								strlen( $api_key ),
								$error_msg
							),
							'openrouter'
						);
					}

					throw new \RuntimeException(
						sprintf(
							/* translators: %d: HTTP status code, %s: error message */
							__( 'OpenRouter API error (HTTP %1$d): %2$s', 'prautoblogger' ),
							$status_code,
							$error_msg
						)
					);
				}

				// Success — parse response, settle the reservation to actuals.
				$parsed = $parser->parse_success( $data, $model );
				PRAutoBlogger_Cost_Governor::settle(
					$reservation,
					$this->estimate_cost( $model, (int) $parsed['prompt_tokens'], (int) $parsed['completion_tokens'] )
				);
				$reservation = null;
				return $parsed;
			}

			// All retries exhausted.
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: max retries, %s: last error message */
					__( 'OpenRouter API failed after %1$d attempts. Last error: %2$s', 'prautoblogger' ),
					PRAUTOBLOGGER_MAX_RETRIES,
					$last_error
				)
			);
		} finally {
			// Always clean up the cURL filter to avoid leaking into other requests.
			remove_action( 'http_api_curl', $curl_auth_filter, 99 );
			// Release any reservation still open (failure/exception path) so
			// a failed call does not permanently hold its worst-case estimate.
			PRAutoBlogger_Cost_Governor::release( $reservation );
		}
	}

	/**
	 * Get available models from OpenRouter's /models endpoint.
	 *
	 * Caches the result in a transient for 24 hours to avoid repeated API calls.
	 *
	 * Side effects: HTTP request (if cache miss), sets transient.
	 *
	 * @return array<int, array{id: string, name: string, context_length: int, pricing: array{prompt: float, completion: float}}>
	 */
	public function get_available_models(): array {
		$pricing = new PRAutoBlogger_OpenRouter_Pricing();
		return $pricing->get_available_models();
	}

	/**
	 * Estimate the cost of an API call in USD.
	 *
	 * Uses hardcoded pricing first, falls back to cached model data.
	 *
	 * @param string $model            Model identifier.
	 * @param int    $prompt_tokens     Input tokens.
	 * @param int    $completion_tokens Output tokens.
	 *
	 * @return float Estimated cost in USD.
	 */
	public function estimate_cost( string $model, int $prompt_tokens, int $completion_tokens ): float {
		$pricing = new PRAutoBlogger_OpenRouter_Pricing();
		return $pricing->estimate_cost( $model, $prompt_tokens, $completion_tokens );
	}

	/**
	 * @return string
	 */
	public function get_provider_name(): string {
		return 'OpenRouter';
	}

	/**
	 * Validate that the OpenRouter API key is configured and working.
	 *
	 * Delegates to PRAutoBlogger_OpenRouter_Validator for the actual checks.
	 *
	 * Side effects: HTTP request to OpenRouter (via validator), logs diagnostic info.
	 *
	 * @return bool
	 */
	public function validate_credentials(): bool {
		$diag = $this->validate_credentials_detailed();
		return 'ok' === $diag['status'];
	}

	/**
	 * Validate credentials with detailed diagnostic info.
	 *
	 * Delegates to PRAutoBlogger_OpenRouter_Validator which returns an array
	 * with status ('ok' or 'error') and a human-readable message explaining
	 * what went wrong if validation fails.
	 *
	 * Side effects: HTTP request to OpenRouter (via validator), logs diagnostic info.
	 *
	 * @return array{status: string, message: string, debug?: string}
	 */
	public function validate_credentials_detailed(): array {
		return ( new PRAutoBlogger_OpenRouter_Validator() )->run();
	}
}
