<?php
/**
 * Tests for PRAutoBlogger_Research_Fanout.
 *
 * Covers: quorum logic, batch cost-reserve wiring, partial-failure handling.
 * The curl_multi execution is delegated to PRAutoBlogger_Research_Batch;
 * these tests inject a stub batch to isolate the fanout orchestration logic.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class ResearchFanoutTest extends BaseTestCase {

	/** @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb. */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = $this->create_mock_wpdb();
		$GLOBALS['wpdb'] = $this->wpdb;

		$this->wpdb->method( 'prepare' )->willReturnCallback(
			static function ( $sql, ...$args ) {
				return $sql . ' /* ' . implode( ',', array_map( 'strval', $args ) ) . ' */';
			}
		);

		\PRAutoBlogger_Run_Context::clear();
		\PRAutoBlogger_Run_State::flush_cache();
		\PRAutoBlogger_Run_Stage_State::flush_cache();

		$this->stub_get_option(
			array(
				'prautoblogger_per_run_cost_ceiling_usd'  => 0.50,
				'prautoblogger_research_agent_count'       => 3,
				'prautoblogger_research_model'             => 'google/gemini-2.5-flash-lite',
				'prautoblogger_log_level'                  => 'error',
			)
		);

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'sanitize_url' )->alias( static fn( $u ) => $u );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'sanitize_textarea_field' )->alias( 'trim' );
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Run_Context::clear();
		\PRAutoBlogger_Run_State::flush_cache();
		\PRAutoBlogger_Run_Stage_State::flush_cache();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// ── Quorum logic ────────────────────────────────────────────────────

	/**
	 * With N=3, quorum = ceil(3/2)+1 = 3. All 3 agents succeed → results returned.
	 */
	public function test_all_agents_succeed_meets_quorum(): void {
		$batch = $this->make_stub_batch( $this->make_valid_raw_results( 3 ) );
		$fanout = $this->make_fanout( $batch );

		$results = $fanout->dispatch( 'run-1', 'idea:abc', $this->make_idea(), $this->make_cost_tracker() );

		$this->assertCount( 3, $results );
		foreach ( $results as $r ) {
			$this->assertArrayHasKey( 'sources', $r );
			$this->assertArrayHasKey( 'agent_role', $r );
		}
	}

	/**
	 * N=3, quorum=3. Only 2 agents succeed (one fails) → quorum NOT met → empty.
	 */
	public function test_partial_failure_below_quorum_returns_empty(): void {
		$raw = $this->make_valid_raw_results( 3 );
		$raw[2] = array( 'error' => 'timeout' ); // Slot 2 fails.
		$batch  = $this->make_stub_batch( $raw );
		$fanout = $this->make_fanout( $batch );

		$results = $fanout->dispatch( 'run-2', 'idea:abc', $this->make_idea(), $this->make_cost_tracker() );

		$this->assertEmpty( $results, 'Quorum not met: should return empty array.' );
	}

	/**
	 * N=3, quorum=3. Agent returns invalid JSON schema → excluded → empty.
	 */
	public function test_invalid_schema_agent_excluded(): void {
		$raw    = $this->make_valid_raw_results( 3 );
		$raw[0] = array( 'content' => '{"bad":"schema"}', 'actual_cost' => 0.001 );
		$raw[1] = array( 'error' => 'bad' );
		$raw[2] = array( 'error' => 'bad' );
		$batch  = $this->make_stub_batch( $raw );
		$fanout = $this->make_fanout( $batch );

		$results = $fanout->dispatch( 'run-3', 'idea:abc', $this->make_idea(), $this->make_cost_tracker() );

		$this->assertEmpty( $results );
	}

	/**
	 * N=5, quorum = ceil(5/2)+1 = 4. 4 agents succeed, 1 fails → quorum met.
	 */
	public function test_quorum_formula_for_n_5(): void {
		$this->stub_get_option(
			array(
				'prautoblogger_per_run_cost_ceiling_usd' => 0.50,
				'prautoblogger_research_agent_count'      => 5,
				'prautoblogger_research_model'            => 'google/gemini-2.5-flash-lite',
				'prautoblogger_log_level'                 => 'error',
			)
		);

		$raw    = $this->make_valid_raw_results( 5 );
		$raw[4] = array( 'error' => 'failed' ); // 4 succeed, 1 fails: 4 >= 4 → quorum met.
		$batch  = $this->make_stub_batch( $raw );
		$fanout = $this->make_fanout( $batch );

		$results = $fanout->dispatch( 'run-4', 'idea:abc', $this->make_idea(), $this->make_cost_tracker() );

		$this->assertCount( 4, $results );
	}

	// ── Cost reserve wiring ─────────────────────────────────────────────

	/**
	 * Cost governor open_amount_reservation is NOT called when there is
	 * no active run context (ungoverned behavior unchanged).
	 */
	public function test_no_run_context_ungoverned(): void {
		// No run context set → Cost_Governor::open_amount_reservation returns null.
		$batch  = $this->make_stub_batch( $this->make_valid_raw_results( 3 ) );
		$fanout = $this->make_fanout( $batch );

		// Should not throw — just proceeds ungoverned.
		$results = $fanout->dispatch( 'run-5', 'idea:abc', $this->make_idea(), $this->make_cost_tracker() );
		$this->assertCount( 3, $results );
	}

	/**
	 * When open_amount_reservation() throws PRAutoBlogger_Cost_Ceiling_Exception,
	 * batch->execute() must NEVER be called — cost governance aborts before dispatch.
	 */
	public function test_ceiling_breach_exception_aborts_before_dispatch(): void {
		$batch = $this->getMockBuilder( \PRAutoBlogger_Research_Batch::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'execute' ) )
			->getMock();

		// PHPUnit enforces this: execute() must NEVER be called when ceiling is breached.
		$batch->expects( $this->never() )->method( 'execute' );

		// ceiling_usd=0.0: any reservation attempt breaches → UPDATE affects 0 rows → exception.
		// get_var must return the table name so Run_State::is_available() returns true;
		// without this, open_amount_reservation() short-circuits (returns null, no throw).
		$this->wpdb->method( 'get_var' )->willReturnCallback(
			static function ( $sql ) {
				// Run_State::table_name() = 'wp_prautoblogger_runs' (not run_states).
				// is_available() does SHOW TABLES LIKE %s where %s = wp_prautoblogger_runs.
				if ( false !== strpos( (string) $sql, 'prautoblogger_runs' ) ) {
					return 'wp_prautoblogger_runs';
				}
				return null;
			}
		);
		// ceiling_usd must be > 0; 0.0 means 'ceiling disabled' (governor returns null, no throw).
		// Set ceiling=0.01, already reserved=0.01 (at limit) so any additional reserve
		// fails — query() returns 0 rows affected → on_breach() → throws.
		// get_row() is called with ARRAY_A mode; get_run() does is_array() check.
		// Return an array (not an object) so get_run() returns the row instead of null.
		// ceiling=0.01, reserved=0.01 → at limit; any further reserve fails (query→0).
		$this->wpdb->method( 'get_row' )->willReturn( array(
			'run_id'       => 'run-ceiling',
			'ceiling_usd'  => 0.01,
			'reserved_usd' => 0.01,
			'settled_usd'  => 0.0,
			'status'       => 'running',
		) );
		$this->wpdb->method( 'query' )->willReturn( 0 );

		\PRAutoBlogger_Run_Context::set_run_id( 'run-ceiling' );

		$fanout = $this->make_fanout( $batch );

		$this->expectException( \PRAutoBlogger_Cost_Ceiling_Exception::class );
		$fanout->dispatch( 'run-ceiling', 'idea:abc', $this->make_idea(), $this->make_cost_tracker() );
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	/**
	 * Build a stub Research_Batch that returns pre-canned raw results.
	 *
	 * @param array $raw_results
	 * @return \PHPUnit\Framework\MockObject\MockObject
	 */
	private function make_stub_batch( array $raw_results ) {
		$stub = $this->getMockBuilder( \PRAutoBlogger_Research_Batch::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'execute' ) )
			->getMock();
		$stub->method( 'execute' )->willReturn( $raw_results );
		return $stub;
	}

	/**
	 * Build a fanout with an injected batch stub and a stubbed cost tracker.
	 *
	 * @param object $batch
	 * @return \PRAutoBlogger_Research_Fanout
	 */
	private function make_fanout( $batch ): \PRAutoBlogger_Research_Fanout {
		// NOTE: do NOT re-stub get_option here. setUp() and individual tests call
		// stub_get_option() with the correct agent_count for that test. Re-stubbing
		// here with hardcoded agent_count=3 silently overrides the test's value and
		// caused test_quorum_formula_for_n_5 to dispatch 3 agents against 5 results.
		return new \PRAutoBlogger_Research_Fanout( $batch );
	}

	/**
	 * Generate N valid raw results (the shape Research_Batch returns).
	 *
	 * @param int $n
	 * @return array
	 */
	private function make_valid_raw_results( int $n ): array {
		$results = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$results[] = array(
				'content'           => json_encode( array(
					'sources' => array(
						array( 'url' => "https://example.com/source-$i", 'title' => "Source $i", 'excerpt' => 'Test', 'relevance' => 0.8 ),
					),
				) ),
				'model'             => 'google/gemini-2.5-flash-lite',
				'prompt_tokens'     => 100,
				'completion_tokens' => 200,
				'actual_cost'       => 0.001,
			);
		}
		return $results;
	}

	/**
	 * @return \PRAutoBlogger_Article_Idea
	 */
	private function make_idea(): \PRAutoBlogger_Article_Idea {
		return new \PRAutoBlogger_Article_Idea( array(
			'topic'           => 'BPC-157 peptide research',
			'article_type'    => 'guide',
			'suggested_title' => 'BPC-157: Research Overview',
			'summary'         => 'Test.',
			'score'           => 0.9,
			'source_ids'      => array(),
			'key_points'      => array(),
			'target_keywords' => array(),
		) );
	}

	/**
	 * @return \PRAutoBlogger_Cost_Tracker
	 */
	private function make_cost_tracker(): \PRAutoBlogger_Cost_Tracker {
		$ct = $this->getMockBuilder( \PRAutoBlogger_Cost_Tracker::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'log_api_call', 'is_budget_exceeded' ) )
			->getMock();
		$ct->method( 'log_api_call' ); // void: no return value
		$ct->method( 'is_budget_exceeded' )->willReturn( false );
		return $ct;
	}
}

