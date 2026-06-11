<?php
/**
 * Tests for PRAutoBlogger_OpenRouter_Completion_Guard (v0.18.1).
 *
 * Validates the empty-completion verdict matrix: healthy pass-through,
 * the single reasoning-disabled retry on length/reasoning empties, the
 * never-retry-twice rule, and the hard failure for unexplained empties.
 *
 * @package PRAutoBlogger\Tests\Providers
 */

namespace PRAutoBlogger\Tests\Providers;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class OpenRouterCompletionGuardTest extends BaseTestCase {

    protected function setUp(): void {
        parent::setUp();

        \PRAutoBlogger_Run_Context::clear();
        \PRAutoBlogger_Audit_Writer::flush_cache();

        $this->stub_get_option( [
            'prautoblogger_log_level' => 'error',
        ] );

        // Cost_Tracker::log_api_call() (failed-attempt booking) inserts a
        // generation_log row and prices the burn via the pricing chain.
        $wpdb = $this->create_mock_wpdb();
        $wpdb->method( 'insert' )->willReturn( 1 );
        $wpdb->insert_id = 7;
        $GLOBALS['wpdb'] = $wpdb;
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        \PRAutoBlogger_Run_Context::clear();
        \PRAutoBlogger_Audit_Writer::flush_cache();
        parent::tearDown();
    }

    /**
     * Build a parsed-response fixture in the Response_Parser shape.
     *
     * @param string $content Visible content.
     * @param string $finish  finish_reason value.
     * @return array
     */
    private function parsed( string $content, string $finish ): array {
        return [
            'content'           => $content,
            'model'             => 'deepseek/deepseek-v4-flash',
            'prompt_tokens'     => 1200,
            'completion_tokens' => 4000,
            'reasoning_tokens'  => 4000,
            'total_tokens'      => 5200,
            'finish_reason'     => $finish,
            'reasoning_content' => 'thinking…',
        ];
    }

    /**
     * Healthy completion passes through untouched; the retry callable is
     * never invoked.
     */
    public function test_healthy_completion_passes_through(): void {
        $parsed  = $this->parsed( 'A full article.', 'stop' );
        $invoked = 0;

        $result = ( new \PRAutoBlogger_OpenRouter_Completion_Guard() )->finalize(
            $parsed,
            [ 'stage' => 'draft' ],
            false,
            function () use ( &$invoked ) {
                ++$invoked;
                return [];
            }
        );

        $this->assertSame( $parsed, $result );
        $this->assertSame( 0, $invoked );
    }

    /**
     * The incident shape — empty content + finish_reason=length — retries
     * exactly once with reasoning disabled and the empty_retry marker set,
     * and returns the retry's result.
     */
    public function test_empty_plus_length_retries_once_with_reasoning_disabled(): void {
        $recovered     = $this->parsed( 'Recovered article.', 'stop' );
        $captured_opts = null;

        $result = ( new \PRAutoBlogger_OpenRouter_Completion_Guard() )->finalize(
            $this->parsed( '', 'length' ),
            [ 'stage' => 'draft', 'prompt_key' => 'content.single_pass' ],
            true,
            function ( array $retry_options ) use ( &$captured_opts, $recovered ) {
                $captured_opts = $retry_options;
                return $recovered;
            }
        );

        $this->assertSame( $recovered, $result );
        $this->assertSame( [ 'enabled' => false ], $captured_opts['reasoning'] );
        $this->assertTrue( $captured_opts['empty_retry'] );
        $this->assertSame( 'draft', $captured_opts['stage'] );
    }

    /**
     * Whitespace-only content counts as empty (the guard trims).
     */
    public function test_whitespace_only_content_counts_as_empty(): void {
        $invoked = 0;

        ( new \PRAutoBlogger_OpenRouter_Completion_Guard() )->finalize(
            $this->parsed( "  \n\t ", 'length' ),
            [ 'stage' => 'polish' ],
            true,
            function ( array $retry_options ) use ( &$invoked ) {
                ++$invoked;
                return $this->parsed( 'ok', 'stop' );
            }
        );

        $this->assertSame( 1, $invoked );
    }

