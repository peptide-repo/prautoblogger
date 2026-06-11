<?php
/**
 * Tests for PRAutoBlogger_OpenRouter_Pricing.
 *
 * Validates the pricing API with instance methods for model prices and cost estimation.
 *
 * @package PRAutoBlogger\Tests\Providers
 */

namespace PRAutoBlogger\Tests\Providers;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class OpenRouterPricingTest extends BaseTestCase {

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb for Logger.
     */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();

        // Logger (called on unknown-model warning) accesses $wpdb->prefix and $wpdb->insert.
        $this->wpdb = $this->create_mock_wpdb();
        $this->wpdb->method( 'insert' )->willReturn( 1 );
        $GLOBALS['wpdb'] = $this->wpdb;

        // Stub get_option for Logger's log_level and Pricing's API key lookup.
        $this->stub_get_option( [
            'prautoblogger_log_level'          => 'info',
            'prautoblogger_openrouter_api_key'  => '',
        ] );

        // Stub current_time — used by Logger when writing log entries.
        $this->stub_current_time( '2026-04-12 10:00:00' );

        // OpenRouterPricing::get_available_models() calls get_transient() to check cache.
        // Return a valid cached model list so it doesn't try to make HTTP calls.
        Functions\when( 'get_transient' )->alias(
            function ( string $key ) {
                if ( 'prautoblogger_openrouter_models' === $key ) {
                    return [
                        [
                            'id'             => 'google/gemini-2.0-flash-001',
                            'name'           => 'Gemini 2.0 Flash',
                            'context_length' => 32768,
                            'pricing'        => [ 'prompt' => 0.10, 'completion' => 0.40 ],
                        ],
                        [
                            'id'             => 'anthropic/claude-sonnet-4',
                            'name'           => 'Claude Sonnet 4',
                            'context_length' => 200000,
                            'pricing'        => [ 'prompt' => 3.00, 'completion' => 15.00 ],
                        ],
                    ];
                }
                return false;
            }
        );

