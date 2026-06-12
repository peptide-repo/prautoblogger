<?php
/**
 * Tests for PRAutoBlogger_Rerun_Executor + Job_Support (v0.20.0, M3).
 *
 * Locks the chained-cron contract (queue() schedules + writes the
 * queued transient — nothing executes synchronously), the under-lock
 * re-validation (a post published between click and pickup aborts the
 * job with ZERO stage mutations), and the terminal-status restore when
 * the stage demotion fails after the run was reopened.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class RerunExecutorTest extends BaseTestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb.
	 */
	private $wpdb;

	/**
	 * SQL captured from $wpdb->query().
	 *
	 * @var string[]
	 */
	private array $queries = array();

	/**
	 * Transients captured from set_transient().
	 *
	 * @var array<int, array>
	 */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();
		$this->queries    = array();
		$this->transients = array();
		$this->wpdb          = $this->create_mock_wpdb();
		$this->wpdb->options = 'wp_options';
		$GLOBALS['wpdb']     = $this->wpdb;
		$this->wpdb->method( 'prepare' )->willReturnCallback(
			static function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				return $sql . ' /* ' . implode( ',', array_map( 'strval', $args ) ) . ' */';
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl = 0 ) {
				$this->transients[] = (array) $value;
				return true;
			}
		);
		// Monthly budget 0 = no limit (fast false path).
		$this->stub_get_option( array( 'prautoblogger_monthly_budget_usd' => '0' ) );
		\PRAutoBlogger_Run_State::flush_cache();
		\PRAutoBlogger_Run_Stage_State::flush_cache();
		\PRAutoBlogger_Stage_Input_Store::flush_cache();
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Run_State::flush_cache();
		\PRAutoBlogger_Run_Stage_State::flush_cache();
		\PRAutoBlogger_Stage_Input_Store::flush_cache();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/** SHOW TABLES probes succeed for every substrate table. */
	private function tables_exist(): void {
		$this->wpdb->method( 'get_var' )->willReturnCallback(
			static function ( $sql ) {
				$sql = (string) $sql;
				if ( false !== strpos( $sql, 'SHOW TABLES' ) ) {
					if ( false !== strpos( $sql, 'stage_inputs' ) ) {
						return 'wp_prautoblogger_stage_inputs';
					}
					if ( false !== strpos( $sql, 'run_stages' ) ) {
						return 'wp_prautoblogger_run_stages';
					}
					return 'wp_prautoblogger_runs';
				}
				return null;
			}
		);
	}

	/**
	 * queue() never executes anything: it schedules a single cron event
	 * with a uniqueness token appended and broadcasts a 'running' status
	 * transient so board/dossier polling shows the queued state
	 * (chained-cron semantics — the CPO hard constraint).
	 */
	public function test_queue_schedules_cron_and_broadcasts_queued_state(): void {
		$scheduled = null;
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function ( $ts, $hook, $args ) use ( &$scheduled ) {
				$scheduled = array(
					'hook' => $hook,
					'args' => $args,
				);
				return true;
			}
		);
		Functions\when( 'spawn_cron' )->justReturn( true );
		Functions\when( 'wp_remote_post' )->justReturn( array() );
		Functions\when( 'site_url' )->justReturn( 'https://example.com' );

		\PRAutoBlogger_Rerun_Job_Support::queue(
			\PRAutoBlogger_Rerun_Executor::REPLAY_ACTION,
			array( 'run-1', 99, 'draft', 'writer', 'idea:abc' ),
			'Queued: re-running Draft…'
		);

		$this->assertSame( \PRAutoBlogger_Rerun_Executor::REPLAY_ACTION, $scheduled['hook'] );
		$this->assertCount( 6, $scheduled['args'] ); // 5 args + uniqueness token.
		$this->assertSame( 'run-1', $scheduled['args'][0] );
		$this->assertCount( 1, $this->transients );
		$this->assertSame( 'running', $this->transients[0]['status'] );
		$this->assertSame( 'Queued: re-running Draft…', $this->transients[0]['stage'] );
	}

	/**
	 * Under-lock re-validation: a post published between click and cron
	 * pickup aborts the replay with an error transient and ZERO
	 * run_stages mutations (guardrail 5 cannot be raced).
	 */
	public function test_replay_job_aborts_on_frozen_post_without_mutations(): void {
		$this->tables_exist();
		$this->wpdb->method( 'query' )->willReturnCallback(
			function ( $sql ) {
				$this->queries[] = (string) $sql;
				return 1; // Lock cleanup + acquire succeed.
			}
		);
		$post              = new \WP_Post();
		$post->ID          = 99;
		$post->post_status = 'publish';
		Functions\when( 'get_post' )->justReturn( $post );

		( new \PRAutoBlogger_Rerun_Executor() )->on_replay_job( 'run-1', 99, 'draft', 'writer', 'idea:abc' );

		foreach ( $this->queries as $sql ) {
			$this->assertStringNotContainsString( 'run_stages', $sql, 'no stage mutation may happen on a frozen post' );
			$this->assertStringNotContainsString( 'run_id = %s AND status IN', $sql, 'the run must not be reopened' );
		}
		$last = end( $this->transients );
		$this->assertSame( 'error', $last['status'] );
		$this->assertStringContainsString( 'published', $last['message'] );
	}

	/**
	 * When the stage demotion fails after the run was reopened (e.g. the
	 * row is concurrently 'running'), the previous terminal status is
	 * restored — the run cannot be left dangling in 'running' for the
	 * reaper to misread.
	 */
	public function test_replay_job_restores_terminal_status_when_restart_fails(): void {
		$this->tables_exist();
		$this->wpdb->method( 'get_row' )->willReturnCallback(
			static function ( $sql ) {
				$sql = (string) $sql;
				if ( false !== strpos( $sql, 'stage_inputs' ) ) {
					return array(
						'version'      => '2',
						'request_json' => '{"model":"m","messages":[{"role":"user","content":"edited"}]}',
					);
				}
				return array(
					'run_id'      => 'run-1',
					'status'      => 'done',
					'ceiling_usd' => '0.50',
				);
			}
		);
		$this->wpdb->method( 'query' )->willReturnCallback(
			function ( $sql ) {
				$sql             = (string) $sql;
				$this->queries[] = $sql;
				if ( false !== strpos( $sql, 'attempt = attempt + 1' ) ) {
					return 0; // restart() fails — row not demotable.
				}
				return 1;
			}
		);
		$post              = new \WP_Post();
		$post->ID          = 99;
		$post->post_status = 'draft';
		Functions\when( 'get_post' )->justReturn( $post );

		( new \PRAutoBlogger_Rerun_Executor() )->on_replay_job( 'run-1', 99, 'draft', 'writer', 'idea:abc' );

		$reopened = false;
		$restored = false;
		foreach ( $this->queries as $sql ) {
			if ( false !== strpos( $sql, "ceiling_usd = %f" ) ) {
				$reopened = true;
			}
			// restore_terminal -> mark_status('done') over the reopened 'running' row.
			if ( false !== strpos( $sql, 'SET status = %s' ) && false !== strpos( $sql, 'done' )
				&& false !== strpos( $sql, "status IN ('pending','running')" ) ) {
				$restored = true;
			}
		}
		$this->assertTrue( $reopened, 'run must have been reopened before restart' );
		$this->assertTrue( $restored, 'terminal status must be restored after the aborted demotion' );
		$last = end( $this->transients );
		$this->assertSame( 'error', $last['status'] );
	}
}
