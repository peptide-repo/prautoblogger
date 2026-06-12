<?php
/**
 * Tests for PRAutoBlogger_Request_Recorder (v0.20.0 / B1).
 *
 * Locks the consume-once + overwrite-on-record semantics that make it
 * impossible for a stale request body to attach to an unrelated log row,
 * and the no-Authorization guarantee of the record path.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;

class RequestRecorderTest extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		\PRAutoBlogger_Request_Recorder::clear();
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Request_Recorder::clear();
		parent::tearDown();
	}

	/**
	 * consume() returns the recorded body exactly once, then null.
	 */
	public function test_consume_returns_recorded_body_exactly_once(): void {
		\PRAutoBlogger_Request_Recorder::record(
			array(
				'model'    => 'google/gemini-2.5-flash-lite',
				'messages' => array( array( 'role' => 'user', 'content' => 'hi' ) ),
			)
		);

		$first = \PRAutoBlogger_Request_Recorder::consume();
		$this->assertIsString( $first );
		$decoded = json_decode( $first, true );
		$this->assertSame( 'google/gemini-2.5-flash-lite', $decoded['model'] );
		$this->assertSame( 'hi', $decoded['messages'][0]['content'] );

		// Consume-once: the slot is cleared by the first read.
		$this->assertNull( \PRAutoBlogger_Request_Recorder::consume() );
	}

	/**
	 * record() overwrites the previous stash — a stale body from an
	 * unlogged earlier call can never survive into the next dispatch.
	 */
	public function test_record_overwrites_previous_stash(): void {
		\PRAutoBlogger_Request_Recorder::record( array( 'model' => 'first/model' ) );
		\PRAutoBlogger_Request_Recorder::record( array( 'model' => 'second/model' ) );

		$decoded = json_decode( (string) \PRAutoBlogger_Request_Recorder::consume(), true );
		$this->assertSame( 'second/model', $decoded['model'] );
	}

	/**
	 * consume() with nothing recorded returns null (historical rows /
	 * non-chat log writes keep request_json NULL).
	 */
	public function test_consume_without_record_returns_null(): void {
		$this->assertNull( \PRAutoBlogger_Request_Recorder::consume() );
	}

	/**
	 * clear() empties the slot.
	 */
	public function test_clear_empties_slot(): void {
		\PRAutoBlogger_Request_Recorder::record( array( 'model' => 'm' ) );
		\PRAutoBlogger_Request_Recorder::clear();
		$this->assertNull( \PRAutoBlogger_Request_Recorder::consume() );
	}

	/**
	 * The full record path for a body built by the real Request_Builder
	 * from poisoned options can never contain credential material:
	 * build_body() copies only whitelisted keys and headers are built
	 * separately, so the recorder's JSON has no Authorization shape.
	 */
	public function test_recorded_builder_body_never_contains_auth_material(): void {
		$this->stub_get_option( array() );

		$builder = new \PRAutoBlogger_OpenRouter_Request_Builder();
		$body    = $builder->build_body(
			array( array( 'role' => 'user', 'content' => 'write about BPC-157' ) ),
			'google/gemini-2.5-flash-lite',
			array(
				'temperature'   => 0.7,
				'max_tokens'    => 4000,
				// Hostile/accidental injection attempts — must all be dropped.
				'Authorization' => 'Bearer sk-or-secret-key-123',
				'headers'       => array( 'Authorization' => 'Bearer sk-or-secret-key-123' ),
				'api_key'       => 'sk-or-secret-key-123',
				// Caller metadata — never sent upstream, never recorded.
				'stage'         => 'draft',
				'prompt_key'    => 'content.draft',
				'empty_retry'   => true,
			)
		);
		\PRAutoBlogger_Request_Recorder::record( $body );

		$json = (string) \PRAutoBlogger_Request_Recorder::consume();
		$this->assertStringNotContainsString( 'Authorization', $json );
		$this->assertStringNotContainsString( 'Bearer', $json );
		$this->assertStringNotContainsString( 'sk-or-', $json );
		$this->assertStringNotContainsString( 'api_key', $json );
		$this->assertStringNotContainsString( 'empty_retry', $json );
		$this->assertStringNotContainsString( 'prompt_key', $json );

		$decoded = json_decode( $json, true );
		$this->assertSame(
			array( 'model', 'messages', 'temperature', 'max_tokens' ),
			array_keys( $decoded )
		);
	}
}
