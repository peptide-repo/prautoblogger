<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Settings field sanitizer for PRAutoBlogger.
 *
 * Centralises all option sanitization logic: encryption for API keys, JSON
 * encoding for multi-value fields, numeric coercion, image-model registry
 * validation. Extracted from PRAutoBlogger_Admin_Page for 300-line compliance.
 *
 * What: Static utility called by register_setting() sanitize_callback.
 * Who triggers: WordPress Settings API on option save; PRAutoBlogger_Admin_Page::sanitize_field().
 * Dependencies: PRAutoBlogger_Encryption, PRAutoBlogger_Image_Model_Registry,
 *               PRAutoBlogger_Image_Template_Filler.
 *
 * @see admin/class-admin-page.php            -- Registers settings and delegates here.
 * @see admin/class-image-model-registry.php  -- Validates image model slug on save.
 * @see CONVENTIONS.md                        -- "How To: Add a New Admin Setting".
 */
class PRAutoBlogger_Settings_Sanitizer {

	/**
	 * Sanitize a settings field value.
	 *
	 * @param mixed $value The submitted value.
	 * @return mixed Sanitized value.
	 */
	public static function sanitize_field( $value ) {
		$option_name = '';
		$filter      = current_filter();
		if ( 0 === strpos( $filter, 'sanitize_option_' ) ) {
			$option_name = substr( $filter, strlen( 'sanitize_option_' ) );
		}

		$encrypted = array( 'prautoblogger_openrouter_api_key', 'prautoblogger_ga4_credentials_json', 'prautoblogger_runware_api_key' );
		if ( in_array( $option_name, $encrypted, true ) ) {
			// Empty value means password field wasn't touched — keep existing.
			if ( '' === $value ) {
				return get_option( $option_name, '' );
			}

			// Already encrypted (has "enc:" prefix) — return as-is.
			// This is the primary defence against double-encryption:
			// PRAutoBlogger_Encryption::encrypt() also checks for this prefix,
			// so even if this callback is called multiple times, the value
			// is encrypted exactly once and never re-encrypted.
			if ( PRAutoBlogger_Encryption::is_encrypted( $value ) ) {
				return $value;
			}

			// New plaintext value — encrypt it (adds "enc:" prefix).
			return PRAutoBlogger_Encryption::encrypt( sanitize_text_field( $value ) );
		}

		$json_fields = array( 'prautoblogger_target_subreddits', 'prautoblogger_topic_exclusions', 'prautoblogger_enabled_sources' );
		if ( in_array( $option_name, $json_fields, true ) ) {
			// PHP array from checkboxes — sanitize each item and re-encode.
			if ( is_array( $value ) ) {
				return wp_json_encode( array_values( array_map( 'sanitize_text_field', $value ) ) );
			}

			$trimmed = trim( (string) $value );

			// Already JSON-encoded array — decode, sanitize each item, re-encode.
			// This prevents sanitize_text_field() from mangling the JSON string,
			// which caused json_decode() on read to return null → empty subreddits.
			if ( '[' === substr( $trimmed, 0, 1 ) ) {
				$decoded = json_decode( $trimmed, true );
				if ( is_array( $decoded ) ) {
					return wp_json_encode( array_values( array_map( 'sanitize_text_field', $decoded ) ) );
				}
			}

			// Comma-separated plain text — split, sanitize, encode as JSON array.
			$items = array_filter( array_map( 'trim', explode( ',', $trimmed ) ) );
			return wp_json_encode( array_values( array_map( 'sanitize_text_field', $items ) ) );
		}

		$numeric = array( 'prautoblogger_daily_article_target', 'prautoblogger_monthly_budget_usd', 'prautoblogger_min_word_count', 'prautoblogger_max_word_count', 'prautoblogger_default_author', 'prautoblogger_default_category', 'prautoblogger_pullpush_cache_ttl', 'prautoblogger_reddit_posts_per_subreddit', 'prautoblogger_board_poll_interval', 'prautoblogger_board_published_window_days', 'prautoblogger_board_column_limit', 'prautoblogger_ideas_per_page' );
		if ( in_array( $option_name, $numeric, true ) ) {
			return is_numeric( $value ) ? $value : 0;
		}
		if ( 'prautoblogger_image_model' === $option_name ) {
			return self::sanitize_image_model( (string) $value );
		}
		if ( 'prautoblogger_image_style_template' === $option_name ) {
			// Multi-line template: validation + textarea-safe sanitisation
			// (incl. the single-token check, brief A5) lives in the filler.
			return PRAutoBlogger_Image_Template_Filler::sanitize_for_save( (string) $value );
		}
		return sanitize_text_field( (string) $value );
	}

	/** Validate model id against the registry and persist the derived provider. */
	private static function sanitize_image_model( string $value ): string {
		$candidate = sanitize_text_field( $value );
		$provider  = PRAutoBlogger_Image_Model_Registry::provider_for( $candidate );
		if ( '' !== $provider ) {
			update_option( 'prautoblogger_image_provider', $provider );
			return $candidate;
		}
		add_settings_error( 'prautoblogger_image_model', 'prautoblogger_image_model_unknown', sprintf( esc_html__( 'Image model "%s" is not in the registry. Keeping the previous selection.', 'prautoblogger' ), esc_html( $candidate ) ) );
		return (string) get_option( 'prautoblogger_image_model', PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL );
	}
}
