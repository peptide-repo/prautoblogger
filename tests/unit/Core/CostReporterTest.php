<?php
/**
 * Tests for PRAutoBlogger_Cost_Reporter.
 *
 * Validates monthly spend, daily spend trends, spend-by-stage breakdowns,
 * and budget utilization reporting methods.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class CostReporterTest extends BaseTestCase {

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb instance.
     */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();

        $this->wpdb = $this->create_mock_wpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        // CostReporter methods call get_option for budget settings.
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
     * Test get_monthly_spend returns float.
     */
    public function test_get_monthly_spend_returns_float(): void {
        $this->wpdb->method( 'prepare' )->willReturn( 'prepared' );
        $this->wpdb->method( 'get_var' )->willReturn( '10.50' );

        $reporter = new \PRAutoBlogger_Cost_Reporter();
        $spend = $reporter->get_monthly_spend();

        $this->assertIsFloat( $spend );
        $this->assertGreaterThanOrEqual( 0.0, $spend );
    }

    /**
     * Test get_daily_spend returns array.
     */
    public function test_get_daily_spend_returns_array(): void {
        $this->wpdb->method( 'prepare' )->willReturn( 'prepared' );
        $this->wpdb->method( 'get_results' )->willReturn( [] );

        $reporter = new \PRAutoBlogger_Cost_Reporter();
        $daily = $reporter->get_daily_spend( 30 );

        $this->assertIsArray( $daily );
    }

    /**
     * Test get_spend_by_stage returns array.
     */
    public function test_get_spend_by_stage_returns_array(): void {
        $this->wpdb->method( 'prepare' )->willReturn( 'prepared' );
        $this->wpdb->method( 'get_results' )->willReturn( [] );

        $reporter = new \PRAutoBlogger_Cost_Reporter();
        $spend = $reporter->get_spend_by_stage( '2026-04-01', '2026-04-30' );

        $this->assertIsArray( $spend );
    }

    /**
     * Test get_budget_utilization returns float between 0 and 100+.
     */
    public function test_get_budget_utilization_returns_float(): void {
        $reporter = new \PRAutoBlogger_Cost_Reporter();
        $utilization = $reporter->get_budget_utilization();

        $this->assertIsFloat( $utilization );
        $this->assertGreaterThanOrEqual( 0.0, $utilization );
    }

    /**
     * Regression: get_daily_spend SQL must not embed phpcs:ignore inside the
     * string literal (MariaDB would receive the comment as literal SQL text and
     * reject the query). Captures the first argument passed to prepare() and
     * asserts the SQL is clean.
     *
     * @see includes/core/class-cost-reporter.php — fixed in v0.18.2
     */
    public function test_get_daily_spend_sql_contains_no_inline_phpcs_ignore(): void {
        $captured_sql = null;
        $this->wpdb->method( 'prepare' )->willReturnCallback(
            function ( $sql, ...$args ) use ( &$captured_sql ) {
                $captured_sql = $sql;
                return 'prepared';
            }
        );
        $this->wpdb->method( 'get_results' )->willReturn( [] );

        $reporter = new \PRAutoBlogger_Cost_Reporter();
        $reporter->get_daily_spend( 7 );

        $this->assertNotNull( $captured_sql, 'prepare() was not called' );
        $this->assertStringNotContainsString(
            '// phpcs:ignore',
            (string) $captured_sql,
            'SQL string must not contain phpcs:ignore comment — MariaDB rejects it'
        );
    }

    /**
     * Regression: get_spend_by_stage SQL must not embed phpcs:ignore inside
     * the string literal.
     *
     * @see includes/core/class-cost-reporter.php — fixed in v0.18.2
     */
    public function test_get_spend_by_stage_sql_contains_no_inline_phpcs_ignore(): void {
        $captured_sql = null;
        $this->wpdb->method( 'prepare' )->willReturnCallback(
            function ( $sql, ...$args ) use ( &$captured_sql ) {
                $captured_sql = $sql;
                return 'prepared';
            }
        );
        $this->wpdb->method( 'get_results' )->willReturn( [] );

        $reporter = new \PRAutoBlogger_Cost_Reporter();
        $reporter->get_spend_by_stage( '2026-06-01', '2026-06-30' );

        $this->assertNotNull( $captured_sql, 'prepare() was not called' );
        $this->assertStringNotContainsString(
            '// phpcs:ignore',
            (string) $captured_sql,
            'SQL string must not contain phpcs:ignore comment — MariaDB rejects it'
        );
    }
}