    /**
     * An attempt already marked empty_retry is never retried again — the
     * guard fails the call instead (no infinite retry loops).
     */
    public function test_already_retried_empty_fails_without_second_retry(): void {
        $invoked = 0;

        try {
            ( new \PRAutoBlogger_OpenRouter_Completion_Guard() )->finalize(
                $this->parsed( '', 'length' ),
                [ 'stage' => 'draft', 'empty_retry' => true ],
                false,
                function () use ( &$invoked ) {
                    ++$invoked;
                    return [];
                }
            );
            $this->fail( 'Expected RuntimeException for retried-and-still-empty completion.' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'empty content', $e->getMessage() );
            $this->assertStringContainsString( 'after a reasoning-disabled retry', $e->getMessage() );
        }

        $this->assertSame( 0, $invoked );
    }

    /**
     * Empty content with finish_reason=stop and no reasoning active: a
     * reasoning-disabled retry cannot plausibly help — fail immediately.
     */
    public function test_unexplained_empty_fails_without_retry(): void {
        $invoked = 0;

        try {
            ( new \PRAutoBlogger_OpenRouter_Completion_Guard() )->finalize(
                $this->parsed( '', 'stop' ),
                [ 'stage' => 'review' ],
                false,
                function () use ( &$invoked ) {
                    ++$invoked;
                    return [];
                }
            );
            $this->fail( 'Expected RuntimeException for unexplained empty completion.' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'stage "review"', $e->getMessage() );
            $this->assertStringContainsString( 'finish_reason=stop', $e->getMessage() );
        }

        $this->assertSame( 0, $invoked );
    }

    /**
     * Empty content with reasoning active retries even when finish_reason
     * is not 'length' (reasoning can eat the budget without a length flag).
     */
    public function test_empty_with_reasoning_active_retries_on_any_finish_reason(): void {
        $invoked = 0;

        ( new \PRAutoBlogger_OpenRouter_Completion_Guard() )->finalize(
            $this->parsed( '', 'stop' ),
            [ 'stage' => 'draft' ],
            true,
            function ( array $retry_options ) use ( &$invoked ) {
                ++$invoked;
                return $this->parsed( 'ok', 'stop' );
            }
        );

        $this->assertSame( 1, $invoked );
    }

    /**
     * The failed attempt is booked to generation_log with its REAL status:
     * response_status='error', the stage, the model, and the token burn.
     */
    public function test_failed_attempt_books_generation_log_row_with_error_status(): void {
        $captured_row = null;

        $wpdb = $this->create_mock_wpdb();
        $wpdb->method( 'insert' )->willReturnCallback(
            function ( $table, $row ) use ( &$captured_row ) {
                if ( false !== strpos( (string) $table, 'generation_log' ) ) {
                    $captured_row = $row;
                }
                return 1;
            }
        );
        $wpdb->insert_id = 9;
        $GLOBALS['wpdb'] = $wpdb;

        try {
            ( new \PRAutoBlogger_OpenRouter_Completion_Guard() )->finalize(
                $this->parsed( '', 'stop' ),
                [ 'stage' => 'draft' ],
                false,
                function () {
                    return [];
                }
            );
            $this->fail( 'Expected RuntimeException.' );
        } catch ( \RuntimeException $e ) {
            $this->assertIsArray( $captured_row );
            $this->assertSame( 'error', $captured_row['response_status'] );
            $this->assertSame( 'draft', $captured_row['stage'] );
            $this->assertSame( 'deepseek/deepseek-v4-flash', $captured_row['model'] );
            $this->assertSame( 4000, $captured_row['completion_tokens'] );
            $this->assertStringContainsString( 'Empty completion', $captured_row['error_message'] );
        }
    }
}
