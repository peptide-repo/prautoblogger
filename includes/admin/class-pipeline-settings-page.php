<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Registers the Pipeline Settings wp-admin submenu page and its assets.
 *
 * What: Adds a "Pipeline" submenu under the PRAutoBlogger parent menu so
 *       operators can configure per-step model, system instructions, agent
 *       prompts, and parameters for every LLM stage in one place. This
 *       page is ADDITIVE in M1 — the existing Settings sections remain
 *       unchanged and both surfaces edit the same wp_options / prompts
 *       table. Decomposition of redundant tabs is M2.
 * Who calls it: PRAutoBlogger::register_admin_hooks() via add_action
 *               ('admin_menu', ..., 13) and ('admin_enqueue_scripts', ...).
 * Dependencies: PRAutoBlogger_Pipeline_Settings_Save_Handler (save),
 *               PRAutoBlogger_Pipeline_Settings_Renderer (render).
 *
 * @see admin/class-pipeline-settings-renderer.php  — Step rail + panel HTML.
 * @see admin/class-pipeline-settings-save-handler.php — nonce + save logic.
 * @see admin/class-admin-page.php   — Parent menu registration (priority 10).
 * @see ARCHITECTURE.md              — Admin pages table.
 * @see INFORMATION-ARCHITECTURE.md — Admin page slug registry.
 */
class PRAutoBlogger_Pipeline_Settings_Page {

	/** wp-admin page slug. */
	public const PAGE_SLUG = 'prautoblogger-pipeline';

	/** Nonce action for saving prompt edits on this page. */
	public const NONCE_ACTION = 'prautoblogger_pipeline_save';

	/** Nonce field name posted with the save form. */
	public const NONCE_FIELD = 'prautoblogger_pipeline_nonce';

	/**
	 * Register the Pipeline submenu page under the PRAutoBlogger parent menu.
	 *
	 * Priority 13 — after board (11), dossier (12). The parent slug must be
	 * registered at priority 10 by PRAutoBlogger_Admin_Page::on_register_menu()
	 * before any submenu can be attached.
	 *
	 * @return void
	 */
	public function on_register_menu(): void {
		add_submenu_page(
			'prautoblogger-settings',
			__( 'Pipeline Settings', 'prautoblogger' ),
			__( 'Pipeline', 'prautoblogger' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue CSS / JS required by the Pipeline Settings page.
	 *
	 * Reuses the existing admin + model-picker asset pairs; adds a small
	 * pipeline-specific stylesheet and script.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function on_enqueue_assets( string $hook_suffix ): void {
		if ( 'prautoblogger_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'prautoblogger-admin', PRAUTOBLOGGER_PLUGIN_URL . 'assets/css/admin.css', array(), PRAUTOBLOGGER_VERSION );
		wp_enqueue_style( 'prautoblogger-model-picker', PRAUTOBLOGGER_PLUGIN_URL . 'assets/css/model-picker.css', array( 'prautoblogger-admin' ), PRAUTOBLOGGER_VERSION );
		wp_enqueue_style( 'prautoblogger-pipeline', PRAUTOBLOGGER_PLUGIN_URL . 'assets/css/pipeline-settings.css', array( 'prautoblogger-admin' ), PRAUTOBLOGGER_VERSION );

		wp_enqueue_script( 'prautoblogger-admin', PRAUTOBLOGGER_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), PRAUTOBLOGGER_VERSION, true );
		wp_enqueue_script( 'prautoblogger-model-picker', PRAUTOBLOGGER_PLUGIN_URL . 'assets/js/model-picker.js', array( 'jquery', 'prautoblogger-admin' ), PRAUTOBLOGGER_VERSION, true );
		wp_enqueue_script( 'prautoblogger-pipeline', PRAUTOBLOGGER_PLUGIN_URL . 'assets/js/pipeline-settings.js', array( 'jquery', 'prautoblogger-admin' ), PRAUTOBLOGGER_VERSION, true );

		wp_localize_script(
			'prautoblogger-admin',
			'prautobloggerAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'adminUrl'    => admin_url(),
				'siteUrl'     => home_url( '/' ),
				'modelsNonce' => wp_create_nonce( 'prautoblogger_get_models' ),
				'imageModels' => PRAutoBlogger_Settings_Fields_Extended::get_image_models(),
			)
		);
	}

	/**
	 * Render the Pipeline Settings page.
	 *
	 * Capability check first; then delegate to the save handler (processes
	 * POST before output starts) and the renderer (assembles view data and
	 * includes the template).
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prautoblogger' ) );
		}

		// Process any pending save before output begins.
		$save_result = PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$renderer = new PRAutoBlogger_Pipeline_Settings_Renderer();
		$renderer->render( $save_result );
	}
}
