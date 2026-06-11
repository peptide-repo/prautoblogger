<?php
/**
 * Tests for PRAutoBlogger_Run_Stage_State.
 *
 * Locks the idempotency-key contract (item scoping for multi-article
 * runs), the sticky-done upsert semantics, and the self-healing
 * no-table degradation.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;

class RunStageStateTest extends BaseTestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb.
	 */
	private $wpdb;

	/**
	 * Captured SQL passed through $wpdb->query().
	 *
	 * @var string[]
	 */
	private array $queries = array();

	protected function setUp(): void {
		parent::setUp();
		$this->queries   = array();
		$this->wpdb      = $this->create_mock_wpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->wpdb->method( 'prepare' )->willReturnCallback(
			static function ( $sql, ...$args ) {
				return $sql . ' /* ' . implode( ',', array_map( 'strval', $args ) ) . ' */';
			}
		);
		\PRAutoBlogger_Run_Stage_State::flush_cache();
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Run_Stage_State::flush_cache();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Two different ideas in the same run must get different item keys
	 * (one run_id spans all N articles of a batch run); the same idea must
	 * hash stably across processes (queue serialization round-trip).
	 */
	public function test_item_keys_scope_articles_within_a_run(): void {
		$idea_a = new \PRAutoBlogger_Article_Idea(
			array(
				'topic'           => 'BPC-157 dosing',
				'article_type'    => 'guide',
				'suggested_title' => 'BPC-157 Dosing Guide',
				'summary'         => 's',
			)
		);
		$idea_b = new \PRAutoBlogger_Article_Idea(
			array(
				'topic'           => 'TB-500 storage',
				'article_type'    => 'question',
				'suggested_title' => 'How To Store TB-500',
				'summary'         => 's',
			)
		);
		$idea_a_again = new \PRAutoBlogger_Article_Idea( $idea_a->to_array() );

		$key_a = \PRAutoBlogger_Run_Stage_State::item_key_for_idea( $idea_a );
		$key_b = \PRAutoBlogger_Run_Stage_State::item_key_for_idea( $idea_b );

		$this->assertNotSame( $key_a, $key_b );
		$this->assertSame( $key_a, \PRAutoBlogger_Run_Stage_State::item_key_for_idea( $idea_a_again ) );
		$this->assertStringStartsWith( 'idea:', $key_a );
		$this->assertLessThanOrEqual( 64, strlen( $key_a ) );
		$this->assertSame(
			\PRAutoBlogger_Run_Stage_State::idea_hash( $idea_a ),
			substr( $key_a, 5 )
		);
	}

	/**
	 * start() upserts must never demote a done stage and must bump attempt
	 * on re-entry; done() must be sticky.
	 */
	public function test_start_upsert_protects_done_status(): void {
		$this->wpdb->method( 'get_var' )->willReturn( 'wp_prautoblogger_run_stages' );
		$this->wpdb->method( 'query' )->willReturnCallback(
			function ( $sql ) {
				$this->queries[] = (string) $sql;
				return 1;
			}
		);

		\PRAutoBlogger_Run_Stage_State::start( 'run-1', 'draft', '', 'idea:abc' );

		$sql = implode( "\n", $this->queries );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $sql );
		$this->assertStringContainsString( "IF(status = 'done', attempt, attempt + 1)", $sql );
		$this->assertStringContainsString( "IF(status = 'done', 'done', 'running')", $sql );
	}

	/**
	 * fail() and fail_open_for_item() must never touch done rows.
	 */
	public function test_fail_paths_exclude_done_rows(): void {
		$this->wpdb->method( 'get_var' )->willReturn( 'wp_prautoblogger_run_stages' );
		$this->wpdb->method( 'query' )->willReturnCallback(
			function ( $sql ) {
				$this->queries[] = (string) $sql;
				return 1;
			}
		);

		\PRAutoBlogger_Run_Stage_State::fail( 'run-1', 'draft', '', 'idea:abc' );
		\PRAutoBlogger_Run_Stage_State::fail_open_for_item( 'run-1', 'idea:abc' );

		$this->assertStringContainsString( "status != 'done'", $this->queries[0] );
		$this->assertStringContainsString( "status IN ('pending','running')", $this->queries[1] );
	}

	/**
	 * Without the table (half-migrated site) every method degrades to a
	 * harmless no-op/false/null — never a fatal.
	 */
	public function test_no_table_degrades_to_noops(): void {
		$this->wpdb->method( 'get_var' )->willReturn( null ); // SHOW TABLES miss.
		$this->wpdb->expects( $this->never() )->method( 'query' );

		\PRAutoBlogger_Run_Stage_State::start( 'run-1', 'draft' );
		\PRAutoBlogger_Run_Stage_State::done( 'run-1', 'draft', '', '', 'output' );
		\PRAutoBlogger_Run_Stage_State::fail( 'run-1', 'draft' );
		$this->assertFalse( \PRAutoBlogger_Run_Stage_State::is_done( 'run-1', 'draft' ) );
		$this->assertNull( \PRAutoBlogger_Run_Stage_State::get_output( 'run-1', 'draft' ) );
	}
}
