<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Main orchestrator for the PRAutoBlogger plugin.
 *
 * Registers all WordPress hooks (actions and filters) and wires up dependencies.
 * This is the ONLY place hooks are registered -- individual classes contain only
 * business logic, not hook registration.
 *
 * Triggered by: prautoblogger() singleton in prautoblogger.php, called on `plugins_loaded`.
 * Dependencies: All other plugin classes (loaded via autoloader), PRAutoBlogger_Executor.
 *
 * @see prautoblogger.php                     -- Plugin bootstrap that instantiates this class.
 * @see class-executor.php                    -- Cron/AJAX handlers for generation, model registry.
 * @see class-ajax-handlers.php               -- Non-generation AJAX endpoints (images, models, test).
 * @see class-generation-lock.php             -- DB mutex for single-writer generation.
 * @see core/class-pipeline-runner.php         -- Executes the generation pipeline.
 * @see ARCHITECTURE.md                        -- Data flow diagram showing how classes interact.
 * @see CONVENTIONS.md                         -- Hook naming conventions.
 */
class PRAutoBlogger {

	private bool $initialized = false;

	/** @var PRAutoBlogger_Executor Handles generation cron/AJAX, model registry. */
	private PRAutoBlogger_Executor $executor;

	/** @var PRAutoBlogger_Ajax_Handlers Handles non-generation AJAX (images, models, test). */
	private PRAutoBlogger_Ajax_Handlers $ajax_handlers;

