<?php
/**
 * Tests for PRAutoBlogger_Generation_Status_Poller (R2/R3 resilience).
 *
 * Covers:
 *  - R2(a): update_generation_stage() renews the transient TTL on every call.
 *  - R2(b): on_ajax_generation_status() detects lock-held-but-transient-gone
 *    when lock age > STATUS_TTL and returns status:error with an infrastructure
 *    timeout message.
 *  - R3: on_ajax_generation_status() returns status:running (with started_at)
 *    when the transient is absent but the lock is held within TTL.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class GenerationStatusPollerTest extends BaseTestCase {

	/** @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb. */
	private $wpdb;

	/** @var array Captured wp_send_json_success payloads. */
	private array $json_success = array();

	/** @var array Captured wp_send_json_error payloads. */
	private array $json_error = array();

	/** @var array Captured transient writes: [key, value, ttl]. */
	private array $transient_writes = array();

	/** @var mixed Current transient value. */
	private $transient_value = false;

	/** @var int|null Mocked lock acquired_at timestamp (null = not held). */
	private ?int $lock_acquired_at = null;

	protected function setUp(): void {
		parent::setUp();

		$this->json_success     = array();
		$this->json_error       = array();
		$this->transient_writes = array();
		$this->transient_value  = false;
		$this->lock_acquired_at = null;

		$this->wpdb          = $this->create_mock_wpdb();
		// Generation_Lock uses $wpdb->options in acquire/release/get_acquired_at.
		$this->wpdb->options = 'wp_options';
		$GLOBALS['wpdb']     = $this->wpdb;

		// Stub nonce check and capability check (always pass in unit tests).
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );

		// Transient stubs.
		Functions\when( 'get_transient' )->alias( function( $key ) {
			return $this->transient_value;
		} );
		Functions\when( 'set_transient' )->alias( function( $key, $value, $ttl ) {
			$this->transient_writes[] = array( $key, $value, $ttl );
			return true;
		} );

		// wp_send_json stubs — capture payload, mark test as "sent".
		Functions\when( 'wp_send_json_success' )->alias( function( $data ) {
			$this->json_success[] = $data;
		} );
		Functions\when( 'wp_send_json_error' )->alias( function( $data ) {
			$this->json_error[] = $data;
		} );

		// Generation_Lock::get_acquired_at() — return our test value.
		// We stub the static method via $wpdb since it does a DB query.
		$this->wpdb->method( 'prepare' )->willReturnCallback(
			function ( $sql, ...$args ) {
				return $sql . ' /* ' . implode( ',', array_map( 'strval', $args ) ) . ' */';
			}
		);
		$this->wpdb->method( 'get_var' )->willReturnCallback(
			function ( $sql ) {
				// Return the lock timestamp when querying the lock option.
				if ( false !== strpos( (string) $sql, 'prautoblogger_generation_lock' ) ) {
					return null !== $this->lock_acquired_at ? (string) $this->lock_acquired_at : null;
				}
				return null;
			}
		);

		// Run_State and Run_Context stubs — no DB tables in unit tests.
		\PRAutoBlogger_Run_State::flush_cache();
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		// Logger stub.
		Functions\when( 'wp_date' )->returnArg();
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Run_State::flush_cache();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// R2(a): update_generation_stage() renews transient TTL
	// -------------------------------------------------------------------------

	/**
	 * update_generation_stage() must renew the transient with STATUS_TTL on
	 * every call so the transient outlasts a long LLM call.
	 */
	public function test_update_generation_stage_renews_transient_ttl(): void {
		// Seed an existing running transient.
		$this->transient_value = array(
			'status'       => 'running',
			'stage'        => 'old stage',
			'started'      => time() - 30,
			'last_updated' => time() - 30,
		);

		$poller = new \PRAutoBlogger_Generation_Status_Poller();
		$poller->update_generation_stage( 'Analyzing sources...' );

		$this->assertCount( 1, $this->transient_writes, 'set_transient must be called once' );
		[ $key, $value, $ttl ] = $this->transient_writes[0];
		$this->assertSame( \PRAutoBlogger_Generation_Status_Poller::STATUS_TRANSIENT, $key );
		$this->assertSame( \PRAutoBlogger_Generation_Status_Poller::STATUS_TTL, $ttl,
			'TTL must be renewed to STATUS_TTL on every stage update' );
		$this->assertSame( 'Analyzing sources...', $value['stage'] );
	}

	/**
	 * update_generation_stage() is a no-op when the transient is absent
	 * (prevents spurious "running" writes after a run has completed).
	 */
	public function test_update_generation_stage_no_op_when_transient_absent(): void {
		$this->transient_value = false; // absent.

		$poller = new \PRAutoBlogger_Generation_Status_Poller();
		$poller->update_generation_stage( 'Stage text' );

		$this->assertEmpty( $this->transient_writes, 'set_transient must NOT be called when transient is absent' );
	}

	// -------------------------------------------------------------------------
	// R3: transient absent, lock held within TTL → return status:running
	// -------------------------------------------------------------------------

	/**
	 * R3: When the status transient has expired but the lock is held within
	 * STATUS_TTL, the poller must return status:running so the Generate Now
	 * button does not silently reset to idle during long background runs.
	 */
	public function test_missing_transient_but_fresh_lock_returns_running(): void {
		$this->transient_value  = false;           // transient gone.
		$this->lock_acquired_at = time() - 60;     // lock held 60s — well within TTL.

		// Stub _POST for the nonce.
		$_POST = array( 'nonce' => 'fake' );
		$poller = new \PRAutoBlogger_Generation_Status_Poller();
		$poller->on_ajax_generation_status();

		$this->assertCount( 1, $this->json_success, 'wp_send_json_success must be called once' );
		$response = $this->json_success[0];
		$this->assertSame( 'running', $response['status'],
			'R3: status must be running when transient absent but lock held within TTL' );
		$this->assertArrayHasKey( 'started', $response,
			'R3: response must include started timestamp from lock' );
		$this->assertSame( $this->lock_acquired_at, $response['started'],
			'R3: started must equal the lock acquired_at timestamp' );
	}

	// -------------------------------------------------------------------------
	// R2(b): transient absent, lock held > STATUS_TTL → abort + status:error
	// -------------------------------------------------------------------------

	/**
	 * R2(b): When the status transient is absent and the lock has been held
	 * longer than STATUS_TTL, the poller must mark the run failed and return
	 * status:error with an "infrastructure timeout" message.
	 */
	public function test_missing_transient_with_expired_lock_marks_failed_and_returns_error(): void {
		$this->transient_value  = false;
		$ttl                    = \PRAutoBlogger_Generation_Status_Poller::STATUS_TTL;
		$this->lock_acquired_at = time() - ( $ttl + 60 ); // Lock held TTL+60s.

		$_POST = array( 'nonce' => 'fake' );
		$poller = new \PRAutoBlogger_Generation_Status_Poller();
		$poller->on_ajax_generation_status();

		$this->assertCount( 1, $this->json_success, 'wp_send_json_success must be called' );
		$response = $this->json_success[0];
		$this->assertSame( 'error', $response['status'],
			'R2(b): status must be error when lock is expired-stale' );
		$this->assertStringContainsString(
			'infrastructure timeout',
			strtolower( $response['message'] ?? '' ),
			'R2(b): error message must mention infrastructure timeout'
		);
	}

	// -------------------------------------------------------------------------
	// Idle path: no transient, no lock
	// -------------------------------------------------------------------------

	/**
	 * When both the transient and the lock are absent, the poller must
	 * return status:idle (normal rest state between runs).
	 */
	public function test_no_transient_no_lock_returns_idle(): void {
		$this->transient_value  = false;
		$this->lock_acquired_at = null; // no lock.

		$_POST = array( 'nonce' => 'fake' );
		$poller = new \PRAutoBlogger_Generation_Status_Poller();
		$poller->on_ajax_generation_status();

		$this->assertCount( 1, $this->json_success );
		$this->assertSame( 'idle', $this->json_success[0]['status'] );
	}
}
