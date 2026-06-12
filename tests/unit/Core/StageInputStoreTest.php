<?php
/**
 * Tests for PRAutoBlogger_Stage_Input_Store (v0.20.0, M3).
 *
 * Locks the INSERT-only immutability contract (fork saves create new
 * versions, nothing is ever updated), seed persist-once semantics, the
 * retention prune scope (human bodies only, seeds kept), and the
 * self-healing missing-table degradation.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;

class StageInputStoreTest extends BaseTestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb.
	 */
	private $wpdb;

	/**
	 * Rows captured from $wpdb->insert().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $inserted = array();

	/**
	 * SQL captured from $wpdb->query().
	 *
	 * @var string[]
	 */
	private array $queries = array();

	protected function setUp(): void {
		parent::setUp();
		$this->inserted  = array();
		$this->queries   = array();
		$this->wpdb      = $this->create_mock_wpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->wpdb->method( 'prepare' )->willReturnCallback(
			static function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				return $sql . ' /* ' . implode( ',', array_map( 'strval', $args ) ) . ' */';
			}
		);
		$this->wpdb->method( 'insert' )->willReturnCallback(
			function ( $table, $row ) {
				$this->inserted[] = (array) $row;
				return 1;
			}
		);
		$this->wpdb->method( 'query' )->willReturnCallback(
			function ( $sql ) {
				$this->queries[] = (string) $sql;
				return 1;
			}
		);
		\PRAutoBlogger_Stage_Input_Store::flush_cache();
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Stage_Input_Store::flush_cache();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/** Make the availability probe succeed. */
	private function table_exists(): void {
		$this->wpdb->method( 'get_var' )->willReturnCallback(
			static function ( $sql ) {
				if ( false !== strpos( (string) $sql, 'SHOW TABLES' ) ) {
					return 'wp_prautoblogger_stage_inputs';
				}
				return null; // MAX(version) on an empty scope.
			}
		);
	}

	/**
	 * First fork for a scope gets version 1 via INSERT — and the write
	 * path is INSERT-only: no UPDATE statement may ever be issued.
	 */
	public function test_save_fork_inserts_version_one_and_never_updates(): void {
		$this->table_exists();

		$version = \PRAutoBlogger_Stage_Input_Store::save_fork(
			'run-1',
			'draft',
			'writer',
			'idea:abc',
			'{"model":"m","messages":[]}',
			'rhys'
		);

		$this->assertSame( 1, $version );
		$this->assertCount( 1, $this->inserted );
		$row = $this->inserted[0];
		$this->assertSame( 'human', $row['source'] );
		$this->assertSame( 1, $row['version'] );
		$this->assertSame( 'rhys', $row['author'] );
		// Immutability: the store issued no UPDATE of any kind.
		foreach ( $this->queries as $sql ) {
			$this->assertStringNotContainsString( 'UPDATE', $sql );
		}
	}

	/**
	 * The next fork for the same scope gets MAX(version)+1 — the original
	 * version row is never overwritten (CPO guardrail 1).
	 */
	public function test_save_fork_increments_version_from_existing_max(): void {
		$this->wpdb->method( 'get_var' )->willReturnCallback(
			static function ( $sql ) {
				if ( false !== strpos( (string) $sql, 'SHOW TABLES' ) ) {
					return 'wp_prautoblogger_stage_inputs';
				}
				return '3'; // Existing max version.
			}
		);

		$version = \PRAutoBlogger_Stage_Input_Store::save_fork( 'run-1', 'draft', 'writer', 'idea:abc', '{}', 'rhys' );

		$this->assertSame( 4, $version );
		$this->assertSame( 4, $this->inserted[0]['version'] );
	}

	/**
	 * Seed persists once: a second save for the same item is a no-op
	 * (idempotent worker resume).
	 */
	public function test_save_seed_is_insert_once(): void {
		$seeded = false;
		$this->wpdb->method( 'get_var' )->willReturnCallback(
			static function ( $sql ) use ( &$seeded ) {
				if ( false !== strpos( (string) $sql, 'SHOW TABLES' ) ) {
					return 'wp_prautoblogger_stage_inputs';
				}
				if ( false !== strpos( (string) $sql, 'SELECT request_json' ) ) {
					return $seeded ? '{"topic":"t"}' : null;
				}
				return null;
			}
		);

		\PRAutoBlogger_Stage_Input_Store::save_seed( 'run-1', 'idea:abc', '{"topic":"t"}' );
		$seeded = true;
		\PRAutoBlogger_Stage_Input_Store::save_seed( 'run-1', 'idea:abc', '{"topic":"other"}' );

		$this->assertCount( 1, $this->inserted );
		$this->assertSame( 'seed', $this->inserted[0]['source'] );
		$this->assertSame( '', $this->inserted[0]['stage'] );
	}

	/**
	 * Retention prune targets ONLY human fork bodies — the WHERE clause
	 * pins source='human', so seed rows survive (re-run-from-here keeps
	 * working for the life of the run).
	 */
	public function test_prune_targets_human_bodies_only(): void {
		$this->table_exists();

		\PRAutoBlogger_Stage_Input_Store::prune_human_bodies( '2026-05-29 00:00:00' );

		$this->assertCount( 1, $this->queries );
		$sql = $this->queries[0];
		$this->assertStringContainsString( 'SET request_json = NULL', $sql );
		$this->assertStringContainsString( "source = %s", $sql );
		$this->assertStringContainsString( 'human', $sql );
		$this->assertStringNotContainsString( "seed", $sql );
	}

	/**
	 * Missing table (half-migrated schema): every method degrades to a
	 * silent no-op / null — never a query, never a notice.
	 */
	public function test_missing_table_degrades_to_noop(): void {
		$this->wpdb->method( 'get_var' )->willReturn( null );

		$this->assertNull( \PRAutoBlogger_Stage_Input_Store::save_fork( 'r', 'draft', 'w', 'i', '{}', 'a' ) );
		\PRAutoBlogger_Stage_Input_Store::save_seed( 'r', 'i', '{}' );
		$this->assertNull( \PRAutoBlogger_Stage_Input_Store::get_seed( 'r', 'i' ) );
		$this->assertNull( \PRAutoBlogger_Stage_Input_Store::latest_fork( 'r', 'draft', 'w', 'i' ) );
		$this->assertSame( 0, \PRAutoBlogger_Stage_Input_Store::prune_human_bodies( '2026-05-29 00:00:00' ) );
		$this->assertCount( 0, $this->inserted );
		$this->assertCount( 0, $this->queries );
	}
}
