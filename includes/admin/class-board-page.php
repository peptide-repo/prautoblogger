<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Registers and renders the Mission Brief board -- the primary landing screen.
 *
 * M5 (v0.27.0): replaces the kanban column layout with a vertical run list
 * grouped by status section (Generating | In Review | Published | Failed),
 * plus a persistent right-rail inspector. Selecting a row fetches full per-stage
 * I/O via PRAutoBlogger_Board_Inspector_Handler (AJAX) and populates the rail
 * without navigating away. The dossier remains the deep-dive destination.
 *
 * Board capabilities preserved from M4:
 *   - New Article action (links to Ideas Browser).
 *   - Per-run dossier deep-link (click row or rail "Open dossier" button).
 *   - Status sections with counts.
 *   - Live AJAX polling with backoff (prautoblogger_board_status).
 *   - Published-window filter (prautoblogger_board_published_window_days).
 *   - Poll-interval setting (prautoblogger_board_poll_interval).
 *   - Human-modified badge.
 *   - Stalled/failed rows read RED (never softened).
 *   - Error banner on poll failure.
 *   - Empty state per section.
 *
 * Triggered by: PRAutoBlogger::register_admin_hooks() on `admin_menu`.
 * Dependencies: PRAutoBlogger_Board_Data_Provider, PRAutoBlogger_Board_Inspector_Handler,
 *               wp_localize_script.
 *
 * @see admin/class-board-data-provider.php    -- Supplies run-list snapshot.
 * @see ajax/class-board-inspector-handler.php -- Inspector AJAX (per-run stage I/O).
 * @see templates/admin/board-page.php         -- HTML template (Mission Brief layout).
 * @see assets/js/board.js                     -- Polling + inspector rail JS.
 * @see ARCHITECTURE.md                        -- §Board (M5 Mission Brief).
 */
class PRAutoBlogger_Board_Page {

	private const PAGE_SLUG = 'prautoblogger-board';

	/** AJAX action name for board polling. */
	public const AJAX_ACTION = 'prautoblogger_board_status';

	/** Nonce action string for board AJAX (polling + inspector share this nonce). */
	public const NONCE_ACTION = 'prautoblogger_board';

