<?php
/**
 * Shared harness for the Dossier_Actions endpoint tests (v0.20.0, M3).
 *
 * Provides: mock wpdb with SQL-pattern wiring, JSON-response capture
 * (wp_send_json_* throw a sentinel), cron-schedule capture, and the
 * eligible-context fixture (draft post, terminal run, free lock).
 * Split across two test classes for the 300-line file cap (the M2
 * split-precedent applies to test files too).
 *
 * @package PRAutoBlogger\Tests\Admin
 */

namespace PRAutoBlogger\Tests\Admin;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

abstract class DossierActionsTestCase extends BaseTestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb.
	 */
	protected $wpdb;

	/**
	 * Captured JSON response (success/error + payload + status).
	 *
	 * @var array|null
	 */
	protected $json = null;

	/**
	 * SQL captured from $wpdb->query().
	 *
	 * @var string[]
	 */
	protected array $queries = array();

	/**
	 * Rows captured from $wpdb->insert().
	 *
	 * @var array<int, array>
	 */
	protected array $inserted = array();

	/**
	 * Cron events captured from wp_schedule_single_event().
	 *
	 * @var array<int, array>
	 */
	protected array $scheduled = array();

	protected function setUp(): void {
		parent::setUp();
		$this->json      = null;
		$this->queries   = array();
		$this->inserted  = array();
		$this->scheduled = array();
		$_POST           = array();

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
		$this->wpdb->method( 'query' )->willReturnCallback(
			function ( $sql ) {
				$this->queries[] = (string) $sql;
				return 1;
			}
		);
		$this->wpdb->method( 'insert' )->willReturnCallback(
			function ( $table, $row ) {
				$this->inserted[] = (array) $row;
				return 1;
			}
		);

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( static function ( $val ) { return abs( (int) $val ); } );
		Functions\when( 'sanitize_key' )->alias( static function ( $val ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $val ) ); } );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $ts, $hook, $args ) {
				$this->scheduled[] = array(
					'hook' => $hook,
					'args' => $args,
				);
				return true;
			}
		);
		Functions\when( 'spawn_cron' )->justReturn( true );
		Functions\when( 'wp_remote_post' )->justReturn( array() );
		Functions\when( 'site_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null ) {
				$this->json = array(
					'ok'   => true,
					'data' => $data,
				);
				throw new \RuntimeException( 'json_sent' );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null, $status = 0 ) {
				$this->json = array(
					'ok'     => false,
					'data'   => $data,
					'status' => $status,
				);
				throw new \RuntimeException( 'json_sent' );
			}
		);
		$user             = new \stdClass();
		$user->user_login = 'rhys';
		Functions\when( 'wp_get_current_user' )->justReturn( $user );

		\PRAutoBlogger_Run_State::flush_cache();
		\PRAutoBlogger_Run_Stage_State::flush_cache();
		\PRAutoBlogger_Stage_Input_Store::flush_cache();
	}

	protected function tearDown(): void {
		$_POST = array();
		\PRAutoBlogger_Run_State::flush_cache();
		\PRAutoBlogger_Run_Stage_State::flush_cache();
		\PRAutoBlogger_Stage_Input_Store::flush_cache();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/** Run an endpoint, swallowing the wp_send_json sentinel. */
	protected function dispatch( callable $endpoint ): void {
		try {
			$endpoint();
		} catch ( \RuntimeException $e ) {
			if ( 'json_sent' !== $e->getMessage() ) {
				throw $e;
			}
		}
	}

	/** Wire post meta + a draft post + a terminal run + free lock. */
	protected function wire_eligible_context( string $post_status = 'draft', string $run_status = 'done' ): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key ) {
				if ( '_prautoblogger_run_id' === $key ) {
					return 'run-1';
				}
				if ( '_prautoblogger_idea_hash' === $key ) {
					return 'abc123';
				}
				return '';
			}
		);
		$post              = new \WP_Post();
		$post->ID          = 99;
		$post->post_status = $post_status;
		Functions\when( 'get_post' )->justReturn( $post );

		$this->wpdb->method( 'get_var' )->willReturnCallback(
			static function ( $sql ) use ( $run_status ) {
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
				if ( false !== strpos( $sql, 'MAX(version)' ) ) {
					return null; // First fork version.
				}
				return null; // Lock free; no seed.
			}
		);
		$this->wpdb->method( 'get_row' )->willReturnCallback(
			static function ( $sql ) use ( $run_status ) {
				$sql = (string) $sql;
				if ( false !== strpos( $sql, 'stage_inputs' ) ) {
					return null; // No fork yet.
				}
				return array(
					'run_id'      => 'run-1',
					'status'      => $run_status,
					'ceiling_usd' => '0.50',
				);
			}
		);
		// resolve_base_body falls through to the gen_log query.
		$this->wpdb->method( 'get_results' )->willReturn(
			array(
				array(
					'id'              => '11',
					'stage'           => 'draft',
					'request_json'    => '{"model":"m","messages":[{"role":"system","content":"sys"},{"role":"user","content":"orig"}]}',
					'response_status' => 'success',
				),
			)
		);
	}
}
