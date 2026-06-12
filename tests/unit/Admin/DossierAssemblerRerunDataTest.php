<?php
/**
 * Tests for the v0.20.0 dossier view-model additions (M3 + F2/F3).
 *
 * Locks: item-scoped stage filtering (multi-article runs no longer
 * collide), other-post gen_log row exclusion, per-stage rerun panel
 * data (policy reasons on non-editable stages; editable writer stage
 * with recorded input), log-only stage exposure (F3) and the spend
 * strip shape (guardrail 4 visibility).
 *
 * @package PRAutoBlogger\Tests\Admin
 */

namespace PRAutoBlogger\Tests\Admin;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class DossierAssemblerRerunDataTest extends BaseTestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb.
	 */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb          = $this->create_mock_wpdb();
		$this->wpdb->options = 'wp_options';
		$GLOBALS['wpdb']     = $this->wpdb;
		$this->wpdb->method( 'prepare' )->willReturnCallback(
			static function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				return $sql . ' /* ' . implode( ',', array_map( 'strval', $args ) ) . ' */';
			}
		);
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_children' )->justReturn( array() );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		\PRAutoBlogger_Run_State::flush_cache();
		\PRAutoBlogger_Run_Stage_State::flush_cache();
		\PRAutoBlogger_Stage_Input_Store::flush_cache();
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Run_State::flush_cache();
		\PRAutoBlogger_Run_Stage_State::flush_cache();
		\PRAutoBlogger_Stage_Input_Store::flush_cache();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/** Build the standard post + meta wiring. */
	private function wire_post(): void {
		$post              = new \WP_Post();
		$post->ID          = 99;
		$post->post_title  = 'Test Article';
		$post->post_status = 'draft';
		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key ) {
				$map = array(
					'_prautoblogger_run_id'         => 'run-1',
					'_prautoblogger_idea_hash'      => 'abc123',
					'_prautoblogger_editor_verdict' => 'approved',
					'_prautoblogger_article_type'   => 'guide',
				);
				return $map[ $key ] ?? '';
			}
		);
	}

	/** Wire wpdb for: tables exist, run row, stage rows, gen_log rows, lock free. */
	private function wire_data( array $stage_rows, array $log_rows ): void {
		$this->wpdb->method( 'get_var' )->willReturnCallback(
			static function ( $sql ) {
				$sql = (string) $sql;
				if ( false !== strpos( $sql, 'SHOW TABLES' ) ) {
					if ( false !== strpos( $sql, 'stage_inputs' ) ) {
						return 'wp_prautoblogger_stage_inputs';
					}
					if ( false !== strpos( $sql, 'run_stages' ) ) {
						return 'wp_prautoblogger_run_stages';
					}
					return 'wp_prautoblogger_runs';
				}
				return null; // Lock free.
			}
		);
		$this->wpdb->method( 'get_row' )->willReturnCallback(
			static function ( $sql ) {
				if ( false !== strpos( (string) $sql, 'stage_inputs' ) ) {
					return null; // No forks saved.
				}
				return array(
					'run_id'       => 'run-1',
					'status'       => 'done',
					'ceiling_usd'  => '0.50',
					'settled_usd'  => '0.30',
					'reserved_usd' => '0.15',
				);
			}
		);
		$this->wpdb->method( 'get_results' )->willReturnCallback(
			static function ( $sql ) use ( $stage_rows, $log_rows ) {
				$sql = (string) $sql;
				if ( false !== strpos( $sql, 'run_stages' ) ) {
					return $stage_rows;
				}
				if ( false !== strpos( $sql, 'generation_log' ) ) {
					return $log_rows;
				}
				return array(); // run_decisions.
			}
		);
	}

	/**
	 * The stage query is item-scoped (idea hash -> item_key IN (item, ''))
	 * and gen_log rows belonging to OTHER posts are dropped while
	 * unlinked (post_id NULL/0) rows are kept.
	 */
	public function test_item_scoping_and_other_post_log_exclusion(): void {
		$this->wire_post();
		$captured_stage_sql = null;
		$this->wpdb->method( 'get_var' )->willReturnCallback(
			static function ( $sql ) {
				if ( false !== strpos( (string) $sql, 'SHOW TABLES' ) ) {
					return false !== strpos( (string) $sql, 'stage_inputs' ) ? 'wp_prautoblogger_stage_inputs'
						: ( false !== strpos( (string) $sql, 'run_stages' ) ? 'wp_prautoblogger_run_stages' : 'wp_prautoblogger_runs' );
				}
				return null;
			}
		);
		$this->wpdb->method( 'get_row' )->willReturn( array( 'run_id' => 'run-1', 'status' => 'done', 'ceiling_usd' => '0' ) );
		$this->wpdb->method( 'get_results' )->willReturnCallback(
			static function ( $sql ) use ( &$captured_stage_sql ) {
				$sql = (string) $sql;
				if ( false !== strpos( $sql, 'run_stages' ) ) {
					$captured_stage_sql = $sql;
					return array();
				}
				if ( false !== strpos( $sql, 'generation_log' ) ) {
					return array(
						array( 'id' => '1', 'stage' => 'draft', 'post_id' => '99', 'model' => 'm', 'request_json' => null ),
						array( 'id' => '2', 'stage' => 'draft', 'post_id' => '77', 'model' => 'm', 'request_json' => null ),
						array( 'id' => '3', 'stage' => 'llm_research', 'post_id' => null, 'model' => 'm', 'request_json' => null ),
					);
				}
				return array();
			}
		);

		$dossier = ( new \PRAutoBlogger_Dossier_Data_Assembler() )->assemble( 99 );

		$this->assertSame( 'idea:abc123', $dossier['item_key'] );
		$this->assertStringContainsString( "item_key IN (%s, '')", (string) $captured_stage_sql );
		// Post 77's draft row dropped; this post's row + unlinked research row kept.
		$this->assertCount( 1, $dossier['gen_log_index']['draft'] );
		$this->assertSame( '1', $dossier['gen_log_index']['draft'][0]['id'] );
		$this->assertCount( 1, $dossier['gen_log_index']['llm_research'] );
		// F3: llm_research has no run_stages row -> exposed as log-only.
		$this->assertContains( 'llm_research', $dossier['log_only_stages'] );
	}

	/**
	 * Per-stage rerun data: a writer stage with a recorded request is
	 * editable with prefill; review carries a policy reason instead; the
	 * spend strip reflects the ledger row (guardrail 4 visibility).
	 */
	public function test_stage_rerun_panel_data_and_spend(): void {
		$this->wire_post();
		$stage_rows = array(
			array(
				'id'             => '1',
				'run_id'         => 'run-1',
				'stage'          => 'draft',
				'agent_role'     => 'writer',
				'item_key'       => 'idea:abc123',
				'status'         => 'done',
				'attempt'        => '1',
				'cost_usd'       => '0.01',
				'meta_json'      => '{"output":"text"}',
				'stale'          => '0',
				'human_modified' => '1',
			),
			array(
				'id'         => '2',
				'run_id'     => 'run-1',
				'stage'      => 'review',
				'agent_role' => 'editor',
				'item_key'   => 'idea:abc123',
				'status'     => 'done',
				'attempt'    => '1',
				'cost_usd'   => '0.005',
				'meta_json'  => null,
				'stale'      => '1',
			),
		);
		$log_rows   = array(
			array(
				'id'              => '10',
				'stage'           => 'draft',
				'post_id'         => '99',
				'model'           => 'google/gemini-2.5-flash-lite',
				'request_json'    => '{"model":"google/gemini-2.5-flash-lite","messages":[{"role":"user","content":"draft prompt"}]}',
				'response_status' => 'success',
			),
		);
		$this->wire_data( $stage_rows, $log_rows );

		$dossier = ( new \PRAutoBlogger_Dossier_Data_Assembler() )->assemble( 99 );

		$draft = $dossier['stages']['draft:writer']['rerun'];
		$this->assertTrue( $draft['editable'] );
		$this->assertSame( 'draft prompt', $draft['prefill']['messages'][0]['content'] );
		$this->assertTrue( $draft['human_modified'] );
		$this->assertTrue( $dossier['human_modified_any'] );

		$review = $dossier['stages']['review:editor']['rerun'];
		$this->assertFalse( $review['editable'] );
		$this->assertStringContainsString( 'Re-run from here', $review['edit_reason'] );
		$this->assertTrue( $review['stale'] );
		$this->assertTrue( $review['rerun_from'] );
		$this->assertTrue( $dossier['stale_any'] );

		$this->assertSame( 0.30, $dossier['spend']['settled'] );
		$this->assertSame( 0.15, $dossier['spend']['reserved'] );
		$this->assertSame( 0.50, $dossier['spend']['ceiling'] );
		$this->assertTrue( $dossier['spend']['warn'] ); // 0.45/0.50 = 90% >= 80%.
	}
}
