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
}
