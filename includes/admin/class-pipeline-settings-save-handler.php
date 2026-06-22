<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Processes form submissions on the Pipeline Settings page.
 *
 * What: A stateless save handler for the Pipeline Settings page. On a valid
 *       POST it verifies the nonce, checks capabilities, sanitizes inputs,
 *       and either:
 *         - saves the model option via update_option(), or
 *         - creates a new immutable prompt version in the registry
 *           (PRAutoBlogger_Prompt_Registry_Writer::create_version()).
 *       Saving a prompt NEVER mutates the existing version — it creates a
 *       new one (the registry's core invariant). A "reset to default" POST
 *       creates a new version with the canonical default body.
 *       Prompt keys arrive as a slug-encoded form value that is matched
 *       against the allowlist; the raw POST value is never used as a key
 *       before allowlist validation.
 *       Returns a result array that render_page() passes to the template.
 * Who calls it: PRAutoBlogger_Pipeline_Settings_Page::render_page() before
 *               any output is produced.
 * Dependencies: PRAutoBlogger_Pipeline_Settings_Step_Map (key allowlists),
 *               PRAutoBlogger_Prompt_Registry_Writer (create_version),
 *               PRAutoBlogger_Prompt_Registry (default_body, flush_cache).
 *
 * @see admin/class-pipeline-settings-step-map.php — Allowed key definitions.
 * @see core/class-prompt-registry-writer.php       — Immutable version writes.
 * @see core/class-prompt-registry.php              — Read side + default_body.
 */
class PRAutoBlogger_Pipeline_Settings_Save_Handler {

	/**
	 * Inspect the current request and process the save when it is a valid
	 * Pipeline Settings form submission.
	 *
	 * Side effects: may call update_option() or
	 *               PRAutoBlogger_Prompt_Registry_Writer::create_version();
	 *               returns error on nonce/cap failure.
	 *
	 * @return array{status: string, message: string} 'idle'|'saved'|'error'.
	 */
	public static function maybe_process_save(): array {
		$idle = array( 'status' => 'idle', 'message' => '' );

		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return $idle;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST[ PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD ] ) ) {
			return $idle;
		}

		// Capability check.
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'status' => 'error', 'message' => __( 'You do not have permission to save settings.', 'prautoblogger' ) );
		}

		// Nonce verification.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! wp_verify_nonce( wp_unslash( $_POST[ PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD ] ), PRAutoBlogger_Pipeline_Settings_Page::NONCE_ACTION ) ) {
			return array( 'status' => 'error', 'message' => __( 'Security check failed. Please reload and try again.', 'prautoblogger' ) );
		}

		$action = isset( $_POST['pipeline_action'] ) ? sanitize_key( $_POST['pipeline_action'] ) : '';

		if ( 'save_model' === $action ) {
			return self::handle_model_save();
		}
		if ( 'save_prompt' === $action || 'reset_prompt' === $action ) {
			return self::handle_prompt_save( $action );
		}

		return $idle;
	}

	/**
	 * Persist a model selection for one step.
	 *
	 * Only the allowlisted model option names from Step_Map may be updated.
	 *
	 * @return array{status: string, message: string}
	 */
	private static function handle_model_save(): array {
		$option_name = isset( $_POST['model_option'] ) ? sanitize_key( $_POST['model_option'] ) : '';
		$model_id    = isset( $_POST['model_id'] ) ? sanitize_text_field( wp_unslash( $_POST['model_id'] ) ) : '';

		if ( ! in_array( $option_name, PRAutoBlogger_Pipeline_Settings_Step_Map::allowed_model_options(), true ) ) {
			return array( 'status' => 'error', 'message' => __( 'Unknown model option.', 'prautoblogger' ) );
		}

		// For the image model, delegate to the same sanitizer logic as
		// PRAutoBlogger_Settings_Sanitizer to derive + store provider consistently.
		if ( 'prautoblogger_image_model' === $option_name ) {
			$candidate = sanitize_text_field( $model_id );
			$provider  = PRAutoBlogger_Image_Model_Registry::provider_for( $candidate );
			if ( '' !== $provider ) {
				update_option( 'prautoblogger_image_provider', $provider );
				update_option( $option_name, $candidate );
				return array( 'status' => 'saved', 'message' => __( 'Model saved.', 'prautoblogger' ) );
			}
			return array( 'status' => 'error', 'message' => __( 'Image model not recognised. Selection unchanged.', 'prautoblogger' ) );
		}

		update_option( $option_name, sanitize_text_field( $model_id ) );
		return array( 'status' => 'saved', 'message' => __( 'Model saved.', 'prautoblogger' ) );
	}

	/**
	 * Create a new prompt version (or reset to default) in the registry.
	 *
	 * The prompt key is received as a slug (dots replaced with hyphens in the
	 * form value). We match the slug against the allowlist by resolving each
	 * allowed key to its slug form — this avoids any reverse-parsing ambiguity
	 * for keys that contain underscores (e.g. content.single_pass).
	 *
	 * Prompt bodies are treated as privileged — only sanitize_textarea_field()
	 * is applied to strip any script injection, but content is not further
	 * mangled — the operator is intentionally writing LLM instructions.
	 *
	 * @param string $action 'save_prompt' or 'reset_prompt'.
	 * @return array{status: string, message: string}
	 */
	private static function handle_prompt_save( string $action ): array {
		// The form sends the key with dots replaced by hyphens (HTML-safe slug).
		$slug = isset( $_POST['prompt_key'] ) ? sanitize_key( wp_unslash( $_POST['prompt_key'] ) ) : '';

		// Resolve slug back to the real registry key via allowlist lookup.
		$prompt_key = self::resolve_key_from_slug( $slug );
		if ( null === $prompt_key ) {
			return array( 'status' => 'error', 'message' => __( 'Unknown prompt key.', 'prautoblogger' ) );
		}

		if ( 'reset_prompt' === $action ) {
			$body = PRAutoBlogger_Prompt_Registry::default_body( $prompt_key );
			if ( null === $body ) {
				return array( 'status' => 'error', 'message' => __( 'No default found for this prompt key.', 'prautoblogger' ) );
			}
			$author = 'reset:pipeline-ui';
		} else {
			$raw_body = isset( $_POST['prompt_body'] ) ? wp_unslash( $_POST['prompt_body'] ) : '';
			$body     = sanitize_textarea_field( $raw_body );
			$author   = 'pipeline-ui:' . ( wp_get_current_user()->user_login ?? 'admin' );
		}

		$version = PRAutoBlogger_Prompt_Registry_Writer::create_version(
			$prompt_key,
			$body,
			null,
			null,
			$author,
			true
		);

		if ( 0 === $version ) {
			return array( 'status' => 'error', 'message' => __( 'Failed to save prompt. Is the prompts table available?', 'prautoblogger' ) );
		}

		return array(
			'status'  => 'saved',
			// translators: %1$s = prompt key, %2$d = new version number.
			'message' => sprintf( __( 'Saved %1$s as version %2$d.', 'prautoblogger' ), esc_html( $prompt_key ), $version ),
		);
	}

	/**
	 * Resolve a URL/form slug back to the canonical registry key.
	 *
	 * Each allowed key is converted to a slug (dots → hyphens, everything
	 * lowercased by sanitize_key) and compared against the submitted value.
	 * No string-reverse parsing — only allowlist membership determines a match.
	 *
	 * @param string $slug Slug as received from the form (after sanitize_key).
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
