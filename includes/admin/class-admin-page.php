<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Registers and renders the main PRAutoBlogger settings page in wp-admin.
 *
 * Uses the WordPress Settings API for option registration, sanitization, and rendering.
 * Settings are defined as a declarative array — adding a new setting is one array entry.
 *
 * Triggered by: PRAutoBlogger::register_admin_hooks() registers menu and settings hooks.
 * Dependencies: PRAutoBlogger_Encryption (for API key storage), PRAutoBlogger_Settings_Fields.
 *
 * @see class-prautoblogger.php     — Registers hooks that call this class.
 * @see class-settings-fields.php — Defines all settings fields and sections.
 * @see CONVENTIONS.md            — "How To: Add a New Admin Setting".
 */
class PRAutoBlogger_Admin_Page {

	private const PAGE_SLUG    = 'prautoblogger-settings';
	private const OPTION_GROUP = 'prautoblogger_settings_group';

	/** Register the top-level PRAutoBlogger menu item. */
	public function on_register_menu(): void {
		add_menu_page(
			__( 'PRAutoBlogger', 'prautoblogger' ),
			__( 'PRAutoBlogger', 'prautoblogger' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-edit-page',
			30
		);
	}

	/** Register all settings with the WordPress Settings API. */
	public function on_register_settings(): void {
		$sections = PRAutoBlogger_Settings_Fields::get_sections();
		$fields   = PRAutoBlogger_Settings_Fields::get_fields();

		foreach ( $sections as $section_id => $section ) {
			add_settings_section( $section_id, $section['title'], '__return_empty_string', self::PAGE_SLUG );
		}

		foreach ( $fields as $field ) {
			$section = $field['section'] ?? 'prautoblogger_api';
			register_setting(
				self::OPTION_GROUP,
				$field['id'],
				array(
					'type'              => $field['wp_type'] ?? 'string',
					'sanitize_callback' => array( $this, 'sanitize_field' ),
					'default'           => $field['default'] ?? '',
				)
			);
			add_settings_field( $field['id'], $field['label'], array( $this, 'render_field' ), self::PAGE_SLUG, $section, $field );
		}
	}

	/** Enqueue admin CSS and JS on all PRAutoBlogger admin pages. */
	public function on_enqueue_assets( string $hook_suffix ): void {
		$pages = array(
			'toplevel_page_' . self::PAGE_SLUG,
			'prautoblogger_page_prautoblogger-board',
			'prautoblogger_page_prautoblogger-metrics',
			'prautoblogger_page_prautoblogger-review-queue',
			'prautoblogger_page_prautoblogger-ideas',
			'prautoblogger_page_prautoblogger-logs',
		);
		if ( ! in_array( $hook_suffix, $pages, true ) ) {
			return;
		}

		wp_enqueue_style( 'prautoblogger-admin', PRAUTOBLOGGER_PLUGIN_URL . 'assets/css/admin.css', array(), PRAUTOBLOGGER_VERSION );
		wp_enqueue_style( 'prautoblogger-model-picker', PRAUTOBLOGGER_PLUGIN_URL . 'assets/css/model-picker.css', array( 'prautoblogger-admin' ), PRAUTOBLOGGER_VERSION );
		wp_enqueue_script( 'prautoblogger-admin', PRAUTOBLOGGER_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), PRAUTOBLOGGER_VERSION, true );
		wp_enqueue_script( 'prautoblogger-model-picker', PRAUTOBLOGGER_PLUGIN_URL . 'assets/js/model-picker.js', array( 'jquery', 'prautoblogger-admin' ), PRAUTOBLOGGER_VERSION, true );
		wp_enqueue_script( 'peptiderepo-model-picker', PRAUTOBLOGGER_PLUGIN_URL . 'assets/admin/peptiderepo-model-picker.js', array( 'jquery' ), PRAUTOBLOGGER_VERSION, true );
		wp_enqueue_style( 'peptiderepo-model-picker', PRAUTOBLOGGER_PLUGIN_URL . 'assets/admin/peptiderepo-model-picker.css', array(), PRAUTOBLOGGER_VERSION );

		wp_localize_script(
			'prautoblogger-admin',
			'prautobloggerAdmin',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'adminUrl'       => admin_url(),
				'siteUrl'        => home_url( '/' ),
				'generateNonce'  => wp_create_nonce( 'prautoblogger_generate_now' ),
				'imageNonce'     => wp_create_nonce( 'prautoblogger_generate_image' ),
				'testNonce'      => wp_create_nonce( 'prautoblogger_test_connection' ),
				'modelsNonce'    => wp_create_nonce( 'prautoblogger_get_models' ),
				'reviewNonce'    => wp_create_nonce( 'prautoblogger_review_queue' ),
				'ideaGenNonce'   => wp_create_nonce( 'prautoblogger_idea_gen' ),
				'generatingText' => __( 'Generating...', 'prautoblogger' ),
				'generateText'   => __( 'Generate Now', 'prautoblogger' ),
				'testingText'    => __( 'Testing...', 'prautoblogger' ),
				'testText'       => __( 'Test Connections', 'prautoblogger' ),
				// Image models for the picker — defined in the settings class to keep this file short.
				'imageModels'    => PRAutoBlogger_Settings_Fields_Extended::get_image_models(),
			)
		);

