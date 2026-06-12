<?php
/**
 * Tests for PRAutoBlogger_Analysis_Prompts.
 *
 * Regression + behavioral coverage for the GA4 self-improvement loop:
 * - SQL passed to $wpdb->get_results() must not embed phpcs:ignore comments
 *   (MariaDB rejects the query if it does).
 * - get_performance_context() returns a non-empty, correctly-shaped string
 *   when seeded content_scores rows are present.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class AnalysisPromptsTest extends BaseTestCase {

	/** @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb. */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb         = $this->create_mock_wpdb();
		// Analysis prompts uses $wpdb->posts (JOIN {->posts}) and prefix.
		$this->wpdb->posts  = 'wp_posts';
		$GLOBALS['wpdb']    = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Regression: get_performance_context() SQL must not embed phpcs:ignore
	 * inside the string literal — MariaDB rejects the query if it does,
	 * causing get_performance_context() to silently return '' on every run.
	 *
	 * @see includes/core/class-analysis-prompts.php — fixed in v0.18.3
	 */
	public function test_get_performance_context_sql_has_no_inline_phpcs_ignore(): void {
		$captured_sql = null;
		$this->wpdb->method( 'prepare' )->willReturnCallback(
			function ( $sql ) use ( &$captured_sql ) {
				// get_results is called WITHOUT prepare (direct string interpolation
				// of a table prefix); capture the first arg to get_results instead.
				return $sql;
			}
		);
		$this->wpdb->method( 'get_results' )->willReturnCallback(
			function ( $sql ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return array();
			}
		);

		\PRAutoBlogger_Analysis_Prompts::get_performance_context();

		$this->assertNotNull( $captured_sql, 'get_results() was not called' );
		$this->assertStringNotContainsString(
			'// phpcs:ignore',
			(string) $captured_sql,
			'SQL passed to get_results() must not contain a phpcs:ignore comment — MariaDB rejects it as a syntax error'
		);
	}

	/**
	 * Behavioral: get_performance_context() returns a non-empty, correctly-shaped
	 * string when the scores table contains rows with composite_score > 0.
	 *
	 * The returned string must:
	 *   - start with the "Top performing" header line
	 *   - contain at least one bullet entry of the form '- "…" (score: N.N)'
	 *   - not be empty
	 */
	public function test_get_performance_context_returns_shaped_context_with_seeded_rows(): void {
		// Simulate two content_scores rows returned from the DB query.
		$rows = array(
			array(
				'post_id'         => 100,
				'composite_score' => '9.2',
				'post_title'      => 'BPC-157 and Gut Healing — What the Research Shows',
			),
			array(
				'post_id'         => 101,
				'composite_score' => '7.8',
				'post_title'      => 'Semaglutide Dosing Guide for Weight Loss',
			),
		);

		$this->wpdb->method( 'get_results' )->willReturn( $rows );

		$context = \PRAutoBlogger_Analysis_Prompts::get_performance_context();

		$this->assertNotEmpty( $context, 'get_performance_context() returned empty string with seeded rows' );
		$this->assertStringContainsString(
			'Top performing past articles',
			$context,
			'Context block must start with the header line'
		);
		$this->assertStringContainsString(
			'BPC-157 and Gut Healing',
			$context,
			'Context block must contain the first seeded article title'
		);
		$this->assertStringContainsString(
			'score: 9.2',
			$context,
			'Context block must include formatted composite_score'
		);
	}

	/**
	 * Edge case: get_performance_context() returns '' when no rows exist.
	 */
	public function test_get_performance_context_returns_empty_string_when_no_rows(): void {
		$this->wpdb->method( 'get_results' )->willReturn( array() );

		$context = \PRAutoBlogger_Analysis_Prompts::get_performance_context();

		$this->assertSame( '', $context, 'get_performance_context() must return empty string when scores table is empty' );
	}
}
