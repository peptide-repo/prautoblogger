<?php
/**
 * Tests for PRAutoBlogger_Research_Judge (curate stage).
 *
 * Covers: dedup/keep-discard logic, quality_score computation, run_sources
 * DB writes (keep=1 / keep=0), and graceful degradation when audit tables
 * are absent.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class ResearchJudgeTest extends BaseTestCase {

	/** @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb. */
	private $wpdb;

	/** @var array<int, array> Rows inserted into run_sources. */
	private array $inserted_rows = array();

	protected function setUp(): void {
		parent::setUp();
		$this->inserted_rows = array();
		$this->wpdb          = $this->create_mock_wpdb();
		$GLOBALS['wpdb']     = $this->wpdb;

		$this->wpdb->prefix = 'wp_';
		$this->wpdb->method( 'prepare' )->willReturnCallback(
			static function ( $sql, ...$args ) {
				return $sql . ' /* ' . implode( ',', array_map( 'strval', $args ) ) . ' */';
			}
		);

		// Capture insert calls.
		$test = $this;
		$this->wpdb->method( 'insert' )->willReturnCallback(
			function ( $table, $data ) use ( $test ) {
				$test->inserted_rows[] = $data;
				return 1;
			}
		);

		\PRAutoBlogger_Audit_Writer::flush_cache();
		\PRAutoBlogger_Run_Stage_State::flush_cache();

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'current_time' )->justReturn( '2026-06-23 00:00:00' );
		Functions\when( 'get_option' )->justReturn( 'error' );
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Audit_Writer::flush_cache();
		\PRAutoBlogger_Run_Stage_State::flush_cache();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// ── Keep / discard ──────────────────────────────────────────────────

	/**
	 * All unique sources with quality > 0 are kept when under MAX_KEPT_SOURCES.
	 */
	public function test_all_unique_sources_kept_under_max(): void {
		$judge   = $this->make_judge_with_available_tables();
		$fanout  = $this->make_fanout_results( 3, 0 );
		$kept    = $judge->curate( 'run-1', 'idea:abc', $fanout );

		$this->assertCount( 3, $kept );
		foreach ( $kept as $s ) {
			$this->assertArrayHasKey( 'quality_score', $s );
			$this->assertGreaterThan( 0.0, $s['quality_score'] );
		}
	}

	/**
	 * Exact-URL duplicates across agents are deduplicated to one entry.
	 */
	public function test_url_duplicates_are_deduplicated(): void {
		$fanout = array(
			array(
				'agent_role' => 'researcher:mechanisms',
				'sources'    => array(
					array( 'url' => 'https://example.com/a', 'title' => 'A', 'excerpt' => 'ex', 'relevance' => 0.8 ),
				),
			),
			array(
				'agent_role' => 'researcher:clinical',
				'sources'    => array(
					array( 'url' => 'https://example.com/a', 'title' => 'A dup', 'excerpt' => 'ex2', 'relevance' => 0.9 ),
				),
			),
		);

		$judge = $this->make_judge_with_available_tables();
		$kept  = $judge->curate( 'run-2', 'idea:abc', $fanout );

		$this->assertCount( 1, $kept );
	}

	/**
	 * Sources beyond MAX_KEPT_SOURCES are written to run_sources with kept=0.
	 */
	public function test_sources_over_max_written_as_discarded(): void {
		// Generate 14 distinct sources (max is 12 → 2 discarded).
		$judge  = $this->make_judge_with_available_tables();
		$fanout = $this->make_fanout_results( 14, 0 );
		$kept   = $judge->curate( 'run-3', 'idea:abc', $fanout );

		$this->assertCount( 12, $kept );

		$discarded_rows = array_filter( $this->inserted_rows, static fn( $r ) => 0 === (int) $r['kept'] );
		$this->assertCount( 2, array_values( $discarded_rows ) );
	}

	// ── Quality scoring ─────────────────────────────────────────────────

	/**
	 * A PubMed URL should receive a higher quality_score than a plain HTTPS URL.
	 */
	public function test_doi_url_scores_higher_than_generic_https(): void {
		$fanout = array(
			array(
				'agent_role' => 'researcher:mechanisms',
				'sources'    => array(
					array( 'url' => 'https://doi.org/10.1234/abc', 'title' => 'DOI', 'excerpt' => 'test', 'relevance' => 0.8 ),
					array( 'url' => 'https://example.com/article', 'title' => 'Generic', 'excerpt' => 'test', 'relevance' => 0.8 ),
				),
			),
		);

		$judge  = $this->make_judge_with_available_tables();
		$kept   = $judge->curate( 'run-4', 'idea:abc', $fanout );

		$this->assertCount( 2, $kept );
		// DOI should be first (sorted descending by quality_score).
		$this->assertStringContainsString( 'doi.org', $kept[0]['url'] );
		$this->assertGreaterThan( $kept[1]['quality_score'], $kept[0]['quality_score'] );
	}

	// ── run_sources writes ───────────────────────────────────────────────

	/**
	 * Each kept source is inserted with kept=1 and a non-empty reason.
	 */
	public function test_kept_sources_written_with_kept_1(): void {
		$judge = $this->make_judge_with_available_tables();
		$judge->curate( 'run-5', 'idea:abc', $this->make_fanout_results( 2, 0 ) );

		$kept_rows = array_filter( $this->inserted_rows, static fn( $r ) => 1 === (int) $r['kept'] );
		$this->assertCount( 2, array_values( $kept_rows ) );

		foreach ( $kept_rows as $row ) {
			$this->assertNotEmpty( $row['reason'] );
			$this->assertGreaterThanOrEqual( 0.0, $row['quality_score'] );
		}
	}

	/**
	 * No-op when the audit tables are absent (is_available() returns false).
	 */
	public function test_no_db_writes_when_tables_absent(): void {
		$judge  = $this->make_judge_without_tables();
		$fanout = $this->make_fanout_results( 3, 0 );
		$kept   = $judge->curate( 'run-6', 'idea:abc', $fanout );

		$this->assertCount( 3, $kept );
		$this->assertEmpty( $this->inserted_rows );
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	/**
	 * Build a judge where audit tables are reported as available.
	 */
	private function make_judge_with_available_tables(): \PRAutoBlogger_Research_Judge {
		// is_available() probes with SHOW TABLES LIKE — return the table name.
		$this->wpdb->method( 'get_var' )->willReturnCallback(
			function ( $sql ) {
				if ( false !== strpos( (string) $sql, 'run_decisions' ) ) {
					return 'wp_prautoblogger_run_decisions';
				}
				if ( false !== strpos( (string) $sql, 'run_stages' ) ) {
					return 'wp_prautoblogger_run_stages';
				}
				return null;
			}
		);
		return new \PRAutoBlogger_Research_Judge();
	}

	/**
	 * Build a judge where audit tables are absent (is_available() → false).
	 */
	private function make_judge_without_tables(): \PRAutoBlogger_Research_Judge {
		$this->wpdb->method( 'get_var' )->willReturn( null );
		return new \PRAutoBlogger_Research_Judge();
	}

	/**
	 * Build fanout_results with $count unique sources and $dup_count duplicates.
	 *
	 * @param int $count    Number of unique sources across all agents.
	 * @param int $dup_count Number of duplicate (same-URL) sources to add.
	 * @return array
	 */
	private function make_fanout_results( int $count, int $dup_count ): array {
		$sources = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$sources[] = array( 'url' => "https://example.com/src-$i", 'title' => "Source $i", 'excerpt' => "Excerpt $i", 'relevance' => 0.7 );
		}
		for ( $i = 0; $i < $dup_count; $i++ ) {
			$sources[] = array( 'url' => "https://example.com/src-0", 'title' => 'Dup', 'excerpt' => 'dup', 'relevance' => 0.5 ); // same URL as first.
		}
		return array(
			array(
				'agent_role' => 'researcher:mechanisms',
				'sources'    => $sources,
			),
		);
	}
}
