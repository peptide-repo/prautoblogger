<?php
/**
 * Dossier_Actions: rerun + status endpoint tests (v0.20.0, M3).
 *
 * Locks the chained-cron contract (queue only — ZERO state mutations in
 * the AJAX layer) and the read-only stage-status poll shape.
 *
 * @package PRAutoBlogger\Tests\Admin
 */

namespace PRAutoBlogger\Tests\Admin;

use Brain\Monkey\Functions;

class DossierActionsRerunTest extends DossierActionsTestCase {

	/**
	 * Chained-cron contract: rerun_stage only schedules — no run/stage
	 * state is mutated by the AJAX layer, and the response says queued.
	 */
	public function test_rerun_stage_queues_without_any_state_mutation(): void {
		$this->wire_eligible_context();
		// A saved fork exists for the replay check.
		$wpdb_get_row_fork = array(
			'version'      => '1',
			'request_json' => '{"model":"m","messages":[{"role":"user","content":"edited"}]}',
		);
		// Rebuild get_row: forks resolve, run row terminal.
		$_POST = array(
			'post_id'    => '99',
			'stage'      => 'draft',
			'agent_role' => 'writer',
		);
		// Replace the get_row wiring (fork now exists).
		$wpdb          = $this->create_mock_wpdb();
		$wpdb->options = 'wp_options';
		$wpdb->method( 'prepare' )->willReturnCallback(
			static function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				return $sql . ' /* ' . implode( ',', array_map( 'strval', $args ) ) . ' */';
			}
		);
		$wpdb->method( 'get_var' )->willReturnCallback(
			static function ( $sql ) {
				if ( false !== strpos( (string) $sql, 'SHOW TABLES' ) ) {
					return ( false !== strpos( (string) $sql, 'stage_inputs' ) ) ? 'wp_prautoblogger_stage_inputs' : 'wp_prautoblogger_runs';
				}
				return null; // Lock free.
			}
		);
		$wpdb->method( 'get_row' )->willReturnCallback(
			static function ( $sql ) use ( $wpdb_get_row_fork ) {
				if ( false !== strpos( (string) $sql, 'stage_inputs' ) ) {
					return $wpdb_get_row_fork;
				}
				return array(
					'run_id' => 'run-1',
					'status' => 'done',
				);
			}
		);
		$wpdb->method( 'query' )->willReturnCallback(
			function ( $sql ) {
				$this->queries[] = (string) $sql;
				return 1;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
		\PRAutoBlogger_Run_State::flush_cache();
		\PRAutoBlogger_Stage_Input_Store::flush_cache();

		$actions = new \PRAutoBlogger_Dossier_Actions();
		$this->dispatch( array( $actions, 'on_rerun_stage' ) );

		$this->assertTrue( $this->json['ok'] );
		$this->assertTrue( $this->json['data']['queued'] );
		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( \PRAutoBlogger_Rerun_Executor::REPLAY_ACTION, $this->scheduled[0]['hook'] );
		$this->assertSame( array( 'run-1', 99, 'draft', 'writer', 'idea:abc123' ), array_slice( $this->scheduled[0]['args'], 0, 5 ) );
		foreach ( $this->queries as $sql ) {
			$this->assertStringNotContainsString( 'UPDATE', $sql, 'the AJAX layer must not mutate state' );
		}
	}

	/**
	 * stage_status: read-only poll returns run status + per-stage state
	 * with stale/human_modified flags.
	 */
	public function test_stage_status_returns_stage_states(): void {
		$this->wire_eligible_context();
		$this->wpdb->method( 'get_results' )->willReturn( array() );
		$_POST = array( 'post_id' => '99' );

		$actions = new \PRAutoBlogger_Dossier_Actions();
		$this->dispatch( array( $actions, 'on_stage_status' ) );

		$this->assertTrue( $this->json['ok'] );
		$this->assertArrayHasKey( 'run_status', $this->json['data'] );
		$this->assertArrayHasKey( 'stages', $this->json['data'] );
	}
}
