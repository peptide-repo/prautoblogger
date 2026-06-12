<?php
/**
 * Tests for PRAutoBlogger_Stage_Replay (v0.20.0, M3).
 *
 * Locks the body -> options inverse transform (the replay must
 * round-trip through Request_Builder::build_body() to the SAME wire
 * shape, including the v0.18.1 reasoning headroom) and the decode
 * validation that guards the replay path against non-chat payloads.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;

class StageReplayTest extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		// Builder reads the global reasoning setting; pin it OFF here and
		// flip it in the drift test below.
		$this->stub_get_option( array( 'prautoblogger_reasoning_enabled' => '0' ) );
	}

	/**
	 * Round-trip: a body WITHOUT reasoning replayed through build_body()
	 * reproduces the original wire shape byte-for-byte.
	 */
	public function test_round_trip_without_reasoning(): void {
		$original = array(
			'model'       => 'google/gemini-2.5-flash-lite',
			'messages'    => array(
				array( 'role' => 'system', 'content' => 'sys' ),
				array( 'role' => 'user', 'content' => 'edited prompt' ),
			),
			'temperature' => 0.7,
			'max_tokens'  => 4000,
		);

		$options = \PRAutoBlogger_Stage_Replay::options_from_body( $original, 'draft' );
		$rebuilt = ( new \PRAutoBlogger_OpenRouter_Request_Builder() )->build_body(
			$original['messages'],
			$original['model'],
			$options
		);

		// The explicit reasoning-off pin is the only permitted delta
		// (same shape the empty-completion retry sends upstream).
		$this->assertSame( array( 'enabled' => false ), $rebuilt['reasoning'] );
		unset( $rebuilt['reasoning'] );
		$this->assertSame( $original, array_merge( array( 'model' => $original['model'], 'messages' => $original['messages'] ), $rebuilt ) );
		$this->assertSame( 4000, $rebuilt['max_tokens'] );
	}

	/**
	 * Round-trip WITH reasoning: the stored body's max_tokens carries the
	 * builder's +cap headroom; the inverse subtracts it so the rebuilt
	 * body is identical (headroom applied once, never compounded).
	 */
	public function test_round_trip_with_reasoning_headroom(): void {
		$original = array(
			'model'      => 'deepseek/deepseek-v4-flash',
			'messages'   => array( array( 'role' => 'user', 'content' => 'p' ) ),
			'max_tokens' => 6048, // Caller asked 4000; builder added the 2048 cap.
			'reasoning'  => array(
				'enabled'    => true,
				'max_tokens' => 2048,
			),
		);

		$options = \PRAutoBlogger_Stage_Replay::options_from_body( $original, 'draft' );
		$this->assertSame( 4000, $options['max_tokens'] );

		$rebuilt = ( new \PRAutoBlogger_OpenRouter_Request_Builder() )->build_body(
			$original['messages'],
			$original['model'],
			$options
		);

		$this->assertSame( 6048, $rebuilt['max_tokens'] );
		$this->assertSame( $original['reasoning'], $rebuilt['reasoning'] );
	}

	/**
	 * Global-setting drift cannot reshape a replay: even with reasoning
	 * globally ENABLED, a body stored without reasoning replays with the
	 * explicit off-pin (build_body honours the per-call override).
	 */
	public function test_global_reasoning_drift_cannot_leak_into_replay(): void {
		$this->stub_get_option(
			array(
				'prautoblogger_reasoning_enabled' => '1',
				'prautoblogger_reasoning_effort'  => 'high',
			)
		);

		$body    = array(
			'model'      => 'google/gemini-2.5-flash-lite',
			'messages'   => array( array( 'role' => 'user', 'content' => 'p' ) ),
			'max_tokens' => 1000,
		);
		$options = \PRAutoBlogger_Stage_Replay::options_from_body( $body, 'polish' );
		$rebuilt = ( new \PRAutoBlogger_OpenRouter_Request_Builder() )->build_body( $body['messages'], $body['model'], $options );

		$this->assertSame( array( 'enabled' => false ), $rebuilt['reasoning'] );
		$this->assertSame( 1000, $rebuilt['max_tokens'] ); // No headroom added.
	}

	/**
	 * Caller metadata (stage/prompt_key) rides options for the guard's
	 * audit rows but never reaches the wire body.
	 */
	public function test_options_carry_stage_metadata_not_sent_upstream(): void {
		$body    = array(
			'model'    => 'm',
			'messages' => array( array( 'role' => 'user', 'content' => 'p' ) ),
		);
		$options = \PRAutoBlogger_Stage_Replay::options_from_body( $body, 'draft' );

		$this->assertSame( 'draft', $options['stage'] );
		$this->assertSame( 'content.draft', $options['prompt_key'] );

		$rebuilt = ( new \PRAutoBlogger_OpenRouter_Request_Builder() )->build_body( $body['messages'], $body['model'], $options );
		$this->assertArrayNotHasKey( 'stage', $rebuilt );
		$this->assertArrayNotHasKey( 'prompt_key', $rebuilt );
	}

	/**
	 * decode_body() accepts only well-formed chat bodies — model string +
	 * non-empty role/content message list. Everything else returns null
	 * (the executor aborts cleanly instead of replaying garbage).
	 */
	public function test_decode_body_validation(): void {
		$valid = wp_json_encode(
			array(
				'model'    => 'm',
				'messages' => array( array( 'role' => 'user', 'content' => 'p' ) ),
			)
		);
		$this->assertIsArray( \PRAutoBlogger_Stage_Replay::decode_body( (string) $valid ) );

		$invalid = array(
			'not json at all',
			'"just a string"',
			'{"messages":[{"role":"user","content":"p"}]}',           // No model.
			'{"model":"m"}',                                           // No messages.
			'{"model":"m","messages":[]}',                             // Empty messages.
			'{"model":"m","messages":["plain string"]}',               // Malformed message.
			'{"model":"m","messages":[{"role":"user"}]}',              // No content.
			'{"model":"m","messages":[{"role":1,"content":"p"}]}',     // Non-string role.
		);
		foreach ( $invalid as $payload ) {
			$this->assertNull( \PRAutoBlogger_Stage_Replay::decode_body( $payload ), "must reject: {$payload}" );
		}
	}
}