	/**
	 * Register all hooks and initialize the plugin.
	 * Called once on `plugins_loaded`. Idempotent.
	 *
	 * Side effects: registers WordPress actions and filters.
	 */
	public function run(): void {
		if ( $this->initialized ) {
			return;
		}
		$this->initialized   = true;
		$this->executor      = new PRAutoBlogger_Executor();
		$this->ajax_handlers = new PRAutoBlogger_Ajax_Handlers( $this->executor->get_model_registry() );

		add_action( 'admin_init', array( $this, 'on_check_db_version' ) );
		// v0.18.0: cron requests must also self-heal the schema -- after a
		// deploy, scheduled generation can run before any admin page load,
		// and new-column inserts would silently fail on the old schema.
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

	/** Register admin-only hooks (settings, notices, metabox, dashboard widget). */
	private function register_admin_hooks(): void {
		// Parent top-level menu first (priority 10), then Board submenu (priority 11).
		// WHY: WordPress computes the page hookname via get_plugin_page_hookname() at
		// add_submenu_page() time. If add_menu_page() has not yet fired, the parent
		// slot in $admin_page_hooks is unset and WP falls back to the `admin_page_*`
		// hookname. At request time WP recomputes the hookname as `prautoblogger_page_*`
		// and finds no registered callback -> wp_die 404. Fixing priority ensures the
		// parent slot is populated before the submenu call. See ARCHITECTURE.md §Board.
		$admin_page = new PRAutoBlogger_Admin_Page();
		add_action( 'admin_menu', array( $admin_page, 'on_register_menu' ), 10 );
		add_action( 'admin_init', array( $admin_page, 'on_register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $admin_page, 'on_enqueue_assets' ) );

		// Board at priority 11 -- fires AFTER add_menu_page, so hookname is correct.
		$board_page = new PRAutoBlogger_Board_Page();
		add_action( 'admin_menu', array( $board_page, 'on_register_menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $board_page, 'on_enqueue_assets' ) );

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

		// Block false update notifications -- our slug collides with another plugin.
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

		// Manual "Generate Now" runs as a one-shot cron event to avoid
		// Hostinger's 120-second web-server connection timeout.
		add_action( 'prautoblogger_manual_generation', array( $this->executor, 'on_manual_generation' ) );

		// Chained article generation -- each queued article fires as its own
		// cron event so it gets a fresh PHP process and execution time budget.
		add_action(
			PRAutoBlogger_Pipeline_Runner::CRON_ACTION,
			array( $this->executor, 'on_process_article_queue' )
		);

		$registry = $this->executor->get_model_registry();
		add_action( 'prautoblogger_refresh_model_registry', array( $registry, 'refresh' ) );
		// v0.15.0: daily Runware model catalog sync to keep the live model list fresh.
		add_action( 'prautoblogger_sync_runware_models', array( new PRAutoBlogger_Runware_Model_Catalog(), 'sync' ) );

		// Opik observability: async trace/span dispatch.
		add_action(
			'prautoblogger_opik_dispatch',
			static function (): void {
				if ( ! defined( 'PRAUTOBLOGGER_OPIK_API_KEY' ) || empty( PRAUTOBLOGGER_OPIK_API_KEY ) ) {
					return;
				}
				$client = new PRAutoBlogger_Opik_Client(
					PRAUTOBLOGGER_OPIK_API_KEY,
					PRAUTOBLOGGER_OPIK_WORKSPACE,
					PRAUTOBLOGGER_OPIK_URL_OVERRIDE
				);
				$queue = new PRAutoBlogger_Opik_Span_Queue();
				$dispatcher = new PRAutoBlogger_Opik_Dispatcher( $client, $queue );
				$dispatcher->dispatch();
				update_option( 'prautoblogger_opik_last_dispatch', time() );
			}
		);

		// Opik settings registration.
		if ( is_admin() ) {
			new PRAutoBlogger_Opik_Settings();
		}

		// Single-idea generation from the Ideas browser page.
		add_action( 'prautoblogger_generate_from_idea', array( 'PRAutoBlogger_Ideas_Browser', 'on_cron_generate_from_idea' ) );

		// v0.8.1: daily reaper for orphan `llm_research` rows (when a
		// pipeline dies before amortize_research_costs runs).
		add_action( 'prautoblogger_reap_orphan_research_rows', array( 'PRAutoBlogger_Research_Reaper', 'on_cron' ) );

		// v0.18.0: stuck-run sweep + audit-payload retention rides the SAME
		// existing daily cron (no new schedule). See class-run-reaper.php.
		add_action( 'prautoblogger_reap_orphan_research_rows', array( 'PRAutoBlogger_Run_Reaper', 'on_cron' ) );

		// v0.8.1: WP-CLI manual trigger for the reaper. Only registers when
		// WP-CLI is present; no-op in normal HTTP requests.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command(
				'prautoblogger reap-research',
				static function (): void {
					$stats = PRAutoBlogger_Research_Reaper::reap();
					\WP_CLI::success(
						sprintf(
							'Reaped %d, deleted %d (stale), skipped %d.',
							(int) $stats['reaped'],
							(int) $stats['deleted'],
							(int) $stats['skipped']
						)
					);
				}
			);
		}
	}

	/** Register AJAX handlers for admin actions. */
	private function register_ajax_hooks(): void {
		// Generation AJAX (start + status polling) stays on executor.
		add_action( 'wp_ajax_prautoblogger_generate_now', array( $this->executor, 'on_ajax_generate_now' ) );
		add_action( 'wp_ajax_prautoblogger_generation_status', array( $this->executor, 'on_ajax_generation_status' ) );

		// Non-generation AJAX (images, models, test) on dedicated handler.
		add_action( 'wp_ajax_prautoblogger_generate_image', array( $this->ajax_handlers, 'on_ajax_generate_image' ) );
		add_action( 'wp_ajax_prautoblogger_test_connection', array( $this->ajax_handlers, 'on_ajax_test_connection' ) );
		add_action( 'wp_ajax_prautoblogger_get_models', array( $this->ajax_handlers, 'on_ajax_get_models' ) );
		add_action( 'wp_ajax_prautoblogger_refresh_models', array( new PRAutoBlogger_Model_Registry_Refresh( $this->ajax_handlers->get_registry() ), 'handle' ) );
		add_action( 'wp_ajax_prautoblogger_sync_runware_models_now', array( $this->ajax_handlers, 'on_ajax_sync_runware_models_now' ) );

		$review_queue = new PRAutoBlogger_Review_Queue();
		add_action( 'wp_ajax_prautoblogger_approve_post', array( $review_queue, 'on_ajax_approve_post' ) );
		add_action( 'wp_ajax_prautoblogger_reject_post', array( $review_queue, 'on_ajax_reject_post' ) );

		add_action( 'wp_ajax_prautoblogger_clear_logs', array( new PRAutoBlogger_Log_Viewer(), 'on_ajax_clear_logs' ) );

		// Board status polling AJAX (nonce: prautoblogger_board).
		add_action(
			'wp_ajax_' . PRAutoBlogger_Board_Page::AJAX_ACTION,
			array( new PRAutoBlogger_Board_Page(), 'on_ajax_board_status' )
		);

		$ideas = new PRAutoBlogger_Ideas_Browser();
		add_action( 'wp_ajax_prautoblogger_generate_from_idea', array( $ideas, 'on_ajax_generate_from_idea' ) );
		add_action( 'wp_ajax_prautoblogger_idea_gen_status', array( $ideas, 'on_ajax_idea_gen_status' ) );
	}

	/**
	 * Check if database needs migration after plugin update.
	 * Compares stored DB version with PRAUTOBLOGGER_DB_VERSION; re-runs activation if different.
	 *
	 * Side effects: may update database schema and db_version option.
	 */
	public function on_check_db_version(): void {
		$stored_version = get_option( 'prautoblogger_db_version', '0' );
		if ( version_compare( $stored_version, PRAUTOBLOGGER_DB_VERSION, '<' ) ) {
			PRAutoBlogger_Activator::activate();
		}

		// One-time migration: switch to Gemini 2.5 Flash Lite for cost/speed.
		if ( ! get_option( 'prautoblogger_migrated_gemini_flash_lite' ) ) {
			update_option( 'prautoblogger_analysis_model', PRAUTOBLOGGER_DEFAULT_ANALYSIS_MODEL );
			update_option( 'prautoblogger_writing_model', PRAUTOBLOGGER_DEFAULT_WRITING_MODEL );
			update_option( 'prautoblogger_editor_model', PRAUTOBLOGGER_DEFAULT_EDITOR_MODEL );
			update_option( 'prautoblogger_migrated_gemini_flash_lite', '1' );
		}

		// One-time migration (v0.8.0): the admin no longer has an independent
		// Image Provider dropdown; provider is derived from the image model on
		// save. Auto-heal any existing site where the saved provider doesn't
		// match the saved model's provider (the root cause of posts 650/657
		// silently missing their featured image on 2026-04-20). Runs once.
		if ( ! get_option( 'prautoblogger_migrated_image_provider_v080' ) ) {
			$saved_model = (string) get_option( 'prautoblogger_image_model', '' );
			$provider    = PRAutoBlogger_Image_Model_Registry::provider_for( $saved_model );
			if ( '' !== $provider ) {
				update_option( 'prautoblogger_image_provider', $provider );
			}
			update_option( 'prautoblogger_migrated_image_provider_v080', '1' );
		}

		// One-time migration (v0.8.2): reschedule the daily-generation cron in
		// the site's configured timezone. Pre-v0.8.2 activator interpreted the
		// admin "Generation Time" input as UTC; after v0.8.2 it honours the
		// site timezone. Clears the stale UTC-scheduled event and re-queues
		// using the timezone-aware helper. See class-activator.php.
		PRAutoBlogger_Activator::reschedule_daily_in_site_timezone_v082();

		// One-time migration (v3): switch to single-panel newspaper comic style.
		// Replaces both the old infomercial pastiche and the short-lived premium
		// photography style. Force-update unless the user has a truly custom value.
		if ( ! get_option( 'prautoblogger_migrated_style_suffix_v3' ) ) {
			$known_old_prefixes = array(
				'Style: a screengrab from a 1995',       // v1: infomercial.
				'Style: premium scientific lifestyle',    // v2: photography.
			);
			$current            = get_option( 'prautoblogger_image_style_suffix', '' );
			$is_old             = ( '' === $current );
			foreach ( $known_old_prefixes as $prefix ) {
				if ( false !== strpos( $current, $prefix ) ) {
					$is_old = true;
					break;
				}
			}
			if ( $is_old ) {
				update_option( 'prautoblogger_image_style_suffix', PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_SUFFIX );
			}
			update_option( 'prautoblogger_migrated_style_suffix_v3', '1' );
		}
		// One-time migration (v4): remove caption-in-image instruction from style suffix.
		// Captions are now inserted as HTML below the image, not baked into the image.
		if ( ! get_option( 'prautoblogger_migrated_style_suffix_v4' ) ) {
			$current = get_option( 'prautoblogger_image_style_suffix', '' );
			// Detect v3 style suffix (contains "caption text" instruction).
			if ( false !== strpos( $current, 'caption text in a clean sans-serif font below the panel' ) ) {
				update_option( 'prautoblogger_image_style_suffix', PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_SUFFIX );
			}
			update_option( 'prautoblogger_migrated_style_suffix_v4', '1' );
		}

		// One-time migration (v0.16.0): editorial pivot. The comic Style Suffix
		// is replaced by the editorial Style Template. Mirror the old suffix
		// value into a deprecated-keyed option for one version cycle (so a
		// customised value is recoverable / auditable) and seed the new
		// template with its editorial default. The old option is intentionally
		// NOT deleted this cycle. See docs/proposals/2026-05-29-image-pipeline-in-plugin-brief.md paragraph 8 Commit 1.
		if ( ! get_option( 'prautoblogger_migrated_style_template_v0160' ) ) {
			$old_suffix = get_option( 'prautoblogger_image_style_suffix', '' );
			if ( '' !== $old_suffix && false === get_option( 'prautoblogger_image_style_suffix_deprecated', false ) ) {
				update_option( 'prautoblogger_image_style_suffix_deprecated', $old_suffix );
			}
			// Only seed the new template if the admin has not already set one.
			if ( '' === (string) get_option( 'prautoblogger_image_style_template', '' ) ) {
				update_option( 'prautoblogger_image_style_template', PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_TEMPLATE );
			}
			update_option( 'prautoblogger_migrated_style_template_v0160', '1' );
		}

		// v0.9.0 -- Runware as default image model. v0.10.0 -- remove CF Workers AI.
		PRAutoBlogger_Activator::migrate_default_image_v090();
		PRAutoBlogger_Migrate_Remove_Cloudflare_V0100::run();

		// One-time migration: re-wrap existing encrypted values with "enc:" prefix.
		if ( ! get_option( 'prautoblogger_migrated_enc_prefix' ) ) {
			$enc_options = array( 'prautoblogger_openrouter_api_key', 'prautoblogger_ga4_credentials_json', 'prautoblogger_runware_api_key' );
			foreach ( $enc_options as $opt ) {
				$val = get_option( $opt, '' );
				if ( '' !== $val && ! PRAutoBlogger_Encryption::is_encrypted( $val ) ) {
					delete_option( $opt );
				}
			}
			update_option( 'prautoblogger_migrated_enc_prefix', '1' );
		}
	}

	/**
	 * Run schema migrations during cron requests.
	 *
	 * Wraps on_check_db_version() so it only fires during WP-Cron execution.
	 * After a deploy, the daily generation cron can fire before any admin page
	 * load -- without this, new-column inserts would silently fail.
	 *
	 * Side effects: may run the same migrations as on_check_db_version().
	 *
	 * @return void
	 */
	public function on_check_db_version_for_cron(): void {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			$this->on_check_db_version();
		}
	}

	/**
	 * Add custom cron schedules (six-hourly for metrics collection).
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
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
	 * Our plugin slug may collide with a different plugin in the directory.
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
