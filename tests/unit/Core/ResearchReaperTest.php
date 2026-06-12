<?php
/**
 * Tests for PRAutoBlogger_Research_Reaper.
 *
 * Exercises the five scenarios spec'd in convo thread
 * research-cost-reaper/01-cto-handoff.md: meta-based reap, grace-period
 * skip, 7-day stale delete, gen_log fallback when meta is missing, and
 * idempotency on repeat runs.
 *
 * All DB work is mocked via a $wpdb stub — no real database round-trips.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class ResearchReaperTest extends BaseTestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb.
	 */
	private $wpdb;

	/**
	 * Captured INSERT rows (from amortize_research_costs delegating to $wpdb->insert).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $inserted_rows = [];

	/**
	 * Captured DELETE calls: [$table, $where-array].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $deleted_rows = [];

	/**
	 * Fixture: the rows get_results should hand back for the orphan scan.
	 *
	 * @var array<int, object>
	 */
	private array $orphan_fixtures = [];

	/**
	 * Fixture: post IDs the reaper's fallback gen_log query should return.
	 *
	 * @var int[]
	 */
	private array $gen_log_post_ids = [];

	protected function setUp(): void {
		parent::setUp();

		$this->inserted_rows    = [];
		$this->deleted_rows     = [];
		$this->orphan_fixtures  = [];
		$this->gen_log_post_ids = [];

		// Bespoke $wpdb mock — BaseTestCase::create_mock_wpdb() doesn't
		// stub `delete` or `get_col`, both of which the reaper + amortize
		// call into. Wide-method mock keeps the test scope self-contained.
		$this->wpdb = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ 'prepare', 'get_var', 'get_results', 'get_row', 'get_col', 'insert', 'delete', 'update', 'query' ] )
			->getMock();
		$this->wpdb->prefix         = 'wp_';
		$this->wpdb->insert_id      = 1;
		$this->wpdb->last_error     = '';
		$this->wpdb->prab_cost_logs = 'wp_prab_cost_logs';
		$this->wpdb->method( 'insert' )->willReturnCallback(
			function ( $table, $row ) {
				$this->inserted_rows[] = array_merge( [ '__table' => $table ], $row );
				return 1;
			}
		);
		$this->wpdb->method( 'delete' )->willReturnCallback(
			function ( $table, $where ) {
				$this->deleted_rows[] = [ (string) $table, (array) $where ];
				return 1;
			}
		);
		// prepare() returns its args joined so we can pattern-match in get_* stubs.
		$this->wpdb->method( 'prepare' )->willReturnCallback(
			function ( $sql, ...$args ) {
				return $sql . '|' . implode( '|', array_map( 'strval', $args ) );
			}
		);
		// get_results routes by SQL fragment between the reaper's orphan scan
		// and the occasional Logger insert probes.
		$this->wpdb->method( 'get_results' )->willReturnCallback(
			function ( $sql ) {
				if ( false !== stripos( (string) $sql, "post_id IS NULL AND created_at <" ) ) {
					return $this->orphan_fixtures;
				}
				return [];
			}
		);
		// get_row serves amortize_research_costs's research-row lookup.
		$this->wpdb->method( 'get_row' )->willReturnCallback(
			function ( $sql ) {
				foreach ( $this->orphan_fixtures as $o ) {
					if ( false !== stripos( (string) $sql, (string) $o->run_id ) ) {
						return (object) [
							'id'                => $o->id,
							'estimated_cost'    => $o->estimated_cost ?? 0.50,
							'provider'          => $o->provider ?? 'openrouter',
							'model'             => $o->model ?? 'gemini-flash',
							'prompt_tokens'     => $o->prompt_tokens ?? 1000,
							'completion_tokens' => $o->completion_tokens ?? 500,
							'prompt_version'    => $o->prompt_version ?? null,
							'agent_role'        => $o->agent_role ?? '',
						];
					}
				}
				return null;
			}
		);
		// get_col is the reaper's fallback "posts by run_id" and amortize's
		// "distinct post_ids" query — both read from the same fixture.
		$this->wpdb->method( 'get_col' )->willReturnCallback(
			fn() => array_map( 'strval', $this->gen_log_post_ids )
		);

		$GLOBALS['wpdb'] = $this->wpdb;

		$this->stub_get_option( [
			'prautoblogger_log_level' => 'info',
		] );

		// Reaper uses get_posts to look up sibling posts via post_meta.
		Functions\when( 'get_posts' )->justReturn( [] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Return only the `generation_log` inserts from the capture — filters out
	 * the `event_log` rows that Logger::instance()->info() writes as a side
	 * effect of the reaper + amortize's own progress logging.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function gen_log_inserts(): array {
		return array_values( array_filter(
			$this->inserted_rows,
			static fn( $row ) => false !== strpos( (string) ( $row['__table'] ?? '' ), 'generation_log' )
		) );
	}

	/**
	 * Helper: seed the orphan list returned by the reaper's primary SELECT.
	 *
	 * @param array<int, array{id: int, run_id: string, created_at: string, estimated_cost?: float, provider?: string, model?: string, prompt_tokens?: int, completion_tokens?: int}> $orphans
	 */
	private function seed_orphans( array $orphans ): void {
		$this->orphan_fixtures = array_map(
			static fn( $r ) => (object) $r,
			$orphans
		);
	}

	/**
	 * Helper: set the post_ids the gen_log fallback query returns.
	 *
	 * @param int[] $post_ids
	 */
	private function seed_gen_log_posts_for_run( array $post_ids ): void {
		$this->gen_log_post_ids = $post_ids;
	}

	/**
	 * 1. Reap succeeds via post_meta primary lookup.
	 *
	 * Orphan row is >1h old, `_prautoblogger_run_id` meta returns two
	 * post IDs, amortize inserts two attributed rows and deletes the
	 * original orphan.
	 */
	public function test_reaps_orphan_with_matching_run_id_posts(): void {
		$old_ts = gmdate( 'Y-m-d H:i:s', time() - ( 2 * HOUR_IN_SECONDS ) );
		$this->seed_orphans( [ [ 'id' => 99, 'run_id' => 'run-abc', 'created_at' => $old_ts, 'estimated_cost' => 0.40 ] ] );

		// In the v0.8.1 world both sources agree. Meta is the reaper's gate;
		// gen_log also has rows so `amortize_research_costs()` can do its
		// own internal post-count query.
		Functions\when( 'get_posts' )->alias(
			static fn( $args ) => ( '_prautoblogger_run_id' === ( $args['meta_key'] ?? '' ) && 'run-abc' === ( $args['meta_value'] ?? '' ) )
				? [ 101, 102 ]
				: []
		);
		$this->seed_gen_log_posts_for_run( [ 101, 102 ] );

		$stats = \PRAutoBlogger_Research_Reaper::reap();

		$this->assertSame( 1, $stats['reaped'] );
		$this->assertSame( 0, $stats['deleted'] );
		$this->assertSame( 0, $stats['skipped'] );
		$this->assertCount(
			2,
			$this->gen_log_inserts(),
			'One amortized row per post should be inserted into the generation_log.'
		);
		// Original orphan deleted by amortize_research_costs.
		$this->assertNotEmpty( array_filter( $this->deleted_rows, static fn( $d ) => ( $d[1]['id'] ?? null ) === 99 ) );
	}

	/**
	 * 2. Orphan within the 1-hour grace window is left alone — a live
	 * pipeline may still be in the middle of its own amortize step.
	 */
	public function test_skips_orphan_within_grace_period(): void {
		// The primary SELECT filters on `created_at < (now - 1h)`, so a
		// fresh orphan is excluded from the result set at the DB boundary.
		// We simulate that by returning an empty orphan list.
		$this->seed_orphans( [] );

		Functions\when( 'get_posts' )->justReturn( [ 101, 102 ] );

		$stats = \PRAutoBlogger_Research_Reaper::reap();

		$this->assertSame( 0, $stats['reaped'] );
		$this->assertSame( 0, $stats['deleted'] );
		$this->assertSame( 0, $stats['skipped'] );
		$this->assertEmpty( $this->inserted_rows );
		$this->assertEmpty( $this->deleted_rows );
	}

	/**
	 * 3. Orphan older than 7 days with zero matching posts is deleted
	 * outright — research cost is sunk with nothing to attribute to.
	 */
	public function test_deletes_orphan_with_no_articles_after_7_days(): void {
		$stale_ts = gmdate( 'Y-m-d H:i:s', time() - ( 8 * DAY_IN_SECONDS ) );
		$this->seed_orphans( [ [ 'id' => 77, 'run_id' => 'run-dead', 'created_at' => $stale_ts ] ] );

		// No posts via meta, no posts via gen_log.
		Functions\when( 'get_posts' )->justReturn( [] );
		$this->seed_gen_log_posts_for_run( [] );

		$stats = \PRAutoBlogger_Research_Reaper::reap();

		$this->assertSame( 0, $stats['reaped'] );
		$this->assertSame( 1, $stats['deleted'] );
		$this->assertNotEmpty( array_filter( $this->deleted_rows, static fn( $d ) => ( $d[1]['id'] ?? null ) === 77 ) );
	}

	/**
	 * 4. When `_prautoblogger_run_id` post_meta is absent (legacy posts
	 * predating v0.8.1), the reaper falls back to querying gen_log for
	 * distinct post_ids carrying the same run_id.
	 */
	public function test_falls_back_to_gen_log_when_post_meta_missing(): void {
		$old_ts = gmdate( 'Y-m-d H:i:s', time() - ( 2 * HOUR_IN_SECONDS ) );
		$this->seed_orphans( [ [ 'id' => 55, 'run_id' => 'run-legacy', 'created_at' => $old_ts, 'estimated_cost' => 0.30 ] ] );

		// Meta lookup returns nothing.
		Functions\when( 'get_posts' )->justReturn( [] );
		// But gen_log has two posts with this run_id.
		$this->seed_gen_log_posts_for_run( [ 201, 202 ] );

		$stats = \PRAutoBlogger_Research_Reaper::reap();

		$this->assertSame( 1, $stats['reaped'] );
		$this->assertCount( 2, $this->gen_log_inserts() );
	}

	/**
	 * 5. A second reap run is a no-op — the first run deleted the orphan
	 * and inserted amortized per-article rows, so the scan returns empty.
	 */
	public function test_idempotent_on_repeat_run(): void {
		// Simulate "already reaped" state: scan returns no orphans.
		$this->seed_orphans( [] );

		$stats_1 = \PRAutoBlogger_Research_Reaper::reap();
		$stats_2 = \PRAutoBlogger_Research_Reaper::reap();

		$this->assertSame( 0, $stats_1['reaped'] );
		$this->assertSame( 0, $stats_2['reaped'] );
		$this->assertEmpty( $this->inserted_rows );
		$this->assertEmpty( $this->deleted_rows );
	}
}
