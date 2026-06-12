<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Registers and renders the Article Dossier admin page (M2).
 *
 * The dossier is link-accessed -- it is NOT a visible submenu item. It registers
 * as a hidden submenu under 'prautoblogger-settings' at admin_menu priority 12
 * (after board at 11) so WordPress resolves the hookname correctly. Board cards and
 * post list columns deep-link here via ?page=prautoblogger-dossier&post_id=X.
 *
 * Design contract: Proposal C "Editorial Record" -- warm editorial dossier,
 * verdict pills, receipt-style cost sidebar, per-stage I/O with raw-trace toggle.
 *
 * Triggered by: PRAutoBlogger::register_admin_hooks() on `admin_menu` (priority 12).
 * Dependencies: PRAutoBlogger_Dossier_Data_Assembler, templates/admin/dossier-page.php.
 *
 * @see admin/class-dossier-data-assembler.php -- Builds the view model.
 * @see templates/admin/dossier-page.php       -- HTML template.
 * @see ARCHITECTURE.md                         -- §Dossier (M2).
 */
class PRAutoBlogger_Dossier_Page {

	/** Admin page slug. */
	public const PAGE_SLUG = 'prautoblogger-dossier';

	/** Nonce action for any future stateful actions. */
	public const NONCE_ACTION = 'prautoblogger_dossier';

	/**
	 * Register the dossier as a hidden submenu page.
	 *
	 * Hidden = not visible in the menu, but reachable by URL.
	 * Priority 12 ensures add_menu_page() (priority 10) has populated
	 * $admin_page_hooks before this add_submenu_page() call. See ARCHITECTURE.md §Board.
	 *
	 * @return void
	 */
	public function on_register_menu(): void {
		add_submenu_page(
			'prautoblogger-settings',
			__( 'Article Dossier', 'prautoblogger' ),
			__( 'Dossier', 'prautoblogger' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		// Remove from visible submenu so it doesn't clutter the nav.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional hidden-submenu pattern.
		global $submenu;
		if ( isset( $submenu['prautoblogger-settings'] ) ) {
			foreach ( $submenu['prautoblogger-settings'] as $idx => $item ) {
				if ( isset( $item[2] ) && self::PAGE_SLUG === $item[2] ) {
					unset( $submenu['prautoblogger-settings'][ $idx ] );
					break;
				}
			}
		}
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
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$assembler = new PRAutoBlogger_Dossier_Data_Assembler();
		$dossier   = $assembler->assemble( $post_id );
		include PRAUTOBLOGGER_PLUGIN_DIR . 'templates/admin/dossier-page.php';
	}

	/**
	 * Enqueue dossier-specific CSS + JS.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function on_enqueue_assets( string $hook_suffix ): void {
		$dossier_hook = 'prautoblogger_page_' . self::PAGE_SLUG;
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
	 * @param int $post_id WordPress post ID.
	 * @return string Admin URL to the dossier page.
	 */
	public static function url_for_post( int $post_id ): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&post_id=' . $post_id );
	}
}
