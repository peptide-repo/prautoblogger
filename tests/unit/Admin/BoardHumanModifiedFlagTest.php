<?php
/**
 * Tests for the v0.20.0 board-card human_modified flag (CPO product AC:
 * human-modified runs are visually distinct at the run-list level).
 *
 * Locks: ONE batched query flags all cards (no N+1), unflagged runs and
 * missing-table sites degrade to false.
 *
 * @package PRAutoBlogger\Tests\Admin
 */

namespace PRAutoBlogger\Tests\Admin;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class BoardHumanModifiedFlagTest extends BaseTestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb.
	 */
	private $wpdb;

	/**
	 * Number of get_col calls (N+1 guard).
	 *
	 * @var int
	 */
	private int $col_queries = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->col_queries = 0;
		$this->wpdb        = $this->create_mock_wpdb();
		$GLOBALS['wpdb']   = $this->wpdb;
		$this->wpdb->method( 'prepare' )->willReturnCallback(
			static function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				return $sql . ' /* ' . implode( ',', array_map( 'strval', $args ) ) . ' */';
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key ) {
				if ( '_prautoblogger_run_id' === $key ) {
					return 'run-' . $post_id;
				}
				if ( '_prautoblogger_total_cost' === $key ) {
					return '0.05';
				}
				return '';
			}
		);
		Functions\when( 'get_edit_post_link' )->justReturn( 'https://example.com/edit' );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );
		\PRAutoBlogger_Run_Stage_State::flush_cache();
	}

	protected function tearDown(): void {
		\WP_Query::$_test_posts_queue = array();
		\PRAutoBlogger_Run_Stage_State::flush_cache();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/** Build a WP_Post for the queue. */
	private function make_post( int $id ): \WP_Post {
		$post                = new \WP_Post();
		$post->ID            = $id;
		$post->post_title    = 'Post ' . $id;
		$post->post_date_gmt = '2026-06-12 00:00:00';
		return $post;
	}

	/**
	 * Cards whose runs carry human_modified rows get the flag; others
	 * stay false; exactly ONE batched query runs for the whole column.
	 */
	public function test_cards_flagged_in_one_batched_query(): void {
		\WP_Query::$_test_posts_queue[] = array( $this->make_post( 1 ), $this->make_post( 2 ), $this->make_post( 3 ) );

		$this->wpdb->method( 'get_var' )->willReturn( 'wp_prautoblogger_run_stages' );
		$this->wpdb->method( 'get_col' )->willReturnCallback(
			function ( $sql ) {
				++$this->col_queries;
				$this->assertStringContainsString( 'human_modified = 1', (string) $sql );
				return array( 'run-2' ); // Only post 2's run was edited.
			}
		);

		$cards = ( new \PRAutoBlogger_Board_Data_Provider() )->get_in_review_cards();

		$this->assertCount( 3, $cards );
		$this->assertFalse( $cards[0]['human_modified'] );
		$this->assertTrue( $cards[1]['human_modified'] );
		$this->assertFalse( $cards[2]['human_modified'] );
		$this->assertSame( 1, $this->col_queries, 'one batched query, never per-card' );
	}

	/**
	 * Missing substrate table: flags default false, zero flag queries.
	 */
	public function test_missing_table_degrades_to_false(): void {
		\WP_Query::$_test_posts_queue[] = array( $this->make_post( 1 ) );

		$this->wpdb->method( 'get_var' )->willReturn( null );
		$this->wpdb->method( 'get_col' )->willReturnCallback(
			function () {
				++$this->col_queries;
				return array();
			}
		);

		$cards = ( new \PRAutoBlogger_Board_Data_Provider() )->get_in_review_cards();

		$this->assertFalse( $cards[0]['human_modified'] );
		$this->assertSame( 0, $this->col_queries );
	}
}
