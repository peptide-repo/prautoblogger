<?php
/**
 * Sync-mode guard tests for PRAutoBlogger_Generation_Checkpoint_Runner (v0.22.1).
 *
 * Isolated from GenerationCheckpointRunnerTest to keep both files under 300 lines.
 *
 * Contract under test:
 *   set_sync_mode(true) -> on_orchestrate_tick() must NOT schedule GENERATE_ACTION
 *     or call spawn_cron/fire_cron_now (VPS loop drives ticks directly).
 *     NON-VACUOUS: orchestrate_only() returns 1 idea so execution reaches the guard
 *     at line ~181, not the empty-ideas early return at ~148.
 *   set_sync_mode(true) -> on_generate_tick() with ideas remaining must NOT schedule
 *     GENERATE_ACTION or call spawn_cron/fire_cron_now.
 *   set_sync_mode(false) -> on_generate_tick() with ideas remaining DOES schedule
 *     GENERATE_ACTION (regression guard -- async path unaffected by sync guard).
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class GenerationCheckpointRunnerSyncModeTest extends BaseTestCase {

	/** @var string[] Cron hooks captured from wp_schedule_single_event(). */
	private array $scheduled = array();

	/** @var array<string, mixed> Option writes captured from update_option(). */
	private array $options = array();

	/** @var bool Tracks whether spawn_cron was called. */
	private bool $spawn_called = false;

	protected function setUp(): void {
		parent::setUp();
		$this->scheduled    = array();
		$this->options      = array();
		$this->spawn_called = false;

		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $ts, $hook, $args = array() ) {
				$this->scheduled[] = $hook;
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'spawn_cron' )->alias( function () {
			$this->spawn_called = true;
			return true;
		} );
		Functions\when( 'wp_remote_post' )->justReturn( array() );
		Functions\when( 'site_url' )->justReturn( 'https://example.com' );

		Functions\when( 'set_transient' )->justReturn( true );
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
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'sync-test-uuid-001' );
		$this->options['prautoblogger_monthly_budget_usd'] = '0';
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		\PRAutoBlogger_Run_State::flush_cache();
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Generation_Checkpoint_Runner::set_sync_mode( false );
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
	private function make_idea( string $topic = 'Sync Topic' ): array {
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

	// __ sync-mode guard: on_orchestrate_tick() ______________________________________________

	/**
	 * sync_mode=true + >=1 idea returned: on_orchestrate_tick() must not schedule
	 * GENERATE_ACTION or call spawn_cron (VPS loop drives generate ticks directly).
	 *
	 * NON-VACUOUS: orchestrate_only() returns 1 idea so execution reaches the sync
	 * guard at line ~181, not the empty-ideas early return at ~148.
	 */
	public function test_orchestrate_tick_in_sync_mode_does_not_schedule_background_event(): void {
		$this->wire_wpdb_for_lock();

		// Provide Pipeline_Runner stub that returns 1 idea -- forces execution past
		// the empty-ideas early return so the sync guard at line ~181 is exercised.
		if ( ! class_exists( 'PRAutoBlogger_Pipeline_Runner', false ) ) {
			// phpcs:ignore Squiz.Strings.DoubleQuoteUsage.NotRequired
			eval( 'class PRAutoBlogger_Pipeline_Runner {
				public function set_skip_dedup($v){ return $this; }
				public function orchestrate_only($ct){
					$idea = new PRAutoBlogger_Article_Idea([
						"topic"=>"Sync Topic","article_type"=>"guide",
						"suggested_title"=>"Guide","summary"=>"","score"=>0.9,
						"analysis_id"=>1,"source_ids"=>[],"key_points"=>[],
						"target_keywords"=>[]
					]);
					return [$idea];
				}
			}' );
		}

		\PRAutoBlogger_Generation_Checkpoint_Runner::set_sync_mode( true );
		\PRAutoBlogger_Generation_Checkpoint_Runner::on_orchestrate_tick();

		$this->assertNotContains(
			\PRAutoBlogger_Generation_Checkpoint_Runner::GENERATE_ACTION,
			$this->scheduled,
			'sync mode: on_orchestrate_tick must not schedule GENERATE_ACTION'
		);
		$this->assertFalse(
			$this->spawn_called,
			'sync mode: on_orchestrate_tick must not call spawn_cron'
		);
	}

	// __ sync-mode guard: on_generate_tick() _________________________________________________

	/**
	 * sync_mode=true + 2 ideas in queue: on_generate_tick() must not reschedule
	 * GENERATE_ACTION or call spawn_cron (VPS loop is the sole driver of next tick).
	 */
	public function test_generate_tick_sync_mode_suppresses_reschedule(): void {
		$run_id = 'sync-gen-tick-uuid';
		$this->options['prautoblogger_article_queue'] = array(
			'run_id'  => $run_id,
			'ideas'   => array( $this->make_idea( 'A' ), $this->make_idea( 'B' ) ),
			'results' => array( 'generated' => 0, 'published' => 0, 'rejected' => 0, 'cost' => 0.0 ),
		);

		$wpdb            = $this->create_mock_wpdb();
		$wpdb->options   = 'wp_options';
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->method( 'get_row' )->willReturn( array( 'status' => 'running', 'ceiling_usd' => '0' ) );
		$wpdb->method( 'query' )->willReturn( 1 );
		$wpdb->method( 'get_var' )->willReturn( null );

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

		\PRAutoBlogger_Generation_Checkpoint_Runner::set_sync_mode( true );
		\PRAutoBlogger_Generation_Checkpoint_Runner::on_generate_tick();

		$this->assertNotContains(
			\PRAutoBlogger_Generation_Checkpoint_Runner::GENERATE_ACTION,
			$this->scheduled,
			'sync mode: on_generate_tick must not schedule GENERATE_ACTION'
		);
		$this->assertFalse(
			$this->spawn_called,
			'sync mode: on_generate_tick must not call spawn_cron'
		);
	}

	// __ async regression guard ______________________________________________________________

	/**
	 * sync_mode=false: on_generate_tick() with ideas remaining DOES schedule
	 * GENERATE_ACTION (regression guard -- async path must be unaffected by sync guard).
	 */
	public function test_generate_tick_async_mode_schedules_generate_action(): void {
		$run_id = 'async-regression-uuid';
		$this->options['prautoblogger_article_queue'] = array(
			'run_id'  => $run_id,
			'ideas'   => array( $this->make_idea( 'A' ), $this->make_idea( 'B' ) ),
			'results' => array( 'generated' => 0, 'published' => 0, 'rejected' => 0, 'cost' => 0.0 ),
		);

		$wpdb            = $this->create_mock_wpdb();
		$wpdb->options   = 'wp_options';
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->method( 'get_row' )->willReturn( array( 'status' => 'running', 'ceiling_usd' => '0' ) );
		$wpdb->method( 'query' )->willReturn( 1 );
		$wpdb->method( 'get_var' )->willReturn( null );

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

		\PRAutoBlogger_Generation_Checkpoint_Runner::set_sync_mode( false );
		\PRAutoBlogger_Generation_Checkpoint_Runner::on_generate_tick();

		$this->assertContains(
			\PRAutoBlogger_Generation_Checkpoint_Runner::GENERATE_ACTION,
			$this->scheduled,
			'async mode: on_generate_tick must schedule GENERATE_ACTION when ideas remain'
		);
	}
}
