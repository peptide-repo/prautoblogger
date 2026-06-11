<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * OpenRouter model pricing and availability lookup.
 *
 * Manages pricing tables, model lists, and cost estimation. Decoupled from
 * the request logic for easy updates and testing.
 *
 * Triggered by: PRAutoBlogger_OpenRouter_Provider calls pricing methods.
 * Dependencies: None — pure data + calculation, no external calls by default.
 *
 * @see providers/class-open-router-provider.php — Instantiates this class.
 * @see ARCHITECTURE.md                         — Model selection flow.
 */
class PRAutoBlogger_OpenRouter_Pricing {

	/**
	 * Known model pricing (per million tokens, in USD).
	 * Updated periodically. Falls back to API-reported pricing if available.
	 *
	 * @var array<string, array{prompt: float, completion: float}>
	 */
	private const MODEL_PRICING = array(
		'anthropic/claude-3.5-haiku' => array(
			'prompt'     => 0.80,
			'completion' => 4.00,
		),
		'anthropic/claude-sonnet-4'  => array(
			'prompt'     => 3.00,
			'completion' => 15.00,
		),
		'anthropic/claude-opus-4'    => array(
			'prompt'     => 15.00,
			'completion' => 75.00,
		),
		'openai/gpt-4o'              => array(
			'prompt'     => 2.50,
			'completion' => 10.00,
		),
		'openai/gpt-4o-mini'         => array(
			'prompt'     => 0.15,
			'completion' => 0.60,
		),
		'google/gemini-2.0-flash'    => array(
			'prompt'     => 0.10,
			'completion' => 0.40,
		),
		'meta-llama/llama-3.3-70b'   => array(
			'prompt'     => 0.40,
			'completion' => 0.40,
		),
	);

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
		$cached = get_transient( 'prautoblogger_openrouter_models' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			return array();
		}

		// Belt-and-suspenders: inject Authorization at cURL level (see OpenRouter_Provider).
		$auth_header_value    = 'Bearer ' . $api_key;
		$curl_auth_filter_mod = function ( $handle, $parsed_args, $url ) use ( $auth_header_value ): void {
			if ( false === strpos( $url, 'openrouter.ai' ) ) {
				return;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt(
				$handle,
				CURLOPT_HTTPHEADER,
				array(
					'Authorization: ' . $auth_header_value,
				)
			);
		};
		add_action( 'http_api_curl', $curl_auth_filter_mod, 99, 3 );

		$response = wp_remote_get(
			'https://openrouter.ai/api/v1/models',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
			)
		);

