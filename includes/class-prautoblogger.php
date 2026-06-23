<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Main orchestrator for the PRAutoBlogger plugin.
 *
 * Registers all WordPress hooks and wires up dependencies.
 * DB/option migrations extracted to PRAutoBlogger_DB_Migrations (v0.19.2).
 *
 * @see prautoblogger.php             -- Plugin bootstrap.
 * @see class-db-migrations.php       -- One-time DB/option migrations.
 * @see ARCHITECTURE.md               -- Data flow diagram.
 */
class PRAutoBlogger {

	private bool $initialized = false;

	/** @var PRAutoBlogger_Executor Handles generation cron/AJAX, model registry. */
	private PRAutoBlogger_Executor $executor;

	/** @var PRAutoBlogger_Ajax_Handlers Handles non-generation AJAX. */
	private PRAutoBlogger_Ajax_Handlers $ajax_handlers;

	/**
	 * Register all hooks and initialize the plugin.
	 * Called once on `plugins_loaded`. Idempotent.
	 */
	public function run(): void {
		if ( $this->initialized ) {
			return;
		}
		$this->initialized   = true;
		$this->executor      = new PRAutoBlogger_Executor();
		$this->ajax_handlers = new PRAutoBlogger_Ajax_Handlers( $this->executor->get_model_registry() );

		add_action( 'admin_init', array( $this, 'on_check_db_version' ) );
		// v0.18.0: cron requests must also self-heal the schema.
		add_action( 'init', array( $this, 'on_check_db_version_for_cron' ) );
		add_filter( 'cron_schedules', array( $this, 'filter_add_cron_schedules' ) );

		if ( is_admin() ) {
			$this->register_admin_hooks();
		}

		$this->register_cron_hooks();
		$this->register_frontend_hooks();
		$this->register_ajax_hooks();
		$this->register_cli_hooks();

		/** Fires after PRAutoBlogger has finished registering all hooks. */
		do_action( 'prautoblogger_loaded' );
	}

