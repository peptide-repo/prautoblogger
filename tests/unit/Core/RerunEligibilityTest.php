<?php
/**
 * Tests for PRAutoBlogger_Rerun_Eligibility (v0.20.0, M3).
 *
 * Locks the published-post lockout (CPO guardrail 5 incl. future/private),
 * the actively-executing-run block, the editable-stage policy, and the
 * downstream/rebuild set computation that drives stale marking.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class RerunEligibilityTest extends BaseTestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb.
	 */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb           = $this->create_mock_wpdb();
		$this->wpdb->options  = 'wp_options';
		$GLOBALS['wpdb']      = $this->wpdb;
		$this->wpdb->method( 'prepare' )->willReturnCallback(
			static function ( $sql, ...$args ) {
				return $sql . ' /* ' . implode( ',', array_map( 'strval', array_filter( $args, 'is_scalar' ) ) ) . ' */';
			}
		);
		\PRAutoBlogger_Run_State::flush_cache();
		\PRAutoBlogger_Stage_Input_Store::flush_cache();
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Run_State::flush_cache();
		\PRAutoBlogger_Stage_Input_Store::flush_cache();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Wire the mock for: runs table exists, run row with given status,
	 * generation lock free.
	 */
	private function wire_run( string $run_status ): void {
		$this->wpdb->method( 'get_var' )->willReturnCallback(
			static function ( $sql ) {
				$sql = (string) $sql;
				if ( false !== strpos( $sql, 'SHOW TABLES' ) ) {
					// Both runs + stage_inputs probes succeed.
					if ( false !== strpos( $sql, 'stage_inputs' ) ) {
						return 'wp_prautoblogger_stage_inputs';
					}
					return 'wp_prautoblogger_runs';
				}
				return null; // Lock option absent -> lock free; seed absent.
			}
		);
		$this->wpdb->method( 'get_row' )->willReturn(
			array(
				'run_id'      => 'run-1',
				'status'      => $run_status,
				'ceiling_usd' => '0.50',
			)
		);
	}

	/** Stub get_post to return a post with the given status. */
	private function stub_post( string $status ): void {
		$post              = new \WP_Post();
		$post->ID          = 99;
		$post->post_status = $status;
		Functions\when( 'get_post' )->justReturn( $post );
	}

	/**
	 * Published, scheduled, and private posts are all frozen — no
	 * edit+rerun (CPO guardrail 5). Draft and pending are not.
	 */
	public function test_published_post_lockout_covers_all_frozen_statuses(): void {
		foreach ( array( 'publish', 'future', 'private' ) as $frozen ) {
			$post              = new \WP_Post();
			$post->post_status = $frozen;
			$this->assertTrue( \PRAutoBlogger_Rerun_Eligibility::post_frozen( $post ), "status {$frozen} must freeze" );
		}
		foreach ( array( 'draft', 'pending' ) as $open ) {
			$post              = new \WP_Post();
			$post->post_status = $open;
			$this->assertFalse( \PRAutoBlogger_Rerun_Eligibility::post_frozen( $post ), "status {$open} must not freeze" );
		}
		// A run with no post yet is not frozen.
		$this->assertFalse( \PRAutoBlogger_Rerun_Eligibility::post_frozen( null ) );
	}

	/**
	 * check() rejects a published post with the WP-editor redirect copy
	 * (server-side enforcement, not just hidden UI).
	 */
	public function test_check_rejects_published_post(): void {
		$this->wire_run( 'done' );
		$this->stub_post( 'publish' );

		$verdict = \PRAutoBlogger_Rerun_Eligibility::check( 'run-1', 99 );

		$this->assertFalse( $verdict['ok'] );
		$this->assertStringContainsString( 'published', $verdict['reason'] );
	}

	/**
	 * check() rejects an actively executing run — "in progress" in the
	 * CPO sense means unpublished workflow state, not mid-execution.
	 */
	public function test_check_rejects_actively_executing_run(): void {
		$this->wire_run( 'running' );
		$this->stub_post( 'draft' );

		$verdict = \PRAutoBlogger_Rerun_Eligibility::check( 'run-1', 99 );

		$this->assertFalse( $verdict['ok'] );
		$this->assertStringContainsString( 'currently executing', $verdict['reason'] );
	}

	/**
	 * A terminal run on an unpublished post is eligible: done, failed
	 * and halted (held) runs can all be re-run.
	 */
	public function test_check_accepts_terminal_runs_on_unpublished_posts(): void {
		foreach ( array( 'done', 'failed', 'halted' ) as $status ) {
			$wpdb          = $this->create_mock_wpdb();
			$wpdb->options = 'wp_options';
			$wpdb->method( 'prepare' )->willReturnCallback( static fn( $sql, ...$a ) => (string) $sql );
			$wpdb->method( 'get_var' )->willReturnCallback(
				static function ( $sql ) {
					return ( false !== strpos( (string) $sql, 'SHOW TABLES' ) ) ? 'wp_prautoblogger_runs' : null;
				}
			);
			$wpdb->method( 'get_row' )->willReturn( array( 'status' => $status ) );
			$GLOBALS['wpdb'] = $wpdb;
			\PRAutoBlogger_Run_State::flush_cache();
			$this->stub_post( 'draft' );

			$verdict = \PRAutoBlogger_Rerun_Eligibility::check( 'run-1', 99 );
			$this->assertTrue( $verdict['ok'], "run status {$status} must be eligible: {$verdict['reason']}" );
		}
	}

	/**
	 * Editable-stage policy: writer chat stages only. review/analysis/
	 * image stages get the replay affordance refused at the policy layer.
	 */
	public function test_editable_stage_policy(): void {
		foreach ( array( 'outline', 'draft', 'polish' ) as $stage ) {
			$this->assertTrue( \PRAutoBlogger_Rerun_Eligibility::is_editable_stage( $stage ) );
		}
		foreach ( array( 'review', 'publish', 'analysis', 'research', 'llm_research', 'image_a', 'image_b', 'curate' ) as $stage ) {
			$this->assertFalse( \PRAutoBlogger_Rerun_Eligibility::is_editable_stage( $stage ) );
		}

		$verdict = \PRAutoBlogger_Rerun_Eligibility::check_replay( 'run-1', 99, 'review', 'editor', 'idea:abc' );
		$this->assertFalse( $verdict['ok'] );
	}

	/**
	 * Downstream/rebuild sets follow the canonical chain — these sets
	 * drive stale marking and demotion, so order and bounds matter.
	 */
	public function test_downstream_and_rebuild_sets(): void {
		$this->assertSame( array( 'polish', 'review', 'publish' ), \PRAutoBlogger_Rerun_Eligibility::downstream_of( 'draft' ) );
		$this->assertSame( array( 'publish' ), \PRAutoBlogger_Rerun_Eligibility::downstream_of( 'review' ) );
		$this->assertSame( array(), \PRAutoBlogger_Rerun_Eligibility::downstream_of( 'publish' ) );
		$this->assertSame( array(), \PRAutoBlogger_Rerun_Eligibility::downstream_of( 'image_a' ) );
		$this->assertSame( array( 'review', 'publish' ), \PRAutoBlogger_Rerun_Eligibility::rebuild_set( 'review' ) );
		$this->assertSame(
			array( 'outline', 'draft', 'polish', 'review', 'publish' ),
			\PRAutoBlogger_Rerun_Eligibility::rebuild_set( 'outline' )
		);
	}
}
