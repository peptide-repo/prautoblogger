<?php
/**
 * Tests for PRAutoBlogger_Cost_Tracker.
 *
 * Validates run ID management, monthly spend, budget limits,
 * current run cost, and cost retrieval methods.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class CostTrackerTest extends BaseTestCase {

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb instance.
     */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();

        $this->wpdb = $this->create_mock_wpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        // CostTracker methods call get_option for budget settings.
        $this->stub_get_option( [
            'prautoblogger_monthly_budget_usd' => '50.00',
        ] );

        // Stub current_time used in date-based queries.
        $this->stub_current_time( '2026-04-12 10:00:00' );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        parent::tearDown();
    }

    /**
     * Test set_run_id and get_run_id.
     */
    public function test_set_and_get_run_id(): void {
        $tracker = new \PRAutoBlogger_Cost_Tracker();

        $tracker->set_run_id( 'run_abc123' );
        $this->assertSame( 'run_abc123', $tracker->get_run_id() );
    }

    /**
     * Test get_run_id returns null when not set.
     */
    public function test_get_run_id_returns_null_when_not_set(): void {
        $tracker = new \PRAutoBlogger_Cost_Tracker();

        $this->assertNull( $tracker->get_run_id() );
    }

    /**
     * Test is_budget_exceeded returns false by default.
     */
    public function test_is_budget_exceeded_returns_false_by_default(): void {
        $tracker = new \PRAutoBlogger_Cost_Tracker();

        $this->assertFalse( $tracker->is_budget_exceeded() );
    }

    /**
     * Test get_current_run_cost returns float.
     */
    public function test_get_current_run_cost_returns_float(): void {
        $this->wpdb->method( 'prepare' )->willReturn( 'prepared' );
        $this->wpdb->method( 'get_var' )->willReturn( '0.05' );

        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $tracker->set_run_id( 'run_abc123' );

        $cost = $tracker->get_current_run_cost();

        $this->assertIsFloat( $cost );
        $this->assertGreaterThanOrEqual( 0.0, $cost );
    }

    /**
     * Test log_api_call with mock database.
     */
    public function test_log_api_call_interacts_with_database(): void {
        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $tracker->set_run_id( 'run_test' );

        // Allow multiple inserts — log_api_call inserts a generation log row,
        // and Logger may also insert an event log row if a warning fires.
        $this->wpdb->expects( $this->atLeastOnce() )
            ->method( 'insert' )
            ->willReturn( 1 );

        // Actual signature: log_api_call(?int $post_id, string $stage,
        //   string $provider, string $model, int $prompt_tokens,
        //   int $completion_tokens, string $response_status, string $error_message).
        // Use 'openai/gpt-4o' which IS in MODEL_PRICING to avoid unknown-model warnings.
        $tracker->log_api_call(
            123,
            'analysis',
            'openrouter',
            'openai/gpt-4o',
            500,
            250
        );

        // If we get here without exception, the method worked.
        $this->assertTrue( true );
    }

    /**
     * Regression: get_avg_tokens_for_stages must return the documented zero
     * shape when there is no generation history, without leaving a $wpdb
     * error behind.
     *
     * Load-bearing: asserting $wpdb->last_error === '' alongside the shape
     * is what would have caught the v0.11.0 column-name drift. The empty-
     * result fallback silently masked the failure from shape-only tests.
     */
    public function test_get_avg_tokens_returns_zero_shape_when_no_history(): void {
        $captured_query = '';
        $this->wpdb->method( 'prepare' )->willReturnCallback(
            function ( $sql, ...$args ) use ( &$captured_query ) {
                $captured_query = $sql;
                return $sql; // Simulate prepared SQL passthrough.
            }
        );
        // Empty result row — fallback branch.
        $this->wpdb->method( 'get_row' )->willReturn( null );

        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $result  = $tracker->get_avg_tokens_for_stages( [ 'analysis' ], 30 );

        $this->assertSame(
            [
                'avg_prompt_tokens'     => 0.0,
                'avg_completion_tokens' => 0.0,
                'sample_size'           => 0,
            ],
            $result
        );
        $this->assertSame( '', $this->wpdb->last_error, 'wpdb->last_error must be empty after the query' );

        // Schema-drift guard: query must reference canonical columns, not the
        // pre-v0.15.1 names that don't exist on wp_prautoblogger_generation_log.
        $this->assertStringContainsString( 'prompt_tokens', $captured_query );
        $this->assertStringContainsString( 'completion_tokens', $captured_query );
        $this->assertStringContainsString( 'created_at', $captured_query );
        $this->assertStringNotContainsString( 'input_tokens', $captured_query );
        $this->assertStringNotContainsString( 'output_tokens', $captured_query );
        // The bug query had `AND timestamp >= %d`; canonical query uses
        // `AND created_at >= %s`. Guard against the column reference, not the
        // bare word (a SQL comment in future code could legitimately mention
        // "timestamp" in prose).
        $this->assertStringNotContainsString( 'timestamp >=', $captured_query );
    }

    /**
     * Regression: with seeded rows the method returns real averages and the
     * canonical SELECT aliases (avg_prompt / avg_completion) are extracted
     * correctly.
     */
    public function test_get_avg_tokens_returns_real_averages_with_seeded_rows(): void {
        $this->wpdb->method( 'prepare' )->willReturnCallback(
            static function ( $sql ) {
                return $sql;
            }
        );
        // Simulate three rows averaged: prompt_tokens=100, completion_tokens=50.
        $this->wpdb->method( 'get_row' )->willReturn(
            [
                'avg_prompt'     => '100.0000',
                'avg_completion' => '50.0000',
                'sample_count'   => '3',
            ]
        );

        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $result  = $tracker->get_avg_tokens_for_stages( [ 'analysis' ], 30 );

        $this->assertSame( 100.0, $result['avg_prompt_tokens'] );
        $this->assertSame( 50.0, $result['avg_completion_tokens'] );
        $this->assertSame( 3, $result['sample_size'] );
        $this->assertSame( '', $this->wpdb->last_error );
    }

    /**
     * Regression: the days cutoff must be a MySQL DATETIME string (compared
     * against the canonical created_at column), not a unix int. Captured via
     * the prepare() callback so we can inspect the bound parameter.
     */
    public function test_get_avg_tokens_respects_days_cutoff_as_datetime(): void {
        $captured_args = [];
        $this->wpdb->method( 'prepare' )->willReturnCallback(
            function ( $sql, ...$args ) use ( &$captured_args ) {
                $captured_args = $args;
                return $sql;
            }
        );
        $this->wpdb->method( 'get_row' )->willReturn( null );

        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $tracker->get_avg_tokens_for_stages( [ 'analysis' ], 30 );

        // Exactly one bound param: the DATETIME cutoff.
        $this->assertCount( 1, $captured_args );
        $this->assertIsString( $captured_args[0], 'Cutoff must be a DATETIME string, not a unix int' );
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $captured_args[0]
        );
    }

    /**
     * Regression: the stage filter is propagated into the SQL. Calling with
     * a single stage should result in exactly one prepared %s placeholder
     * for the IN ( … ) list.
     */
    public function test_get_avg_tokens_filters_by_stage(): void {
        $stage_prepare_calls = 0;
        $main_query          = '';
        $this->wpdb->method( 'prepare' )->willReturnCallback(
            function ( $sql, ...$args ) use ( &$stage_prepare_calls, &$main_query ) {
                // The method calls $wpdb->prepare twice in two distinct shapes:
                //  1. per-stage placeholder prep (sql='%s', single arg)
                //  2. the main SELECT (sql contains 'SELECT', many tokens)
                if ( strpos( $sql, 'SELECT' ) !== false ) {
                    $main_query = $sql;
                } else {
                    ++$stage_prepare_calls;
                }
                return $sql;
            }
        );
        $this->wpdb->method( 'get_row' )->willReturn( null );

        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $tracker->get_avg_tokens_for_stages( [ 'analysis' ], 30 );

        $this->assertSame( 1, $stage_prepare_calls, 'One stage → one per-stage prepare() call' );
        $this->assertStringContainsString( 'stage IN', $main_query );
        $this->assertStringContainsString( 'wp_prautoblogger_generation_log', $main_query );
        $this->assertSame( '', $this->wpdb->last_error );
    }
}