	/**
	 * Register admin-only hooks (settings, notices, metabox, board, dossier, widget).
	 *
	 * Menu-ordering rule (v0.19.1): parent menu must register at priority 10 before
	 * any submenu (priority 11+). WordPress resolves the submenu hookname via
	 * get_plugin_page_hookname() at add_submenu_page() time; if the parent slot in
	 * $admin_page_hooks is unset, WP falls back to admin_page_* -> 404 at request
	 * time. See ARCHITECTURE.md §Board for the full trace.
	 */
	private function register_admin_hooks(): void {
		$admin_page = new PRAutoBlogger_Admin_Page();
		add_action( 'admin_menu', array( $admin_page, 'on_register_menu' ), 10 );
		add_action( 'admin_init', array( $admin_page, 'on_register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $admin_page, 'on_enqueue_assets' ) );

		$board_page = new PRAutoBlogger_Board_Page();
		add_action( 'admin_menu', array( $board_page, 'on_register_menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $board_page, 'on_enqueue_assets' ) );

		// Pipeline Settings: visible submenu, priority 13 (after dossier).
		$pipeline_page = new PRAutoBlogger_Pipeline_Settings_Page();
		add_action( 'admin_menu', array( $pipeline_page, 'on_register_menu' ), 13 );
		add_action( 'admin_enqueue_scripts', array( $pipeline_page, 'on_enqueue_assets' ) );

		// Dossier: link-accessed hidden submenu, priority 12 (after board).
		$dossier_page = new PRAutoBlogger_Dossier_Page();
		add_action( 'admin_menu', array( $dossier_page, 'on_register_menu' ), 12 );
		add_action( 'admin_enqueue_scripts', array( $dossier_page, 'on_enqueue_assets' ) );

		add_action( 'admin_notices', array( new PRAutoBlogger_Admin_Notices(), 'on_display_notices' ) );
		add_action( 'add_meta_boxes', array( new PRAutoBlogger_Post_Metabox(), 'on_register_metabox' ) );
		add_action( 'admin_menu', array( new PRAutoBlogger_Metrics_Page(), 'on_register_menu' ) );
		add_action( 'wp_dashboard_setup', array( new PRAutoBlogger_Dashboard_Widget(), 'on_register_widget' ) );

		$review_queue = new PRAutoBlogger_Review_Queue();
		add_action( 'admin_menu', array( $review_queue, 'on_register_menu' ) );

		$ideas_browser = new PRAutoBlogger_Ideas_Browser();
		add_action( 'admin_menu', array( $ideas_browser, 'on_register_menu' ) );
		add_action( 'admin_menu', array( new PRAutoBlogger_Log_Viewer(), 'on_register_menu' ) );

		( new PRAutoBlogger_Post_List_Columns() )->register();

		add_filter( 'site_transient_update_plugins', array( $this, 'filter_block_false_updates' ) );
	}

	/** Register frontend hooks (shortcode, REST, typography). */
	private function register_frontend_hooks(): void {
		$posts_widget = new PRAutoBlogger_Posts_Widget();
		add_action( 'init', array( $posts_widget, 'on_register_shortcode' ) );
		add_action( 'rest_api_init', array( $posts_widget, 'on_register_rest_route' ) );

		$typography = new PRAutoBlogger_Article_Typography();
		add_action( 'wp_head', array( $typography, 'on_wp_head' ) );
		add_action( 'wp_enqueue_scripts', array( $typography, 'on_enqueue_fonts' ) );
		add_filter( 'the_content', array( $typography, 'on_wrap_tables' ), 99 );
	}

	/** Register cron-triggered hooks for scheduled generation and metrics. */
	private function register_cron_hooks(): void {
		add_action( 'prautoblogger_daily_generation', array( $this->executor, 'on_daily_generation' ) );
		add_action( 'prautoblogger_collect_metrics', array( $this->executor, 'on_collect_metrics' ) );
		add_action( 'prautoblogger_manual_generation', array( $this->executor, 'on_manual_generation' ) );
		add_action(
			PRAutoBlogger_Pipeline_Runner::CRON_ACTION,
			array( $this->executor, 'on_process_article_queue' )
		);

		$registry = $this->executor->get_model_registry();
		add_action( 'prautoblogger_refresh_model_registry', array( $registry, 'refresh' ) );
		add_action( 'prautoblogger_sync_runware_models', array( new PRAutoBlogger_Runware_Model_Catalog(), 'sync' ) );

		add_action(
			'prautoblogger_opik_dispatch',
			static function (): void {
				if ( ! defined( 'PRAUTOBLOGGER_OPIK_API_KEY' ) || empty( PRAUTOBLOGGER_OPIK_API_KEY ) ) {
					return;
				}
				$client     = new PRAutoBlogger_Opik_Client(
					PRAUTOBLOGGER_OPIK_API_KEY,
					PRAUTOBLOGGER_OPIK_WORKSPACE,
					PRAUTOBLOGGER_OPIK_URL_OVERRIDE
				);
				$dispatcher = new PRAutoBlogger_Opik_Dispatcher( $client, new PRAutoBlogger_Opik_Span_Queue() );
				$dispatcher->dispatch();
				update_option( 'prautoblogger_opik_last_dispatch', time() );
			}
		);

		if ( is_admin() ) {
			new PRAutoBlogger_Opik_Settings();
		}

		// v0.20.0 (M3): operator re-run jobs — chained-cron, never synchronous.
		$rerun_executor = new PRAutoBlogger_Rerun_Executor();
		add_action( PRAutoBlogger_Rerun_Executor::REPLAY_ACTION, array( $rerun_executor, 'on_replay_job' ), 10, 5 );
		add_action( PRAutoBlogger_Rerun_Executor::REBUILD_ACTION, array( $rerun_executor, 'on_rebuild_job' ), 10, 4 );

		// v0.21.0 (M4): chained-cron checkpoint ticks for new article generation.
		add_action( PRAutoBlogger_Generation_Checkpoint_Runner::ORCHESTRATE_ACTION, array( 'PRAutoBlogger_Generation_Checkpoint_Runner', 'on_orchestrate_tick' ) );
		add_action( PRAutoBlogger_Generation_Checkpoint_Runner::GENERATE_ACTION, array( 'PRAutoBlogger_Generation_Checkpoint_Runner', 'on_generate_tick' ) );

		add_action( 'prautoblogger_generate_from_idea', array( 'PRAutoBlogger_Ideas_Browser', 'on_cron_generate_from_idea' ) );
		add_action( 'prautoblogger_reap_orphan_research_rows', array( 'PRAutoBlogger_Research_Reaper', 'on_cron' ) );
		add_action( 'prautoblogger_reap_orphan_research_rows', array( 'PRAutoBlogger_Run_Reaper', 'on_cron' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command(
				'prautoblogger reap-research',
				static function (): void {
					$stats = PRAutoBlogger_Research_Reaper::reap();
					\WP_CLI::success( sprintf( 'Reaped %d, deleted %d, skipped %d.', (int) $stats['reaped'], (int) $stats['deleted'], (int) $stats['skipped'] ) );
				}
			);
		}
	}

	/** Register AJAX handlers for admin actions. */
	private function register_ajax_hooks(): void {
		add_action( 'wp_ajax_prautoblogger_generate_now', array( $this->executor, 'on_ajax_generate_now' ) );
		add_action( 'wp_ajax_prautoblogger_generation_status', array( $this->executor, 'on_ajax_generation_status' ) );

		add_action( 'wp_ajax_prautoblogger_generate_image', array( $this->ajax_handlers, 'on_ajax_generate_image' ) );
		add_action( 'wp_ajax_prautoblogger_test_connection', array( $this->ajax_handlers, 'on_ajax_test_connection' ) );
		add_action( 'wp_ajax_prautoblogger_get_models', array( $this->ajax_handlers, 'on_ajax_get_models' ) );
		add_action( 'wp_ajax_prautoblogger_refresh_models', array( new PRAutoBlogger_Model_Registry_Refresh( $this->ajax_handlers->get_registry() ), 'handle' ) );
		add_action( 'wp_ajax_prautoblogger_sync_runware_models_now', array( $this->ajax_handlers, 'on_ajax_sync_runware_models_now' ) );

		$review_queue = new PRAutoBlogger_Review_Queue();
		add_action( 'wp_ajax_prautoblogger_approve_post', array( $review_queue, 'on_ajax_approve_post' ) );
		add_action( 'wp_ajax_prautoblogger_reject_post', array( $review_queue, 'on_ajax_reject_post' ) );

		add_action( 'wp_ajax_prautoblogger_clear_logs', array( new PRAutoBlogger_Log_Viewer(), 'on_ajax_clear_logs' ) );
		add_action( 'wp_ajax_' . PRAutoBlogger_Board_Page::AJAX_ACTION, array( new PRAutoBlogger_Board_Page(), 'on_ajax_board_status' ) );

		// v0.20.0 (M3): dossier edit + re-run endpoints (validate + queue only).
		$dossier_actions = new PRAutoBlogger_Dossier_Actions();
		add_action( 'wp_ajax_prautoblogger_dossier_save_input', array( $dossier_actions, 'on_save_input' ) );
		add_action( 'wp_ajax_prautoblogger_dossier_rerun_stage', array( $dossier_actions, 'on_rerun_stage' ) );
		add_action( 'wp_ajax_prautoblogger_dossier_rerun_from', array( $dossier_actions, 'on_rerun_from' ) );
		add_action( 'wp_ajax_prautoblogger_dossier_stage_status', array( $dossier_actions, 'on_stage_status' ) );

		$ideas = new PRAutoBlogger_Ideas_Browser();
		add_action( 'wp_ajax_prautoblogger_generate_from_idea', array( $ideas, 'on_ajax_generate_from_idea' ) );
		add_action( 'wp_ajax_prautoblogger_idea_gen_status', array( $ideas, 'on_ajax_idea_gen_status' ) );

		// v0.25.0 (M3): pipeline prompt preview + history/diff endpoints.
		PRAutoBlogger_Pipeline_Preview_Handler::register_hooks();
		PRAutoBlogger_Pipeline_History_Handler::register_hooks();
	}

	/**
	 * Check if database needs migration. Delegates to PRAutoBlogger_DB_Migrations::run().
	 * Hooked on `admin_init`.
	 */
	public function on_check_db_version(): void {
		PRAutoBlogger_DB_Migrations::run();
	}

	/**
	 * Run DB migrations during cron requests (hooked on `init`).
	 * Only fires when wp_doing_cron() is true.
	 */
	public function on_check_db_version_for_cron(): void {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			$this->on_check_db_version();
		}
	}

	/**
	 * Add custom cron schedules (six-hourly for metrics collection).
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function filter_add_cron_schedules( array $schedules ): array {
		$schedules['prautoblogger_six_hours'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every Six Hours', 'prautoblogger' ),
		);
		return $schedules;
	}

	/** Register WP-CLI commands. */
	private function register_cli_hooks(): void {
		PRAutoBlogger_WP_CLI_Commands::register();
	}

	/**
	 * Block false update notifications from wordpress.org.
	 *
	 * @param object $transient The update_plugins transient data.
	 * @return object Modified transient.
	 */
	public function filter_block_false_updates( $transient ) {
		if ( isset( $transient->response[ PRAUTOBLOGGER_PLUGIN_BASENAME ] ) ) {
			unset( $transient->response[ PRAUTOBLOGGER_PLUGIN_BASENAME ] );
		}
		return $transient;
	}

	/** Expose the executor for external access (e.g., model registry). */
	public function get_executor(): PRAutoBlogger_Executor {
		return $this->executor;
	}

	// -- Backward-compatible proxies --

	/** @see PRAutoBlogger_Executor::on_daily_generation() */
	public function on_daily_generation(): void {
		$this->executor->on_daily_generation(); }

	/** @see PRAutoBlogger_Executor::on_collect_metrics() */
	public function on_collect_metrics(): void {
		$this->executor->on_collect_metrics(); }

	/** @see PRAutoBlogger_Executor::on_ajax_generate_now() */
	public function on_ajax_generate_now(): void {
		$this->executor->on_ajax_generate_now(); }

	/** @see PRAutoBlogger_Ajax_Handlers::on_ajax_test_connection() */
	public function on_ajax_test_connection(): void {
		$this->ajax_handlers->on_ajax_test_connection(); }

	/** @see PRAutoBlogger_Executor::get_model_registry() */
	public function get_model_registry(): PRAutoBlogger_OpenRouter_Model_Registry {
		return $this->executor->get_model_registry(); }
}
