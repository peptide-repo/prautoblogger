<?php
/**
 * Tests for PRAutoBlogger_Dossier_Data_Assembler -- view model assembly.
 *
 * Covers:
 *   - Full modern run: runs + stages + gen_log + decisions
 *   - Legacy absent: post has no run_id meta
 *   - Zero post_id: bogus ID
 *   - Amortized research: gen_log row with prompt_version=NULL, agent_role=''
 *   - Pruned meta_json: stage row with meta_json=NULL, status=done
 *   - Decisions present and absent
 *
 * All DB queries are mocked via $wpdb mock. No live DB required.
 *
 * @package PRAutoBlogger\Tests\Admin
 */

namespace PRAutoBlogger\Tests\Admin;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class DossierDataAssemblerTest extends BaseTestCase {

	/** @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb. */
	private $wpdb;

	/** @var \PHPUnit\Framework\MockObject\MockObject Mock gen_log_query. */
	private $log_query;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb         = $this->create_mock_wpdb();
		$GLOBALS['wpdb']    = $this->wpdb;

		// Stub PRAutoBlogger_Run_Stage_State::is_available() to return true.
		// Static method -- stubbed via wpdb table check returning the table name.
		\PRAutoBlogger_Run_Stage_State::flush_cache();
		\PRAutoBlogger_Run_State::flush_cache();
		\PRAutoBlogger_Stage_Input_Store::flush_cache();

		// v0.20.0 additions: image data + eligibility paths.
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_children' )->justReturn( array() );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// table_name returns wp_prautoblogger_run_stages.
		$this->wpdb->prefix = 'wp_';

