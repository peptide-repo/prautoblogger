<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Processes form submissions on the Pipeline Settings page.
 *
 * What: A stateless save handler for the Pipeline Settings page. On a valid
 *       POST it verifies the nonce, checks capabilities, sanitizes inputs,
 *       and dispatches to the correct sub-handler:
 *         - save_model   — persists a step model option via update_option().
 *         - save_prompt  — creates a new immutable prompt version in the registry.
 *         - reset_prompt — creates a new version with the canonical default body.
 *         - save_step_settings — persists one or more relocated option fields for
 *           a step context (global/research/analysis/writer/editorial/curate/seo/authority).
 *           For the authority context, also parses the tier-map textarea into
 *           prautoblogger_category_tiers (serialised array) via
 *           parse_and_save_category_tiers().
 *       Saving a prompt NEVER mutates the existing version — the registry's core
 *       invariant. Model + step-option values read from POST keys matching the
 *       option name. Prompt keys are slug-matched against the allowlist.
 *       Returns a result array that render_page() passes to the template.
 * Who calls it: PRAutoBlogger_Pipeline_Settings_Page::render_page() before output.
 * Dependencies: PRAutoBlogger_Pipeline_Settings_Step_Map (key allowlists),
 *               PRAutoBlogger_Pipeline_Settings_Option_Fields (step option allowlist),
 *               PRAutoBlogger_Prompt_Registry_Writer (create_version),
 *               PRAutoBlogger_Prompt_Registry (default_body, flush_cache).
 *
 * @see admin/class-pipeline-settings-step-map.php         — Model/prompt key allowlists.
 * @see admin/class-pipeline-settings-option-fields.php    — Step option allowlist + sanitizer.
 * @see admin/fields/class-open-router-model-field.php     — Emits name="$option_name".
 * @see core/class-prompt-registry-writer.php              — Immutable version writes.
 * @see core/class-prompt-registry.php                     — Read side + default_body.
 */
class PRAutoBlogger_Pipeline_Settings_Save_Handler {

