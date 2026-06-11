<?php
/**
 * Tests for PRAutoBlogger_OpenRouter_Provider.
 *
 * Validates the LLM provider interface implementation methods.
 * All HTTP calls are mocked — no real API calls.
 *
 * @package PRAutoBlogger\Tests\Providers
 */

namespace PRAutoBlogger\Tests\Providers;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class OpenRouterProviderTest extends BaseTestCase {

    protected function setUp(): void {
        parent::setUp();

        // Stub get_option — provider calls get_option('prautoblogger_openrouter_api_key', '')
        // for API key retrieval via private get_api_key() method.
        // Return empty string so decryption is skipped (no key = empty return).
        // Also include prautoblogger_log_level for Logger singleton.
        $this->stub_get_option( [
            'prautoblogger_openrouter_api_key' => '',
            'prautoblogger_log_level'          => 'info',
        ] );

        // Stub wp_salt — called by PRAutoBlogger_Encryption during API key decryption.
        Functions\when( 'wp_salt' )->justReturn( 'test_salt_key_for_unit_tests' );

        // Stub get_transient/set_transient — used by OpenRouter_Pricing (called internally).
        Functions\when( 'get_transient' )->alias(
            function ( string $key ) {
                if ( 'prautoblogger_openrouter_models' === $key ) {
                    return [
                        [
                            'id'             => 'model/test',
                            'name'           => 'Test Model',
                            'context_length' => 4096,
                            'pricing'        => [ 'prompt' => 1.00, 'completion' => 2.00 ],
                        ],
                    ];
                }
                return false;
            }
        );
        Functions\when( 'set_transient' )->justReturn( true );

        // Stub URL parsing.
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

        // Stub HTTP functions for API calls.
        Functions\when( 'wp_remote_post' )->justReturn( [
            'body'     => '{"choices":[{"message":{"content":"test"},"finish_reason":"stop"}],"usage":{"prompt_tokens":10,"completion_tokens":5,"total_tokens":15}}',
            'response' => [ 'code' => 200 ],
        ] );
        Functions\when( 'wp_remote_get' )->justReturn( [
            'body'     => '{}',
            'response' => [ 'code' => 200 ],
        ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn(
            '{"choices":[{"message":{"content":"test"},"finish_reason":"stop"}],"usage":{"prompt_tokens":10,"completion_tokens":5,"total_tokens":15}}'
        );
        Functions\when( 'is_wp_error' )->justReturn( false );
    }

    /**
     * Test OpenRouter Provider can be instantiated.
     */
    public function test_openrouter_provider_instantiation(): void {
        $provider = new \PRAutoBlogger_OpenRouter_Provider();

        $this->assertInstanceOf( \PRAutoBlogger_OpenRouter_Provider::class, $provider );
    }

    /**
     * Test that provider implements LLM Provider Interface.
     */
    public function test_provider_implements_interface(): void {
        $provider = new \PRAutoBlogger_OpenRouter_Provider();

        $this->assertInstanceOf( \PRAutoBlogger_LLM_Provider_Interface::class, $provider );
    }

    /**
     * Test send_chat_completion method is callable.
     */
    public function test_send_chat_completion_method_exists(): void {
        $provider = new \PRAutoBlogger_OpenRouter_Provider();

        $this->assertTrue( method_exists( $provider, 'send_chat_completion' ) );
    }

    /**
     * Test get_available_models returns array.
     */
    public function test_get_available_models_returns_array(): void {
        $provider = new \PRAutoBlogger_OpenRouter_Provider();
        $models = $provider->get_available_models();

        $this->assertIsArray( $models );
    }

    /**
     * Test estimate_cost returns float.
     */
    public function test_estimate_cost_returns_float(): void {
        $provider = new \PRAutoBlogger_OpenRouter_Provider();
        $cost = $provider->estimate_cost( 'model/test', 1000, 500 );

        $this->assertIsFloat( $cost );
    }

    /**
     * Test get_provider_name returns string.
     */
    public function test_get_provider_name_returns_string(): void {
        $provider = new \PRAutoBlogger_OpenRouter_Provider();
        $name = $provider->get_provider_name();

        $this->assertIsString( $name );
        $this->assertNotEmpty( $name );
    }

    /**
     * Test validate_credentials returns boolean.
     */
    public function test_validate_credentials_returns_boolean(): void {
        $provider = new \PRAutoBlogger_OpenRouter_Provider();
        $valid = $provider->validate_credentials();

        $this->assertIsBool( $valid );
    }

    /**
     * Test send_chat_completion throws RuntimeException when API key is empty.
     *
     * The guard clause at the top of send_chat_completion checks for a
     * configured API key before making any HTTP calls.
     */
    public function test_send_chat_completion_throws_without_api_key(): void {
        $provider = new \PRAutoBlogger_OpenRouter_Provider();

        $messages = [
            [ 'role' => 'user', 'content' => 'Test message' ],
        ];

        $this->expectException( \RuntimeException::class );
        $provider->send_chat_completion( $messages, 'model/test', [] );
    }

    /**
     * Test send_chat_completion with options also throws when no key.
     */
    public function test_send_chat_completion_with_options_throws_without_api_key(): void {
        $provider = new \PRAutoBlogger_OpenRouter_Provider();

        $messages = [
            [ 'role' => 'user', 'content' => 'Test message' ],
        ];
        $options = [ 'temperature' => 0.7 ];

        $this->expectException( \RuntimeException::class );
        $provider->send_chat_completion( $messages, 'model/test', $options );
    }

    /**
     * Wire the provider for a full send_chat_completion run: decryptable
     * API key, reasoning settings, HTTP stubs that replay scripted
     * responses, and a wpdb mock for the guard's failure bookkeeping.
     *
     * @param array $option_overrides Extra get_option values.
     * @param array $responses        Scripted JSON-decodable response bodies.
     * @param array $requests         (by ref) captures decoded request bodies.
     */
    private function wire_full_provider( array $option_overrides, array $responses, array &$requests ): void {
        \PRAutoBlogger_Run_Context::clear();

        $encrypted_key = \PRAutoBlogger_Encryption::encrypt( 'sk-or-v1-unit-test-key-0000' );

        $this->stub_get_option( array_merge( [
            'prautoblogger_openrouter_api_key' => $encrypted_key,
            'prautoblogger_log_level'          => 'error',
        ], $option_overrides ) );

        Functions\when( 'absint' )->alias( function ( $val ) {
            return abs( (int) $val );
        } );

        Functions\when( 'wp_remote_post' )->alias(
            function ( $url, $args ) use ( &$requests, $responses ) {
                $requests[] = json_decode( $args['body'], true );
                $body       = $responses[ count( $requests ) - 1 ];
                return [
                    'body'     => $body,
                    'response' => [ 'code' => 200 ],
                ];
            }
        );
        Functions\when( 'wp_remote_retrieve_response_code' )->alias(
            function ( $response ) {
                return $response['response']['code'];
            }
        );
        Functions\when( 'wp_remote_retrieve_body' )->alias(
            function ( $response ) {
                return $response['body'];
            }
        );

        // The guard books failed attempts through Cost_Tracker + Logger.
        $wpdb = $this->create_mock_wpdb();
        $wpdb->method( 'insert' )->willReturn( 1 );
        $wpdb->insert_id = 11;
        $GLOBALS['wpdb'] = $wpdb;
    }

    /**
     * v0.18.1 incident shape, end to end: reasoning enabled (uncapped
     * effort mode), the first response burns the whole completion budget
     * on thinking and returns empty content with finish_reason=length —
     * the provider retries exactly once with reasoning disabled and
     * returns the recovered completion.
     */
    public function test_empty_length_completion_retries_once_with_reasoning_disabled(): void {
        $requests = [];

        $empty_response = json_encode( [
            'model'   => 'deepseek/deepseek-v4-flash',
            'choices' => [ [ 'message' => [ 'content' => '', 'reasoning' => 'endless thinking…' ], 'finish_reason' => 'length' ] ],
            'usage'   => [ 'prompt_tokens' => 1200, 'completion_tokens' => 4000, 'reasoning_tokens' => 4000, 'total_tokens' => 5200 ],
        ] );
        $good_response = json_encode( [
            'model'   => 'deepseek/deepseek-v4-flash',
            'choices' => [ [ 'message' => [ 'content' => 'Recovered article.' ], 'finish_reason' => 'stop' ] ],
            'usage'   => [ 'prompt_tokens' => 1200, 'completion_tokens' => 900, 'total_tokens' => 2100 ],
        ] );

        $this->wire_full_provider(
            [
                'prautoblogger_reasoning_enabled'    => '1',
                'prautoblogger_reasoning_effort'     => 'xhigh',
                'prautoblogger_reasoning_max_tokens' => 0,
            ],
            [ $empty_response, $good_response ],
            $requests
        );

        try {
            $provider = new \PRAutoBlogger_OpenRouter_Provider();
            $result   = $provider->send_chat_completion(
                [ [ 'role' => 'user', 'content' => 'Write the article.' ] ],
                'deepseek/deepseek-v4-flash',
                [ 'temperature' => 0.7, 'max_tokens' => 4000, 'stage' => 'draft' ]
            );

            $this->assertSame( 'Recovered article.', $result['content'] );
            $this->assertCount( 2, $requests );
            // First attempt: global reasoning, uncapped effort mode.
            $this->assertSame( [ 'enabled' => true, 'effort' => 'xhigh' ], $requests[0]['reasoning'] );
            $this->assertSame( 4000, $requests[0]['max_tokens'] );
            // Retry: reasoning disabled per call.
            $this->assertSame( [ 'enabled' => false ], $requests[1]['reasoning'] );
            // Caller metadata never reaches the HTTP body.
            $this->assertArrayNotHasKey( 'stage', $requests[0] );
            $this->assertArrayNotHasKey( 'empty_retry', $requests[1] );
        } finally {
            unset( $GLOBALS['wpdb'] );
            \PRAutoBlogger_Run_Context::clear();
        }
    }

    /**
     * v0.18.1 reasoning budget: with the token cap active (default), the
     * outgoing request caps thinking via reasoning.max_tokens and raises
     * max_tokens by the cap — and a healthy completion needs no retry.
     */
    public function test_reasoning_cap_shapes_the_outgoing_request(): void {
        $requests = [];

        $good_response = json_encode( [
            'model'   => 'deepseek/deepseek-v4-flash',
            'choices' => [ [ 'message' => [ 'content' => 'Article text.' ], 'finish_reason' => 'stop' ] ],
            'usage'   => [ 'prompt_tokens' => 1200, 'completion_tokens' => 900, 'total_tokens' => 2100 ],
        ] );

        $this->wire_full_provider(
            [
                'prautoblogger_reasoning_enabled'    => '1',
                'prautoblogger_reasoning_effort'     => 'xhigh',
                'prautoblogger_reasoning_max_tokens' => 2048,
            ],
            [ $good_response ],
            $requests
        );

        try {
            $provider = new \PRAutoBlogger_OpenRouter_Provider();
            $result   = $provider->send_chat_completion(
                [ [ 'role' => 'user', 'content' => 'Write the article.' ] ],
                'deepseek/deepseek-v4-flash',
                [ 'temperature' => 0.7, 'max_tokens' => 4000, 'stage' => 'draft' ]
            );

            $this->assertSame( 'Article text.', $result['content'] );
            $this->assertCount( 1, $requests );
            $this->assertSame( 2048, $requests[0]['reasoning']['max_tokens'] );
            $this->assertArrayNotHasKey( 'effort', $requests[0]['reasoning'] );
            $this->assertSame( 6048, $requests[0]['max_tokens'] );
        } finally {
            unset( $GLOBALS['wpdb'] );
            \PRAutoBlogger_Run_Context::clear();
        }
    }
}
