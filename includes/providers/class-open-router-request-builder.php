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