	/**
	 * Register the Board as the PRIMARY (first) submenu item, overriding the
	 * implicit Settings landing page. The trick is: WordPress auto-generates a
	 * submenu duplicate of the top-level page; we name it "Board" so the
	 * first click goes to the board, not the raw settings page.
	 *
	 * Registration order note: this method is hooked at admin_menu priority 11
	 * so that PRAutoBlogger_Admin_Page::on_register_menu() (priority 10) has
	 * already called add_menu_page(). This ensures WordPress has populated
	 * $admin_page_hooks['prautoblogger-settings'] before add_submenu_page()
	 * runs here, so get_plugin_page_hookname() resolves to the correct
	 * 'prautoblogger_page_prautoblogger-board' suffix rather than the fallback
	 * 'admin_page_prautoblogger-board'. See ARCHITECTURE.md §Board.
	 *
	 * @return void
	 */
	public function on_register_menu(): void {
		// Override the auto-created "PRAutoBlogger" submenu entry with "Board".
		add_submenu_page(
			'prautoblogger-settings',
			__( 'PRAutoBlogger Board', 'prautoblogger' ),
			__( 'Board', 'prautoblogger' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		// Reorder submenu so Board is the FIRST entry (primary click target).
		// WordPress auto-appended a duplicate of the top-level page as submenu
		// index 0; our Board was appended at index 1. We swap them so the
		// top-level PRAutoBlogger click lands on the Board page, not Settings.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional submenu reorder.
		global $submenu;
		if ( isset( $submenu['prautoblogger-settings'] ) ) {
			$items     = $submenu['prautoblogger-settings'];
			$board_idx = null;
			foreach ( $items as $i => $item ) {
				if ( isset( $item[2] ) && self::PAGE_SLUG === $item[2] ) {
					$board_idx = $i;
					break;
				}
			}
			if ( null !== $board_idx && $board_idx > 0 ) {
				$board_item = $items[ $board_idx ];
				unset( $items[ $board_idx ] );
				$submenu['prautoblogger-settings'] = array_merge( array( $board_item ), array_values( $items ) );
			}
		}
	}

	/**
	 * Render the board page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include PRAUTOBLOGGER_PLUGIN_DIR . 'templates/admin/board-page.php';
	}

	/**
	 * Enqueue board-specific JS + CSS and localize the poller + inspector config.
	 *
	 * Called via `admin_enqueue_scripts` action, gated to this page's hook suffix.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function on_enqueue_assets( string $hook_suffix ): void {
		$board_hook = 'prautoblogger_page_' . self::PAGE_SLUG;
		if ( $hook_suffix !== $board_hook ) {
			return;
		}

		wp_enqueue_style(
			'prautoblogger-board',
			PRAUTOBLOGGER_PLUGIN_URL . 'assets/css/board.css',
			array( 'prautoblogger-admin' ),
			PRAUTOBLOGGER_VERSION
		);

		wp_enqueue_script(
			'prautoblogger-board',
			PRAUTOBLOGGER_PLUGIN_URL . 'assets/js/board.js',
			array( 'jquery' ),
			PRAUTOBLOGGER_VERSION,
			true
		);

		$poll_interval  = max( 3, (int) get_option( 'prautoblogger_board_poll_interval', 5 ) );
		$published_days = max( 1, (int) get_option( 'prautoblogger_board_published_window_days', 7 ) );

		wp_localize_script(
			'prautoblogger-board',
			'prabBoard',
			array(
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'nonce'               => wp_create_nonce( self::NONCE_ACTION ),
				'pollInterval'        => $poll_interval * 1000,
				'publishedWindowDays' => $published_days,
				'action'              => self::AJAX_ACTION,
				'inspectorAction'     => PRAutoBlogger_Board_Inspector_Handler::ACTION,
				'ideasUrl'            => admin_url( 'admin.php?page=prautoblogger-ideas' ),
				'strings'             => array(
					'generating'      => __( 'Generating', 'prautoblogger' ),
					'inReview'        => __( 'In review', 'prautoblogger' ),
					'published'       => __( 'Published', 'prautoblogger' ),
					'failed'          => __( 'Failed', 'prautoblogger' ),
					'stalled'         => __( 'Stalled', 'prautoblogger' ),
					'empty'           => __( 'Nothing here yet.', 'prautoblogger' ),
					'cost'            => __( 'Cost', 'prautoblogger' ),
					'view'            => __( 'View', 'prautoblogger' ),
					'edit'            => __( 'Edit', 'prautoblogger' ),
					'viewLog'         => __( 'View log', 'prautoblogger' ),
					'pollError'       => __( 'Board update failed — retrying.', 'prautoblogger' ),
					'humanModified'   => __( 'Human-modified', 'prautoblogger' ),
					'inspectorEmpty'  => __( 'Select an article to preview its pipeline.', 'prautoblogger' ),
					'inspectorLoad'   => __( 'Loading…', 'prautoblogger' ),
					'inspectorError'  => __( 'Could not load run details.', 'prautoblogger' ),
					'openDossier'     => __( 'Open dossier →', 'prautoblogger' ),
					'newArticle'      => __( 'New article', 'prautoblogger' ),
					'input'           => __( 'Input', 'prautoblogger' ),
					'output'          => __( 'Output', 'prautoblogger' ),
					'pruned'          => __( '[pruned — past retention window]', 'prautoblogger' ),
					'noOutput'        => __( '[no output recorded]', 'prautoblogger' ),
					'totalCost'       => __( 'Total cost', 'prautoblogger' ),
					'tokens'          => __( 'tok', 'prautoblogger' ),
				),
			)
		);
	}

	/**
	 * AJAX handler: return the current board snapshot (all four sections).
	 *
	 * Nonce: `prautoblogger_board`. Requires `manage_options` capability.
	 *
	 * Side effects: DB reads only -- no writes.
	 *
	 * @return void
	 */
	public function on_ajax_board_status(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ), 403 );
			return;
		}

		$provider = new PRAutoBlogger_Board_Data_Provider();
		$snapshot = $provider->get_board_snapshot();

		wp_send_json_success( $snapshot );
	}
}
