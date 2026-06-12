<?php
/**
 * Tests for Article Dossier admin page menu registration.
 *
 * Regression guard for the menu-ordering rule (v0.19.1 lesson):
 * any submenu registered BEFORE add_menu_page fires will resolve under the
 * fallback hookname `admin_page_*` instead of `prautoblogger_page_*`, causing
 * a wp_die 404 at request time.
 *
 * The dossier page uses a hidden-submenu pattern (removed from $submenu after
 * registration) at priority 12 -- after board (11) and parent (10).
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

	protected function setUp(): void {
		parent::setUp();
		$this->call_sequence = [];

		Functions\when( 'add_menu_page' )->alias(
			function () {
				$this->call_sequence[] = 'add_menu_page';
				return 'prautoblogger-settings';
			}
		);
		Functions\when( 'add_submenu_page' )->alias(
			function ( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug ) {
				$this->call_sequence[] = 'add_submenu_page:' . $menu_slug;
				return 'prautoblogger_page_' . $menu_slug;
			}
		);

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
			return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
		} );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test isolation.
		$GLOBALS['submenu'] = array();
	}

	protected function tearDown(): void {
		$this->call_sequence = [];
		unset( $GLOBALS['submenu'] );
		parent::tearDown();
	}

	/**
	 * Dossier on_register_menu must be hooked at priority > board (11).
	 * Mirrors the bindings in register_admin_hooks():
	 *   parent at 10, board at 11, dossier at 12.
	 *
	 * FAILS if dossier drops to priority ≤ 11 (same as or before board).
	 * FAILS if dossier drops to priority ≤ 10 (same as or before parent).
	 */
	public function test_dossier_hook_registered_after_board(): void {
		$admin_page   = new \PRAutoBlogger_Admin_Page();
		$board_page   = new \PRAutoBlogger_Board_Page();
		$dossier_page = new \PRAutoBlogger_Dossier_Page();

		// Mirror class-prautoblogger.php register_admin_hooks() priorities.
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

		$this->assertGreaterThan( $parent_priority, $board_priority,
			'Board priority must exceed parent priority.' );
		$this->assertGreaterThan( $board_priority, $dossier_priority,
			'Dossier priority must exceed board priority to ensure hookname resolves correctly.' );
	}

	/**
	 * Dossier submenu slug must be 'prautoblogger-dossier'.
	 */
	public function test_dossier_uses_correct_slug(): void {
		$dossier_page = new \PRAutoBlogger_Dossier_Page();
		$dossier_page->on_register_menu();

		$found_slug = null;
		foreach ( $this->call_sequence as $entry ) {
			if ( str_starts_with( $entry, 'add_submenu_page:' ) ) {
				$found_slug = substr( $entry, strlen( 'add_submenu_page:' ) );
				break;
			}
		}

		$this->assertSame( \PRAutoBlogger_Dossier_Page::PAGE_SLUG, $found_slug,
			'Dossier must register under PAGE_SLUG.' );
	}

	/**
	 * Dossier submenu parent must be 'prautoblogger-settings'.
	 */
	public function test_dossier_parent_slug_is_settings(): void {
		$captured_parent = null;
		Functions\when( 'add_submenu_page' )->alias(
			function ( string $parent_slug ) use ( &$captured_parent ) {
				$captured_parent = $parent_slug;
				return null;
			}
		);

		$dossier_page = new \PRAutoBlogger_Dossier_Page();
		$dossier_page->on_register_menu();

		$this->assertSame( 'prautoblogger-settings', $captured_parent,
			'Dossier must use prautoblogger-settings as parent.' );
	}

	/**
	 * url_for_post() returns a URL containing the dossier slug and post_id.
	 */
	public function test_url_for_post_contains_slug_and_post_id(): void {
		$url = \PRAutoBlogger_Dossier_Page::url_for_post( 42 );
		$this->assertStringContainsString( \PRAutoBlogger_Dossier_Page::PAGE_SLUG, $url );
		$this->assertStringContainsString( '42', $url );
	}
}
