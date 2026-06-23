<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * AJAX handler: fetch the assembled (rendered) preview for a prompt key.
 *
 * What: Returns the fully-assembled instruction text for a given prompt key
 *       and stage. Prefers the last run's rendered prompt (extracted from
 *       the generation_log request_json) so the admin sees the exact text
 *       the LLM received; falls back to a sample render (template with
 *       placeholder token values) when no run exists yet. The preview is
 *       ALWAYS read-only — there is no save path, cap check, or nonce that
 *       would allow the returned text to be persisted. Escaping is done
 *       server-side; the client renders as pre-formatted text.
 *
 *       Stage-list-driven: the prompt_key -> stage mapping goes through
 *       Stage_Display_Map so Phase 2b stages auto-resolve without rework.
 *
 * Security: cap check (manage_options) + nonce verification on every
 *           request. Output is JSON with esc_html'd preview body + a
 *           boolean indicating whether the source was a real run.
 *
 * Who triggers it: pipeline-settings.js fetch() on "Preview" tab click.
 * Dependencies: PRAutoBlogger_Prompt_Registry (render / get_active),
 *               PRAutoBlogger_Pipeline_Preview_Source (last-run query),
 *               PRAutoBlogger_Pipeline_Settings_Step_Map (key allowlist),
 *               PRAutoBlogger_Stage_Display_Map (stage vocabulary).
 *
 * @see ajax/class-pipeline-history-handler.php     -- Version list / diff AJAX.
 * @see admin/class-pipeline-settings-page.php      -- Registers nonce for JS.
 * @see core/class-prompt-registry.php              -- Template render API.
 * @see core/class-stage-display-map.php            -- Stage vocabulary.
 * @see ARCHITECTURE.md #21                         -- Prompt registry design.
 */
class PRAutoBlogger_Pipeline_Preview_Handler {

	/** AJAX action name (wp_ajax_ prefixed, manage_options gate). */
	public const ACTION = 'prautoblogger_pipeline_preview';

	/**
	 * Register wp-ajax hooks. Called from PRAutoBlogger::register_ajax_hooks().
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Handle the AJAX request.
	 *
	 * Expected POST fields:
	 *   nonce      string  wp_nonce for 'prautoblogger_pipeline_preview'.
	 *   prompt_key string  Registry key slug (dots to hyphens, post sanitize_key).
	 *
	 * @return void  Outputs JSON and calls wp_die().
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ), 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'prautoblogger' ) ), 403 );
		}

		$slug       = isset( $_POST['prompt_key'] ) ? sanitize_key( wp_unslash( $_POST['prompt_key'] ) ) : '';
		$prompt_key = self::resolve_key_from_slug( $slug );

		if ( null === $prompt_key ) {
			wp_send_json_error( array( 'message' => __( 'Unknown prompt key.', 'prautoblogger' ) ), 400 );
		}

		$source_data = PRAutoBlogger_Pipeline_Preview_Source::last_run( $prompt_key );

		if ( null !== $source_data ) {
			wp_send_json_success(
				array(
					'preview'  => esc_html( $source_data['rendered'] ),
					'from_run' => true,
					'run_date' => esc_html( $source_data['run_date'] ),
					'run_post' => esc_html( $source_data['run_post'] ),
				)
			);
		}

		$rendered = PRAutoBlogger_Pipeline_Preview_Source::sample_render( $prompt_key );
		wp_send_json_success(
			array(
				'preview'  => esc_html( $rendered ),
				'from_run' => false,
				'run_date' => '',
				'run_post' => '',
			)
		);
	}

	/**
	 * Resolve a sanitize_key'd slug back to a canonical registry key.
	 * Identical logic to Save_Handler so the same form slug works for both.
	 *
	 * @param string $slug Slug from POST (after sanitize_key).
	 * @return string|null Canonical key, or null when not in the allowlist.
	 */
	private static function resolve_key_from_slug( string $slug ): ?string {
		foreach ( PRAutoBlogger_Pipeline_Settings_Step_Map::allowed_prompt_keys() as $key ) {
			if ( sanitize_key( str_replace( '.', '-', $key ) ) === $slug ) {
				return $key;
			}
		}
		return null;
	}
}
