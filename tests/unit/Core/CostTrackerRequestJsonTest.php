<?php
/**
 * Tests for the v0.20.0 (B1) request_json persistence in
 * PRAutoBlogger_Cost_Tracker::log_api_call().
 *
 * Every chat call's request body must land on its generation_log row
 * (stage inputs exist for edit + re-run; the dossier raw-input trace is
 * no longer hollow — QA M2 F1), with consume-once isolation between rows.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;

class CostTrackerRequestJsonTest extends BaseTestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb instance.
	 */
	private $wpdb;

	/**
	 * Rows captured from $wpdb->insert().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $inserted = array();

	protected function setUp(): void {
		parent::setUp();
		$this->inserted  = array();
		$this->wpdb      = $this->create_mock_wpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->wpdb->insert_id = 7;
		$this->wpdb->method( 'insert' )->willReturnCallback(
			function ( $table, $row ) {
				// Capture generation_log rows only — the Logger also
				// inserts (event_log) when e.g. Pricing warns on an
				// unknown model; those rows are not under test.
				if ( false !== strpos( (string) $table, 'generation_log' ) ) {
					$this->inserted[] = (array) $row;
				}
				return 1;
			}
		);
		$this->stub_get_option( array() );
		\PRAutoBlogger_Request_Recorder::clear();
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Request_Recorder::clear();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * log_api_call() persists the recorder stash into request_json.
	 */
	public function test_log_api_call_persists_recorded_request_json(): void {
		\PRAutoBlogger_Request_Recorder::record(
			array(
				'model'    => 'google/gemini-2.5-flash-lite',
				'messages' => array( array( 'role' => 'user', 'content' => 'draft prompt' ) ),
			)
		);

		( new \PRAutoBlogger_Cost_Tracker() )->log_api_call(
			null,
			'draft',
			'OpenRouter',
			'google/gemini-2.5-flash-lite',
			100,
			500
		);

		$this->assertCount( 1, $this->inserted );
		$row = $this->inserted[0];
		$this->assertArrayHasKey( 'request_json', $row );
		$decoded = json_decode( (string) $row['request_json'], true );
		$this->assertSame( 'draft prompt', $decoded['messages'][0]['content'] );
	}

	/**
	 * Consume-once isolation: a second log row written with no new send
	 * in between must get NULL, never the previous call's body.
	 */
	public function test_second_log_row_without_new_record_gets_null(): void {
		\PRAutoBlogger_Request_Recorder::record( array( 'model' => 'm1' ) );

		$tracker = new \PRAutoBlogger_Cost_Tracker();
		$tracker->log_api_call( null, 'draft', 'OpenRouter', 'm1', 10, 10 );
		$tracker->log_api_call( null, 'polish', 'OpenRouter', 'm1', 10, 10 );

		$this->assertCount( 2, $this->inserted );
		$this->assertNotNull( $this->inserted[0]['request_json'] );
		$this->assertNull( $this->inserted[1]['request_json'] );
	}

	/**
	 * Historical behavior preserved: nothing recorded -> request_json NULL.
	 */
	public function test_log_api_call_without_stash_writes_null(): void {
		( new \PRAutoBlogger_Cost_Tracker() )->log_api_call(
			null,
			'analysis',
			'OpenRouter',
			'google/gemini-2.5-flash-lite',
			10,
			10
		);

		$this->assertCount( 1, $this->inserted );
		$this->assertNull( $this->inserted[0]['request_json'] );
	}

	/**
	 * The persisted payload can never contain credential material — the
	 * provider records only build_body() output (headers are separate).
	 * Belt-and-braces audit on the exact column value.
	 */
	public function test_persisted_request_json_contains_no_auth_header(): void {
		$this->stub_get_option( array() );
		$builder = new \PRAutoBlogger_OpenRouter_Request_Builder();
		\PRAutoBlogger_Request_Recorder::record(
			$builder->build_body(
				array( array( 'role' => 'user', 'content' => 'x' ) ),
				'google/gemini-2.5-flash-lite',
				array( 'max_tokens' => 100 )
			)
		);

		( new \PRAutoBlogger_Cost_Tracker() )->log_api_call(
			null,
			'draft',
			'OpenRouter',
			'google/gemini-2.5-flash-lite',
			10,
			10
		);

		$json = (string) $this->inserted[0]['request_json'];
		$this->assertStringNotContainsString( 'Authorization', $json );
		$this->assertStringNotContainsString( 'Bearer', $json );
		$this->assertStringNotContainsString( 'sk-or-', $json );
	}

	/**
	 * The half-migrated-schema legacy retry (v0.18.0 pattern) keeps
	 * request_json — the column predates the audit columns (v1.1.0
	 * schema) and exists on every site.
	 */
	public function test_legacy_schema_retry_keeps_request_json(): void {
		$this->inserted = array();
		$wpdb           = $this->create_mock_wpdb();
		$wpdb->insert_id = 7;
		$calls           = 0;
		$wpdb->method( 'insert' )->willReturnCallback(
			function ( $table, $row ) use ( &$calls ) {
				if ( false === strpos( (string) $table, 'generation_log' ) ) {
					return 1; // Logger/event_log noise — not under test.
				}
				++$calls;
				$this->inserted[] = (array) $row;
				return 1 === $calls ? false : 1; // First insert fails (missing v0.18.0 columns).
			}
		);
		$GLOBALS['wpdb'] = $wpdb;

		\PRAutoBlogger_Request_Recorder::record( array( 'model' => 'm1' ) );
		( new \PRAutoBlogger_Cost_Tracker() )->log_api_call( null, 'draft', 'OpenRouter', 'm1', 10, 10 );

		$this->assertCount( 2, $this->inserted );
		$retry_row = $this->inserted[1];
		$this->assertArrayNotHasKey( 'agent_role', $retry_row );
		$this->assertArrayNotHasKey( 'prompt_version', $retry_row );
		$this->assertArrayHasKey( 'request_json', $retry_row );
		$this->assertNotNull( $retry_row['request_json'] );
	}
}
