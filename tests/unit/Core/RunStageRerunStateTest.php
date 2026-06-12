<?php
/**
 * Tests for PRAutoBlogger_Run_Stage_Writes (M3 done() delta) and
 * PRAutoBlogger_Run_Stage_Rerun_State (operator-action mutations).
 *
 * Locks: fresh done() clears stale but never touches human_modified;
 * the half-migrated-schema self-heal retry; restart() demotion rules
 * (never from 'running', human_modified is sticky); mark_stale() flags
 * only 'done' rows; demote_to_pending() preserves audit columns.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;

class RunStageRerunStateTest extends BaseTestCase {

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

	protected function setUp(): void {
		parent::setUp();
		$this->queries   = array();
		$this->wpdb      = $this->create_mock_wpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->wpdb->method( 'get_var' )->willReturn( 'wp_prautoblogger_run_stages' );
		$this->wpdb->method( 'prepare' )->willReturnCallback(
			static function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				return $sql . ' /* ' . implode( ',', array_map( 'strval', $args ) ) . ' */';
			}
		);
		\PRAutoBlogger_Run_Stage_State::flush_cache();
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Run_Stage_State::flush_cache();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/** Capture queries, all succeeding. */
	private function capture_queries(): void {
		$this->wpdb->method( 'query' )->willReturnCallback(
			function ( $sql ) {
				$this->queries[] = (string) $sql;
				return 1;
			}
		);
	}

	/**
	 * done() writes stale = 0 on both INSERT and duplicate-key paths and
	 * never references human_modified (sticky audit — set only by
	 * Rerun_State::restart()).
	 */
	public function test_done_clears_stale_and_never_touches_human_modified(): void {
		$this->capture_queries();

		\PRAutoBlogger_Run_Stage_State::done( 'run-1', 'draft', '', 'idea:abc', 'output text', 0.01 );

		$this->assertCount( 1, $this->queries );
		$sql = $this->queries[0];
		$this->assertStringContainsString( 'stale', $sql );
		$this->assertStringContainsString( 'stale = 0', $sql );
		$this->assertStringNotContainsString( 'human_modified', $sql );
	}

	/**
	 * Half-migrated schema (no stale column yet): the failed write is
	 * retried with the pre-v0.20.0 statement — the resume checkpoint is
	 * never lost (Cost_Tracker v0.18.0 retry pattern).
	 */
	public function test_done_self_heals_on_missing_stale_column(): void {
		$calls = 0;
		$this->wpdb->method( 'query' )->willReturnCallback(
			function ( $sql ) use ( &$calls ) {
				$this->queries[] = (string) $sql;
				++$calls;
				return 1 === $calls ? false : 1; // First write fails (unknown column).
			}
		);

		\PRAutoBlogger_Run_Stage_State::done( 'run-1', 'draft', '', 'idea:abc', 'output', 0.01 );

		$this->assertCount( 2, $this->queries );
		$this->assertStringContainsString( 'stale', $this->queries[0] );
		$this->assertStringNotContainsString( 'stale', $this->queries[1] );
		$this->assertStringContainsString( "status = 'done'", $this->queries[1] );
	}

	/**
	 * restart() demotes terminal/pending rows only — a 'running' row can
	 * never be restarted (the WHERE excludes it), the attempt counter is
	 * bumped, stale clears, and human_modified can only be raised
	 * (IF(...,1,human_modified)), never reset.
	 */
	public function test_restart_rules(): void {
		$this->capture_queries();

		$ok = \PRAutoBlogger_Run_Stage_Rerun_State::restart( 'run-1', 'draft', 'writer', 'idea:abc', true );

		$this->assertTrue( $ok );
		$sql = $this->queries[0];
		$this->assertStringContainsString( "status = 'running'", $sql );
		$this->assertStringContainsString( 'attempt = attempt + 1', $sql );
		$this->assertStringContainsString( 'stale = 0', $sql );
		$this->assertStringContainsString( 'IF(%d = 1, 1, human_modified)', $sql );
		$this->assertStringContainsString( "IN ('done','failed','halted','pending')", $sql );
		$this->assertStringNotContainsString( "'running')", str_replace( "status = 'running'", '', $sql ) );
	}

	/**
	 * restart() reports false when no row matched (e.g. concurrently
	 * running stage) so the executor can abort cleanly.
	 */
	public function test_restart_returns_false_when_no_row_demoted(): void {
		$this->wpdb->method( 'query' )->willReturn( 0 );

		$this->assertFalse(
			\PRAutoBlogger_Run_Stage_Rerun_State::restart( 'run-1', 'draft', 'writer', 'idea:abc', false )
		);
	}

	/**
	 * mark_stale() flags only 'done' rows of the given stages — pending/
	 * failed rows are not "stale", they are simply not run. Nothing is
	 * demoted (no status write): guardrail 3's no-silent-auto-rerun holds
	 * by construction.
	 */
	public function test_mark_stale_targets_done_rows_only_and_never_demotes(): void {
		$this->capture_queries();

		$count = \PRAutoBlogger_Run_Stage_Rerun_State::mark_stale( 'run-1', 'idea:abc', array( 'polish', 'review', 'publish' ) );

		$this->assertSame( 1, $count );
		$sql = $this->queries[0];
		$this->assertStringContainsString( 'SET stale = 1', $sql );
		$this->assertStringContainsString( "status = 'done'", $sql );
		$this->assertStringContainsString( 'IN (%s,%s,%s)', $sql );
		$this->assertStringNotContainsString( "SET status", $sql );
	}

	/**
	 * demote_to_pending() sets pending+stale, keeps meta_json/attempt/
	 * human_modified untouched (audit survives), and never touches a
	 * 'running' row.
	 */
	public function test_demote_to_pending_preserves_audit_columns(): void {
		$this->capture_queries();

		\PRAutoBlogger_Run_Stage_Rerun_State::demote_to_pending( 'run-1', 'idea:abc', array( 'review', 'publish' ) );

		$sql = $this->queries[0];
		$this->assertStringContainsString( "SET status = 'pending', stale = 1", $sql );
		$this->assertStringContainsString( "status != 'running'", $sql );
		$this->assertStringNotContainsString( 'meta_json', $sql );
		$this->assertStringNotContainsString( 'attempt', $sql );
		$this->assertStringNotContainsString( 'human_modified', $sql );
	}

	/**
	 * Empty stage lists and missing tables degrade to 0 / no queries.
	 */
	public function test_noop_paths(): void {
		$this->capture_queries();

		$this->assertSame( 0, \PRAutoBlogger_Run_Stage_Rerun_State::mark_stale( 'run-1', 'i', array() ) );
		$this->assertSame( 0, \PRAutoBlogger_Run_Stage_Rerun_State::demote_to_pending( '', 'i', array( 'draft' ) ) );
		$this->assertCount( 0, $this->queries );
	}
}
