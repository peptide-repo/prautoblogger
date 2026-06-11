<?php
/**
 * Tests for PRAutoBlogger_Cost_Governor.
 *
 * Locks the reserve-before-call contract: estimates use prompt-size +
 * max_tokens against the pricing chain; reservations are atomic
 * conditional UPDATEs; standalone (no-run) calls are ungoverned; a breach
 * halts the run and throws Cost_Ceiling_Exception; settle releases the
 * hold and books actuals.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;

class CostGovernorTest extends BaseTestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb.
	 */
	private $wpdb;

	/**
	 * Captured SQL passed through $wpdb->query().
	 *
	 * @var string[]
	 */
	private array $queries = array();

	protected function setUp(): void {
		parent::setUp();
		$this->queries   = array();
		$this->wpdb      = $this->create_mock_wpdb();
		$GLOBALS['wpdb'] = $this->wpdb;

		// prepare() passes SQL through with args appended for inspection.
		$this->wpdb->method( 'prepare' )->willReturnCallback(
			static function ( $sql, ...$args ) {
				return $sql . ' /* ' . implode( ',', array_map( 'strval', $args ) ) . ' */';
			}
		);

		\PRAutoBlogger_Run_Context::clear();
		\PRAutoBlogger_Run_State::flush_cache();

		$this->stub_get_option(
			array(
				'prautoblogger_per_run_cost_ceiling_usd' => 0.50,
				'prautoblogger_log_level'                => 'error',
			)
		);
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Run_Context::clear();
		\PRAutoBlogger_Run_State::flush_cache();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Outside any run, the governor must not govern (historical behavior).
	 */
	public function test_no_run_context_means_ungoverned(): void {
		$reservation = \PRAutoBlogger_Cost_Governor::open_amount_reservation( 0.10, 'test' );
		$this->assertNull( $reservation );
	}

	/**
	 * Estimate = (chars/4 prompt tokens + max_tokens completion) priced by
	 * the pricing chain. With an unknown model the chain falls back to its
	 * conservative default pricing, which is still a positive number.
	 */
	public function test_estimate_chat_cost_is_positive_and_scales_with_max_tokens(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => str_repeat( 'a', 400 ), // 100 estimated prompt tokens.
			),
		);
		$small = \PRAutoBlogger_Cost_Governor::estimate_chat_cost(
			'unknown/model-x',
			$messages,
			array( 'max_tokens' => 100 )
		);
		$large = \PRAutoBlogger_Cost_Governor::estimate_chat_cost(
			'unknown/model-x',
			$messages,
			array( 'max_tokens' => 4000 )
		);
		$this->assertGreaterThan( 0.0, $small );
		$this->assertGreaterThan( $small, $large );
	}

	/**
	 * A winning conditional UPDATE (affected rows = 1) opens a reservation
	 * carrying the run id and amount.
	 */
	public function test_successful_reservation(): void {
		\PRAutoBlogger_Run_Context::set_run_id( 'run-ok' );

		$this->wpdb->method( 'get_var' )->willReturn( 'wp_prautoblogger_runs' );
		$this->wpdb->method( 'get_row' )->willReturn(
			array(
				'run_id'       => 'run-ok',
				'status'       => 'running',
				'ceiling_usd'  => '0.50',
				'reserved_usd' => '0.00',
				'settled_usd'  => '0.00',
				'overage_usd'  => '0.00',
			)
		);
		$this->wpdb->method( 'query' )->willReturnCallback(
			function ( $sql ) {
				$this->queries[] = (string) $sql;
				return 1;
			}
		);

		$reservation = \PRAutoBlogger_Cost_Governor::open_amount_reservation( 0.10, 'test' );

		$this->assertIsArray( $reservation );
		$this->assertSame( 'run-ok', $reservation['run_id'] );
		$this->assertSame( 0.10, $reservation['amount'] );

		$reserve_sql = implode( "\n", $this->queries );
		$this->assertStringContainsString( 'reserved_usd + settled_usd', $reserve_sql );
		$this->assertStringContainsString( "status IN ('pending','running')", $reserve_sql );
	}

	/**
	 * A losing conditional UPDATE (affected rows = 0) halts the run,
	 * records the overage, and throws Cost_Ceiling_Exception.
	 */
	public function test_breach_halts_run_and_throws(): void {
		\PRAutoBlogger_Run_Context::set_run_id( 'run-over' );

		$this->wpdb->method( 'get_var' )->willReturn( 'wp_prautoblogger_runs' );
		$this->wpdb->method( 'get_row' )->willReturn(
			array(
				'run_id'       => 'run-over',
				'status'       => 'running',
				'ceiling_usd'  => '0.50',
				'reserved_usd' => '0.10',
				'settled_usd'  => '0.38',
				'overage_usd'  => '0.00',
			)
		);
		$this->wpdb->method( 'query' )->willReturnCallback(
			function ( $sql ) {
				$this->queries[] = (string) $sql;
				// The reserve UPDATE loses; status/overage UPDATEs win.
				return false !== strpos( (string) $sql, 'reserved_usd + ' ) ? 0 : 1;
			}
		);
		$this->wpdb->method( 'insert' )->willReturn( 1 ); // Logger event row.

		try {
			\PRAutoBlogger_Cost_Governor::open_amount_reservation( 0.10, 'test' );
			$this->fail( 'Expected PRAutoBlogger_Cost_Ceiling_Exception.' );
		} catch ( \PRAutoBlogger_Cost_Ceiling_Exception $e ) {
			$this->assertStringContainsString( 'run-over', $e->getMessage() );
		}

		$all_sql = implode( "\n", $this->queries );
		$this->assertStringContainsString( "status = %s", $all_sql );
		$this->assertStringContainsString( 'halted', $all_sql );
		$this->assertStringContainsString( 'overage_usd', $all_sql );
	}

	/**
	 * Ceiling 0 on the run row disables per-run governance for that run.
	 */
	public function test_zero_ceiling_is_ungoverned(): void {
		\PRAutoBlogger_Run_Context::set_run_id( 'run-free' );

		$this->wpdb->method( 'get_var' )->willReturn( 'wp_prautoblogger_runs' );
		$this->wpdb->method( 'get_row' )->willReturn(
			array(
				'run_id'       => 'run-free',
				'status'       => 'running',
				'ceiling_usd'  => '0',
				'reserved_usd' => '0.00',
				'settled_usd'  => '0.00',
				'overage_usd'  => '0.00',
			)
		);
		$this->wpdb->method( 'query' )->willReturn( 1 );

		$this->assertNull( \PRAutoBlogger_Cost_Governor::open_amount_reservation( 99.0, 'test' ) );
	}

	/**
	 * Settle writes the release + actuals UPDATE; null reservations no-op.
	 */
	public function test_settle_and_release(): void {
		\PRAutoBlogger_Cost_Governor::settle( null, 1.0 ); // Must not touch $wpdb.

		$this->wpdb->method( 'get_var' )->willReturn( 'wp_prautoblogger_runs' );
		$this->wpdb->method( 'query' )->willReturnCallback(
			function ( $sql ) {
				$this->queries[] = (string) $sql;
				return 1;
			}
		);

		\PRAutoBlogger_Cost_Governor::settle(
			array(
				'run_id' => 'run-ok',
				'amount' => 0.10,
			),
			0.002
		);

		$sql = implode( "\n", $this->queries );
		$this->assertStringContainsString( 'GREATEST(reserved_usd - %f, 0)', $sql );
		$this->assertStringContainsString( 'settled_usd = settled_usd + %f', $sql );
	}
}
