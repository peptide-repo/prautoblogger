<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Assembles view data and renders the Pipeline Settings page template.
 *
 * What: Reads current option values, active prompt versions, and registry
 *       params, then passes a typed data structure to the pipeline-settings-
 *       page.php template. The renderer contains NO business logic — it only
 *       reads and shapes data for the view. All output is escaped by the
 *       template or by helper methods on this class.
 * Who calls it: PRAutoBlogger_Pipeline_Settings_Page::render_page() after
 *               the save handler runs.
 * Dependencies: PRAutoBlogger_Pipeline_Settings_Step_Map (step list),
 *               PRAutoBlogger_Prompt_Registry (active row, versions, defs),
 *               PRAutoBlogger_OpenRouter_Model_Field (model picker render),
 *               PRAutoBlogger_Cost_Reporter (monthly spend header).
 *
 * @see admin/class-pipeline-settings-step-map.php — Step + key definitions.
 * @see admin/fields/class-open-router-model-field.php — Model picker component.
 * @see core/class-prompt-registry.php              — Registry read API.
 * @see templates/admin/pipeline-settings-page.php  — HTML template.
 */
class PRAutoBlogger_Pipeline_Settings_Renderer {

	/**
	 * Build the view-data array and include the page template.
	 *
	 * @param array{status: string, message: string} $save_result Result from save handler.
	 * @return void
	 */
	public function render( array $save_result ): void {
		$active_step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : 'research';
		$steps       = PRAutoBlogger_Pipeline_Settings_Step_Map::steps();
		$step        = PRAutoBlogger_Pipeline_Settings_Step_Map::find( $active_step ) ?? $steps[0];

		$view = array(
			'steps'        => $steps,
			'active_step'  => $step,
			'save_result'  => $save_result,
			'nonce_field'  => PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD,
			'nonce_action' => PRAutoBlogger_Pipeline_Settings_Page::NONCE_ACTION,
			'page_slug'    => PRAutoBlogger_Pipeline_Settings_Page::PAGE_SLUG,
			'step_data'    => $this->build_step_data( $step ),
		);

		include PRAUTOBLOGGER_PLUGIN_DIR . 'templates/admin/pipeline-settings-page.php';
	}

	/**
	 * Assemble all data needed to render a single step panel.
	 *
	 * @param array<string, mixed> $step Step definition from Step_Map::steps().
	 * @return array<string, mixed> View data for the step panel.
	 */
	public function build_step_data( array $step ): array {
		$defs         = PRAutoBlogger_Prompt_Registry::defs();
		$all_versions = PRAutoBlogger_Prompt_Registry_Writer::list_versions( $step['system_key'] ?? '' );
		$active_versions_map = PRAutoBlogger_Prompt_Registry::active_versions();

		// Model info.
		$model_option = (string) ( $step['model_option'] ?? '' );
		$model_value  = '' !== $model_option ? (string) get_option( $model_option, '' ) : '';

		// System prompt.
		$system_key  = (string) ( $step['system_key'] ?? '' );
		$system_data = $this->build_prompt_panel_data( $system_key, $defs, $active_versions_map );

		// Agent/user prompts.
		$agent_panels = array();
		foreach ( ( $step['agent_keys'] ?? array() ) as $key ) {
			$agent_panels[ $key ] = $this->build_prompt_panel_data( $key, $defs, $active_versions_map );
		}

		// Params from the def for the system key (canonical params are on system).
		$params = isset( $defs[ $system_key ] ) ? ( $defs[ $system_key ]['params'] ?? array() ) : array();

		return array(
			'step'         => $step,
			'model_option' => $model_option,
			'model_value'  => $model_value,
			'system'       => $system_data,
			'agent_panels' => $agent_panels,
			'params'       => $params,
		);
	}

	/**
	 * Build display data for a single prompt key's edit panel.
	 *
	 * @param string                                                                    $key Registry key.
	 * @param array<string, array{body: string, model_option: ?string, params: array<string, mixed>}> $defs All defs.
	 * @param array<string, int>                                                        $active_versions_map key => active version.
	 * @return array<string, mixed>
	 */
	private function build_prompt_panel_data( string $key, array $defs, array $active_versions_map ): array {
		if ( '' === $key ) {
			return array();
		}

		$active_row     = PRAutoBlogger_Prompt_Registry::get_active( $key );
		$active_version = $active_versions_map[ $key ] ?? 0;
		$body           = null !== $active_row ? (string) $active_row['body'] : (string) PRAutoBlogger_Prompt_Registry::default_body( $key );
		$default_body   = (string) PRAutoBlogger_Prompt_Registry::default_body( $key );
		$is_default     = ( $body === $default_body );
		$created_at     = null !== $active_row ? (string) $active_row['created_at'] : '';
		$author         = null !== $active_row ? (string) $active_row['author'] : 'seed';

		$versions = PRAutoBlogger_Prompt_Registry_Writer::list_versions( $key );

		return array(
			'key'            => $key,
			'body'           => $body,
			'default_body'   => $default_body,
			'is_default'     => $is_default,
			'active_version' => $active_version,
			'created_at'     => $created_at,
			'author'         => $author,
			'version_count'  => count( $versions ),
		);
	}

	/**
	 * Render the model picker for a step using the existing field component.
	 *
	 * Public so the template can call it directly. Keeps markup generation
	 * in one place and avoids duplicating the picker rendering logic.
	 *
	 * @param string               $model_option wp_option name (e.g. 'prautoblogger_writing_model').
	 * @param string               $model_value  Current model id stored in the option.
	 * @param array<string, mixed> $field_args   Field args forwarded to the picker.
	 * @return void Side effect: outputs HTML.
	 */
	public function render_model_picker( string $model_option, string $model_value, array $field_args ): void {
		PRAutoBlogger_OpenRouter_Model_Field::render( $model_option, $model_value, $field_args );
	}
}
