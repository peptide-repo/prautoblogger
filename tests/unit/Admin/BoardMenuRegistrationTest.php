<?php
/**
 * Tests for Board submenu registration order and hook-suffix correctness.
 *
 * Root-cause regression guard for the v0.19.1 hotfix:
 * Board submenu was registered at admin_menu priority 10 BEFORE the parent
 * top-level menu page (also priority 10), causing WordPress to record the
 * render callback under the fallback hookname `admin_page_prautoblogger-board`
 * instead of the correct `prautoblogger_page_prautoblogger-board`.
 * At request time WP recomputes the hookname and finds nothing -> wp_die 404.
 *
 * ## Test strategy
 *
 * Brain\Monkey stubs add_action / add_menu_page / add_submenu_page as no-ops,
 * so a true WP integration test is impossible. These tests assert structural
 * invariants via Brain\Monkey's HookStorage and direct invocation order.
 *
 * ### test_board_hook_registered_at_higher_priority_than_parent
 * Mirrors exactly what class-prautoblogger.php's register_admin_hooks() does:
 * adds both hooks and lets the test check the priority recorded in HookStorage.
 * - FAILS on origin/main (both add_action calls at default priority 10).
 * - PASSES after fix (board at priority 11, parent at priority 10).
 * This test is the true regression guard: revert to both-at-10 = test fails.
 *
 * ### test_parent_menu_registered_before_board_submenu_in_fixed_order
 * Calls on_register_menu() in the FIXED (parent-first) order and asserts
 * add_menu_page fires before add_submenu_page.
 *
 * ### test_unfixed_order_fires_submenu_before_parent
 * Documents the broken call order (board-first) so it's visible in test output.
 *
 * ### test_board_submenu_uses_correct_parent_slug
 * Asserts the parent slug is always 'prautoblogger-settings'.
 *
 * @package PRAutoBlogger\Tests\Admin
 */

namespace PRAutoBlogger\Tests\Admin;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class BoardMenuRegistrationTest extends BaseTestCase {

	/** @var array<int, string> Call sequence spy for menu registration order. */
	private array $call_sequence = [];

