<?php
/**
 * Shared test harness for Dossier menu registration test suites.
 *
 * Provides the Brain\Monkey spy stubs (add_menu_page, add_submenu_page) and the
 * $submenu isolation that both DossierMenuRegistrationTest and
 * DossierHooknameMechanismTest depend on.
 *
 * @package PRAutoBlogger\Tests\Admin
 */

namespace PRAutoBlogger\Tests\Admin;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

abstract class DossierMenuRegistrationTestCase extends BaseTestCase {

	/** @var array<int, string> Call sequence spy. */
	protected array $call_sequence = [];

	/**
	 * Captured parent slug from the most recent add_submenu_page call.
	 *
	 * @var string|null
	 */
	protected ?string $captured_parent_slug = null;

	/**
	 * Captured menu slug from the most recent add_submenu_page call.
	 *
	 * @var string|null
	 */
	protected ?string $captured_menu_slug = null;

	/**
	 * Hookname returned by the most recent add_submenu_page call.
	 *
	 * In real WordPress this is `{parent_key}_page_{slug}` when parent is known,
	 * or `admin_page_{slug}` when parent is not in $admin_page_hooks. For options.php
	 * children WordPress always emits `admin_page_{slug}`.
	 *
	 * @var string|null
	 */
	protected ?string $registration_hookname = null;

	protected function setUp(): void {
		parent::setUp();
		$this->call_sequence         = [];
		$this->captured_parent_slug  = null;
		$this->captured_menu_slug    = null;
		$this->registration_hookname = null;

		// v0.20.0: the dossier enqueue path localizes the edit/re-run config.
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		Functions\when( 'absint' )->alias( static function ( $val ) { return abs( (int) $val ); } );

		// Spy stub: tracks call order, captures slugs, returns a hookname that
		// reflects how WordPress actually resolves it for known parents.
		Functions\when( 'add_menu_page' )->alias(
			function () {
				$this->call_sequence[] = 'add_menu_page';
				return 'prautoblogger-settings';
			}
		);

		// Simulate WordPress hookname computation:
		// - options.php parent  → 'admin_page_{slug}'
		// - known plugin parent → '{parent_key}_page_{slug}'
		// - unknown parent      → 'admin_page_{slug}' (orphan fallback)
		Functions\when( 'add_submenu_page' )->alias(
			function (
				string $parent_slug,
				string $page_title,
				string $menu_title,
				string $capability,
				string $menu_slug,
				$callback = ''
			) {
				$this->call_sequence[]      = 'add_submenu_page:' . $parent_slug . ':' . $menu_slug;
				$this->captured_parent_slug = $parent_slug;
				$this->captured_menu_slug   = $menu_slug;

				if ( 'options.php' === $parent_slug ) {
					$hookname = 'admin_page_' . $menu_slug;
				} else {
					$parent_key = str_replace( '.php', '', $parent_slug );
					$hookname   = $parent_key . '_page_' . $menu_slug;
				}

				$this->registration_hookname = $hookname;

				// Populate $submenu as WordPress does at registration time.
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
		$this->call_sequence         = [];
		$this->captured_parent_slug  = null;
		$this->captured_menu_slug    = null;
		$this->registration_hookname = null;
		unset( $GLOBALS['submenu'] );
		parent::tearDown();
	}
}
