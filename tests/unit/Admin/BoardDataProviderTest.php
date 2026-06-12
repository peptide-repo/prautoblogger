<?php
/**
 * Tests for PRAutoBlogger_Board_Data_Provider — column/state bucket mapping.
 *
 * Seeds mock DB results and WordPress post data, then asserts that each
 * board column receives the correct cards. No live DB required; uses Brain\Monkey
 * and a mock $wpdb.
 *
 * @package PRAutoBlogger\Tests\Admin
 */

namespace PRAutoBlogger\Tests\Admin;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class BoardDataProviderTest extends BaseTestCase {

	/** @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb. */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb         = $this->create_mock_wpdb();
		$GLOBALS['wpdb']    = $this->wpdb;

		Functions\when( 'get_option' )->alias(
			function ( string $name, $default = false ) {
				$map = array(
					'prautoblogger_board_published_window_days' => 7,
					'prautoblogger_board_poll_interval'         => 5,
				);
				return $map[ $name ] ?? $default;
			}
		);

		// Transient stub: by default no active run.
		Functions\when( 'get_transient' )->justReturn( false );

		// WP_Query stub: return empty by default; individual tests override.
		Functions\when( 'WP_Query' )->justReturn( (object) array( 'posts' => array() ) );

		// Admin URL stub.
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ) {
				return 'https://test.example.com/wp-admin/' . ltrim( $path, '/' );
			}
		);

		// DAY_IN_SECONDS constant.
		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// ── Generating cards ────────────────────────────────────────────────────

	/**
	 * A running transient with status='running' yields a Generating card.
	 */
	public function test_generating_cards_from_transient(): void {
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				if ( 'prautoblogger_generation_status' === $key ) {
					return array(
						'status'  => 'running',
						'stage'   => 'Drafting article…',
						'started' => time() - 60,
					);
				}
				return false;
			}
		);

		// No gen_log secondary runs needed.
		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT 1' );
		$this->wpdb->method( 'get_results' )->willReturn( array() );

		$provider = new \PRAutoBlogger_Board_Data_Provider();
		$cards    = $provider->get_generating_cards();

		$this->assertCount( 1, $cards );
		$this->assertSame( 'Drafting article…', $cards[0]['stage_current'] );
		$this->assertSame( 'logs', $cards[0]['click_action'] );
	}

	/**
	 * When the transient is idle, orphan gen_log rows produce Generating cards.
	 */
	public function test_generating_cards_from_gen_log(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT 1' );
		$this->wpdb->method( 'get_results' )->willReturn(
			array(
				array(
					'run_id'      => 'abc-123',
					'started_at'  => gmdate( 'Y-m-d H:i:s', time() - 120 ),
					'last_stage'  => 'draft',
					'stage_count' => '3',
					'cost_total'  => '0.005000',
				),
			)
		);

		$provider = new \PRAutoBlogger_Board_Data_Provider();
		$cards    = $provider->get_generating_cards();

		$this->assertCount( 1, $cards );
		$this->assertSame( 'abc-123', $cards[0]['run_id'] );
		$this->assertSame( 'draft', $cards[0]['stage_current'] );
		$this->assertEqualsWithDelta( 0.005, $cards[0]['cost_total'], 0.0001 );
	}

	/**
	 * An idle transient + empty gen_log yields zero Generating cards.
	 */
	public function test_no_generating_cards_when_idle(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT 1' );
		$this->wpdb->method( 'get_results' )->willReturn( array() );

		$provider = new \PRAutoBlogger_Board_Data_Provider();
		$cards    = $provider->get_generating_cards();

		$this->assertCount( 0, $cards );
	}

	// ── In Review cards ─────────────────────────────────────────────────────

	/**
	 * Draft posts with the _prautoblogger_generated meta appear in In Review.
	 */
	public function test_in_review_cards_from_draft_posts(): void {
		$post        = new \stdClass();
		$post->ID    = 42;
		$post->post_title    = 'Test Draft Post';
		$post->post_date_gmt = '2026-06-12 10:00:00';

		// WP_Query returns our fake post.
		Functions\when( 'WP_Query' )->justReturn(
			(object) array( 'posts' => array( $post ) )
		);

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single ) use ( $post ) {
				if ( $post_id === $post->ID ) {
					if ( '_prautoblogger_run_id' === $key ) {
						return 'run-draft-999';
					}
					if ( '_prautoblogger_total_cost' === $key ) {
						return '0.012000';
					}
				}
				return '';
			}
		);

		Functions\when( 'get_edit_post_link' )->justReturn(
			'https://test.example.com/wp-admin/post.php?post=42&action=edit'
		);

		$provider = new \PRAutoBlogger_Board_Data_Provider();
		$cards    = $provider->get_in_review_cards();

		$this->assertCount( 1, $cards );
		$this->assertSame( 42, $cards[0]['post_id'] );
		$this->assertSame( 'Test Draft Post', $cards[0]['title'] );
		$this->assertSame( 'review', $cards[0]['click_action'] );
		$this->assertEqualsWithDelta( 0.012, $cards[0]['cost_total'], 0.0001 );
	}

	// ── Published cards ─────────────────────────────────────────────────────

	/**
	 * Published posts within the window appear in Published.
	 */
	public function test_published_cards_within_window(): void {
		$post             = new \stdClass();
		$post->ID         = 77;
		$post->post_title     = 'Published Article';
		$post->post_date_gmt  = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		Functions\when( 'WP_Query' )->justReturn(
			(object) array( 'posts' => array( $post ) )
		);

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single ) use ( $post ) {
				if ( $post_id === $post->ID ) {
					if ( '_prautoblogger_run_id' === $key ) {
						return 'run-pub-77';
					}
					if ( '_prautoblogger_total_cost' === $key ) {
						return '0.020000';
					}
				}
				return '';
			}
		);

		Functions\when( 'get_edit_post_link' )->justReturn(
			'https://test.example.com/wp-admin/post.php?post=77&action=edit'
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://test.example.com/?p=77' );

		$provider = new \PRAutoBlogger_Board_Data_Provider();
		$cards    = $provider->get_published_cards();

		$this->assertCount( 1, $cards );
		$this->assertSame( 77, $cards[0]['post_id'] );
		$this->assertSame( 'edit', $cards[0]['click_action'] );
	}

	// ── Failed cards ────────────────────────────────────────────────────────

	/**
	 * Gen_log error rows with no post produce Failed cards.
	 */
	public function test_failed_cards_from_error_log(): void {
		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT 1' );
		$this->wpdb->method( 'get_results' )->willReturn(
			array(
				array(
					'run_id'      => 'fail-run-001',
					'started_at'  => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
					'last_error'  => 'OpenRouter rate limit exceeded',
					'last_stage'  => 'draft',
					'call_count'  => '2',
				),
			)
		);

		$provider = new \PRAutoBlogger_Board_Data_Provider();
		$cards    = $provider->get_failed_cards();

		$this->assertCount( 1, $cards );
		$this->assertSame( 'fail-run-001', $cards[0]['run_id'] );
		$this->assertStringContainsString( 'rate limit', $cards[0]['error_message'] );
		$this->assertSame( 'logs', $cards[0]['click_action'] );
	}

	// ── Full snapshot ────────────────────────────────────────────────────────

	/**
	 * get_board_snapshot() has_active_runs = true when Generating is non-empty.
	 */
	public function test_snapshot_has_active_runs_flag(): void {
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				if ( 'prautoblogger_generation_status' === $key ) {
					return array(
						'status'  => 'running',
						'stage'   => 'Writing…',
						'started' => time(),
					);
				}
				return false;
			}
		);

		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT 1' );
		$this->wpdb->method( 'get_results' )->willReturn( array() );

		Functions\when( 'WP_Query' )->justReturn( (object) array( 'posts' => array() ) );

		$provider = new \PRAutoBlogger_Board_Data_Provider();
		$snapshot = $provider->get_board_snapshot();

		$this->assertTrue( $snapshot['has_active_runs'] );
	}

	/**
	 * get_board_snapshot() has_active_runs = false when Generating is empty.
	 */
	public function test_snapshot_no_active_runs_when_idle(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT 1' );
		$this->wpdb->method( 'get_results' )->willReturn( array() );

		Functions\when( 'WP_Query' )->justReturn( (object) array( 'posts' => array() ) );

		$provider = new \PRAutoBlogger_Board_Data_Provider();
		$snapshot = $provider->get_board_snapshot();

		$this->assertFalse( $snapshot['has_active_runs'] );
	}
}
