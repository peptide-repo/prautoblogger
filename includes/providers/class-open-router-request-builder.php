<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Request builder for OpenRouter API calls.
 *
 * Encapsulates the logic for building HTTP request headers, including
 * Cloudflare AI Gateway caching directives and belt-and-suspenders
 * cURL header injection to work around authorization header stripping
 * in certain hosting environments.
 *
 * Triggered by: PRAutoBlogger_OpenRouter_Provider::send_chat_completion()
 * Dependencies: add_action(), curl_setopt() (via http_api_curl filter).
 *
 * @see class-open-router-provider.php — Parent class that uses this builder.
 */
class PRAutoBlogger_OpenRouter_Request_Builder {

	/**
	 * Build the JSON request body for a chat-completion call.
	 *
	 * Centralizes option handling (v0.18.1, moved from the provider for
	 * the 300-line cap): temperature, max_tokens, response_format, and
	 * the reasoning block — an explicit caller override takes priority
	 * over the global setting (that per-call override is how the
	 * empty-completion retry disables reasoning for its second attempt).
	 *
	 * Caller-metadata keys ('stage', 'prompt_key', 'empty_retry') are
	 * deliberately NOT copied into the HTTP body.
	 *
	 * @param array<int, array{role: string, content: string}> $messages Chat messages.
	 * @param string                                           $model    Model identifier.
	 * @param array<string, mixed>                             $options  Call options.
	 *
	 * @return array<string, mixed> Request body ready for wp_json_encode().
	 */
	public function build_body( array $messages, string $model, array $options ): array {
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

		return $this->apply_reasoning_budget( $body );
	}

	/**
	 * Reasoning budget sanity (v0.18.1): cap thinking, protect content.
	 *
	 * When the outgoing request enables reasoning and carries a completion
	 * ceiling, the thinking budget is capped via OpenRouter's
	 * `reasoning.max_tokens` and the completion ceiling is raised by the
	 * same amount — so no reasoning effort (incl. xhigh) can consume the
	 * entire completion budget and emit an empty message (the 2026-06-11
	 * empty-draft incident, prod run acf24029). The cap is SETTINGS-backed
	 * (`prautoblogger_reasoning_max_tokens`, default
	 * PRAUTOBLOGGER_DEFAULT_REASONING_MAX_TOKENS, 0 = pure effort mode:
	 * no cap, no headroom — the historical behavior). When the cap is
	 * active it replaces `effort` — OpenRouter treats the two as
	 * alternative budget controls and normalizes max_tokens per model.
	 *
	 * No-op when reasoning is absent/disabled on the request, when the
	 * request has no completion ceiling, or when the cap setting is 0.
	 *
	 * @param array<string, mixed> $body Assembled request body.
	 *
	 * @return array<string, mixed> Body with the reasoning budget applied.
	 */
	private function apply_reasoning_budget( array $body ): array {
		if ( ! isset( $body['reasoning'] ) || false === ( $body['reasoning']['enabled'] ?? true ) ) {
			return $body;
		}
		if ( ! isset( $body['max_tokens'] ) ) {
			return $body; // No completion ceiling to protect.
		}

		if ( ! isset( $body['reasoning']['max_tokens'] ) ) {
			$cap = absint( get_option( 'prautoblogger_reasoning_max_tokens', PRAUTOBLOGGER_DEFAULT_REASONING_MAX_TOKENS ) );
			if ( $cap < 1 ) {
				return $body; // Cap disabled — pure effort mode.
			}
			$body['reasoning']['max_tokens'] = $cap;
			unset( $body['reasoning']['effort'] );
		}

		// Headroom: models that spend reasoning from the completion budget
		// (e.g. deepseek/deepseek-v4-flash) must still have the caller's
		// full max_tokens available for visible content.
		$body['max_tokens'] = (int) $body['max_tokens'] + (int) $body['reasoning']['max_tokens'];

		return $body;
	}

