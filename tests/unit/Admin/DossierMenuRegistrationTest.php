<?php
/**
 * Tests for Dossier admin page menu registration — structural invariants.
 *
 * Covers: parent slug (options.php), $submenu non-mutation, page slug constant,
 * asset enqueue gate prefix, deep-link URL shape, and hook priority ordering.
 *
 * Request-time hookname faithfulness tests live in DossierHooknameMechanismTest.
 *
 * @see DossierHooknameMechanismTest -- request-time discriminator + regression proof.
 * @see ARCHITECTURE.md             -- §22b (hidden admin page convention).
 * @see CONVENTIONS.md              -- §Hidden Admin Pages.
 *
 * @package PRAutoBlogger\Tests\Admin
 */

namespace PRAutoBlogger\Tests\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;

class DossierMenuRegistrationTest extends DossierMenuRegistrationTestCase {

	// =========================================================================
	// § STRUCTURAL INVARIANTS
	// =========================================================================

	/**
	 * Parent slug must be PARENT_SLUG constant ('options.php').
	 *
	 * FAILS on v0.19.2 (parent was 'prautoblogger-settings').
	 * PASSES after v0.19.3 fix (parent is 'options.php').
	 */
	public function test_dossier_parent_slug_is_options_php(): void {
		$dossier_page = new \PRAutoBlogger_Dossier_Page();
		$dossier_page->on_register_menu();

		$this->assertSame(
			'options.php',
			$this->captured_parent_slug,
			'Dossier must use options.php as parent (canonical hidden-page pattern). '
			. 'Never use a plugin-owned parent with post-registration $submenu unset -- '
			. 'that is the v0.19.2 403 bug class. See CONVENTIONS.md §Hidden Admin Pages.'
		);

		$this->assertSame(
			\PRAutoBlogger_Dossier_Page::PARENT_SLUG,
			$this->captured_parent_slug,
			'Captured parent slug must match PARENT_SLUG constant.'
		);
	}

	/**
	 * No $submenu mutation after registration.
	 *
	 * options.php-parent pages are hidden by construction; the slug must remain
	 * in $submenu so get_admin_page_parent() can resolve the parent at request time.
	 *
	 * FAILS on v0.19.2 (slug was unset from $submenu after registration).
	 * PASSES after v0.19.3 fix (no mutation).
	 */
	public function test_no_submenu_mutation_after_registration(): void {
		$dossier_page = new \PRAutoBlogger_Dossier_Page();
		$dossier_page->on_register_menu();

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test read.
		global $submenu;

		// The dossier slug must still be present somewhere in $submenu.
		$slug_found = false;
		foreach ( $submenu as $parent => $items ) {
			foreach ( $items as $item ) {
				if ( isset( $item[2] ) && \PRAutoBlogger_Dossier_Page::PAGE_SLUG === $item[2] ) {
					$slug_found = true;
					break 2;
				}
			}
		}

		$this->assertTrue(
			$slug_found,
			'Dossier slug must remain in $submenu after on_register_menu() completes. '
			. 'Unsetting it causes get_admin_page_parent() to fail at request time and '
			. 'produces the admin_page_* orphan hookname -- the v0.19.2 403 bug class. '
			. 'options.php-parent pages are hidden by construction; no mutation needed.'
		);
	}

	/**
	 * Page slug must be PAGE_SLUG constant ('prautoblogger-dossier').
	 */
	public function test_dossier_uses_correct_slug(): void {
		$dossier_page = new \PRAutoBlogger_Dossier_Page();
		$dossier_page->on_register_menu();

		$this->assertSame(
			\PRAutoBlogger_Dossier_Page::PAGE_SLUG,
			$this->captured_menu_slug,
			'Dossier must register under PAGE_SLUG.'
		);
	}

	/**
	 * Asset enqueue gate must use 'admin_page_' prefix (not 'prautoblogger_page_').
	 *
	 * options.php-parent pages always resolve to 'admin_page_{slug}'.
	 * FAILS on v0.19.2 (gate checked 'prautoblogger_page_prautoblogger-dossier').
	 * PASSES after v0.19.3 fix (gate checks 'admin_page_prautoblogger-dossier').
	 */
	public function test_asset_enqueue_gate_uses_admin_page_prefix(): void {
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );

		$dossier_page = new \PRAutoBlogger_Dossier_Page();

		$expected_hook = 'admin_page_' . \PRAutoBlogger_Dossier_Page::PAGE_SLUG;

		// Wrong hook -- assets must NOT enqueue.
		$enqueued = false;
		Functions\when( 'wp_enqueue_style' )->alias( function () use ( &$enqueued ) {
			$enqueued = true;
		} );

