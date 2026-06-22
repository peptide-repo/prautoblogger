<?php
/**
 * Tests for PRAutoBlogger_Run_Reaper.
 *
 * Locks the retention contract (R days from the SETTING, 0 = keep
 * forever) and the stuck-sweep SQL shapes (2x expected wall-clock,
 * running-only).
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class RunReaperTest extends BaseTestCase {

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
		$this->queries = array();
		// Local mock: the reaper also uses get_col(), which the shared
		// BaseTestCase mock does not declare.
		$this->wpdb = $this->getMockBuilder( \stdClass::class )
			->addMethods( array( 'prepare', 'get_var', 'get_results', 'get_col', 'insert', 'query', 'get_row', 'update' ) )
			->getMock();
		$this->wpdb->prefix     = 'wp_';
		$this->wpdb->insert_id  = 0;
		$this->wpdb->last_error = '';
		$GLOBALS['wpdb']        = $this->wpdb;
		$this->wpdb->method( 'prepare' )->willReturnCallback(
			static function ( $sql, ...$args ) {
				return $sql . ' /* ' . implode( ',', array_map( 'strval', $args ) ) . ' */';
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// wp_date() is called by the reaper's stuck-sweep cutoff (v0.22.1 TZ fix).
		// Alias to gmdate() so tests remain timezone-agnostic.
		Functions\when( 'wp_date' )->alias(
			static function ( string $format, ?int $timestamp = null ): string {
				return gmdate( $format, null !== $timestamp ? $timestamp : time() );
			}
		);
		\PRAutoBlogger_Run_State::flush_cache();
		\PRAutoBlogger_Run_Stage_State::flush_cache();
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Run_State::flush_cache();
		\PRAutoBlogger_Run_Stage_State::flush_cache();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Retention 0 (or negative) means "keep forever": the pruner must not
	 * issue any UPDATE.
	 */
	public function test_zero_retention_keeps_payloads_forever(): void {
		$this->stub_get_option(
			array(
				'prautoblogger_request_json_retention_days' => 0,
			)
		);
		$this->wpdb->method( 'get_var' )->willReturn( null );   // No state tables.
		$this->wpdb->method( 'get_col' )->willReturn( array() );
		$this->wpdb->expects( $this->never() )->method( 'query' );

		$stats = \PRAutoBlogger_Run_Reaper::reap();
		$this->assertSame( 0, $stats['payloads_pruned'] );
	}

	/**
	 * With the default setting the pruner NULLs request_json older than R
	 * days on the generation log (and stage payloads when that table
	 * exists). The sweeps only touch 'running'/open rows.
	 */
	public function test_reap_prunes_and_sweeps_with_setting_backed_window(): void {
		$this->stub_get_option(
			array(
				'prautoblogger_request_json_retention_days' => PRAUTOBLOGGER_DEFAULT_REQUEST_JSON_RETENTION_DAYS,
			)
		);
		// Both state tables exist.
		$this->wpdb->method( 'get_var' )->willReturnCallback(
			static function ( $sql ) {
				if ( false !== strpos( (string) $sql, 'run_stages' ) ) {
					return 'wp_prautoblogger_run_stages';
				}
				return 'wp_prautoblogger_runs';
			}
		);
		$this->wpdb->method( 'get_col' )->willReturn( array() ); // No stuck runs.
		$this->wpdb->method( 'query' )->willReturnCallback(
			function ( $sql ) {
				$this->queries[] = (string) $sql;
				return 2;
			}
		);

		$stats = \PRAutoBlogger_Run_Reaper::reap();

		$all_sql = implode( "\n", $this->queries );
		$this->assertStringContainsString( 'request_json = NULL', $all_sql );
		$this->assertStringContainsString( 'meta_json = NULL', $all_sql );
		$this->assertStringContainsString( "status = 'failed'", $all_sql );
		$this->assertStringContainsString( "WHERE status = 'running' AND updated_at <", $all_sql );
		$this->assertSame( 4, $stats['payloads_pruned'] ); // 2 rows x 2 tables.
	}
}
