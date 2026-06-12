<?php
/**
 * Tests for Dossier admin page hookname faithfulness — request-time mechanism.
 *
 * ## Why the v0.19.2 hide-by-unset pattern caused a 403
 *
 * WordPress computes a page's hook suffix at TWO separate moments:
 *
 * 1. Registration time (inside add_submenu_page):
 *    WP resolves the parent slug against $admin_page_hooks. If found, the hookname
 *    becomes "{parent_key}_page_{slug}" (e.g. "prautoblogger_page_prautoblogger-dossier").
 *
 * 2. Request time (inside wp-admin/admin.php):
 *    WP calls get_admin_page_parent() which scans $submenu for the slug. If not found
 *    (because it was unset post-registration), it falls back to "admin_page_{slug}".
 *
 * When these two hooknames diverge, the render callback lives under name-A but WP
 * dispatches to name-B -- no handler found -- wp_die(403).
 *
 * The fix: options.php-parent guarantees "admin_page_{slug}" at BOTH moments.
 * No $submenu mutation is needed or permitted.
 *
 * ## Discriminator function: simulate_request_time_hookname
 *
 * Replicates get_admin_page_parent() + hookname derivation in wp-admin/admin.php.
 * Returns different hooknames for hide-by-unset vs options.php-parent, making the
 * 403 divergence mechanically verifiable in a unit test.
 *
 * Structural invariant tests (parent slug, URL shape, priority) live in
 * DossierMenuRegistrationTest.
 *
 * @see DossierMenuRegistrationTest -- structural invariants + deep-link URLs + priority.
 * @see ARCHITECTURE.md             -- §22b (hidden admin page convention).
 * @see CONVENTIONS.md              -- §Hidden Admin Pages.
 *
 * @package PRAutoBlogger\Tests\Admin
 */

namespace PRAutoBlogger\Tests\Admin;

class DossierHooknameMechanismTest extends DossierMenuRegistrationTestCase {

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
}