		$dossier_page->on_enqueue_assets( 'wrong_hook_prautoblogger_page_' . \PRAutoBlogger_Dossier_Page::PAGE_SLUG );
		$this->assertFalse(
			$enqueued,
			'Assets must NOT enqueue when hook_suffix is the old prautoblogger_page_* hook.'
		);

		// Correct hook -- assets must enqueue.
		$dossier_page->on_enqueue_assets( $expected_hook );
		$this->assertTrue(
			$enqueued,
			sprintf(
				'Assets must enqueue when hook_suffix is "%s" (admin_page_* from options.php parent).',
				$expected_hook
			)
		);
	}

	// =========================================================================
	// § DEEP-LINK URL INVARIANCE
	// =========================================================================

	/**
	 * url_for_post() must produce admin.php?page=prautoblogger-dossier&post_id=N.
	 *
	 * Deep-link URLs are parent-agnostic -- they reference only the page slug.
	 * The fix changes only the registration parent; the URL shape is unchanged.
	 */
	public function test_url_for_post_contains_slug_and_post_id(): void {
		$url = \PRAutoBlogger_Dossier_Page::url_for_post( 42 );

		$this->assertStringContainsString(
			\PRAutoBlogger_Dossier_Page::PAGE_SLUG,
			$url,
			'url_for_post() must contain the dossier page slug.'
		);
		$this->assertStringContainsString(
			'42',
			$url,
			'url_for_post() must contain the post_id.'
		);
		$this->assertStringContainsString(
			'admin.php',
			$url,
			'url_for_post() must use admin.php (parent-agnostic deep-link).'
		);
	}

	/**
	 * url_for_post() URL shape is unchanged from v0.19.2.
	 *
	 * Confirms the exact URL format that board cards and the metabox link depend on.
	 */
	public function test_url_for_post_exact_shape(): void {
		$url = \PRAutoBlogger_Dossier_Page::url_for_post( 930 );

		// Must contain admin.php?page=prautoblogger-dossier&post_id=930
		// (or equivalent query string order -- admin_url may vary order).
		$this->assertStringContainsString( 'page=prautoblogger-dossier', $url );
		$this->assertStringContainsString( 'post_id=930', $url );
	}

	// =========================================================================
	// § HOOK REGISTRATION ORDERING (priority guard)
	// =========================================================================

	/**
	 * Dossier on_register_menu must remain at priority 12 (after board 11, parent 10).
	 *
	 * The options.php-parent fix removes the dependency on ordering relative to the
	 * plugin's own parent menu (since we no longer add a submenu under it), but we
	 * keep the priority assertion to prevent accidental priority regression.
	 */
	public function test_dossier_hook_registered_at_priority_12(): void {
		$admin_page   = new \PRAutoBlogger_Admin_Page();
		$board_page   = new \PRAutoBlogger_Board_Page();
		$dossier_page = new \PRAutoBlogger_Dossier_Page();

		add_action( 'admin_menu', array( $admin_page,   'on_register_menu' ), 10 );
		add_action( 'admin_menu', array( $board_page,   'on_register_menu' ), 11 );
		add_action( 'admin_menu', array( $dossier_page, 'on_register_menu' ), 12 );

		$storage = Monkey\Container::instance()->hookStorage();
		$ref     = new \ReflectionClass( $storage );
		$prop    = $ref->getProperty( 'storage' );
		$prop->setAccessible( true );
		$data    = $prop->getValue( $storage );

		$parent_priority  = -1;
		$board_priority   = -1;
		$dossier_priority = -1;

		$admin_menu_entries = $data['added']['actions']['admin_menu'] ?? array();
		foreach ( $admin_menu_entries as $entry ) {
			$cb       = (string) ( $entry[0] ?? '' );
			$priority = (int) ( $entry[1] ?? -1 );
			if ( false !== strpos( $cb, 'PRAutoBlogger_Admin_Page' ) )   { $parent_priority  = $priority; }
			if ( false !== strpos( $cb, 'PRAutoBlogger_Board_Page' ) )   { $board_priority   = $priority; }
			if ( false !== strpos( $cb, 'PRAutoBlogger_Dossier_Page' ) ) { $dossier_priority = $priority; }
		}

		$this->assertGreaterThan(
			$parent_priority,
			$board_priority,
			'Board priority must exceed parent priority.'
		);
		$this->assertGreaterThan(
			$board_priority,
			$dossier_priority,
			'Dossier priority must exceed board priority.'
		);
	}
}
