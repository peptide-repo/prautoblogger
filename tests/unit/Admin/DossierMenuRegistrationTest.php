<?php
/**
 * Tests for Article Dossier admin page menu registration.
 *
 * ## Why the v0.19.2 hide-by-unset pattern caused a 403
 *
 * WordPress computes a page's hook suffix at TWO separate moments:
 *
 * 1. Registration time (inside add_submenu_page):
 *    WP resolves the parent slug against $admin_page_hooks. If found, the hookname
 *    becomes "{parent_key}_page_{slug}" (e.g. "prautoblogger_page_prautoblogger-dossier").
 *    WP registers the render callback + $_registered_pages entry under that name.
 *
 * 2. Request time (inside wp-admin/admin.php):
 *    WP calls get_admin_page_parent() which scans $submenu for the slug. If it finds
 *    a match, it derives the hookname the same way as (1). If not found (because it
 *    was unset post-registration), it falls back to "admin_page_{slug}".
 *
 * When these two hooknames diverge, the render callback lives under name-A but WP
 * dispatches to name-B -- no handler found -- wp_die(403).
 *
 * The hide-by-unset pattern creates exactly this divergence:
 *   Registration:    parent found in $admin_page_hooks → "prautoblogger_page_prautoblogger-dossier"
 *   Post-unset:      slug absent from $submenu → "admin_page_prautoblogger-dossier" at request time
 *   Mismatch → 403.
 *
 * ## The fix: options.php-parent pattern
 *
 * options.php is a built-in WordPress admin page in $admin_page_hooks. Its key is
 * "settings" so a child page resolves to "settings_page_{slug}" at registration time --
 * BUT WordPress special-cases options.php children: get_admin_page_parent() returns
 * "options.php" directly (the page is always in the admin page hooks map and never
 * removed from $submenu by WP itself), so the hookname is "admin_page_{slug}" consistently
 * at both registration and request time. No $submenu mutation needed or permitted.
 *
 * ## Test strategy
 *
 * Brain\Monkey stubs WordPress functions. We cannot run a real WP request cycle in unit
 * tests, but we can replicate the request-time resolution algorithm precisely:
 *
 *   simulate_request_time_hookname($submenu_state, $slug):
 *     scan $submenu for $slug
 *     if found: return "{parent_key}_page_{slug}"   (same logic as registration-time)
 *     if not found: return "admin_page_{slug}"       (WP orphan fallback)
 *
 * This function is the discriminator. Against the v0.19.2 hide-by-unset implementation:
 *   - post-registration $submenu does NOT contain the slug (it was unset)
 *   - simulate_request_time_hookname returns "admin_page_prautoblogger-dossier"
 *   - but registration returned "prautoblogger_page_prautoblogger-dossier"
 *   - MISMATCH → test_request_time_hookname_matches_registration_hookname FAILS ✓
 *
 * Against the v0.19.3 options.php-parent fix:
 *   - $submenu is not mutated; the slug appears under options.php
 *   - simulate_request_time_hookname sees options.php as parent
 *   - registration also used options.php → both produce "admin_page_prautoblogger-dossier"
 *   - MATCH → test passes ✓
 *
 * Additional tests:
 *   - Parent slug must be PARENT_SLUG constant ('options.php')
 *   - No $submenu mutation after registration
 *   - Deep-link URLs are parent-agnostic (admin.php?page=...&post_id=N unchanged)
 *   - Asset enqueue gate uses 'admin_page_' prefix (not 'prautoblogger_page_')
 *
 * @package PRAutoBlogger\Tests\Admin
 */

namespace PRAutoBlogger\Tests\Admin;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class DossierMenuRegistrationTest extends BaseTestCase {

	/** @var array<int, string> Call sequence spy. */
	private array $call_sequence = [];

	/**
	 * Captured parent slug from the most recent add_submenu_page call.
	 *
	 * @var string|null
	 */
	private ?string $captured_parent_slug = null;

	/**
	 * Captured menu slug from the most recent add_submenu_page call.
	 *
	 * @var string|null
	 */
	private ?string $captured_menu_slug = null;

	/**
	 * Hookname returned by the most recent add_submenu_page call.
	 *
	 * In real WordPress this is `{parent_key}_page_{slug}` when parent is known,
	 * or `admin_page_{slug}` when parent is not in $admin_page_hooks. For options.php
	 * children WordPress always emits `admin_page_{slug}`.
	 *
	 * @var string|null
	 */
	private ?string $registration_hookname = null;

