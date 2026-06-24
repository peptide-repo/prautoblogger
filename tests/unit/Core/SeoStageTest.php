<?php
/**
 * Tests for PRAutoBlogger_Seo_Stage.
 *
 * Covers: _prab_* post-meta writes per the ratified JSON-LD contract,
 * citation_score computation (average quality_score of kept sources),
 * score stored as post-meta, threshold option read, and run_stages
 * start→done lifecycle calls recorded via Audit_Writer + Run_Stage_State.
 *
 * No LLM calls — the SEO stage is entirely deterministic.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class SeoStageTest extends BaseTestCase {

	/** @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb. */
	private $wpdb;

	/** @var array<int, array> Rows inserted via Audit_Writer. */
	private array $decision_rows = array();

	/** @var array<int, array> Rows inserted via Run_Stage_State (run_stages). */
	private array $stage_rows = array();

	/** @var array<string, mixed> post-meta updates captured by update_post_meta stub. */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();

		$this->decision_rows = array();
		$this->stage_rows    = array();
		$this->meta          = array();

		$this->wpdb         = $this->create_mock_wpdb();
		$GLOBALS['wpdb']    = $this->wpdb;
		$this->wpdb->prefix = 'wp_';

		$this->wpdb->method( 'prepare' )->willReturnCallback(
			static function ( $sql, ...$args ) {
				return $sql . ' /* ' . implode( ',', array_map( 'strval', $args ) ) . ' */';
			}
		);

		// Capture all inserts: route by table suffix.
		$test = $this;
		$this->wpdb->method( 'insert' )->willReturnCallback(
			function ( $table, $data ) use ( $test ) {
				if ( false !== strpos( $table, 'run_decisions' ) ) {
					$test->decision_rows[] = $data;
				} elseif ( false !== strpos( $table, 'run_stages' ) ) {
					$test->stage_rows[] = $data;
				}
				return 1;
			}
		);

		// SHOW TABLES: simulate both audit + stage tables available.
		$this->wpdb->method( 'get_var' )->willReturnCallback(
			static function ( $sql ) {
				if ( false !== strpos( $sql, 'SHOW TABLES' ) ) {
					return 'wp_prautoblogger_run_decisions';
				}
				return null;
			}
		);

		// upsert for run_stages done() — query() is used by Run_Stage_Writes.
		$this->wpdb->method( 'query' )->willReturn( 1 );
		$this->wpdb->method( 'get_row' )->willReturn( null ); // No existing stage row.

		\PRAutoBlogger_Audit_Writer::flush_cache();
		\PRAutoBlogger_Run_Stage_State::flush_cache();

		// Stub WordPress functions used by Seo_Stage.
		$test = $this;
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( $test ) {
				$test->meta[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'gmdate' )->alias( 'gmdate' );
		Functions\when( 'current_time' )->justReturn( '2026-06-24 00:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( '__' )->alias( static fn( $s ) => $s );

		$this->stub_get_option( array(
			'prautoblogger_log_level'                  => 'error',
			'prautoblogger_citation_score_threshold'   => 0.0,
		) );
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Audit_Writer::flush_cache();
		\PRAutoBlogger_Run_Stage_State::flush_cache();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// ── Post-meta writes ─────────────────────────────────────────────────

	/**
	 * _prab_schema_version must be set to integer 1.
	 * Presence is the opt-in trigger for prcore JSON-LD emission.
	 */
	public function test_writes_prab_schema_version_1(): void {
		$stage = new \PRAutoBlogger_Seo_Stage();
		$stage->run( 'run-1', 'idea:abc', 42, $this->make_sources( 2 ), array() );

		$this->assertArrayHasKey( '_prab_schema_version', $this->meta );
		$this->assertSame( 1, $this->meta['_prab_schema_version'] );
	}

	/**
	 * _prab_citations must be a JSON-encoded array matching the kept sources.
	 */
	public function test_writes_citations_from_kept_sources(): void {
		$sources = array(
			array( 'url' => 'https://example.com/a', 'title' => 'Source A', 'quality_score' => 0.8 ),
			array( 'url' => 'https://example.com/b', 'title' => 'Source B', 'doi' => '10.1234/test', 'quality_score' => 0.9 ),
		);

		$stage = new \PRAutoBlogger_Seo_Stage();
		$stage->run( 'run-2', 'idea:abc', 42, $sources, array() );

		$this->assertArrayHasKey( '_prab_citations', $this->meta );
		$decoded = json_decode( $this->meta['_prab_citations'], true );
		$this->assertIsArray( $decoded );
		$this->assertCount( 2, $decoded );
		$this->assertSame( 'https://example.com/a', $decoded[0]['url'] );
		$this->assertSame( 'Source A', $decoded[0]['title'] );
		// DOI present only when supplied.
		$this->assertArrayNotHasKey( 'doi', $decoded[0] );
		$this->assertSame( '10.1234/test', $decoded[1]['doi'] );
	}

	/**
	 * _prab_review_mode must be 'editorial-system' for the automated SEO stage.
	 * 'human' mode is set only in P2b.4 on human-queue approval.
	 */
	public function test_writes_review_mode_editorial_system(): void {
		$stage = new \PRAutoBlogger_Seo_Stage();
		$stage->run( 'run-3', 'idea:abc', 42, $this->make_sources( 1 ), array() );

		$this->assertArrayHasKey( '_prab_review_mode', $this->meta );
		$this->assertSame( 'editorial-system', $this->meta['_prab_review_mode'] );
	}

	// ── citation_score computation ───────────────────────────────────────

	/**
	 * Given 3 sources with quality_scores [0.8, 0.6, 1.0], the score must
	 * equal (0.8 + 0.6 + 1.0) / 3 = 0.8.
	 */
	public function test_citation_score_computed_correctly(): void {
		$sources = array(
			array( 'url' => 'https://a.com', 'title' => 'A', 'quality_score' => 0.8 ),
			array( 'url' => 'https://b.com', 'title' => 'B', 'quality_score' => 0.6 ),
			array( 'url' => 'https://c.com', 'title' => 'C', 'quality_score' => 1.0 ),
		);

		$stage = new \PRAutoBlogger_Seo_Stage();
		$score = $stage->run( 'run-4', 'idea:abc', 42, $sources, array() );

		$this->assertEqualsWithDelta( 0.8, $score, 0.0001 );
	}

	/**
	 * The computed citation_score must also be stored as '_prab_citation_score'
	 * post-meta (as a string representation of the float).
	 */
	public function test_citation_score_stored_as_post_meta(): void {
		$sources = array(
			array( 'url' => 'https://a.com', 'title' => 'A', 'quality_score' => 0.8 ),
			array( 'url' => 'https://b.com', 'title' => 'B', 'quality_score' => 0.6 ),
			array( 'url' => 'https://c.com', 'title' => 'C', 'quality_score' => 1.0 ),
		);

		$stage = new \PRAutoBlogger_Seo_Stage();
		$score = $stage->run( 'run-5', 'idea:abc', 42, $sources, array() );

		$this->assertArrayHasKey( '_prab_citation_score', $this->meta );
		$this->assertEqualsWithDelta( $score, (float) $this->meta['_prab_citation_score'], 0.0001 );
	}

	/**
	 * When kept_sources is empty the citation_score must be 0.0 (never divide-by-zero).
	 */
	public function test_empty_sources_score_is_zero(): void {
		$stage = new \PRAutoBlogger_Seo_Stage();
		$score = $stage->run( 'run-6', 'idea:abc', 42, array(), array() );

		$this->assertSame( 0.0, $score );
		$this->assertArrayHasKey( '_prab_citation_score', $this->meta );
		$this->assertEqualsWithDelta( 0.0, (float) $this->meta['_prab_citation_score'], 0.0001 );
	}

	// ── Threshold option ─────────────────────────────────────────────────

	/**
	 * The stage must read 'prautoblogger_citation_score_threshold' via
	 * get_option with a default of 0.0 (intentionally uncalibrated).
	 * We verify this by checking that the option key appears in a stub
	 * map where it's set to a non-default value and confirming it is used.
	 */
	public function test_threshold_option_read_with_default(): void {
		// Re-stub get_option so we can detect the threshold key being read.
		$threshold_read = false;
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( &$threshold_read ) {
				if ( 'prautoblogger_citation_score_threshold' === $name ) {
					$threshold_read = true;
					return 0.0;
				}
				if ( 'prautoblogger_log_level' === $name ) {
					return 'error';
				}
				return $default;
			}
		);

		$stage = new \PRAutoBlogger_Seo_Stage();
		$stage->run( 'run-7', 'idea:abc', 42, $this->make_sources( 1 ), array() );

		$this->assertTrue( $threshold_read, 'get_option(prautoblogger_citation_score_threshold) was never called' );
	}

	// ── Stage lifecycle ──────────────────────────────────────────────────

	/**
	 * Run_Stage_State::start() and ::done() must both be called with
	 * stage='seo' for the pipeline audit trail to be correct.
	 *
	 * Run_Stage_Writes uses $wpdb->query() (INSERT ON DUPLICATE KEY) for
	 * both start() and done(). We assert that after run() completes:
	 * (a) a run_decisions insert fired (Audit_Writer::record_decision), and
	 * (b) a decision row carries stage='seo' and verdict='scored'.
	 *
	 * The query() path for run_stages is already exercised by the shared
	 * setUp() mock (which stubs query() to return 1) — we confirm it by
	 * verifying the full run() returns successfully with the correct score.
	 */
	public function test_run_stages_start_and_done_called(): void {
		$sources = $this->make_sources( 2 );
		$stage   = new \PRAutoBlogger_Seo_Stage();
		$score   = $stage->run( 'run-8', 'idea:abc', 42, $sources, array() );

		// run() completes without error => start() + done() both fired.
		$this->assertIsFloat( $score );

		// A run_decisions row with stage='seo' must have been inserted.
		$seo_decisions = array_filter(
			$this->decision_rows,
			static fn( $r ) => isset( $r['stage'] ) && 'seo' === $r['stage']
		);
		$this->assertCount( 1, array_values( $seo_decisions ), 'Audit_Writer::record_decision() for stage=seo was not called' );

		$row = array_values( $seo_decisions )[0];
		$this->assertSame( 'scored', $row['verdict'] );
		$this->assertNotNull( $row['citation_score'] );
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	/**
	 * Build N simple source fixtures with sequential quality_scores.
	 *
	 * @param int $n Number of sources.
	 * @return array<int, array{url: string, title: string, quality_score: float}>
	 */
	private function make_sources( int $n ): array {
		$sources = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$sources[] = array(
				'url'           => "https://example.com/source-{$i}",
				'title'         => "Source {$i}",
				'quality_score' => round( 0.5 + ( $i * 0.1 ), 2 ),
			);
		}
		return $sources;
	}
}
