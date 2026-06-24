<?php
/**
 * Tests for PRAutoBlogger_Authority_Pipeline and tier routing integration.
 *
 * Covers: Economy path taken when flag OFF (proven via TierRouter unit tests),
 * cost ceiling halt -> draft hold, citation gate (below/at threshold),
 * imagery suppressed on held articles, and stage order via mock calls.
 *
 * All dependencies that would make HTTP calls are mocked or stubbed.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class AuthorityPipelineTest extends BaseTestCase {

	/** @var object Mock $wpdb. */
	private object $wpdb;

	/** @var array Rows inserted to run_decisions. */
	private array $decision_rows = array();

	/** @var array Post meta written. */
	private array $meta_written = array();

	/** @var array Posts that had wp_publish_post() called. */
	private array $published_posts = array();

	protected function setUp(): void {
		parent::setUp();

		$this->decision_rows   = array();
		$this->meta_written    = array();
		$this->published_posts = array();

		$this->wpdb         = $this->create_mock_wpdb();
		$GLOBALS["wpdb"]    = $this->wpdb;
		$this->wpdb->prefix = "wp_";

		$this->wpdb->method( "prepare" )->willReturnCallback(
			static fn( $sql, ...$args ) => $sql . " /* " . implode( ",", array_map( "strval", $args ) ) . " */"
		);

		$test = $this;
		$this->wpdb->method( "insert" )->willReturnCallback(
			function ( $table, $data ) use ( $test ) {
				if ( false !== strpos( $table, "run_decisions" ) ) {
					$test->decision_rows[] = $data;
				}
				return 1;
			}
		);

		$this->wpdb->method( "get_var" )->willReturnCallback(
			static function ( $sql ) {
				if ( false !== strpos( $sql, "SHOW TABLES" ) ) {
					return "wp_prautoblogger_run_decisions";
				}
				return null;
			}
		);
		$this->wpdb->method( "query" )->willReturn( 1 );
		$this->wpdb->method( "get_row" )->willReturn( null );
		$this->wpdb->method( "get_col" )->willReturn( array() );

		\PRAutoBlogger_Audit_Writer::flush_cache();
		\PRAutoBlogger_Run_Stage_State::flush_cache();

		// Common WP function stubs.
		Functions\when( "current_time" )->justReturn( "2026-06-24 00:00:00" );
		Functions\when( "wp_json_encode" )->alias( "json_encode" );
		Functions\when( "gmdate" )->alias( "gmdate" );
		Functions\when( "__" )->alias( static fn( $s ) => $s );
		Functions\when( "sanitize_text_field" )->alias( static fn( $s ) => $s );
		Functions\when( "wp_kses_post" )->alias( static fn( $s ) => $s );
		Functions\when( "wp_strip_all_tags" )->alias( "strip_tags" );
		Functions\when( "apply_filters" )->alias( static fn( $tag, $val ) => $val );
		Functions\when( "do_action" )->justReturn( null );
		Functions\when( "wp_insert_post" )->justReturn( 42 );
		Functions\when( "wp_set_post_terms" )->justReturn( array() );
		Functions\when( "wp_set_post_categories" )->justReturn( array() );
		Functions\when( "wp_set_post_tags" )->justReturn( array() );
		Functions\when( "post_type_exists" )->justReturn( false );
		Functions\when( "get_users" )->justReturn( array( 1 ) );
		Functions\when( "get_term_by" )->justReturn( false );
		Functions\when( "wp_insert_term" )->justReturn( array( "term_id" => 1 ) );
		Functions\when( "absint" )->alias( "intval" );
		Functions\when( "is_wp_error" )->justReturn( false );
		Functions\when( "get_post_meta" )->justReturn( "" );

		$test = $this;
		Functions\when( "wp_publish_post" )->alias(
			function ( $id ) use ( $test ) {
				$test->published_posts[] = $id;
			}
		);

		Functions\when( "update_post_meta" )->alias(
			function ( $post_id, $key, $value ) use ( $test ) {
				$test->meta_written[ $key ] = $value;
				return true;
			}
		);

		$this->stub_get_option( array(
			"prautoblogger_log_level"                  => "error",
			"prautoblogger_auto_publish"               => "0",
			"prautoblogger_citation_score_threshold"   => 0.0,
			"prautoblogger_writing_pipeline"           => "single_pass",
			"prautoblogger_writing_model"              => "openai/gpt-4o-mini",
			"prautoblogger_default_author"             => 1,
		) );
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Audit_Writer::flush_cache();
		\PRAutoBlogger_Run_Stage_State::flush_cache();
		unset( $GLOBALS["wpdb"] );
		parent::tearDown();
	}

	// -- Cost ceiling halt ------------------------------------------------

	/**
	 * When the research stage throws PRAutoBlogger_Cost_Ceiling_Exception,
	 * the pipeline must HOLD the article as draft (status="halted") and
	 * must NOT force-complete or publish it.
	 */
	public function test_cost_ceiling_halt_holds_article(): void {
		$fanout   = $this->make_fanout_throwing_ceiling();
		$tracker  = $this->make_cost_tracker_stub();
		$pipeline = new \PRAutoBlogger_Authority_Pipeline( $tracker, $fanout );
		$result   = $pipeline->run( "run-ceiling", $this->make_idea(), $tracker );

		$this->assertSame( "halted", $result["status"] );
		$this->assertSame( 0, $result["published"] );
		$this->assertEmpty( $this->published_posts );
	}

	// -- Citation gate ----------------------------------------------------

	/**
	 * When citation_score (computed by Seo_Stage from kept_sources) is below
	 * the threshold, the article must be held as draft, not published.
	 * auto_publish=1 is set so the only thing blocking publish is the gate.
	 */
	public function test_citation_gate_holds_below_threshold(): void {
		$this->stub_get_option( array(
			"prautoblogger_log_level"                => "error",
			"prautoblogger_auto_publish"             => "1",
			"prautoblogger_citation_score_threshold" => 0.8,
			"prautoblogger_writing_pipeline"         => "single_pass",
			"prautoblogger_writing_model"            => "openai/gpt-4o-mini",
			"prautoblogger_default_author"           => 1,
		) );

		$low_quality_sources = array(
			array( "url" => "https://a.com", "title" => "A", "quality_score" => 0.3 ),
		);

		$fanout    = $this->make_fanout_stub( $this->make_fanout_results( $low_quality_sources ) );
		$judge     = $this->make_judge_stub( $low_quality_sources );
		$loop      = $this->make_editorial_loop_stub( "<p>approved content</p>", false );
		$generator = $this->make_generator_stub( "<p>draft content</p>" );
		$tracker   = $this->make_cost_tracker_stub();
		$pipeline  = new \PRAutoBlogger_Authority_Pipeline( $tracker, $fanout, $judge, $loop, $generator );
		$result    = $pipeline->run( "run-citation-low", $this->make_idea(), $tracker );

		$this->assertSame( "held-citation", $result["status"] );
		$this->assertSame( 0, $result["published"] );
		$this->assertEmpty( $this->published_posts );
		$this->assertArrayHasKey( "_prautoblogger_imagery_suppressed", $this->meta_written );
		$this->assertSame( "1", $this->meta_written["_prautoblogger_imagery_suppressed"] );
	}

	/**
	 * When citation_score equals the threshold, the gate passes.
	 * auto_publish=0 so we verify status is not "held-citation".
	 */
	public function test_citation_gate_passes_at_threshold(): void {
		$this->stub_get_option( array(
			"prautoblogger_log_level"                => "error",
			"prautoblogger_auto_publish"             => "0",
			"prautoblogger_citation_score_threshold" => 0.8,
			"prautoblogger_writing_pipeline"         => "single_pass",
			"prautoblogger_writing_model"            => "openai/gpt-4o-mini",
			"prautoblogger_default_author"           => 1,
		) );

		$high_quality_sources = array(
			array( "url" => "https://a.com", "title" => "A", "quality_score" => 0.8 ),
		);

		$fanout    = $this->make_fanout_stub( $this->make_fanout_results( $high_quality_sources ) );
		$judge     = $this->make_judge_stub( $high_quality_sources );
		$loop      = $this->make_editorial_loop_stub( "<p>approved content</p>", false );
		$generator = $this->make_generator_stub( "<p>draft content</p>" );
		$tracker   = $this->make_cost_tracker_stub();
		$pipeline  = new \PRAutoBlogger_Authority_Pipeline( $tracker, $fanout, $judge, $loop, $generator );
		$result    = $pipeline->run( "run-citation-at", $this->make_idea(), $tracker );

		$this->assertNotSame( "held-citation", $result["status"] );
		$this->assertArrayNotHasKey( "_prautoblogger_imagery_suppressed", $this->meta_written );
	}

	// -- Imagery gate -----------------------------------------------------

	/**
	 * When the editorial loop escalates (article held), imagery must be
	 * suppressed via _prautoblogger_imagery_suppressed=1 post-meta.
	 */
	public function test_imagery_suppressed_on_hold(): void {
		$sources   = array( array( "url" => "https://a.com", "title" => "A", "quality_score" => 0.9 ) );
		$fanout    = $this->make_fanout_stub( $this->make_fanout_results( $sources ) );
		$judge     = $this->make_judge_stub( $sources );
		$loop      = $this->make_editorial_loop_stub( "", true );
		$generator = $this->make_generator_stub( "<p>draft content</p>" );
		$tracker   = $this->make_cost_tracker_stub();
		$pipeline  = new \PRAutoBlogger_Authority_Pipeline( $tracker, $fanout, $judge, $loop, $generator );
		$result    = $pipeline->run( "run-imagery", $this->make_idea(), $tracker );

		$this->assertSame( "held-escalated", $result["status"] );
		$this->assertArrayHasKey( "_prautoblogger_imagery_suppressed", $this->meta_written );
		$this->assertSame( "1", $this->meta_written["_prautoblogger_imagery_suppressed"] );
	}

	// -- Economy path flag check ------------------------------------------

	/**
	 * When the master flag is OFF, Tier_Router returns "economy".
	 * The Authority pipeline is never instantiated or called.
	 */
	public function test_master_flag_off_economy_path_used(): void {
		Functions\when( "get_option" )->alias(
			function ( $name, $default = false ) {
				if ( "prautoblogger_authority_pipeline_enabled" === $name ) {
					return false;
				}
				if ( "prautoblogger_log_level" === $name ) {
					return "error";
				}
				return $default;
			}
		);

		$idea   = $this->make_idea();
		$router = new \PRAutoBlogger_Tier_Router();
		$tier   = $router->resolve( $idea );

		$this->assertSame( "economy", $tier );
		$this->assertArrayNotHasKey( "_prautoblogger_imagery_suppressed", $this->meta_written );
	}

	// -- Stage ordering ---------------------------------------------------

	/**
	 * Stage order: research -> curate -> draft -> editorial -> seo -> gate.
	 * Verify that fanout, judge, generator, and editorial loop are called.
	 */
	public function test_authority_chain_runs_stages_in_order(): void {
		$sources = array( array( "url" => "https://a.com", "title" => "A", "quality_score" => 0.9 ) );
		$called  = array();

		$fanout = $this->getMockBuilder( \PRAutoBlogger_Research_Fanout::class )
			->disableOriginalConstructor()
			->onlyMethods( array( "dispatch" ) )
			->getMock();
		$fanout->expects( $this->once() )->method( "dispatch" )
			->willReturnCallback(
				function () use ( &$called, $sources ) {
					$called[] = "research";
					return $this->make_fanout_results( $sources );
				}
			);

		$judge = $this->getMockBuilder( \PRAutoBlogger_Research_Judge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( "curate" ) )
			->getMock();
		$judge->expects( $this->once() )->method( "curate" )
			->willReturnCallback(
				function () use ( &$called, $sources ) {
					$called[] = "curate";
					return $sources;
				}
			);

		$generator = $this->getMockBuilder( \PRAutoBlogger_Content_Generator::class )
			->disableOriginalConstructor()
			->onlyMethods( array( "generate" ) )
			->getMock();
		$generator->expects( $this->once() )->method( "generate" )
			->willReturnCallback(
				function () use ( &$called ) {
					$called[] = "draft";
					return "<p>draft content</p>";
				}
			);

		$loop = $this->getMockBuilder( \PRAutoBlogger_Editorial_Loop::class )
			->disableOriginalConstructor()
			->onlyMethods( array( "run", "was_escalated" ) )
			->getMock();
		$loop->expects( $this->once() )->method( "run" )
			->willReturnCallback(
				function () use ( &$called ) {
					$called[] = "editorial";
					return "<p>approved content</p>";
				}
			);
		$loop->method( "was_escalated" )->willReturn( false );

		$tracker  = $this->make_cost_tracker_stub();
		$pipeline = new \PRAutoBlogger_Authority_Pipeline( $tracker, $fanout, $judge, $loop, $generator );
		$result   = $pipeline->run( "run-order", $this->make_idea(), $tracker );

		$this->assertSame( array( "research", "curate", "draft", "editorial" ), $called );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( "status", $result );
	}

	// -- Helpers ----------------------------------------------------------

	private function make_idea(): \PRAutoBlogger_Article_Idea {
		return new \PRAutoBlogger_Article_Idea( array(
			"topic"           => "BPC-157",
			"article_type"    => "guide",
			"suggested_title" => "BPC-157 Guide",
			"summary"         => "Comprehensive guide.",
			"score"           => 0.9,
		) );
	}

	private function make_cost_tracker_stub(): object {
		$tracker = $this->getMockBuilder( \PRAutoBlogger_Cost_Tracker::class )
			->disableOriginalConstructor()
			->onlyMethods( array( "get_run_id", "get_current_run_cost", "log_api_call" ) )
			->getMock();
		$tracker->method( "get_run_id" )->willReturn( "run-test" );
		$tracker->method( "get_current_run_cost" )->willReturn( 0.01 );
		return $tracker;
	}

	private function make_fanout_throwing_ceiling(): object {
		$fanout = $this->getMockBuilder( \PRAutoBlogger_Research_Fanout::class )
			->disableOriginalConstructor()
			->onlyMethods( array( "dispatch" ) )
			->getMock();
		$fanout->method( "dispatch" )->willThrowException(
			new \PRAutoBlogger_Cost_Ceiling_Exception( "Test cost ceiling breach." )
		);
		return $fanout;
	}

	private function make_fanout_stub( array $results ): object {
		$fanout = $this->getMockBuilder( \PRAutoBlogger_Research_Fanout::class )
			->disableOriginalConstructor()
			->onlyMethods( array( "dispatch" ) )
			->getMock();
		$fanout->method( "dispatch" )->willReturn( $results );
		return $fanout;
	}

	private function make_judge_stub( array $kept_sources ): object {
		$judge = $this->getMockBuilder( \PRAutoBlogger_Research_Judge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( "curate" ) )
			->getMock();
		$judge->method( "curate" )->willReturn( $kept_sources );
		return $judge;
	}

	private function make_editorial_loop_stub( string $content, bool $escalated ): object {
		$loop = $this->getMockBuilder( \PRAutoBlogger_Editorial_Loop::class )
			->disableOriginalConstructor()
			->onlyMethods( array( "run", "was_escalated" ) )
			->getMock();
		$loop->method( "run" )->willReturn( $content );
		$loop->method( "was_escalated" )->willReturn( $escalated );
		return $loop;
	}

	private function make_generator_stub( string $content ): object {
		$gen = $this->getMockBuilder( \PRAutoBlogger_Content_Generator::class )
			->disableOriginalConstructor()
			->onlyMethods( array( "generate" ) )
			->getMock();
		$gen->method( "generate" )->willReturn( $content );
		return $gen;
	}

	private function make_fanout_results( array $sources ): array {
		return array(
			array( "sources" => $sources, "agent_role" => "mechanisms" ),
			array( "sources" => $sources, "agent_role" => "clinical" ),
		);
	}
}
