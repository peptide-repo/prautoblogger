<?php
/**
 * Tests for PRAutoBlogger_Generation_Checkpoint_Runner (v0.21.0, M4).
 *
 * Locks the chained-cron checkpoint contract:
 *   kick_off()         -- schedules cron + writes initial transient, no pipeline call.
 *   on_generate_tick() -- pops ONE idea, reschedules or finalizes; halted run aborts early.
 *
 * Sync-mode guard tests live in GenerationCheckpointRunnerSyncModeTest.php
 * (split to comply with the 300-line rule).
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class GenerationCheckpointRunnerTest extends BaseTestCase {

	/** @var string[] Cron hooks captured from wp_schedule_single_event(). */
	private array $scheduled = array();

	/** @var array<string, mixed> Option writes captured from update_option(). */
	private array $options = array();

	/** @var array<int, array> Transient writes captured from set_transient(). */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();
		$this->scheduled  = array();
		$this->options    = array();
		$this->transients = array();

		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $ts, $hook, $args = array() ) {
				$this->scheduled[] = $hook;
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'spawn_cron' )->justReturn( true );
		Functions\when( 'wp_remote_post' )->justReturn( array() );
		Functions\when( 'site_url' )->justReturn( 'https://example.com' );

		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl = 0 ) {
				$this->transients[] = array( 'key' => $key, 'value' => (array) $value );
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return $this->options[ $name ] ?? $default;
			}
		);
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-run-uuid-001' );
		// Monthly budget 0 = no limit.
		$this->options['prautoblogger_monthly_budget_usd'] = '0';
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		\PRAutoBlogger_Run_State::flush_cache();
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Run_State::flush_cache();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/** Stub the generation lock to always succeed. */
	private function wire_wpdb_for_lock(): void {
		$wpdb            = $this->create_mock_wpdb();
		$wpdb->options   = 'wp_options';
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->method( 'query' )->willReturn( 1 );
		$wpdb->method( 'get_var' )->willReturn( null );
	}

	/** Build a minimal Article_Idea-compatible array. */
	private function make_idea( string $topic = 'Test Topic' ): array {
		return array(
			'topic'           => $topic,
			'article_type'    => 'guide',
			'suggested_title' => 'Guide to ' . $topic,
			'summary'         => '',
			'score'           => 0.9,
			'analysis_id'     => 1,
			'source_ids'      => array(),
			'key_points'      => array(),
			'target_keywords' => array(),
		);
	}

	// __ kick_off() __________________________________________________________________________

	/**
	 * kick_off() schedules exactly the ORCHESTRATE cron action and writes
	 * an initial 'running' status transient. The pipeline is NOT called.
	 */
	public function test_kick_off_schedules_orchestrate_cron_and_writes_initial_transient(): void {
		\PRAutoBlogger_Generation_Checkpoint_Runner::kick_off();

		$this->assertContains(
			\PRAutoBlogger_Generation_Checkpoint_Runner::ORCHESTRATE_ACTION,
			$this->scheduled,
			'kick_off() must schedule ORCHESTRATE_ACTION'
		);

		$found_running_transient = false;
		foreach ( $this->transients as $t ) {
			if ( isset( $t['value']['status'] ) && 'running' === $t['value']['status'] ) {
				$found_running_transient = true;
				break;
			}
		}
		$this->assertTrue( $found_running_transient, 'kick_off() must write a running status transient' );
	}

	// __ on_generate_tick() __________________________________________________________________

	/**
	 * An empty queue causes generate tick to finalize without scheduling
	 * another GENERATE action (no Article_Worker call).
	 */
	public function test_generate_tick_finalizes_when_queue_is_empty(): void {
		// No queue option => get_option returns false (default from setUp alias).
		// wire_wpdb_for_lock() provides $wpdb so Helpers::finalize() ->
		// Generation_Lock::release() can run without "Call to member on null".
		$this->wire_wpdb_for_lock();
		\PRAutoBlogger_Generation_Checkpoint_Runner::on_generate_tick();

		$this->assertNotContains(
			\PRAutoBlogger_Generation_Checkpoint_Runner::GENERATE_ACTION,
			$this->scheduled,
			'Empty queue must not reschedule GENERATE'
		);
	}

	/**
	 * A halted run causes generate tick to abort: queue is cleaned up and
	 * GENERATE is not rescheduled (Article_Worker would not be called again).
	 */
	public function test_generate_tick_aborts_on_halted_run_without_rescheduling(): void {
		$run_id = 'halted-run-uuid';
		$this->options['prautoblogger_article_queue'] = array(
			'run_id'  => $run_id,
			'ideas'   => array( $this->make_idea() ),
			'results' => array( 'generated' => 0, 'published' => 0, 'rejected' => 0, 'cost' => 0.0 ),
		);

		$wpdb            = $this->create_mock_wpdb();
		$wpdb->options   = 'wp_options';
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->method( 'get_row' )->willReturn( array( 'status' => 'halted', 'ceiling_usd' => '0' ) );
		$wpdb->method( 'query' )->willReturn( 1 );
		$wpdb->method( 'get_var' )->willReturn( null );

		\PRAutoBlogger_Generation_Checkpoint_Runner::on_generate_tick();

		$this->assertArrayNotHasKey(
			'prautoblogger_article_queue',
			$this->options,
			'Halted run must trigger queue cleanup'
		);
		$this->assertNotContains(
			\PRAutoBlogger_Generation_Checkpoint_Runner::GENERATE_ACTION,
			$this->scheduled,
			'Halted run must not reschedule GENERATE'
		);
	}

	/**
	 * With 2 ideas in queue, first generate tick pops one, reschedules GENERATE,
	 * and leaves 1 idea remaining in the persisted queue.
	 */
	public function test_generate_tick_pops_one_idea_and_reschedules_when_more_remain(): void {
		$run_id = 'multi-idea-run-uuid';
		$this->options['prautoblogger_article_queue'] = array(
			'run_id'  => $run_id,
			'ideas'   => array( $this->make_idea( 'Idea A' ), $this->make_idea( 'Idea B' ) ),
			'results' => array( 'generated' => 0, 'published' => 0, 'rejected' => 0, 'cost' => 0.0 ),
		);
		$this->options['prautoblogger_checkpoint_run_id'] = $run_id;

		$wpdb            = $this->create_mock_wpdb();
		$wpdb->options   = 'wp_options';
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->method( 'get_row' )->willReturn( array( 'status' => 'running', 'ceiling_usd' => '0' ) );
		$wpdb->method( 'query' )->willReturn( 1 );
		$wpdb->method( 'get_var' )->willReturn( null );

		// Provide stub Article_Worker so CI doesn't need real LLM dependencies.
		if ( ! class_exists( 'PRAutoBlogger_Article_Worker', false ) ) {
			// phpcs:ignore Squiz.Strings.DoubleQuoteUsage.NotRequired
			eval( 'class PRAutoBlogger_Article_Worker {
				public function __construct($ct){}
				public function generate($idea){ return ["generated"=>1,"published"=>1,"rejected"=>0,"cost"=>0.01]; }
			}' );
		}
		if ( ! class_exists( 'PRAutoBlogger_Post_Assembler', false ) ) {
			// phpcs:ignore Squiz.Strings.DoubleQuoteUsage.NotRequired
			eval( 'class PRAutoBlogger_Post_Assembler { public static function amortize_research_costs($r){} }' );
		}

		\PRAutoBlogger_Generation_Checkpoint_Runner::on_generate_tick();

		$this->assertContains(
			\PRAutoBlogger_Generation_Checkpoint_Runner::GENERATE_ACTION,
			$this->scheduled,
			'More ideas remaining: GENERATE must be rescheduled'
		);
		if ( isset( $this->options['prautoblogger_article_queue'] ) ) {
			$remaining = $this->options['prautoblogger_article_queue']['ideas'] ?? array();
			$this->assertCount( 1, $remaining, 'Queue must have exactly 1 idea left after first tick' );
		}
	}
}