        Functions\when( 'set_transient' )->justReturn( true );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        parent::tearDown();
    }

    /**
     * Test Pricing can be instantiated.
     */
    public function test_openrouter_pricing_instantiation(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();

        $this->assertInstanceOf( \PRAutoBlogger_OpenRouter_Pricing::class, $pricing );
    }

    /**
     * Test get_available_models returns array.
     */
    public function test_get_available_models_returns_array(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();
        $models = $pricing->get_available_models();

        $this->assertIsArray( $models );
    }

    /**
     * Test get_available_models returns non-empty array.
     */
    public function test_get_available_models_not_empty(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();
        $models = $pricing->get_available_models();

        $this->assertNotEmpty( $models );
    }

    /**
     * Test estimate_cost returns float.
     */
    public function test_estimate_cost_returns_float(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();
        $cost = $pricing->estimate_cost( 'google/gemini-2.0-flash-001', 1000, 500 );

        $this->assertIsFloat( $cost );
    }

    /**
     * Test estimate_cost with zero tokens returns zero or non-negative.
     */
    public function test_estimate_cost_with_zero_tokens(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();
        $cost = $pricing->estimate_cost( 'google/gemini-2.0-flash-001', 0, 0 );

        $this->assertIsFloat( $cost );
        $this->assertGreaterThanOrEqual( 0.0, $cost );
    }

    /**
     * Test estimate_cost with positive tokens.
     */
    public function test_estimate_cost_with_positive_tokens(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();
        $cost = $pricing->estimate_cost( 'google/gemini-2.0-flash-001', 1000, 1000 );

        $this->assertIsFloat( $cost );
        $this->assertGreaterThanOrEqual( 0.0, $cost );
    }

    /**
     * Test estimate_cost with unknown model.
     */
    public function test_estimate_cost_with_unknown_model(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();
        $cost = $pricing->estimate_cost( 'unknown/fake-model', 1000, 500 );

        // Unknown models may return 0.0 or a fallback value.
        $this->assertIsFloat( $cost );
        $this->assertGreaterThanOrEqual( 0.0, $cost );
    }

    /**
     * Test estimate_cost consistency.
     */
    public function test_estimate_cost_consistency(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();

        $cost1 = $pricing->estimate_cost( 'google/gemini-2.0-flash-001', 1000, 500 );
        $cost2 = $pricing->estimate_cost( 'google/gemini-2.0-flash-001', 1000, 500 );

        // Same inputs should yield same results.
        $this->assertSame( $cost1, $cost2 );
    }

    /**
     * Test estimate_cost increases with tokens.
     */
    public function test_estimate_cost_increases_with_tokens(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();

        $cost_small = $pricing->estimate_cost( 'google/gemini-2.0-flash-001', 100, 100 );
        $cost_large = $pricing->estimate_cost( 'google/gemini-2.0-flash-001', 1000, 1000 );

        // Larger token counts should cost more or equal.
        $this->assertGreaterThanOrEqual( $cost_small, $cost_large );
    }

    /**
     * Test available models includes known OpenRouter models.
     */
    public function test_available_models_includes_popular_models(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();
        $models = $pricing->get_available_models();

        // At least some models should be present.
        $this->assertGreaterThan( 0, count( $models ) );
    }

    /**
     * Test a snapshot-suffixed response model ID resolves to the base model's price.
     *
     * OpenRouter sometimes returns a dated snapshot ID as the response model
     * (e.g. 'deepseek/deepseek-v4-flash-20260423' for a
     * 'deepseek/deepseek-v4-flash' request). Pricing must strip the date
     * suffix and bill at the base model's rate, not the conservative fallback.
     */
    public function test_estimate_cost_snapshot_suffix_resolves_to_base_model_price(): void {
        Functions\when( 'get_transient' )->alias(
            function ( string $key ) {
                if ( 'prautoblogger_openrouter_models' === $key ) {
                    return [
                        [
                            'id'             => 'deepseek/deepseek-v4-flash',
                            'name'           => 'DeepSeek V4 Flash',
                            'context_length' => 131072,
                            'pricing'        => [ 'prompt' => 0.05, 'completion' => 0.20 ],
                        ],
                    ];
                }
                return false;
            }
        );

        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();

        $base_cost     = $pricing->estimate_cost( 'deepseek/deepseek-v4-flash', 1000, 500 );
        $snapshot_cost = $pricing->estimate_cost( 'deepseek/deepseek-v4-flash-20260423', 1000, 500 );

        // Billed exactly like the base model: (1000 * 0.05 + 500 * 0.20) / 1M.
        $this->assertSame( $base_cost, $snapshot_cost );
        $this->assertEqualsWithDelta( 0.00015, $snapshot_cost, 1e-12 );

        // And not at the conservative fallback rate (10/30 per M = 0.025 here).
        $this->assertNotEquals( 0.025, $snapshot_cost );
    }

    /**
     * Test an unknown model with no date suffix still gets the conservative estimate.
     */
    public function test_estimate_cost_unknown_model_without_suffix_uses_conservative_estimate(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();
        $cost = $pricing->estimate_cost( 'unknown/fake-model', 1000, 500 );

        // Conservative fallback: (1000 * $10 + 500 * $30) / 1M.
        $this->assertEqualsWithDelta( 0.025, $cost, 1e-12 );
    }

    /**
     * Test a trailing digit run that is not an 8-digit date is never stripped.
     *
     * 'foo-202612345' has nine trailing digits — even with 'foo' and 'foo-2'
     * registered, no stripping may occur and the conservative estimate applies.
     */
    public function test_estimate_cost_non_date_suffix_is_not_stripped(): void {
        Functions\when( 'get_transient' )->alias(
            function ( string $key ) {
                if ( 'prautoblogger_openrouter_models' === $key ) {
                    return [
                        [
                            'id'             => 'foo',
                            'name'           => 'Foo',
                            'context_length' => 8192,
                            'pricing'        => [ 'prompt' => 0.01, 'completion' => 0.02 ],
                        ],
                        [
                            'id'             => 'foo-2',
                            'name'           => 'Foo 2',
                            'context_length' => 8192,
                            'pricing'        => [ 'prompt' => 0.01, 'completion' => 0.02 ],
                        ],
                    ];
                }
                return false;
            }
        );

        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();
        $cost = $pricing->estimate_cost( 'foo-202612345', 1000, 500 );

        // Nine trailing digits is not a snapshot date — conservative estimate applies.
        $this->assertEqualsWithDelta( 0.025, $cost, 1e-12 );
    }
}
