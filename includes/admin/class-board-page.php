<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Registers and renders the kanban board — the primary landing screen for PRAutoBlogger.
 *
 * Board columns: Generating | In Review | Published | Failed.
 * Cards live-update via AJAX polling at a settings-backed interval (default 5s),
 * backing off to 2× when no active generating run is detected.
 *
 * Card click-throughs (M1):
 *   - Generating → Activity Log filtered to run context
 *   - In Review  → Review Queue
 *   - Published  → Post edit screen
 *   - Failed     → Activity Log
 *
 * M2 will rewire all click-throughs to the Article Dossier page.
 *
 * Triggered by: PRAutoBlogger::register_admin_hooks() on `admin_menu`.
 * Dependencies: PRAutoBlogger_Board_Data_Provider, wp_localize_script.
 *
 * @see admin/class-board-data-provider.php — Supplies card data.
 * @see templates/admin/board-page.php      — HTML template.
 * @see assets/js/board.js                  — Polling + DOM updates.
 * @see ARCHITECTURE.md                     — §Board (kanban dashboard).
 */
class PRAutoBlogger_Board_Page {

	private const PAGE_SLUG = 'prautoblogger-board';

	/** AJAX action name for board polling. */
	public const AJAX_ACTION = 'prautoblogger_board_status';

	/** Nonce action string for board AJAX. */
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
			$items = $submenu['prautoblogger-settings'];
			// Find the Board entry and move it to position 0.
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
				// Prepend board item at position 0 while preserving remaining order.
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
	 * Enqueue board-specific JS + CSS and localize the poller config.
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
		wp_enqueue_script(
			'prautoblogger-board-generate',
			PRAUTOBLOGGER_PLUGIN_URL . 'assets/js/board-generate.js',
			array( 'jquery', 'prautoblogger-board' ),
			PRAUTOBLOGGER_VERSION,
			true
		);

		$poll_interval   = max( 3, (int) get_option( 'prautoblogger_board_poll_interval', 5 ) );
		$published_days  = max( 1, (int) get_option( 'prautoblogger_board_published_window_days', 7 ) );

		wp_localize_script(
			'prautoblogger-board',
			'prabBoard',
			array(
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'nonce'             => wp_create_nonce( self::NONCE_ACTION ),
				'generateNonce'     => wp_create_nonce( 'prautoblogger_generate_now' ),
				'pollInterval'      => $poll_interval * 1000,
				'publishedWindowDays' => $published_days,
				'action'            => self::AJAX_ACTION,
				'strings'           => array(
					'generating'   => __( 'Generating', 'prautoblogger' ),
					'inReview'     => __( 'In Review', 'prautoblogger' ),
					'published'    => __( 'Published', 'prautoblogger' ),
					'failed'       => __( 'Failed', 'prautoblogger' ),
					'empty'        => __( 'Nothing here yet.', 'prautoblogger' ),
					'cost'         => __( 'Cost', 'prautoblogger' ),
					'view'         => __( 'View', 'prautoblogger' ),
					'edit'         => __( 'Edit', 'prautoblogger' ),
					'viewLog'      => __( 'View Log', 'prautoblogger' ),
					'pollError'    => __( 'Board update failed — retrying.', 'prautoblogger' ),
					'humanModified'  => __( 'Human-modified', 'prautoblogger' ),
					'newArticle'     => __( 'New Article', 'prautoblogger' ),
					'generatingBtn'  => __( 'Generatingâ¦', 'prautoblogger' ),
					'genStarted'     => __( 'Generation started. Board will update automatically.', 'prautoblogger' ),
					'genError'       => __( 'Generation failed to start. Check the Activity Log.', 'prautoblogger' ),
				),
			)
		);
	}

	/**
	 * AJAX handler: return the current board snapshot (all four columns).
	 *
	 * Nonce: `prautoblogger_board`. Requires `manage_options` capability.
	 *
	 * Side effects: DB reads only — no writes.
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
