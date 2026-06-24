<?php
/**
 * Tests for PRAutoBlogger_Editorial_Loop.
 *
 * Covers: bound enforcement (max rounds respected), approval on first round,
 * escalation when max rounds exhausted without approval, round recording to
 * run_decisions, inline revision path vs writer revision path.
 *
 * The LLM calls are made via Chief_Editor (editor) and Editorial_Revision_Caller
 * (writer revision). Both are mocked in these unit tests.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class EditorialLoopTest extends BaseTestCase {

	/** @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb. */
	private $wpdb;

	/** @var array<int, array> Rows inserted to run_decisions via Audit_Writer. */
	private array $decision_rows = array();

	protected function setUp(): void {
		parent::setUp();
		$this->decision_rows = array();
		$this->wpdb          = $this->create_mock_wpdb();
		$GLOBALS["wpdb"]     = $this->wpdb;
		$this->wpdb->prefix  = "wp_";

		$this->wpdb->method( "prepare" )->willReturnCallback(
			static function ( $sql, ...$args ) {
				return $sql . " /* " . implode( ",", array_map( "strval", $args ) ) . " */";
			}
		);

		// Capture run_decisions inserts (Audit_Writer::record_decision).
		$test = $this;
		$this->wpdb->method( "insert" )->willReturnCallback(
			function ( $table, $data ) use ( $test ) {
				$test->decision_rows[] = $data;
				return 1;
			}
		);

		// SHOW TABLES returns the table name to simulate "tables available".
		$this->wpdb->method( "get_var" )->willReturnCallback(
			function ( $sql ) {
				if ( false !== strpos( $sql, "SHOW TABLES" ) ) {
					return "wp_prautoblogger_run_decisions";
				}
				return null;
			}
		);

		\PRAutoBlogger_Audit_Writer::flush_cache();
		\PRAutoBlogger_Run_Stage_State::flush_cache();

		Functions\when( "current_time" )->justReturn( "2026-06-24 00:00:00" );
		Functions\when( "wp_json_encode" )->alias( "json_encode" );

		$this->stub_get_option( array(
			"prautoblogger_log_level"              => "error",
			"prautoblogger_editorial_max_rounds"   => 3,
			"prautoblogger_writing_model"          => "openai/gpt-4o-mini",
		) );

		// Pipeline_Status::broadcast is a no-op in tests (WP functions stubbed).
		Functions\when( "__" )->alias( static fn( $s ) => $s );
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Audit_Writer::flush_cache();
		\PRAutoBlogger_Run_Stage_State::flush_cache();
		unset( $GLOBALS["wpdb"] );
		parent::tearDown();
	}

	// ── Approval on first round ──────────────────────────────────────────

	/**
	 * When the editor approves on round 1, the loop exits immediately and
	 * the original content is returned unchanged.
	 */
	public function test_approved_on_first_round_returns_content(): void {
		$loop    = $this->make_loop_with_results( array( "approved" ) );
		$content = "<p>Draft content.</p>";
		$idea    = $this->make_idea();

		$result = $loop->run( "run-1", "idea:abc", $content, $idea, $this->make_cost_tracker() );

		$this->assertSame( $content, $result );
		$this->assertFalse( $loop->was_escalated() );
		$this->assertCount( 1, $loop->get_rounds() );
		$this->assertSame( "approved", $loop->get_rounds()[0]->get_editor_verdict() );
	}

	// ── Max-rounds bound ────────────────────────────────────────────────

	/**
	 * The loop never exceeds `editorial_max_rounds` regardless of verdict.
	 * After max rounds without approval, was_escalated() is true and "" returned.
	 */
	public function test_escalation_after_max_rounds(): void {
		// All 3 rounds return "revised" → escalation.
		$loop = $this->make_loop_with_results( array( "revised", "revised", "revised" ) );
		$idea = $this->make_idea();

		$result = $loop->run( "run-2", "idea:abc", "<p>Draft.</p>", $idea, $this->make_cost_tracker() );

		$this->assertSame( "", $result );
		$this->assertTrue( $loop->was_escalated() );
		$this->assertCount( 3, $loop->get_rounds() );
	}

	/**
	 * If max_rounds = 1 (floor) and editor rejects, escalation happens after
	 * exactly 1 round.
	 */
	public function test_single_round_floor_escalates_after_one_round(): void {
		$this->stub_get_option( array(
			"prautoblogger_log_level"            => "error",
			"prautoblogger_editorial_max_rounds" => 1,
			"prautoblogger_writing_model"        => "openai/gpt-4o-mini",
		) );

		$loop   = $this->make_loop_with_results( array( "rejected" ) );
		$result = $loop->run( "run-3", "idea:abc", "<p>Draft.</p>", $this->make_idea(), $this->make_cost_tracker() );

		$this->assertSame( "", $result );
		$this->assertTrue( $loop->was_escalated() );
		$this->assertCount( 1, $loop->get_rounds() );
	}

	/**
	 * Configuring max_rounds above MAX_ROUNDS cap (10) is silently clamped to 10.
	 * The loop still exits once approved within the cap.
	 */
	public function test_max_rounds_clamped_to_cap(): void {
		$this->stub_get_option( array(
			"prautoblogger_log_level"            => "error",
			"prautoblogger_editorial_max_rounds" => 999,
			"prautoblogger_writing_model"        => "openai/gpt-4o-mini",
		) );

		// Approve on round 2 — verifies the loop runs at least 2 rounds before approving.
		$loop   = $this->make_loop_with_results( array( "revised", "approved" ) );
		$result = $loop->run( "run-4", "idea:abc", "<p>Draft.</p>", $this->make_idea(), $this->make_cost_tracker() );

		$this->assertNotSame( "", $result );
		$this->assertFalse( $loop->was_escalated() );
		$this->assertCount( 2, $loop->get_rounds() );
	}

	// ── Round recording ──────────────────────────────────────────────────

	/**
	 * Every non-escalation round is recorded to run_decisions with the
	 * editor verdict and round number in the rationale.
	 */
	public function test_each_round_recorded_to_run_decisions(): void {
		$loop = $this->make_loop_with_results( array( "revised", "approved" ) );
		$loop->run( "run-5", "idea:abc", "<p>Draft.</p>", $this->make_idea(), $this->make_cost_tracker() );

		$decision_rows = array_filter(
			$this->decision_rows,
			static fn( $r ) => isset( $r["stage"] ) && "editorial" === $r["stage"]
		);

		// Two rounds → two decision rows.
		$this->assertCount( 2, array_values( $decision_rows ) );

		$rows = array_values( $decision_rows );
		$this->assertSame( "revised", $rows[0]["verdict"] );
		$this->assertStringStartsWith( "Round 1:", $rows[0]["rationale"] );

		$this->assertSame( "approved", $rows[1]["verdict"] );
		$this->assertStringStartsWith( "Round 2:", $rows[1]["rationale"] );
	}

	/**
	 * Escalation is recorded as a single run_decisions row with verdict="escalated".
	 */
	public function test_escalation_recorded_as_single_decision_row(): void {
		$loop = $this->make_loop_with_results( array( "revised", "revised", "revised" ) );
		$loop->run( "run-6", "idea:abc", "<p>Draft.</p>", $this->make_idea(), $this->make_cost_tracker() );

		$escalation_rows = array_filter(
			$this->decision_rows,
			static fn( $r ) => isset( $r["verdict"] ) && "escalated" === $r["verdict"]
		);

		$this->assertCount( 1, $escalation_rows );
	}

	// ── Inline revision path ─────────────────────────────────────────────

	/**
	 * When the editor returns verdict="revised" WITH revised_content in the
	 * review object, that inline content is used without calling the writer LLM.
	 */
	public function test_inline_revised_content_is_used_without_writer_call(): void {
		$inline_revision = "<p>Inline revised.</p>";
		$editor          = $this->make_editor_mock( array( "revised" ), $inline_revision );
		$revision_caller = $this->make_revision_caller_mock();

		// Revision caller should NOT be called because editor provided inline content.
		$revision_caller->expects( $this->never() )->method( "call" );

		$loop   = new \PRAutoBlogger_Editorial_Loop( $editor, $revision_caller );
		// Run with max_rounds=1 so it escalates after 1 "revised" without approval.
		$this->stub_get_option( array(
			"prautoblogger_log_level"            => "error",
			"prautoblogger_editorial_max_rounds" => 1,
			"prautoblogger_writing_model"        => "openai/gpt-4o-mini",
		) );

		$loop->run( "run-7", "idea:abc", "<p>Draft.</p>", $this->make_idea(), $this->make_cost_tracker() );

		// Escalated (no approval in 1 round), but revision caller was never invoked.
		$this->assertTrue( $loop->was_escalated() );
	}

	// ── Writer revision path ─────────────────────────────────────────────

	/**
	 * When the editor returns "rejected" (no inline revision), the revision
	 * caller is invoked and its output becomes the next round content.
	 */
	public function test_writer_revision_caller_invoked_on_rejected_verdict(): void {
		$revised_html    = "<p>Writer revised.</p>";
		$editor          = $this->make_editor_mock( array( "rejected", "approved" ) );
		$revision_caller = $this->make_revision_caller_mock();

		$revision_caller->expects( $this->once() )
			->method( "call" )
			->willReturn( $revised_html );

		$loop = new \PRAutoBlogger_Editorial_Loop( $editor, $revision_caller );
		$result = $loop->run( "run-8", "idea:abc", "<p>Draft.</p>", $this->make_idea(), $this->make_cost_tracker() );

		// Second round approved → returns revised HTML.
		$this->assertSame( $revised_html, $result );
		$this->assertFalse( $loop->was_escalated() );
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	/**
	 * Build an Editorial_Loop whose editor returns verdicts in sequence.
	 *
	 * @param string[] $verdicts Sequence of editor verdicts (per round).
	 */
	private function make_loop_with_results( array $verdicts ): \PRAutoBlogger_Editorial_Loop {
		$editor          = $this->make_editor_mock( $verdicts );
		$revision_caller = $this->make_revision_caller_stub();
		return new \PRAutoBlogger_Editorial_Loop( $editor, $revision_caller );
	}

	/**
	 * Create a Chief_Editor mock returning the given sequence of verdicts.
	 *
	 * @param string[] $verdicts   Verdicts per successive review() call.
	 * @param string   $inline_rev Optional inline revised_content for "revised" verdicts.
	 */
	private function make_editor_mock( array $verdicts, string $inline_rev = "" ): object {
		$editor = $this->getMockBuilder( \PRAutoBlogger_Chief_Editor::class )
			->disableOriginalConstructor()
			->getMock();

		$call = 0;
		$editor->method( "review" )->willReturnCallback(
			function () use ( &$call, $verdicts, $inline_rev ) {
				$verdict = $verdicts[ $call ] ?? "revised";
				$call++;
				return new \PRAutoBlogger_Editorial_Review( array(
					"verdict"         => $verdict,
					"notes"           => "Editor notes round {$call}.",
					"revised_content" => ( "revised" === $verdict && "" !== $inline_rev ) ? $inline_rev : null,
					"quality_score"   => 0.7,
					"seo_score"       => 0.75,
					"issues"          => array(),
				) );
			}
		);

		return $editor;
	}

	/**
	 * Create an Editorial_Revision_Caller stub that returns fixed content.
	 *
	 * Uses onlyMethods() so PHPUnit knows "call" is a real method to mock.
	 */
	private function make_revision_caller_stub(): object {
		$caller = $this->getMockBuilder( \PRAutoBlogger_Editorial_Revision_Caller::class )
			->disableOriginalConstructor()
			->onlyMethods( array( "call" ) )
			->getMock();
		$caller->method( "call" )->willReturn( "<p>Revised.</p>" );
		return $caller;
	}

	/**
	 * Create an Editorial_Revision_Caller mock with no pre-set behaviour.
	 */
	private function make_revision_caller_mock(): object {
		return $this->getMockBuilder( \PRAutoBlogger_Editorial_Revision_Caller::class )
			->disableOriginalConstructor()
			->onlyMethods( array( "call" ) )
			->getMock();
	}

	/** Create a minimal article idea stub. */
	private function make_idea(): \PRAutoBlogger_Article_Idea {
		return new \PRAutoBlogger_Article_Idea( $this->get_article_idea_fixture() );
	}

	/** Create a minimal cost tracker stub (void methods stubbed). */
	private function make_cost_tracker(): object {
		$tracker = $this->getMockBuilder( \PRAutoBlogger_Cost_Tracker::class )
			->disableOriginalConstructor()
			->getMock();
		return $tracker;
	}
}
