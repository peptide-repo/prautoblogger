<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Registers and renders the Article Dossier admin page (M2).
 *
 * The dossier is link-accessed -- it is NOT a visible submenu item. It uses
 * the canonical hidden-admin-page pattern: register under parent 'options.php'
 * so WordPress computes the hookname as `admin_page_prautoblogger-dossier` at
 * BOTH registration time AND request time. The page is hidden from nav by
 * construction (options.php is a built-in WP page with no visible submenu block)
 * so no $submenu mutation is needed or permitted.
 *
 * Why NOT the hide-by-unset pattern (the v0.19.2 bug):
 *   add_submenu_page('prautoblogger-settings', ...) → hookname 'prautoblogger_page_prautoblogger-dossier'
 *   post-registration unset($submenu['prautoblogger-settings'][$idx])
 *   → at request time get_admin_page_parent() no longer finds the slug in $submenu
 *   → WP recomputes hookname in the 'admin_page_*' orphan namespace
 *   → no callback registered there → wp_die(403).
 * See ARCHITECTURE.md §22c + CONVENTIONS.md §Hidden Admin Pages for the full incident
 * history (board 404 v0.19.1, dossier 403 v0.19.2).
 *
 * Board cards and post-metabox deep-link here via
 * admin.php?page=prautoblogger-dossier&post_id=X. Deep-link URLs are parent-agnostic
 * (they reference only the page slug) so they are unchanged by this fix.
 *
 * Design contract: Proposal C "Editorial Record" -- warm editorial dossier,
 * verdict pills, receipt-style cost sidebar, per-stage I/O with raw-trace toggle.
 *
 * Triggered by: PRAutoBlogger::register_admin_hooks() on `admin_menu` (priority 12).
 * Dependencies: PRAutoBlogger_Dossier_Data_Assembler, templates/admin/dossier-page.php.
 *
 * @see admin/class-dossier-data-assembler.php -- Builds the view model.
 * @see templates/admin/dossier-page.php       -- HTML template.
 * @see ARCHITECTURE.md                         -- §22c (hidden admin page convention).
 * @see CONVENTIONS.md                          -- §Hidden Admin Pages.
 */
class PRAutoBlogger_Dossier_Page {

	/** Admin page slug. */
	public const PAGE_SLUG = 'prautoblogger-dossier';

	/**
	 * Parent slug used at registration.
	 * 'options.php' is the canonical WP hidden-page parent: the hookname resolves
	 * to `admin_page_{slug}` at both registration time and request time, so there is
	 * no hookname mismatch and no need to manipulate $submenu.
	 */
	public const PARENT_SLUG = 'options.php';

	/** Nonce action for any future stateful actions. */
	public const NONCE_ACTION = 'prautoblogger_dossier';

	/**
	 * Register the dossier as a hidden admin page.
	 *
	 * Uses the canonical options.php-parent pattern so the hookname is stable at
	 * both registration time and request time. No $submenu manipulation required
	 * or permitted (see CONVENTIONS.md §Hidden Admin Pages).
	 *
	 * @return void
	 */
	public function on_register_menu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Article Dossier', 'prautoblogger' ),
			__( 'Dossier', 'prautoblogger' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
		// No $submenu mutation. options.php-parent pages are hidden by construction.
	}

	/**
	 * Render the dossier page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only dossier; no state changes.
		$post_id   = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$assembler = new PRAutoBlogger_Dossier_Data_Assembler();
		$dossier   = $assembler->assemble( $post_id );
		include PRAUTOBLOGGER_PLUGIN_DIR . 'templates/admin/dossier-page.php';
	}

	/**
	 * Enqueue dossier-specific CSS + JS.
	 *
	 * The hook suffix for an options.php-parent page is `admin_page_{slug}`.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function on_enqueue_assets( string $hook_suffix ): void {
		// options.php-parent pages resolve to 'admin_page_{slug}' (not 'prautoblogger_page_*').
		$dossier_hook = 'admin_page_' . self::PAGE_SLUG;
		if ( $hook_suffix !== $dossier_hook ) {
			return;
		}

		wp_enqueue_style(
			'prautoblogger-dossier',
			PRAUTOBLOGGER_PLUGIN_URL . 'assets/css/dossier.css',
			array( 'prautoblogger-admin' ),
			PRAUTOBLOGGER_VERSION
		);

		wp_enqueue_script(
			'prautoblogger-dossier',
			PRAUTOBLOGGER_PLUGIN_URL . 'assets/js/dossier.js',
			array(),
			PRAUTOBLOGGER_VERSION,
			true
		);
	}

	/**
	 * Build the URL to the dossier for a given post.
	 *
	 * Deep-link URLs use admin.php?page=<slug> which is parent-agnostic.
	 * This method is unchanged from v0.19.2 -- only the registration parent changed.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return string Admin URL to the dossier page.
	 */
	public static function url_for_post( int $post_id ): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&post_id=' . $post_id );
	}
}