	/**
	 * Build request headers for OpenRouter API call.
	 *
	 * Includes Authorization, Content-Type, HTTP-Referer, and optional
	 * Cloudflare AI Gateway cache control headers.
	 *
	 * @param string $api_key     Decrypted OpenRouter API key.
	 * @param bool   $via_gateway Whether the request is routed through Cloudflare AI Gateway.
	 * @param int    $cache_ttl   Cache TTL in seconds (0 disables caching).
	 *
	 * @return array<string, string> HTTP headers ready for wp_remote_post().
	 */
	public function build_headers( string $api_key, bool $via_gateway, int $cache_ttl ): array {
		$headers = array(
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
			'HTTP-Referer'  => home_url(),
			'X-Title'       => 'PRAutoBlogger WordPress Plugin',
		);

		if ( $via_gateway && $cache_ttl > 0 ) {
			$headers['cf-aig-cache-ttl'] = (string) $cache_ttl;
		}

		return $headers;
	}

	/**
	 * Register a cURL filter to inject Authorization header.
	 *
	 * Some hosting environments (Hostinger, certain proxies) strip the
	 * Authorization header from wp_remote_post's 'headers' array before
	 * the request is sent. The http_api_curl action fires after WordPress
	 * configures the cURL handle but before curl_exec — setting
	 * CURLOPT_HTTPHEADER here ensures the header reaches the upstream.
	 *
	 * Side effects: Adds an http_api_curl filter (caller must remove).
	 *
	 * @param array  $request_headers Request headers (includes Authorization).
	 * @param string $base_host       Upstream host (scopes the filter to avoid leaking auth).
	 *
	 * @return callable The filter function (for later removal via remove_action).
	 */
	public function register_curl_auth_filter( array $request_headers, string $base_host ): callable {
		$curl_auth_filter = function ( $handle, $parsed_args, $url ) use ( $request_headers, $base_host ): void {
			// Scope to the configured upstream host only — never leak auth
			// into unrelated outbound requests made elsewhere in WordPress.
			if ( '' === $base_host || false === strpos( (string) $url, $base_host ) ) {
				return;
			}
			$curl_headers = array();
			foreach ( $request_headers as $name => $value ) {
				$curl_headers[] = $name . ': ' . $value;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt( $handle, CURLOPT_HTTPHEADER, $curl_headers );
		};
		add_action( 'http_api_curl', $curl_auth_filter, 99, 3 );
		return $curl_auth_filter;
	}

	/**
	 * Fetch, decrypt, and format-validate the OpenRouter API key.
	 *
	 * Moved from OpenRouter_Provider in v0.18.0 (300-line cap); the
	 * messages and behavior are unchanged.
	 *
	 * @return string Validated plaintext API key.
	 * @throws \RuntimeException When the key is missing or has an
	 *                           unexpected format (corrupted decryption).
	 */
	public function resolve_api_key(): string {
		$encrypted = get_option( 'prautoblogger_openrouter_api_key', '' );
		$api_key   = '' === $encrypted ? '' : PRAutoBlogger_Encryption::decrypt( $encrypted );

		if ( '' === $api_key ) {
			throw new \RuntimeException(
				__( 'OpenRouter API key is not configured. Go to PRAutoBlogger → Settings.', 'prautoblogger' )
			);
		}

		// Validate key format — OpenRouter keys start with "sk-or-".
		// A key that decrypts to garbage (e.g. after a salt change) won't match.
		if ( 0 !== strpos( $api_key, 'sk-or-' ) ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf(
					'Decrypted API key has unexpected format (prefix="%s", len=%d). Re-enter your key in settings.',
					substr( $api_key, 0, 6 ),
					strlen( $api_key )
				),
				'openrouter'
			);
			throw new \RuntimeException(
				__( 'OpenRouter API key appears corrupted (unexpected format). Please re-enter your key in PRAutoBlogger → Settings.', 'prautoblogger' )
			);
		}

		return $api_key;
	}
}
