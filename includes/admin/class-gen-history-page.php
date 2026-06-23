<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Registers and renders the Generation History admin page (M4).
 *
 * What: A browsable, paginated list of all previous generation runs —
 *   newest first, 20 per page. Each row shows the article title (when
 *   a post was produced), date, final run status, models used, total
 *   cost, and duration. "View dossier" links into the existing Article
 *   Dossier for full per-stage I/O detail (the dossier already renders
 *   input AND output for every stage). For failed/orphan runs (no post)
 *   a "Stage I/O" link opens the inline drill-down panel.
 *
 *   Uses the canonical options.php-parent hidden-page pattern so the
 *   hookname is stable at registration time and request time (same
 *   design as the Dossier page — see CONVENTIONS.md §Hidden Admin Pages).
 *
 *   Visible entry points:
 *     - PRAutoBlogger board "Generation History" link (nav added in M4).
 *     - PRAutoBlogger → Pipeline → History link (nav added in M4).
 *     - Direct URL: admin.php?page=prautoblogger-gen-history
 *
 * SECURITY: manage_options cap on render. AJAX drill-down uses a separate
 *   nonce (prautoblogger_gen_run_io) gated in Gen_Run_IO_Handler. The
 *   history list itself is read-only GET — no state changes. Output
 *   escaped in template via esc_html() / esc_url().
 *
 * Triggered by: PRAutoBlogger::register_admin_hooks() on `admin_menu` (priority 14).
 * Dependencies: PRAutoBlogger_Gen_History_Query (list data),
 *               PRAutoBlogger_Dossier_Page (dossier URL builder).
 *
 * @see admin/class-gen-history-query.php    -- List query + per-run I/O.
 * @see ajax/class-gen-run-io-handler.php    -- Per-run stage I/O AJAX.
 * @see admin/class-dossier-page.php         -- Dossier deep-link target.
 * @see templates/admin/gen-history-page.php -- HTML template.
 * @see ARCHITECTURE.md                       -- Admin pages table.
 * @see CONVENTIONS.md                        -- §Hidden Admin Pages.
 */
class PRAutoBlogger_Gen_History_Page {

	/** Admin page slug. */
	public const PAGE_SLUG = 'prautoblogger-gen-history';

	/**
	 * Canonical hidden-page parent (same pattern as Dossier_Page).
	 * Resolves hookname to `admin_page_{slug}` at both registration
	 * and request time — no $submenu manipulation required.
	 */
	public const PARENT_SLUG = 'options.php';

	/** Nonce action for the gen-run I/O AJAX. */
	public const IO_NONCE_ACTION = 'prautoblogger_gen_run_io';

	/**
	 * Register the history page as a hidden admin page.
	 *
	 * @return void
	 */
	public function on_register_menu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Generation History', 'prautoblogger' ),
			__( 'Generation History', 'prautoblogger' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the generation history list page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list page.
		$page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page = PRAutoBlogger_Gen_History_Query::PAGE_SIZE;

		$query   = new PRAutoBlogger_Gen_History_Query();
		$result  = $query->get_page( $page, $per_page );
		$rows    = $result['rows'];
		$total   = $result['total'];
		$pages   = max( 1, (int) ceil( $total / $per_page ) );

		$pagination = array(
			'current' => $page,
			'total'   => $pages,
			'count'   => $total,
			'base'    => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
		);

		include PRAUTOBLOGGER_PLUGIN_DIR . 'templates/admin/gen-history-page.php';
	}

	/**
	 * Enqueue assets for the generation history page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function on_enqueue_assets( string $hook_suffix ): void {
		if ( 'admin_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'prautoblogger-admin',
			PRAUTOBLOGGER_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			PRAUTOBLOGGER_VERSION
		);

		wp_enqueue_style(
			'prautoblogger-gen-history',
			PRAUTOBLOGGER_PLUGIN_URL . 'assets/css/gen-history.css',
			array( 'prautoblogger-admin' ),
			PRAUTOBLOGGER_VERSION
		);

		wp_enqueue_script(
			'prautoblogger-gen-history',
			PRAUTOBLOGGER_PLUGIN_URL . 'assets/js/gen-history.js',
			array(),
			PRAUTOBLOGGER_VERSION,
			true
		);

		wp_localize_script(
			'prautoblogger-gen-history',
			'prabGenHistory',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'action'   => 'prautoblogger_gen_run_io',
				'nonce'    => wp_create_nonce( self::IO_NONCE_ACTION ),
				'strings'  => array(
					'loading'    => __( 'Loading…', 'prautoblogger' ),
					'error'      => __( 'Failed to load stage I/O — try again.', 'prautoblogger' ),
					'noStages'   => __( 'No generation log entries for this run.', 'prautoblogger' ),
					'pruned'     => __( '[Output pruned by retention policy]', 'prautoblogger' ),
					'noOutput'   => __( '[No output stored for this stage]', 'prautoblogger' ),
					'noInput'    => __( '[No request payload stored for this stage]', 'prautoblogger' ),
				),
			)
		);
	}

	/**
	 * Build the URL to the generation history page.
	 *
	 * @param int $page Optional page number.
	 * @return string Admin URL.
	 */
	public static function url( int $page = 1 ): string {
		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		return $page > 1 ? $base . '&paged=' . $page : $base;
	}
}
