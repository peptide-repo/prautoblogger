<?php
/**
 * Tests for PRAutoBlogger_OpenRouter_Request_Builder::build_body() (v0.18.1).
 *
 * Validates body assembly, the global-vs-per-call reasoning precedence, the
 * reasoning token cap + completion headroom, and that caller-metadata keys
 * never leak into the HTTP body.
 *
 * @package PRAutoBlogger\Tests\Providers
 */

namespace PRAutoBlogger\Tests\Providers;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class OpenRouterRequestBuilderTest extends BaseTestCase {

    private const MESSAGES = [
        [ 'role' => 'system', 'content' => 'sys' ],
        [ 'role' => 'user', 'content' => 'usr' ],
    ];

    protected function setUp(): void {
        parent::setUp();
        Functions\when( 'absint' )->alias( function ( $val ) {
            return abs( (int) $val );
        } );
    }

    /**
     * Reasoning enabled globally + cap set: thinking is capped via
     * reasoning.max_tokens, effort is dropped, and the completion budget
     * is raised by the cap so content keeps its full allowance.
     */
    public function test_reasoning_cap_replaces_effort_and_raises_headroom(): void {
        $this->stub_get_option( [
            'prautoblogger_reasoning_enabled'    => '1',
            'prautoblogger_reasoning_effort'     => 'xhigh',
            'prautoblogger_reasoning_max_tokens' => 2048,
        ] );

        $body = ( new \PRAutoBlogger_OpenRouter_Request_Builder() )
            ->build_body( self::MESSAGES, 'deepseek/deepseek-v4-flash', [ 'max_tokens' => 4000 ] );

        $this->assertSame( 2048, $body['reasoning']['max_tokens'] );
        $this->assertArrayNotHasKey( 'effort', $body['reasoning'] );
        $this->assertSame( 6048, $body['max_tokens'] );
        $this->assertTrue( $body['reasoning']['enabled'] );
    }

    /**
     * Cap setting 0 = pure effort mode: the historical body shape is
     * preserved exactly (effort kept, no reasoning.max_tokens, no headroom).
     */
    public function test_cap_zero_keeps_pure_effort_mode(): void {
        $this->stub_get_option( [
            'prautoblogger_reasoning_enabled'    => '1',
            'prautoblogger_reasoning_effort'     => 'xhigh',
            'prautoblogger_reasoning_max_tokens' => 0,
        ] );

        $body = ( new \PRAutoBlogger_OpenRouter_Request_Builder() )
            ->build_body( self::MESSAGES, 'deepseek/deepseek-v4-flash', [ 'max_tokens' => 4000 ] );

        $this->assertSame( [ 'enabled' => true, 'effort' => 'xhigh' ], $body['reasoning'] );
        $this->assertSame( 4000, $body['max_tokens'] );
    }

    /**
     * Per-call reasoning override (the guard's retry) beats the global
     * setting; a disabled override gets no cap and no headroom.
     */
    public function test_per_call_disabled_override_beats_global_setting(): void {
        $this->stub_get_option( [
            'prautoblogger_reasoning_enabled'    => '1',
            'prautoblogger_reasoning_effort'     => 'xhigh',
            'prautoblogger_reasoning_max_tokens' => 2048,
        ] );

        $body = ( new \PRAutoBlogger_OpenRouter_Request_Builder() )
            ->build_body(
                self::MESSAGES,
                'deepseek/deepseek-v4-flash',
                [
                    'max_tokens' => 4000,
                    'reasoning'  => [ 'enabled' => false ],
                ]
            );

        $this->assertSame( [ 'enabled' => false ], $body['reasoning'] );
        $this->assertSame( 4000, $body['max_tokens'] );
    }

    /**
     * Global reasoning off + no override: no reasoning key at all and no
     * budget math (historical default path).
     */
    public function test_reasoning_off_produces_no_reasoning_key(): void {
        $this->stub_get_option( [
            'prautoblogger_reasoning_enabled' => '0',
        ] );

        $body = ( new \PRAutoBlogger_OpenRouter_Request_Builder() )
            ->build_body(
                self::MESSAGES,
                'google/gemini-2.5-flash-lite',
                [
                    'temperature'     => 0.7,
                    'max_tokens'      => 4000,
                    'response_format' => [ 'type' => 'json_object' ],
                ]
            );

        $this->assertArrayNotHasKey( 'reasoning', $body );
        $this->assertSame( 0.7, $body['temperature'] );
        $this->assertSame( 4000, $body['max_tokens'] );
        $this->assertSame( [ 'type' => 'json_object' ], $body['response_format'] );
    }

    /**
     * An explicit caller reasoning config with its own max_tokens is
     * respected (no cap overwrite) but still gets completion headroom.
     */
    public function test_caller_reasoning_max_tokens_respected_with_headroom(): void {
        $this->stub_get_option( [
            'prautoblogger_reasoning_enabled'    => '0',
            'prautoblogger_reasoning_max_tokens' => 2048,
        ] );

        $body = ( new \PRAutoBlogger_OpenRouter_Request_Builder() )
            ->build_body(
                self::MESSAGES,
                'deepseek/deepseek-v4-flash',
                [
                    'max_tokens' => 1000,
                    'reasoning'  => [ 'enabled' => true, 'max_tokens' => 512 ],
                ]
            );

        $this->assertSame( 512, $body['reasoning']['max_tokens'] );
        $this->assertSame( 1512, $body['max_tokens'] );
    }

    /**
     * Caller-metadata keys (stage, prompt_key, empty_retry) must never
     * appear in the HTTP body.
     */
    public function test_caller_metadata_keys_never_leak_into_body(): void {
        $this->stub_get_option( [
            'prautoblogger_reasoning_enabled' => '0',
        ] );

        $body = ( new \PRAutoBlogger_OpenRouter_Request_Builder() )
            ->build_body(
                self::MESSAGES,
                'google/gemini-2.5-flash-lite',
                [
                    'max_tokens'  => 4000,
                    'stage'       => 'draft',
                    'prompt_key'  => 'content.single_pass',
                    'empty_retry' => true,
                ]
            );

        $this->assertArrayNotHasKey( 'stage', $body );
        $this->assertArrayNotHasKey( 'prompt_key', $body );
        $this->assertArrayNotHasKey( 'empty_retry', $body );
        $this->assertSame( [ 'model', 'messages', 'max_tokens' ], array_keys( $body ) );
    }

    /**
     * Reasoning enabled but the call has no completion ceiling: nothing to
     * protect, body passes through with effort untouched.
     */
    public function test_no_max_tokens_means_no_budget_math(): void {
        $this->stub_get_option( [
            'prautoblogger_reasoning_enabled'    => '1',
            'prautoblogger_reasoning_effort'     => 'high',
            'prautoblogger_reasoning_max_tokens' => 2048,
        ] );

        $body = ( new \PRAutoBlogger_OpenRouter_Request_Builder() )
            ->build_body( self::MESSAGES, 'deepseek/deepseek-v4-flash', [] );

        $this->assertSame( [ 'enabled' => true, 'effort' => 'high' ], $body['reasoning'] );
        $this->assertArrayNotHasKey( 'max_tokens', $body );
    }
}
