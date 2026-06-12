<?php
/**
 * Tests verifying the SQL inline-comment bug fix in PRAutoBlogger_Cost_Reporter.
 *
 * The original code embedded PHP `// phpcs:ignore` annotations inside SQL string
 * literals, passing them to MySQL as literal comment text. These tests confirm the
 * SQL strings passed to $wpdb->prepare() no longer contain inline PHP comments.
 *
 * Kept separate from CostReporterTest to isolate the bug-fix regression signal.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class CostReporterSqlBugFixTest extends BaseTestCase {

	/** @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb. */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb         = $this->create_mock_wpdb();
		$GLOBALS['wpdb']    = $this->wpdb;

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return 'prautoblogger_monthly_budget_usd' === $name ? '50.00' : $default;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * get_daily_spend() must not pass inline PHP comments to prepare().
	 *
	 * Captures the SQL argument passed to $wpdb->prepare() and asserts it
	 * contains no `// phpcs:ignore` text (which would be literal SQL, not PHP).
	 */
	public function test_get_daily_spend_sql_has_no_inline_comment(): void {
		$captured_sql = null;

		$this->wpdb->method( 'prepare' )->willReturnCallback(
			function ( string $query ) use ( &$captured_sql ) {
				$captured_sql = $query;
				return 'prepared_query';
			}
		);

		$this->wpdb->method( 'get_results' )->willReturn( array() );

		$reporter = new \PRAutoBlogger_Cost_Reporter();
		$reporter->get_daily_spend( 30 );

		$this->assertNotNull( $captured_sql, 'prepare() should have been called' );
		$this->assertStringNotContainsString(
			'// phpcs:ignore',
			$captured_sql,
			'get_daily_spend() SQL must not contain a PHP inline comment'
		);
	}

	/**
	 * get_spend_by_stage() must not pass inline PHP comments to prepare().
	 */
	public function test_get_spend_by_stage_sql_has_no_inline_comment(): void {
		$captured_sql = null;

		$this->wpdb->method( 'prepare' )->willReturnCallback(
			function ( string $query ) use ( &$captured_sql ) {
				$captured_sql = $query;
				return 'prepared_query';
			}
		);

		$this->wpdb->method( 'get_results' )->willReturn( array() );

		$reporter = new \PRAutoBlogger_Cost_Reporter();
		$reporter->get_spend_by_stage( '2026-06-01', '2026-06-30' );

		$this->assertNotNull( $captured_sql, 'prepare() should have been called' );
		$this->assertStringNotContainsString(
			'// phpcs:ignore',
			$captured_sql,
			'get_spend_by_stage() SQL must not contain a PHP inline comment'
		);
	}

	/**
	 * Confirm the existing monthly spend query was already clean (regression guard).
	 */
	public function test_get_monthly_spend_sql_was_already_clean(): void {
		$captured_sql = null;

		$this->wpdb->method( 'prepare' )->willReturnCallback(
			function ( string $query ) use ( &$captured_sql ) {
				$captured_sql = $query;
				return 'prepared_query';
			}
		);

		$this->wpdb->method( 'get_var' )->willReturn( '5.00' );

		$reporter = new \PRAutoBlogger_Cost_Reporter();
		$reporter->get_monthly_spend();

		$this->assertNotNull( $captured_sql );
		$this->assertStringNotContainsString(
			'// phpcs:ignore',
			$captured_sql,
			'get_monthly_spend() SQL must not contain a PHP inline comment'
		);
	}
}