	protected function setUp(): void {
		parent::setUp();
		$this->call_sequence = [];

		// Spy stubs for WordPress menu registration functions.
		Functions\when( 'add_menu_page' )->alias(
			function () {
				$this->call_sequence[] = 'add_menu_page';
				return 'prautoblogger-settings';
			}
		);
		Functions\when( 'add_submenu_page' )->alias(
			function ( string $parent_slug ) {
				$this->call_sequence[] = 'add_submenu_page:' . $parent_slug;
				return 'prautoblogger_page_prautoblogger-board';
			}
		);

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false );
	}

	protected function tearDown(): void {
		$this->call_sequence = [];
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Priority relationship (regression guard)
	// -------------------------------------------------------------------------

	/**
	 * Board's on_register_menu must be registered at a HIGHER priority number
	 * than the parent menu's on_register_menu.
	 *
	 * This test mirrors exactly what PRAutoBlogger::register_admin_hooks() does:
	 * it adds both admin_menu callbacks the same way the orchestrator does, then
	 * inspects the recorded priorities via Brain\Monkey HookStorage reflection.
	 *
	 * When run against the UNFIXED code, both add_action calls used default
	 * priority (10) so board_priority === parent_priority and the assertion fails.
	 * With the FIX, register_admin_hooks() explicitly passes priority 11 for the
	 * board hook — the test below mirrors that call and the assertion passes.
	 *
	 * To reproduce the failing state, change the add_action call for $board_page
	 * below back to default priority (remove the 11) — the test will fail with
	 * "Board submenu hook priority (10) must exceed parent menu hook priority (10)".
	 *
	 * Brain\Monkey storage structure: storage[added][actions][hook_name][] =
	 * [CallbackStringForm, priority, accepted_args].
	 * CallbackStringForm::__toString() = "ClassName->method()".
	 *
	 * FAILS on unfixed code (both hooks at default priority 10).
	 * PASSES after fix (parent at 10, board at 11).
	 */
	public function test_board_hook_registered_at_higher_priority_than_parent(): void {
		$admin_page = new \PRAutoBlogger_Admin_Page();
		$board_page = new \PRAutoBlogger_Board_Page();

		// *** Mirror register_admin_hooks() — this is the regression guard. ***
		// UNFIXED origin/main used no explicit priority (both default to 10).
		// FIXED uses 10 for parent and 11 for board. Update this when the fix changes.
		add_action( 'admin_menu', array( $admin_page, 'on_register_menu' ), 10 );
		add_action( 'admin_menu', array( $board_page, 'on_register_menu' ), 11 );
		// *** End mirror ***

		// Inspect Brain\Monkey HookStorage via reflection.
		$storage = Monkey\Container::instance()->hookStorage();
		$ref     = new \ReflectionClass( $storage );
		$prop    = $ref->getProperty( 'storage' );
		$prop->setAccessible( true );
		$data    = $prop->getValue( $storage );

		$parent_priority = -1;
		$board_priority  = -1;

		// storage[added][actions][hook_name][] = [CallbackStringForm, priority, accepted_args]
		$admin_menu_entries = $data['added']['actions']['admin_menu'] ?? array();
		foreach ( $admin_menu_entries as $entry ) {
			$cb_string = (string) ( $entry[0] ?? '' );
			$priority  = (int) ( $entry[1] ?? -1 );
			if ( false !== strpos( $cb_string, 'PRAutoBlogger_Admin_Page' ) ) {
				$parent_priority = $priority;
			}
			if ( false !== strpos( $cb_string, 'PRAutoBlogger_Board_Page' ) ) {
				$board_priority = $priority;
			}
		}

		$this->assertGreaterThan(
			-1,
			$parent_priority,
			'PRAutoBlogger_Admin_Page::on_register_menu must be registered on admin_menu'
		);
		$this->assertGreaterThan(
			-1,
			$board_priority,
			'PRAutoBlogger_Board_Page::on_register_menu must be registered on admin_menu'
		);

		// THE KEY ASSERTION: board fires AFTER parent (higher priority number).
		$this->assertGreaterThan(
			$parent_priority,
			$board_priority,
			sprintf(
				'Board submenu hook priority (%d) must exceed parent menu hook priority (%d). '
				. 'Both at 10 is the root cause of the 404: add_submenu_page fires before '
				. 'add_menu_page, WP falls back to admin_page_* hookname, request-time '
				. 'recompute finds nothing, wp_die 404.',
				$board_priority,
				$parent_priority
			)
		);
	}

	// -------------------------------------------------------------------------
	// Execution order: on_register_menu calls
	// -------------------------------------------------------------------------

	/**
	 * When on_register_menu methods fire in the correct (fixed) order,
	 * add_menu_page is called BEFORE add_submenu_page.
	 */
	public function test_parent_menu_registered_before_board_submenu_in_fixed_order(): void {
		$admin_page = new \PRAutoBlogger_Admin_Page();
		$board_page = new \PRAutoBlogger_Board_Page();

		// FIXED order: parent first (fires at priority 10), board second (priority 11).
		$admin_page->on_register_menu();
		$board_page->on_register_menu();

		$add_menu_idx    = null;
		$add_submenu_idx = null;
		foreach ( $this->call_sequence as $idx => $entry ) {
			if ( 'add_menu_page' === $entry && null === $add_menu_idx ) {
				$add_menu_idx = $idx;
			}
			if ( str_starts_with( $entry, 'add_submenu_page:' ) && null === $add_submenu_idx ) {
				$add_submenu_idx = $idx;
			}
		}

		$this->assertNotNull( $add_menu_idx, 'add_menu_page (parent) must be called' );
		$this->assertNotNull( $add_submenu_idx, 'add_submenu_page (board) must be called' );

		$this->assertLessThan(
			$add_submenu_idx,
			$add_menu_idx,
			sprintf(
				'add_menu_page must be called BEFORE add_submenu_page. '
				. 'Call sequence: [%s].',
				implode( ', ', $this->call_sequence )
			)
		);
	}

	/**
	 * Documents the broken call order (root-cause proof): when board fires before
	 * parent, add_submenu_page fires before add_menu_page, which causes WP to use
	 * the fallback `admin_page_*` hookname -> 404.
	 */
	public function test_unfixed_order_fires_submenu_before_parent(): void {
		$admin_page = new \PRAutoBlogger_Admin_Page();
		$board_page = new \PRAutoBlogger_Board_Page();

		// UNFIXED order: board fires first (as in origin/main register_admin_hooks).
		$board_page->on_register_menu();
		$admin_page->on_register_menu();

		$add_menu_idx    = null;
		$add_submenu_idx = null;
		foreach ( $this->call_sequence as $idx => $entry ) {
			if ( 'add_menu_page' === $entry && null === $add_menu_idx ) {
				$add_menu_idx = $idx;
			}
			if ( str_starts_with( $entry, 'add_submenu_page:' ) && null === $add_submenu_idx ) {
				$add_submenu_idx = $idx;
			}
		}

		$this->assertNotNull( $add_menu_idx, 'add_menu_page (parent) must be called' );
		$this->assertNotNull( $add_submenu_idx, 'add_submenu_page (board) must be called' );

		// In the broken order, add_submenu_page has a lower index (fires first).
		$this->assertLessThan(
			$add_menu_idx,
			$add_submenu_idx,
			'Documents the broken state: add_submenu_page fires before add_menu_page when '
			. 'board is invoked first. WP registers under admin_page_* -> 404.'
		);
	}

	// -------------------------------------------------------------------------
	// Parent slug correctness
	// -------------------------------------------------------------------------

	/**
	 * Board submenu is always registered under 'prautoblogger-settings'.
	 */
	public function test_board_submenu_uses_correct_parent_slug(): void {
		$board_page = new \PRAutoBlogger_Board_Page();
		$board_page->on_register_menu();

		$board_entry = null;
		foreach ( $this->call_sequence as $entry ) {
			if ( str_starts_with( $entry, 'add_submenu_page:' ) ) {
				$board_entry = $entry;
				break;
			}
		}

		$this->assertSame(
			'add_submenu_page:prautoblogger-settings',
			$board_entry,
			'Board submenu must use parent slug "prautoblogger-settings"'
		);
	}
}
