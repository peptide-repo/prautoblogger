<?php
/**
 * Tests for PRAutoBlogger_Board_Gen_Log_Query — binding order and result shape.
 *
 * The key regression: both get_generating_cards() and get_failed_cards() had
 * (a) a missing comma between bound args and (b) reversed argument order — the
 * integer limit was passed first, the datetime string second, which bound
 * created_at >= <int> and LIMIT <datetime>. These tests assert the correct
 * binding order (string first, int second) so any future regression is caught
 * before CI.
 *
 * @package PRAutoBlogger\Tests\Admin
 */

namespace PRAutoBlogger\Tests\Admin;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class BoardGenLogQueryTest extends BaseTestCase {

	/**
	 * Captured prepare() calls: each entry is ['sql' => string, 'args' => array].
	 *
	 * @var array<int, array{sql: string, args: array}>
	 */
	private array $prepare_calls = array();

	/**
	 * Build a fresh mock $wpdb whose get_results() always returns $rows.
	 *
	 * @param array $rows Rows to return from get_results().
	 * @return \PHPUnit\Framework\MockObject\MockObject
	 */
	private function make_wpdb( array $rows = array() ): object {
		$wpdb            = $this->create_mock_wpdb();
		$wpdb->prefix    = 'wp_';
		$GLOBALS['wpdb'] = $wpdb;
		$self            = $this;

		$wpdb->method( 'prepare' )->willReturnCallback(
			static function ( string $sql, ...$args ) use ( $self ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$self->prepare_calls[] = array( 'sql' => $sql, 'args' => $args );
				return 'PREPARED_SQL';
			}
		);

		$wpdb->method( 'get_results' )->willReturn( $rows );
		return $wpdb;
	}

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_calls = array();

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ) {
				return 'https://test.example.com/wp-admin/' . ltrim( $path, '/' );
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( string $name, $default = false ) {
				if ( 'prautoblogger_board_column_limit' === $name ) {
					return 10;
				}
				return $default;
			}
		);
	}

	protected function tearDown(): void {
		$this->prepare_calls = array();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// ── get_generating_cards() binding order ────────────────────────────────

	/**
	 * get_generating_cards() must pass $since (string) as the FIRST bound arg
	 * and the integer limit as the SECOND — matching placeholder order %s … %d.
	 *
	 * This would have caught the reversed-order bug (limit int passed first,
	 * datetime string second) that silently bound created_at >= <int>.
	 */
	public function test_generating_cards_prepare_arg_order(): void {
		$this->make_wpdb();
		$query = new \PRAutoBlogger_Board_Gen_Log_Query();
		$query->get_generating_cards();

		// Find the prepare() call containing LIMIT %d (the gen-log secondary query).
		$gen_call = null;
		foreach ( $this->prepare_calls as $call ) {
			if (
				str_contains( $call['sql'], 'LIMIT %d' ) &&
				str_contains( $call['sql'], 'post_id IS NULL' ) &&
				! str_contains( $call['sql'], "response_status = 'error'" )
			) {
				$gen_call = $call;
				break;
			}
		}

		$this->assertNotNull( $gen_call, 'get_generating_cards() must call $wpdb->prepare() with LIMIT %d' );
		$this->assertCount( 2, $gen_call['args'], 'prepare() must receive exactly 2 bound args' );

		// First arg must be a string (the $since datetime).
		$this->assertIsString( $gen_call['args'][0], 'First arg to prepare() must be the $since string (for %s)' );
		// Second arg must be an integer (the column limit).
		$this->assertIsInt( $gen_call['args'][1], 'Second arg to prepare() must be the integer limit (for %d)' );
	}

	/**
	 * get_generating_cards() must return an empty array (not error) when DB returns nothing.
	 */
	public function test_generating_cards_returns_empty_array_on_no_results(): void {
		$this->make_wpdb();
		$query = new \PRAutoBlogger_Board_Gen_Log_Query();
		$cards = $query->get_generating_cards();
		$this->assertIsArray( $cards );
	}

	/**
	 * When the status transient signals a running job, get_generating_cards()
	 * returns a card from the transient (no DB call needed for that card).
	 */
	public function test_generating_cards_returns_transient_card_when_running(): void {
		$this->make_wpdb();

		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				if ( 'prautoblogger_generation_status' === $key ) {
					return array(
						'status'  => 'running',
						'stage'   => 'Drafting…',
						'started' => time() - 30,
					);
				}
				return false;
			}
		);

		$query = new \PRAutoBlogger_Board_Gen_Log_Query();
		$cards = $query->get_generating_cards();

		$this->assertNotEmpty( $cards, 'Transient running state must produce at least one card' );
		$this->assertSame( '', $cards[0]['run_id'], 'Transient card has empty run_id' );
		$this->assertSame( 'logs', $cards[0]['click_action'] );
	}

	// ── get_failed_cards() binding order ────────────────────────────────────

	/**
	 * get_failed_cards() must pass $since (string) as the FIRST bound arg
	 * and the integer limit as the SECOND — matching placeholder order %s … %d.
	 */
	public function test_failed_cards_prepare_arg_order(): void {
		$this->make_wpdb();
		$query = new \PRAutoBlogger_Board_Gen_Log_Query();
		$query->get_failed_cards();

		// Find the prepare() call for the failed query.
		$fail_call = null;
		foreach ( $this->prepare_calls as $call ) {
			if ( str_contains( $call['sql'], "response_status = 'error'" ) ) {
				$fail_call = $call;
				break;
			}
		}

		$this->assertNotNull( $fail_call, 'get_failed_cards() must call $wpdb->prepare() with response_status filter' );
		$this->assertCount( 2, $fail_call['args'], 'prepare() must receive exactly 2 bound args' );

		// First arg must be a string (the $since datetime).
		$this->assertIsString( $fail_call['args'][0], 'First arg to prepare() must be the $since string (for %s)' );
		// Second arg must be an integer (the column limit).
		$this->assertIsInt( $fail_call['args'][1], 'Second arg to prepare() must be the integer limit (for %d)' );
	}

	/**
	 * get_failed_cards() returns the correct card shape from a seeded result row.
	 */
	public function test_failed_cards_returns_correct_shape(): void {
		// Use a fresh wpdb with non-empty get_results rows.
		$this->make_wpdb(
			array(
				array(
					'run_id'     => 'fail-run-abc',
					'started_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
					'last_error' => 'Rate limit exceeded',
					'last_stage' => 'draft',
					'call_count' => '3',
				),
			)
		);

		$query = new \PRAutoBlogger_Board_Gen_Log_Query();
		$cards = $query->get_failed_cards();

		$this->assertCount( 1, $cards );
		$this->assertSame( 'fail-run-abc', $cards[0]['run_id'] );
		$this->assertStringContainsString( 'Rate limit', $cards[0]['error_message'] );
		$this->assertSame( 'logs', $cards[0]['click_action'] );
		$this->assertArrayHasKey( 'started_at', $cards[0] );
	}

	/**
	 * Column-limit option is honoured: limit 7 → second arg to prepare() is 7.
	 */
	public function test_column_limit_option_is_respected(): void {
		Functions\when( 'get_option' )->alias(
			function ( string $name, $default = false ) {
				if ( 'prautoblogger_board_column_limit' === $name ) {
					return 7;
				}
				return $default;
			}
		);

		$this->make_wpdb();
		$query = new \PRAutoBlogger_Board_Gen_Log_Query();
		$query->get_generating_cards();

		$gen_call = null;
		foreach ( $this->prepare_calls as $call ) {
			if ( str_contains( $call['sql'], 'LIMIT %d' ) && ! str_contains( $call['sql'], "response_status = 'error'" ) ) {
				$gen_call = $call;
				break;
			}
		}

		$this->assertNotNull( $gen_call );
		$this->assertSame( 7, $gen_call['args'][1], 'Limit arg must match the option value' );
	}
}