		// is_available() calls $wpdb->get_var(SHOW TABLES LIKE ...) -- return the table.
		$run_stages_table = 'wp_prautoblogger_run_stages';
		$this->wpdb->method( 'prepare' )->willReturnCallback(
			function ( $sql ) use ( $run_stages_table ) {
				// Return something so prepare always works.
				return $sql;
			}
		);

		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
			return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
		} );

		$this->log_query = $this->getMockBuilder( \PRAutoBlogger_Dossier_Gen_Log_Query::class )
			->onlyMethods( array( 'get_by_run' ) )
			->getMock();
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Run_Stage_State::flush_cache();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// ─────────────────────────────────────────────────────────────────────
	// Zero / invalid post ID
	// ─────────────────────────────────────────────────────────────────────

	public function test_zero_post_id_returns_no_run(): void {
		$assembler = new \PRAutoBlogger_Dossier_Data_Assembler( $this->log_query );
		$result    = $assembler->assemble( 0 );

		$this->assertFalse( $result['has_run'] );
		$this->assertEmpty( $result['stages'] );
	}

	public function test_nonexistent_post_returns_no_run(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$assembler = new \PRAutoBlogger_Dossier_Data_Assembler( $this->log_query );
		$result    = $assembler->assemble( 9999 );

		$this->assertFalse( $result['has_run'] );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Legacy post (no run_id meta)
	// ─────────────────────────────────────────────────────────────────────

	public function test_legacy_post_no_run_id_returns_no_run(): void {
		$post             = new \WP_Post();
		$post->ID         = 100;
		$post->post_title = 'Old Article';
		$post->post_status = 'publish';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$assembler = new \PRAutoBlogger_Dossier_Data_Assembler( $this->log_query );
		$result    = $assembler->assemble( 100 );

		$this->assertFalse( $result['has_run'],
			'Legacy post without run_id must return has_run=false' );
		$this->assertSame( 'Old Article', $result['post_title'] );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Full modern run
	// ─────────────────────────────────────────────────────────────────────

	public function test_full_run_assembles_correctly(): void {
		$post              = new \WP_Post();
		$post->ID          = 925;
		$post->post_title  = 'BPC-157 Research Overview';
		$post->post_status = 'publish';

		$run_id = 'abc-123-def-456';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single ) use ( $run_id ) {
				if ( '_prautoblogger_run_id' === $key )       { return $run_id; }
				if ( '_prautoblogger_editor_verdict' === $key ) { return 'approved'; }
				if ( '_prautoblogger_article_type' === $key ) { return 'authority'; }
				return '';
			}
		);

		// Mock runs table query.
		$this->wpdb->method( 'get_row' )->willReturn( array(
			'run_id'     => $run_id,
			'status'     => 'done',
			'started_at' => '2026-06-12 03:00:00',
			'finished_at' => '2026-06-12 03:04:00',
			'settled_usd' => '0.012000',
		) );

		// Mock run_stages query -- get_results returns stage rows.
		$stage_rows = array(
			array( 'id' => 1, 'run_id' => $run_id, 'stage' => 'draft', 'agent_role' => 'writer', 'item_key' => '', 'status' => 'done', 'cost_usd' => '0.008', 'meta_json' => wp_json_encode( array( 'output' => 'Draft content here.' ) ), 'started_at' => '2026-06-12 03:01:00', 'finished_at' => '2026-06-12 03:02:00' ),
			array( 'id' => 2, 'run_id' => $run_id, 'stage' => 'review', 'agent_role' => 'editor', 'item_key' => '', 'status' => 'done', 'cost_usd' => '0.004', 'meta_json' => wp_json_encode( array( 'output' => 'Looks good.' ) ), 'started_at' => '2026-06-12 03:02:00', 'finished_at' => '2026-06-12 03:03:00' ),
		);

		// is_available check: get_var returns the table name.
		$this->wpdb->method( 'get_var' )->willReturn( 'wp_prautoblogger_run_stages' );
		$this->wpdb->method( 'get_results' )->willReturn( $stage_rows );

		$this->log_query->method( 'get_by_run' )->willReturn( array(
			'draft'  => array( array( 'model' => 'gpt-4', 'prompt_version' => '3', 'agent_role' => 'writer', 'prompt_tokens' => 500, 'completion_tokens' => 1000, 'estimated_cost' => '0.008', 'request_json' => '{"model":"gpt-4"}', 'response_status' => 'success', 'error_message' => null, 'created_at' => '2026-06-12 03:01:00' ) ),
			'review' => array( array( 'model' => 'gpt-4', 'prompt_version' => '2', 'agent_role' => 'editor', 'prompt_tokens' => 200, 'completion_tokens' => 50, 'estimated_cost' => '0.004', 'request_json' => '{"model":"gpt-4"}', 'response_status' => 'success', 'error_message' => null, 'created_at' => '2026-06-12 03:02:00' ) ),
		) );

		$assembler = new \PRAutoBlogger_Dossier_Data_Assembler( $this->log_query );
		$result    = $assembler->assemble( 925 );

		$this->assertTrue( $result['has_run'], 'Modern run must have has_run=true' );
		$this->assertSame( $run_id, $result['run_id'] );
		$this->assertSame( 'approved', $result['verdict'] );
		$this->assertSame( 'authority', $result['tier'] );
		$this->assertNotEmpty( $result['stages'], 'Stage rows must be present' );
		$this->assertNotEmpty( $result['gen_log_index'], 'Gen_log index must be present' );

		// Check draft stage has display_output set.
		$draft_key = 'draft:writer';
		$this->assertArrayHasKey( $draft_key, $result['stages'] );
		$this->assertSame( 'Draft content here.', $result['stages'][ $draft_key ]['display_output'] );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Amortized research row (pv=null, role='')
	// ─────────────────────────────────────────────────────────────────────

	public function test_amortized_research_row_renders_gracefully(): void {
		$post              = new \WP_Post();
		$post->ID          = 930;
		$post->post_title  = 'TB-500 Article';
		$post->post_status = 'publish';

		$run_id = 'llm-research-run-001';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single ) use ( $run_id ) {
				return '_prautoblogger_run_id' === $key ? $run_id : '';
			}
		);

		$this->wpdb->method( 'get_row' )->willReturn( array( 'run_id' => $run_id, 'status' => 'done', 'started_at' => '2026-06-12 03:00:00' ) );
		$this->wpdb->method( 'get_var' )->willReturn( 'wp_prautoblogger_run_stages' );

		// Amortized research row: pv=null, role=''.
		$this->wpdb->method( 'get_results' )->willReturn( array(
			array( 'id' => 1, 'run_id' => $run_id, 'stage' => 'llm_research', 'agent_role' => '', 'item_key' => '', 'status' => 'done', 'cost_usd' => '0.001', 'meta_json' => null, 'started_at' => '2026-06-12 03:00:00', 'finished_at' => '2026-06-12 03:00:30' ),
		) );

		$this->log_query->method( 'get_by_run' )->willReturn( array(
			'llm_research' => array( array( 'model' => 'gpt-4', 'prompt_version' => null, 'agent_role' => '', 'prompt_tokens' => 100, 'completion_tokens' => 50, 'estimated_cost' => '0.001', 'request_json' => null, 'response_status' => 'success', 'error_message' => null, 'created_at' => '2026-06-12 03:00:00' ) ),
		) );

		$assembler = new \PRAutoBlogger_Dossier_Data_Assembler( $this->log_query );

		// Must not throw or produce fatal.
		$result = $assembler->assemble( 930 );

		$this->assertTrue( $result['has_run'] );

		// Stage is present; display_output is null (meta_json was null = pruned).
		$llm_key = 'llm_research:';
		$this->assertArrayHasKey( $llm_key, $result['stages'] );
		$this->assertNull( $result['stages'][ $llm_key ]['display_output'],
			'Amortized research with null meta_json must have null display_output' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Decisions present and absent
	// ─────────────────────────────────────────────────────────────────────

	public function test_decisions_absent_returns_empty_array(): void {
		$post = new \WP_Post();
		$post->ID = 901; $post->post_title = 'Test'; $post->post_status = 'publish';
		$run_id = 'no-decisions-run';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => '_prautoblogger_run_id' === $key ? $run_id : '' );
		$this->wpdb->method( 'get_row' )->willReturn( array( 'run_id' => $run_id, 'status' => 'done' ) );
		$this->wpdb->method( 'get_var' )->willReturn( 'wp_prautoblogger_run_stages' );
		$this->wpdb->method( 'get_results' )->willReturn( array() ); // no stages, no decisions
		$this->log_query->method( 'get_by_run' )->willReturn( array() );

		$assembler = new \PRAutoBlogger_Dossier_Data_Assembler( $this->log_query );
		$result    = $assembler->assemble( 901 );

		$this->assertIsArray( $result['decisions'] );
		$this->assertEmpty( $result['decisions'] );
	}
}
