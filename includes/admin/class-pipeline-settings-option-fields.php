<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Per-step-context option field definitions for the Pipeline Settings page.
 *
 * What: Central registry mapping step context identifiers to their editable
 *       wp_option fields. Provides allowlist enforcement, field-type metadata,
 *       and a single sanitize_option() entry point used by the save handler.
 *       Fields were previously under Settings tabs (AI Models, Content, Sources)
 *       retired in M2 — the wp_options themselves are unchanged, only the UI
 *       surface that edits them has moved.
 * Who calls it: PRAutoBlogger_Pipeline_Settings_Save_Handler (save_step_settings),
 *               PRAutoBlogger_Pipeline_Settings_Renderer (view data assembly).
 * Dependencies: PRAutoBlogger_Pipeline_Settings_Option_Fields_Data (field arrays).
 *
 * @see admin/class-pipeline-settings-option-fields-data.php — Raw field arrays per context.
 * @see admin/class-pipeline-settings-save-handler.php       — save_step_settings dispatch.
 * @see admin/class-pipeline-settings-renderer.php           — passes field values to template.
 * @see CONVENTIONS.md §Retired Settings Tabs                — retirement pattern.
 */
class PRAutoBlogger_Pipeline_Settings_Option_Fields {

	/**
	 * All step context identifiers.
	 *
	 * @return string[]
	 */
	public static function contexts(): array {
		return array( 'global', 'research', 'analysis', 'writer', 'editorial' );
	}

	/**
	 * All allowed option names across every context (for allowlist enforcement).
	 *
	 * @return string[]
	 */
	public static function allowed_options(): array {
		$names = array();
		foreach ( self::contexts() as $ctx ) {
			foreach ( self::get_fields_for_context( $ctx ) as $field ) {
				$names[] = $field['id'];
			}
		}
		return array_unique( $names );
	}

	/**
	 * Field definitions for a given step context.
	 *
	 * Each field definition array contains at minimum:
	 *   id      string  wp_option name.
	 *   type    string  'textarea'|'select'|'number'|'toggle'|'checkboxes'.
	 * Additional keys per type:
	 *   options array   For 'select': key => label map of allowed values.
	 *   choices array   For 'checkboxes': key => label map.
	 *   min/max int     For 'number': enforced bounds.
	 *   default mixed   Fallback when option is not set.
	 *   label   string  Human-readable field label.
	 *   description string  Help text shown below the field.
	 *
	 * @param string $context One of self::contexts().
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_fields_for_context( string $context ): array {
		return PRAutoBlogger_Pipeline_Settings_Option_Fields_Data::fields_for( $context );
	}

	/**
	 * Sanitize a raw POST value for a single field.
	 *
	 * Handles each field type independently: textarea → sanitize_textarea_field,
	 * select → key validated against the options allowlist, number → absint +
	 * bounds clamp, toggle → '0'|'1', checkboxes → JSON array of allowed keys.
	 *
	 * @param mixed                $value Raw value from $_POST.
	 * @param array<string, mixed> $field Field definition from get_fields_for_context().
	 * @return mixed Sanitized value ready for update_option().
	 */
	public static function sanitize_option( mixed $value, array $field ): mixed {
		$type = (string) ( $field['type'] ?? 'textarea' );

		switch ( $type ) {
			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'toggle':
				return ( '1' === (string) $value ) ? '1' : '0';

			case 'number':
				$int = absint( $value );
				if ( isset( $field['min'] ) && $int < (int) $field['min'] ) {
					$int = (int) $field['min'];
				}
				if ( isset( $field['max'] ) && $int > (int) $field['max'] ) {
					$int = (int) $field['max'];
				}
				return $int;

			case 'select':
				$key     = sanitize_key( (string) $value );
				$allowed = array_keys( (array) ( $field['options'] ?? array() ) );
				return in_array( $key, $allowed, true ) ? $key : (string) ( $field['default'] ?? '' );

			case 'checkboxes':
				if ( ! is_array( $value ) ) {
					$value = array();
				}
				$allowed  = array_keys( (array) ( $field['choices'] ?? array() ) );
				$filtered = array();
				foreach ( $value as $item ) {
					$item = sanitize_key( (string) $item );
					if ( in_array( $item, $allowed, true ) ) {
						$filtered[] = $item;
					}
				}
				return wp_json_encode( $filtered );

			default:
				return sanitize_text_field( (string) $value );
		}
	}
}