		remove_action( 'http_api_curl', $curl_auth_filter_mod, 99 );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			PRAutoBlogger_Logger::instance()->warning( 'Failed to fetch OpenRouter models list.', 'openrouter' );
			return array();
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = array();

		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			foreach ( $data['data'] as $model ) {
				$models[] = array(
					'id'             => $model['id'] ?? '',
					'name'           => $model['name'] ?? $model['id'] ?? '',
					'context_length' => (int) ( $model['context_length'] ?? 0 ),
					'pricing'        => array(
						'prompt'     => (float) ( $model['pricing']['prompt'] ?? 0 ) * 1000000,
						'completion' => (float) ( $model['pricing']['completion'] ?? 0 ) * 1000000,
					),
				);
			}
		}

		set_transient( 'prautoblogger_openrouter_models', $models, DAY_IN_SECONDS );
		return $models;
	}

	/**
	 * Estimate the cost of an API call in USD.
	 *
	 * Uses hardcoded pricing first, falls back to cached model data. IDs
	 * carrying a date-snapshot suffix that miss both sources are retried
	 * once as their base ID (see resolve_pricing()).
	 *
	 * @param string $model            Model identifier.
	 * @param int    $prompt_tokens     Input tokens.
	 * @param int    $completion_tokens Output tokens.
	 *
	 * @return float Estimated cost in USD.
	 */
	public function estimate_cost( string $model, int $prompt_tokens, int $completion_tokens ): float {
		// OpenRouter free models: the `openrouter/free` router and any `:free` suffix model.
		if ( 'openrouter/free' === $model || ':free' === substr( $model, -5 ) ) {
			return 0.0;
		}

		$pricing = $this->resolve_pricing( $model );

		if ( null === $pricing ) {
			// Unknown model — use a conservative estimate.
			PRAutoBlogger_Logger::instance()->warning( "Unknown model pricing for '{$model}'. Using conservative estimate.", 'openrouter' );
			$pricing = array(
				'prompt'     => 10.0,
				'completion' => 30.0,
			);
		}

		return ( $prompt_tokens * $pricing['prompt'] / 1000000 )
			+ ( $completion_tokens * $pricing['completion'] / 1000000 );
	}

	/**
	 * Resolve pricing for a model ID, normalizing dated snapshot IDs.
	 *
	 * Tries the exact ID first. On a miss, strips a trailing date-snapshot
	 * suffix and retries once with the base ID: OpenRouter responses can
	 * name a dated snapshot of the requested model (e.g. request
	 * 'deepseek/deepseek-v4-flash', response model
	 * 'deepseek/deepseek-v4-flash-20260423') while pricing sources only
	 * list the base ID. Every cost path (governor reserve/settle and
	 * generation-log booking) prices through this seam, so all of them
	 * survive snapshot IDs instead of booking the conservative estimate.
	 *
	 * Side effects: may fetch the models list over HTTP on cache miss
	 * (via get_available_models()).
	 *
	 * @param string $model Model ID as requested, or as returned by the API.
	 *
	 * @return array{prompt: float, completion: float}|null Per-million USD pricing, or null when unknown.
	 */
	private function resolve_pricing( string $model ): ?array {
		$pricing = $this->lookup_pricing( $model );
		if ( null !== $pricing ) {
			return $pricing;
		}

		$base = $this->strip_snapshot_suffix( $model );
		if ( $base !== $model ) {
			return $this->lookup_pricing( $base );
		}

		return null;
	}

	/**
	 * Look up one exact model ID: hardcoded table first, then cached model list.
	 *
	 * Side effects: may fetch the models list over HTTP on cache miss
	 * (via get_available_models()).
	 *
	 * @param string $model Model ID (exact match, no normalization).
	 *
	 * @return array{prompt: float, completion: float}|null Per-million USD pricing, or null on miss.
	 */
	private function lookup_pricing( string $model ): ?array {
		$pricing = self::MODEL_PRICING[ $model ] ?? null;
		if ( null !== $pricing ) {
			return $pricing;
		}

		foreach ( $this->get_available_models() as $m ) {
			if ( $m['id'] === $model && isset( $m['pricing'] ) ) {
				return $m['pricing'];
			}
		}

		return null;
	}

	/**
	 * Strip a trailing date-snapshot suffix (e.g. '-20260423') from a model ID.
	 *
	 * Only a dash followed by an 8-digit, 20-prefixed date at the very end
	 * is treated as a snapshot suffix. Any other trailing digit run (e.g.
	 * 'foo-202612345') is part of the model name and left untouched.
	 *
	 * Side effects: none — pure string transformation.
	 *
	 * @param string $model Model ID.
	 *
	 * @return string Base model ID, or the input unchanged when no snapshot suffix is present.
	 */
	private function strip_snapshot_suffix( string $model ): string {
		$base = preg_replace( '/-20\d{6}$/', '', $model );
		return is_string( $base ) ? $base : $model;
	}

	/**
	 * Get the decrypted API key from options.
	 *
	 * @return string Decrypted API key, or empty string if not set.
	 */
	private function get_api_key(): string {
		$encrypted = get_option( 'prautoblogger_openrouter_api_key', '' );
		if ( '' === $encrypted ) {
			return '';
		}
		return PRAutoBlogger_Encryption::decrypt( $encrypted );
	}
}
