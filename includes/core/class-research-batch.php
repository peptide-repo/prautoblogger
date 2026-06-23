<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Low-level curl_multi executor for the Authority research fan-out.
 *
 * What: Wraps PHP's curl_multi interface to dispatch N parallel LLM
 *       chat-completion requests to OpenRouter and parse each handle's
 *       response into a normalised result array. Mirrors the pattern of
 *       PRAutoBlogger_OpenRouter_Image_Batch (image parallel dispatch).
 *       All failures emit Logger::warning and fold the slot into an
 *       `{error: string}` entry — the caller (Research_Fanout) applies
 *       quorum logic. No retry: the 300s timeout + single-attempt
 *       success rate on OpenRouter is sufficient at this fan-out size.
 * Who triggers it: PRAutoBlogger_Research_Fanout::dispatch() only.
 *       Not wired into the Economy path.
 * Dependencies: PRAutoBlogger_OpenRouter_Config, Request_Builder,
 *       PRAutoBlogger_OpenRouter_Pricing, Logger.
 *
 * @see core/class-research-fanout.php                  — Sole caller.
 * @see providers/class-open-router-image-batch.php     — curl_multi pattern.
 * @see ARCHITECTURE.md                                 — Phase 2b data flow.
 */
class PRAutoBlogger_Research_Batch {

	/**
	 * Execute multiple chat-completion requests concurrently via curl_multi.
	 *
	 * @param string                                                        $model              OpenRouter model slug.
	 * @param array<int, array<int, array{role: string, content: string}>> $messages_per_agent One message-set per agent slot.
	 * @param array<string, mixed>                                          $options            Shared call options (temperature, max_tokens, response_format).
	 * @param string[]                                                      $roles              Role label per slot — for log attribution only.
	 * @return array<int, array{content?: string, model?: string, prompt_tokens?: int, completion_tokens?: int, actual_cost?: float, error?: string}>
	 *         Indexed 0..N-1; error key present on failure.
	 *
	 * Side effects: outbound HTTP via curl_multi, Logger::warning on failures.
	 */
	public function execute(
		string $model,
		array $messages_per_agent,
		array $options,
		array $roles
	): array {
		if ( empty( $messages_per_agent ) ) {
			return array();
		}

		$config  = new PRAutoBlogger_OpenRouter_Config();
		$builder = new PRAutoBlogger_OpenRouter_Request_Builder();
		$api_key = $builder->resolve_api_key();
		$url     = $config->get_api_base_url() . '/chat/completions';
		$headers = $builder->build_headers( $api_key, $config->is_via_gateway(), 0 );

		$curl_headers = array();
		foreach ( $headers as $name => $value ) {
			$curl_headers[] = $name . ': ' . $value;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_init
		$mh      = curl_multi_init();
		$handles = array();

		foreach ( $messages_per_agent as $idx => $msgs ) {
			$body = array(
				'model'           => $model,
				'messages'        => $msgs,
				'temperature'     => $options['temperature'] ?? 0.3,
				'max_tokens'      => $options['max_tokens'] ?? 3000,
				'response_format' => $options['response_format'] ?? array( 'type' => 'json_object' ),
			);

			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
			$ch = curl_init();
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt( $ch, CURLOPT_URL, $url );
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, wp_json_encode( $body ) );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $curl_headers );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_TIMEOUT, PRAUTOBLOGGER_API_TIMEOUT_SECONDS );
			curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
			// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_setopt

			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_add_handle
			curl_multi_add_handle( $mh, $ch );
			$handles[ $idx ] = array(
				'handle' => $ch,
				'role'   => $roles[ $idx ] ?? (string) $idx,
			);
		}

		$this->run_multi( $mh );

		$results = array();
		foreach ( $handles as $idx => $entry ) {
			$results[ $idx ] = $this->parse_handle(
				$entry['handle'],
				$model,
				$entry['role']
			);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle
			curl_multi_remove_handle( $mh, $entry['handle'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
			curl_close( $entry['handle'] );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_close
		curl_multi_close( $mh );

		return $results;
	}

	// ── Private helpers ─────────────────────────────────────────────────

	/**
	 * Drive curl_multi_exec to completion.
	 *
	 * @param \CurlMultiHandle $mh Multi handle.
	 * @return void
	 */
	private function run_multi( $mh ): void {
		$running = 0;
		do {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_exec
			$status = curl_multi_exec( $mh, $running );
			if ( $running > 0 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_select
				curl_multi_select( $mh, 1.0 );
			}
		} while ( $running > 0 && CURLM_OK === $status );
	}

	/**
	 * Parse one completed curl handle into a normalised result.
	 *
	 * @param \CurlHandle $ch    Completed handle.
	 * @param string      $model Model slug (for cost estimation fallback).
	 * @param string      $role  Agent role label (for log attribution).
	 * @return array{content?: string, model?: string, prompt_tokens?: int, completion_tokens?: int, actual_cost?: float, error?: string}
	 *
	 * Side effects: Logger::warning on failure.
	 */
	private function parse_handle( $ch, string $model, string $role ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno
		if ( 0 !== curl_errno( $ch ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error
			$err = curl_error( $ch );
			PRAutoBlogger_Logger::instance()->warning(
				sprintf( 'Research batch agent "%s" cURL error: %s', $role, $err ),
				'research-batch'
			);
			return array( 'error' => $err );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		$http = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_getcontent
		$raw  = (string) curl_multi_getcontent( $ch );

		if ( $http >= 400 ) {
			$msg = sprintf(
				'Research batch agent "%s" HTTP %d: %s',
				$role,
				$http,
				substr( $raw, 0, 200 )
			);
			PRAutoBlogger_Logger::instance()->warning( $msg, 'research-batch' );
			return array( 'error' => $msg );
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['choices'][0]['message']['content'] ) ) {
			$msg = sprintf( 'Research batch agent "%s" unexpected response shape.', $role );
			PRAutoBlogger_Logger::instance()->warning( $msg, 'research-batch' );
			return array( 'error' => $msg );
		}

		$content           = (string) $decoded['choices'][0]['message']['content'];
		$usage             = $decoded['usage'] ?? array();
		$prompt_tokens     = (int) ( $usage['prompt_tokens'] ?? 0 );
		$completion_tokens = (int) ( $usage['completion_tokens'] ?? 0 );
		$actual_model      = (string) ( $decoded['model'] ?? $model );

		$actual_cost = ( new PRAutoBlogger_OpenRouter_Pricing() )->estimate_cost(
			$actual_model,
			$prompt_tokens,
			$completion_tokens
		);

		return array(
			'content'           => $content,
			'model'             => $actual_model,
			'prompt_tokens'     => $prompt_tokens,
			'completion_tokens' => $completion_tokens,
			'actual_cost'       => $actual_cost,
		);
	}
}
