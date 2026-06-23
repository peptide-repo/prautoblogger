<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Resolves the "assembled instructions" preview body for a prompt key.
 *
 * What: Two strategies, tried in order:
 *
 *   1. Last-run strategy: Queries generation_log for the most recent
 *      successful row whose stage maps to the given prompt_key via
 *      Stage_Display_Map. Decodes the stored request_json and extracts
 *      the user-role message content (the fully-rendered, token-filled
 *      instruction the LLM actually received). This is the preferred
 *      source because it is factually what ran.
 *
 *   2. Sample render: Falls back to Prompt_Registry::render() with
 *      placeholder token values taken from the registry def. Tokens are
 *      replaced with "[sample: <token_name>]" labels so the admin can
 *      see the template's token slots without live data. Labelled
 *      "sample — no run found" in the UI.
 *
 *      Neither path has a save route. All returned text is plain strings;
 *      escaping is the caller's responsibility (Pipeline_Preview_Handler
 *      calls esc_html before JSON-encoding).
 *
 * Stage-list-driven: Stage_Display_Map::all() iterates the full known
 * stage vocabulary; Phase 2b stages resolve automatically.
 *
 * Who calls it: PRAutoBlogger_Pipeline_Preview_Handler::handle().
 * Dependencies: WordPress $wpdb, PRAutoBlogger_Stage_Display_Map,
 *               PRAutoBlogger_Prompt_Registry.
 *
 * @see ajax/class-pipeline-preview-handler.php  -- Sole caller.
 * @see core/class-stage-display-map.php         -- prompt_key -> stage map.
 * @see core/class-prompt-registry.php           -- render() + default_body().
 * @see ARCHITECTURE.md                          -- generation_log schema.
 */
class PRAutoBlogger_Pipeline_Preview_Source {

	/**
	 * Try to extract the rendered prompt from the most recent successful
	 * generation_log row that used the given prompt key.
	 *
	 * Returns null when no matching row exists or when the request_json
	 * cannot be decoded into a usable messages array.
	 *
	 * @param string $prompt_key Registry key (e.g. 'analysis.system').
	 * @return array{rendered: string, run_date: string, run_post: string}|null
	 */
	public static function last_run( string $prompt_key ): ?array {
		global $wpdb;
		if ( null === $wpdb ) {
			return null;
		}

		$stage = self::stage_for_key( $prompt_key );
		if ( null === $stage ) {
			return null;
		}

		$table = $wpdb->prefix . 'prautoblogger_generation_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT request_json, created_at, post_id
				 FROM {$table}
				 WHERE stage = %s
				   AND response_status = 'success'
				   AND request_json IS NOT NULL
				 ORDER BY id DESC
				 LIMIT 1",
				$stage
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || empty( $row['request_json'] ) ) {
			return null;
		}

		$body = json_decode( (string) $row['request_json'], true );
		if ( ! is_array( $body ) || empty( $body['messages'] ) ) {
			return null;
		}

		$rendered = self::extract_rendered_from_messages( (array) $body['messages'], $prompt_key );
		if ( '' === $rendered ) {
			return null;
		}

		$run_post = '';
		if ( ! empty( $row['post_id'] ) ) {
			$post = get_post( (int) $row['post_id'] );
			if ( $post instanceof WP_Post ) {
				$run_post = $post->post_title;
			}
		}

		return array(
			'rendered' => $rendered,
			'run_date' => (string) $row['created_at'],
			'run_post' => $run_post,
		);
	}

	/**
	 * Produce a sample render of the active template with placeholder tokens.
	 *
	 * Token slots in the template ({{ token_name }}) are substituted with
	 * "[sample: token_name]" so the admin can see every injection point
	 * without needing live generation data.
	 *
	 * @param string $prompt_key Registry key.
	 * @return string Rendered string (may be empty when key is unknown).
	 */
	public static function sample_render( string $prompt_key ): string {
		$active = PRAutoBlogger_Prompt_Registry::get_active( $prompt_key );
		$body   = null !== $active
			? (string) $active['body']
			: (string) PRAutoBlogger_Prompt_Registry::default_body( $prompt_key );

		if ( '' === $body ) {
			return '';
		}

		// Find all {{ token_name }} placeholders and substitute each with a
		// clearly labelled placeholder so the admin sees the injection map.
		$tokens = array();
		preg_match_all( '/\{\{\s*(\w+)\s*\}\}/', $body, $matches );
		if ( ! empty( $matches[1] ) ) {
			foreach ( array_unique( $matches[1] ) as $token ) {
				// translators: %s = token name, e.g. 'niche_description'.
				$tokens[ $token ] = sprintf( '[%s]', $token );
			}
		}

		return PRAutoBlogger_Prompt_Registry::fill( $body, $tokens );
	}

	/**
	 * Map a prompt registry key to its primary stage via Stage_Display_Map.
	 *
	 * Iterates all() so new Phase 2b stages are picked up automatically.
	 *
	 * @param string $prompt_key Registry key.
	 * @return string|null Stage slug, or null when no stage owns this key.
	 */
	private static function stage_for_key( string $prompt_key ): ?string {
		foreach ( PRAutoBlogger_Stage_Display_Map::all() as $stage => $def ) {
			if ( isset( $def['prompt_key'] ) && $def['prompt_key'] === $prompt_key ) {
				return $stage;
			}
		}
		return null;
	}

	/**
	 * Extract the most informative rendered text from a messages array.
	 *
	 * For system-prompt keys (*.system) we return the system message content.
	 * For user/agent-prompt keys we return the last user-role message content.
	 * Falls back to the longest non-empty message when role detection fails.
	 *
	 * @param array<int, array<string, mixed>> $messages Decoded messages array.
	 * @param string                           $key      Prompt key hint.
	 * @return string
	 */
	private static function extract_rendered_from_messages( array $messages, string $key ): string {
		$is_system_key = str_contains( $key, '.system' ) || str_ends_with( $key, '.rewriter_system' );

		// Try role-based selection first.
		$target_role = $is_system_key ? 'system' : 'user';
		$last_match  = '';
		foreach ( $messages as $msg ) {
			if ( is_array( $msg )
				&& isset( $msg['role'], $msg['content'] )
				&& $msg['role'] === $target_role
				&& is_string( $msg['content'] )
				&& '' !== $msg['content'] ) {
				$last_match = $msg['content'];
			}
		}
		if ( '' !== $last_match ) {
			return $last_match;
		}

		// Fallback: return the longest non-empty message content.
		$longest = '';
		foreach ( $messages as $msg ) {
			if ( is_array( $msg ) && isset( $msg['content'] ) && is_string( $msg['content'] )
				&& strlen( $msg['content'] ) > strlen( $longest ) ) {
				$longest = $msg['content'];
			}
		}
		return $longest;
	}
}
