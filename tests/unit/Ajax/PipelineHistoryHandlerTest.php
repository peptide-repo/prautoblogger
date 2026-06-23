<?php
/**
 * Unit tests for PRAutoBlogger_Pipeline_History_Handler.
 *
 * Tests cover:
 * (1) compute_diff() LCS algorithm via reflection on the private static method.
 * (2) resolve_key_from_slug() round-trip through the Step_Map allowlist.
 *
 * NOTE: PHP is unavailable in the sandbox. Structure was self-verified
 * prior to push (see PR description for grep + brace-count proof).
 *
 * @see ajax/class-pipeline-history-handler.php
 * @see admin/class-pipeline-settings-step-map.php
 *
 * @package PRAutoBlogger\Tests\Ajax
 */

namespace PRAutoBlogger\Tests\Ajax;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class PipelineHistoryHandlerTest extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias(
			function ( string $key ): string {
				return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', $key ) );
			}
		);
		Functions\when( 'wp_unslash' )->alias(
			function ( $val ) {
				return $val;
			}
		);
	}

	/**
	 * compute_diff detects added and removed lines.
	 */
	public function test_compute_diff_detects_changes(): void {
		$old = "Line A\nLine B\nLine C";
		$new = "Line A\nLine X\nLine C";

		$method = new \ReflectionMethod( 'PRAutoBlogger_Pipeline_History_Handler', 'compute_diff' );
		$method->setAccessible( true );
		$lines = $method->invoke( null, $old, $new );

		$types = array_column( $lines, 'type' );

		$this->assertContains( 'removed', $types, 'Expected a removed line' );
		$this->assertContains( 'added', $types, 'Expected an added line' );
		$this->assertContains( 'context', $types, 'Expected context lines' );
	}

	/**
	 * Identical texts produce only context lines.
	 */
	public function test_compute_diff_identical_texts_no_changes(): void {
		$body = "Same line\nAnother same line";

		$method = new \ReflectionMethod( 'PRAutoBlogger_Pipeline_History_Handler', 'compute_diff' );
		$method->setAccessible( true );
		$lines = $method->invoke( null, $body, $body );

		foreach ( $lines as $line ) {
			$this->assertNotEquals( 'added', $line['type'], 'No added lines for identical input' );
			$this->assertNotEquals( 'removed', $line['type'], 'No removed lines for identical input' );
		}
	}

	/**
	 * Each diff line has the required type and text keys.
	 */
	public function test_compute_diff_line_shape(): void {
		$old = "alpha\nbeta";
		$new = "alpha\ngamma";

		$method = new \ReflectionMethod( 'PRAutoBlogger_Pipeline_History_Handler', 'compute_diff' );
		$method->setAccessible( true );
		$lines = $method->invoke( null, $old, $new );

		foreach ( $lines as $line ) {
			$this->assertArrayHasKey( 'type', $line );
			$this->assertArrayHasKey( 'text', $line );
			$this->assertIsString( $line['type'] );
			$this->assertIsString( $line['text'] );
		}
	}

	/**
	 * resolve_key_from_slug returns canonical key for a known slug.
	 */
	public function test_resolve_key_from_slug_returns_canonical_key(): void {
		$method = new \ReflectionMethod( 'PRAutoBlogger_Pipeline_History_Handler', 'resolve_key_from_slug' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'research-system' );
		$this->assertEquals( 'research.system', $result );
	}

	/**
	 * resolve_key_from_slug returns null for an unknown slug.
	 */
	public function test_resolve_key_from_slug_returns_null_for_unknown(): void {
		$method = new \ReflectionMethod( 'PRAutoBlogger_Pipeline_History_Handler', 'resolve_key_from_slug' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'not-a-real-key-xyz' );
		$this->assertNull( $result );
	}

	/**
	 * Far-away context lines are collapsed with an omitted marker.
	 */
	public function test_compute_diff_omits_far_context(): void {
		$old_lines = array( 'changed', 'ctx1', 'ctx2', 'ctx3', 'ctx4', 'ctx5', 'ctx6', 'ctx7', 'ctx8', 'end' );
		$new_lines = array( 'CHANGED', 'ctx1', 'ctx2', 'ctx3', 'ctx4', 'ctx5', 'ctx6', 'ctx7', 'ctx8', 'end' );

		$method = new \ReflectionMethod( 'PRAutoBlogger_Pipeline_History_Handler', 'compute_diff' );
		$method->setAccessible( true );
		$lines = $method->invoke( null, implode( "\n", $old_lines ), implode( "\n", $new_lines ) );

		$types = array_column( $lines, 'type' );
		$this->assertContains( 'omitted', $types, 'Far-away context lines should be omitted' );
	}
}