		// Ideas browser: generate-from-idea JS (only on the Ideas page).
		if ( 'prautoblogger_page_prautoblogger-ideas' === $hook_suffix ) {
			wp_enqueue_script( 'prautoblogger-ideas-browser', PRAUTOBLOGGER_PLUGIN_URL . 'assets/js/ideas-browser.js', array( 'jquery', 'prautoblogger-admin' ), PRAUTOBLOGGER_VERSION, true );
		}
	}

	/** Render the settings page (delegates to template). */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$sections = PRAutoBlogger_Settings_Fields::get_sections();
		$fields   = PRAutoBlogger_Settings_Fields::get_fields();
		include PRAUTOBLOGGER_PLUGIN_DIR . 'templates/admin/settings-page.php';
	}

	/**
	 * Render a single settings field based on its type.
	 *
	 * @param array<string, mixed> $args Field definition.
	 */
	public function render_field( array $args ): void {
		$id      = esc_attr( $args['id'] );
		$type    = $args['type'] ?? 'text';
		$default = $args['default'] ?? '';
		$desc    = $args['description'] ?? '';
		$value   = get_option( $args['id'], $default );

		if ( 'password' === $type && '' !== $value ) {
			$decrypted = PRAutoBlogger_Encryption::decrypt( $value );
			$value     = '' !== $decrypted ? '••••••••' : '';
		}

		switch ( $type ) {
			case 'text':
			case 'number':
			case 'url':
			case 'time':
				$attrs = '';
				if ( isset( $args['min'] ) ) {
					$attrs .= ' min="' . esc_attr( (string) $args['min'] ) . '"'; }
				if ( isset( $args['max'] ) ) {
					$attrs .= ' max="' . esc_attr( (string) $args['max'] ) . '"'; }
				if ( isset( $args['step'] ) ) {
					$attrs .= ' step="' . esc_attr( (string) $args['step'] ) . '"'; }
				printf( '<input type="%s" id="%s" name="%s" value="%s" class="ab-input" %s />', esc_attr( $type ), $id, $id, esc_attr( (string) $value ), $attrs );
				break;

			case 'password':
				printf(
					'<input type="password" id="%s" name="%s" value="" class="ab-input" placeholder="%s" autocomplete="off" />',
					$id,
					$id,
					'' !== $value ? esc_attr__( 'Saved (enter new value to change)', 'prautoblogger' ) : ''
				);
				break;

			case 'textarea':
				printf( '<textarea id="%s" name="%s" rows="3" class="ab-textarea">%s</textarea>', $id, $id, esc_textarea( (string) $value ) );
				break;

			case 'select':
				printf( '<select id="%s" name="%s" class="ab-select">', $id, $id );
				foreach ( ( $args['options'] ?? array() ) as $v => $label ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( (string) $v ), selected( $value, (string) $v, false ), esc_html( $label ) );
				}
				echo '</select>';
				break;

			case 'toggle':
				$checked = in_array( $value, array( '1', 'yes', true ), true );
				printf(
					'<label class="ab-toggle"><input type="hidden" name="%s" value="0" /><input type="checkbox" name="%s" value="1" %s /><span class="ab-toggle-slider"></span></label>',
					$id,
					$id,
					checked( $checked, true, false )
				);
				break;

			case 'checkboxes':
				$current = json_decode( (string) $value, true ) ?? array();
				// Hidden input ensures an empty value is POSTed when all boxes
				// are unchecked — without this, the browser omits the field
				// entirely, so WordPress never fires the sanitize callback and
				// the old value persists in wp_options.
				printf( '<input type="hidden" name="%s" value="" />', $id );
				foreach ( ( $args['options'] ?? array() ) as $v => $label ) {
					$is_checked = in_array( $v, $current, true );
					$disabled   = strpos( $label, 'coming soon' ) !== false ? ' disabled' : '';
					printf(
						'<label class="ab-checkbox-label"><input type="checkbox" name="%s[]" value="%s" %s%s /> %s</label>',
						$id,
						esc_attr( $v ),
						checked( $is_checked, true, false ),
						$disabled,
						esc_html( $label )
					);
				}
				break;

			case 'model_select':
				$this->render_model_select_field( $id, (string) $value, $args );
				break;

			case 'source_status':
				$this->render_source_status_field();
				break;

			case 'author_select':
				wp_dropdown_users(
					array(
						'name'              => $id,
						'id'                => $id,
						'selected'          => absint( $value ),
						'show_option_none'  => __( '— Auto (first admin) —', 'prautoblogger' ),
						'option_none_value' => '0',
						'class'             => 'ab-select',
					)
				);
				break;

			case 'category_select':
				wp_dropdown_categories(
					array(
						'name'              => $id,
						'id'                => $id,
						'selected'          => absint( $value ),
						'show_option_none'  => __( '— Auto-assign by type —', 'prautoblogger' ),
						'option_none_value' => '0',
						'class'             => 'ab-select',
						'hide_empty'        => false,
					)
				);
				break;
		}

		if ( isset( $args['badge'] ) ) {
			printf( ' <span class="ab-badge">%s</span>', esc_html( $args['badge'] ) );
		}
		if ( '' !== $desc ) {
			printf( '<p class="ab-field-desc">%s</p>', esc_html( $desc ) );
		}
	}

	/**
	 * Delegate source status rendering to the dedicated field class.
	 *
	 * @see admin/fields/class-source-status-field.php
	 */
	private function render_source_status_field(): void {
		PRAutoBlogger_Source_Status_Field::render();
	}

	/**
	 * Delegate model picker rendering to the dedicated field class.
	 *
	 * @param string               $id    Field HTML id/name.
	 * @param string               $value Current model id.
	 * @param array<string, mixed> $args  Field definition.
	 *
	 * @see admin/fields/class-openrouter-model-field.php
	 */
	private function render_model_select_field( string $id, string $value, array $args ): void {
		PRAutoBlogger_OpenRouter_Model_Field::render( $id, $value, $args );
	}

	/**
	 * Sanitize a settings field value.
	 *
	 * Delegates to PRAutoBlogger_Settings_Sanitizer (extracted for 300-line compliance).
	 *
	 * @param mixed $value The submitted value.
	 * @return mixed Sanitized value.
	 *
	 * @see admin/class-settings-sanitizer.php
	 */
	public function sanitize_field( $value ) {
		return PRAutoBlogger_Settings_Sanitizer::sanitize_field( $value );
	}
}