	/**
	 * Inspect the current request and process the save when it is a valid
	 * Pipeline Settings form submission.
	 *
	 * @return array{status: string, message: string} 'idle'|'saved'|'error'.
	 */
	public static function maybe_process_save(): array {
		$idle = array(
			'status'  => 'idle',
			'message' => '',
		);

		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return $idle;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST[ PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD ] ) ) {
			return $idle;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'status'  => 'error',
				'message' => __( 'You do not have permission to save settings.', 'prautoblogger' ),
			);
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! wp_verify_nonce( wp_unslash( $_POST[ PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD ] ), PRAutoBlogger_Pipeline_Settings_Page::NONCE_ACTION ) ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Security check failed. Please reload and try again.', 'prautoblogger' ),
			);
		}

		$action = isset( $_POST['pipeline_action'] ) ? sanitize_key( $_POST['pipeline_action'] ) : '';

		if ( 'save_model' === $action ) {
			return self::handle_model_save();
		}
		if ( 'save_step_settings' === $action ) {
			return self::handle_step_settings_save();
		}
		if ( 'save_prompt' === $action || 'reset_prompt' === $action ) {
			return self::handle_prompt_save( $action );
		}

		return $idle;
	}

	/**
	 * Persist option fields for a step context.
	 *
	 * Reads step_context from POST, validates it, then for each field in that
	 * context reads the corresponding POST value, sanitizes via
	 * PRAutoBlogger_Pipeline_Settings_Option_Fields::sanitize_option(), and
	 * calls update_option(). Only allowlisted option names are written.
	 * For the authority context, also parses the tier-map textarea.
	 *
	 * @return array{status: string, message: string}
	 */
	private static function handle_step_settings_save(): array {
		$context = isset( $_POST['step_context'] ) ? sanitize_key( $_POST['step_context'] ) : '';

		if ( ! in_array( $context, PRAutoBlogger_Pipeline_Settings_Option_Fields::contexts(), true ) ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Unknown step context.', 'prautoblogger' ),
			);
		}

		$fields = PRAutoBlogger_Pipeline_Settings_Option_Fields::get_fields_for_context( $context );

		foreach ( $fields as $field ) {
			$id = (string) $field['id'];

			// Virtual display fields are not persisted directly — they are parsed
			// into a canonical option by a dedicated handler. Skip them here.
			// prautoblogger_category_tiers_input is the textarea that parse_and_save_category_tiers()
			// converts to the prautoblogger_category_tiers serialised array.
			if ( str_ends_with( $id, '_input' ) ) {
				continue;
			}

			$raw = isset( $_POST[ $id ] ) ? wp_unslash( $_POST[ $id ] ) : ( $field['default'] ?? '' );
			// For checkboxes the POST value is an array (or absent = empty array).
			if ( 'checkboxes' === ( $field['type'] ?? '' ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$raw = isset( $_POST[ $id ] ) && is_array( $_POST[ $id ] ) ? $_POST[ $id ] : array();
			}
			$sanitized = PRAutoBlogger_Pipeline_Settings_Option_Fields::sanitize_option( $raw, $field );
			update_option( $id, $sanitized );
		}

		// For the authority context, also parse the category-tier textarea into the tiers array.
		if ( 'authority' === $context ) {
			self::parse_and_save_category_tiers();
		}

		return array(
			'status'  => 'saved',
			'message' => __( 'Settings saved.', 'prautoblogger' ),
		);
	}

	/**
	 * Parse the prautoblogger_category_tiers_input textarea and persist
	 * the result as the prautoblogger_category_tiers serialised array.
	 *
	 * Format: one line per category, "slug: authority|economy".
	 * Invalid tier values default to 'authority' (additive-safety rule).
	 *
	 * @return void
	 */
	private static function parse_and_save_category_tiers(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by caller
		$raw = isset( $_POST['prautoblogger_category_tiers_input'] )
			? wp_unslash( $_POST['prautoblogger_category_tiers_input'] )
			: '';
		$raw   = sanitize_textarea_field( $raw );
		$lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
		$tiers = array();
		foreach ( $lines as $line ) {
			if ( ! str_contains( $line, ':' ) ) {
				continue;
			}
			[ $slug, $tier ] = explode( ':', $line, 2 );
			$slug = sanitize_key( trim( $slug ) );
			$tier = sanitize_key( trim( $tier ) );
			if ( '' === $slug ) {
				continue;
			}
			$tiers[ $slug ] = ( 'economy' === $tier ) ? 'economy' : 'authority';
		}
		update_option( 'prautoblogger_category_tiers', $tiers );
	}

	/**
	 * Persist a model selection for one step.
	 *
	 * The model value is read from $_POST[$option_name] — the same key that
	 * PRAutoBlogger_OpenRouter_Model_Field::render($option_name, ...) emits as
	 * <input type="hidden" name="$option_name" ...>. model-picker.js writes to
	 * that hidden input directly via DOM id.
	 *
	 * Only allowlisted model option names from Step_Map may be updated.
	 *
	 * @return array{status: string, message: string}
	 */
	private static function handle_model_save(): array {
		$option_name = isset( $_POST['model_option'] ) ? sanitize_key( $_POST['model_option'] ) : '';

		if ( ! in_array( $option_name, PRAutoBlogger_Pipeline_Settings_Step_Map::allowed_model_options(), true ) ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Unknown model option.', 'prautoblogger' ),
			);
		}

		$model_id = isset( $_POST[ $option_name ] )
			? sanitize_text_field( wp_unslash( $_POST[ $option_name ] ) )
			: '';

		if ( 'prautoblogger_image_model' === $option_name ) {
			$candidate = sanitize_text_field( $model_id );
			$provider  = PRAutoBlogger_Image_Model_Registry::provider_for( $candidate );
			if ( '' !== $provider ) {
				update_option( 'prautoblogger_image_provider', $provider );
				update_option( $option_name, $candidate );
				return array(
					'status'  => 'saved',
					'message' => __( 'Model saved.', 'prautoblogger' ),
				);
			}
			return array(
				'status'  => 'error',
				'message' => __( 'Image model not recognised. Selection unchanged.', 'prautoblogger' ),
			);
		}

		update_option( $option_name, sanitize_text_field( $model_id ) );
		return array(
			'status'  => 'saved',
			'message' => __( 'Model saved.', 'prautoblogger' ),
		);
	}

	/**
	 * Create a new prompt version (or reset to default) in the registry.
	 *
	 * The prompt key is received as a slug (dots replaced with hyphens in the
	 * form value). We match the slug against the allowlist by resolving each
	 * allowed key to its slug form — avoiding reverse-parsing ambiguity.
	 *
	 * @param string $action 'save_prompt' or 'reset_prompt'.
	 * @return array{status: string, message: string}
	 */
	private static function handle_prompt_save( string $action ): array {
		$slug       = isset( $_POST['prompt_key'] ) ? sanitize_key( wp_unslash( $_POST['prompt_key'] ) ) : '';
		$prompt_key = self::resolve_key_from_slug( $slug );
		if ( null === $prompt_key ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Unknown prompt key.', 'prautoblogger' ),
			);
		}

		if ( 'reset_prompt' === $action ) {
			$body = PRAutoBlogger_Prompt_Registry::default_body( $prompt_key );
			if ( null === $body ) {
				return array(
					'status'  => 'error',
					'message' => __( 'No default found for this prompt key.', 'prautoblogger' ),
				);
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
			return array(
				'status'  => 'error',
				'message' => __( 'Failed to save prompt. Is the prompts table available?', 'prautoblogger' ),
			);
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