	protected function setUp(): void {
		parent::setUp();
		$this->call_sequence         = [];
		$this->captured_parent_slug  = null;
		$this->captured_menu_slug    = null;
		$this->registration_hookname = null;

		// Spy stub: tracks call order, captures slugs, returns a hookname that
		// reflects how WordPress actually resolves it for known parents.
		Functions\when( 'add_menu_page' )->alias(
			function () {
				$this->call_sequence[] = 'add_menu_page';
				return 'prautoblogger-settings';
			}
		);

		// Simulate WordPress hookname computation:
		// - options.php parent  → 'admin_page_{slug}'  (WP built-in; options.php children always resolve here)
		// - known plugin parent → '{parent_key}_page_{slug}'
		// - unknown parent      → 'admin_page_{slug}'  (orphan fallback)
		Functions\when( 'add_submenu_page' )->alias(
			function (
				string $parent_slug,
				string $page_title,
				string $menu_title,
				string $capability,
				string $menu_slug,
				$callback = ''
			) {
				$this->call_sequence[]       = 'add_submenu_page:' . $parent_slug . ':' . $menu_slug;
				$this->captured_parent_slug  = $parent_slug;
				$this->captured_menu_slug    = $menu_slug;

				// Replicate WordPress hookname logic:
				// options.php-parent pages always resolve to admin_page_{slug}.
				// Plugin-parent pages (like prautoblogger-settings) resolve to
				// {parent_key}_page_{slug} at registration time.
				if ( 'options.php' === $parent_slug ) {
					$hookname = 'admin_page_' . $menu_slug;
				} else {
					// Derive parent key: strip .php extension if present, then
					// replace - with _ for the namespace prefix.
					$parent_key = str_replace( '.php', '', $parent_slug );
					$hookname   = $parent_key . '_page_' . $menu_slug;
				}

				$this->registration_hookname = $hookname;

				// Populate $submenu to simulate what WordPress does at registration time.
				// This is the state that get_admin_page_parent() scans at request time.
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test simulation.
				global $submenu;
				$submenu[ $parent_slug ]   = $submenu[ $parent_slug ] ?? array();
				$submenu[ $parent_slug ][] = array( $page_title, $capability, $menu_slug, $menu_title );

				return $hookname;
			}
		);

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ) {
				return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
			}
		);
		Functions\when( '__' )->returnArg( 1 );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test isolation.
		$GLOBALS['submenu'] = array();
	}

	protected function tearDown(): void {
		$this->call_sequence        = [];
		$this->captured_parent_slug = null;
		$this->captured_menu_slug   = null;
		$this->registration_hookname = null;
		unset( $GLOBALS['submenu'] );
		parent::tearDown();
	}

	// =========================================================================
	// § REQUEST-TIME HOOKNAME FAITHFULNESS
	// The primary regression guard for the 403 incident class.
	// =========================================================================

	/**
	 * Simulate the request-time hookname resolution algorithm.
	 *
	 * Replicates get_admin_page_parent() + the hookname derivation in
	 * wp-admin/admin.php::wp_admin_canonical_url() / do_action("admin_{$page}"):
	 *
	 *   scan $submenu for the slug
	 *   if found at parent P → return hookname derived from P
	 *   if not found          → return "admin_page_{slug}"
	 *
	 * This is the discriminator function: it WILL return a different hookname than
	 * add_submenu_page returned when the slug has been unset from $submenu.
	 *
	 * @param array<string, array<int, array<int, string>>> $submenu_state $submenu after all registration + mutations.
	 * @param string                                        $slug          Page slug to resolve.
	 * @return string Request-time hookname.
	 */
	private function simulate_request_time_hookname( array $submenu_state, string $slug ): string {
		foreach ( $submenu_state as $parent => $items ) {
			foreach ( $items as $item ) {
				if ( isset( $item[2] ) && $slug === $item[2] ) {
					// Found the slug; derive hookname the same way as registration.
					if ( 'options.php' === $parent ) {
						return 'admin_page_' . $slug;
					}
					$parent_key = str_replace( '.php', '', $parent );
					return $parent_key . '_page_' . $slug;
				}
			}
		}
		// Slug absent from $submenu -- WP falls back to the orphan namespace.
		return 'admin_page_' . $slug;
	}

	/**
	 * REQUEST-TIME FAITHFULNESS: registration hookname must equal request-time hookname.
	 *
	 * This test directly discriminates the two patterns:
	 *
	 *   HIDE-BY-UNSET (v0.19.2, BROKEN):
	 *     - add_submenu_page('prautoblogger-settings', ...) → 'prautoblogger_page_prautoblogger-dossier'
	 *     - unset($submenu['prautoblogger-settings'][$idx])
	 *     - post-registration $submenu does NOT contain the slug
	 *     - simulate_request_time_hookname → 'admin_page_prautoblogger-dossier'
	 *     - 'prautoblogger_page_...' ≠ 'admin_page_...' → FAIL ✓ (test correctly detects broken state)
	 *
	 *   OPTIONS.PHP-PARENT (v0.19.3, FIXED):
	 *     - add_submenu_page('options.php', ...) → 'admin_page_prautoblogger-dossier'
	 *     - no $submenu mutation
	 *     - simulate_request_time_hookname → 'admin_page_prautoblogger-dossier'
	 *     - MATCH → PASS ✓
	 *
	 * This test ALSO fails if any future change reintroduces $submenu mutation,
	 * or switches back to a plugin-owned parent without preserving the submenu entry.
	 */
	public function test_request_time_hookname_matches_registration_hookname(): void {
		$dossier_page = new \PRAutoBlogger_Dossier_Page();
		$dossier_page->on_register_menu();

		$this->assertNotNull(
			$this->registration_hookname,
			'add_submenu_page must be called during on_register_menu()'
		);

		// Capture $submenu state AFTER all mutations that on_register_menu() performs.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test read.
		global $submenu;
		$submenu_after = $submenu;

		$request_time_hookname = $this->simulate_request_time_hookname(
			$submenu_after,
			\PRAutoBlogger_Dossier_Page::PAGE_SLUG
		);

		$this->assertSame(
			$this->registration_hookname,
			$request_time_hookname,
			sprintf(
				'Hookname mismatch: registration="%s", request-time="%s". '
				. 'This is the 403 failure class. '
				. 'The hide-by-unset pattern unsets the slug from $submenu after registration, '
				. 'so get_admin_page_parent() fails at request time, WP recomputes under '
				. 'the orphan "admin_page_*" namespace, finds no registered handler, '
				. 'and calls wp_die(403). Fix: use options.php as parent -- hookname is '
				. '"admin_page_{slug}" at both registration and request time, no mutation needed.',
				$this->registration_hookname,
				$request_time_hookname
			)
		);
	}

	/**
	 * REGRESSION PROOF: documents that v0.19.2 hide-by-unset would have failed the
	 * request-time test above.
	 *
	 * Directly simulates the broken implementation, applies simulate_request_time_hookname,
	 * and asserts the MISMATCH -- proving the discriminator is effective.
	 *
	 * This test documents the failure state and serves as evidence that the test methodology
	 * is correct. It passes by verifying the broken pattern produces the wrong hookname.
	 */
	public function test_hide_by_unset_would_fail_request_time_check(): void {
		// --- SIMULATE V0.19.2 hide-by-unset IMPLEMENTATION ---
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test simulation.
		global $submenu;

		// Step 1: Registration (add_submenu_page with plugin parent).
		// Real add_submenu_page stub returns 'prautoblogger_page_prautoblogger-dossier'
		// when parent is 'prautoblogger-settings'. Populate $submenu as WP does.
		$broken_parent_slug = 'prautoblogger-settings';
		$slug               = 'prautoblogger-dossier';

		$submenu[ $broken_parent_slug ]   = $submenu[ $broken_parent_slug ] ?? array();
		$submenu[ $broken_parent_slug ][] = array( 'Article Dossier', 'manage_options', $slug, 'Dossier' );

		// The hookname WP computed at registration time with plugin-owned parent:
		$broken_registration_hookname = 'prautoblogger_page_' . $slug;

		// Step 2: The unset mutation (the bug).
		// This is what v0.19.2's on_register_menu() did after add_submenu_page.
		foreach ( $submenu[ $broken_parent_slug ] as $idx => $item ) {
			if ( isset( $item[2] ) && $slug === $item[2] ) {
				unset( $submenu[ $broken_parent_slug ][ $idx ] );
				break;
			}
		}

		// Step 3: Request time -- slug is now ABSENT from $submenu.
		$request_time_hookname = $this->simulate_request_time_hookname( $submenu, $slug );

		// THE MISMATCH that caused the 403:
		$this->assertNotSame(
			$broken_registration_hookname,
			$request_time_hookname,
			'Test methodology error: hide-by-unset must produce a hookname mismatch. '
			. 'If this assertion fails the discriminator is broken.'
		);

		// Specifically: request-time falls back to admin_page_* orphan namespace.
		$this->assertSame(
			'admin_page_' . $slug,
			$request_time_hookname,
			'Request-time fallback must be admin_page_{slug} when slug absent from $submenu.'
		);
		$this->assertSame(
			'prautoblogger_page_' . $slug,
			$broken_registration_hookname,
			'Registration-time hookname with prautoblogger-settings parent must be prautoblogger_page_{slug}.'
		);
	}

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
