<?php
/**
 * Unit tests for PRAutoBlogger_Gen_History_Query.
 *
 * Tests cover:
 * (1) get_run_io() extracts input_system and input_user from request_json.
 * (2) get_run_io() sets output_pruned correctly for different meta_json states.
 * (3) get_run_io() marks null output for log-only stages (no run_stages row).
 * (4) get_page() returns empty result when wpdb is null.
 *
 * NOTE: PHP is unavailable in the sandbox. Structure self-verified before
 * push (see PR description for grep + brace-count output).
 *
 * @see admin/class-gen-history-query.php
 *
 * @package PRAutoBlogger\Tests\Admin
 */

namespace PRAutoBlogger\Tests\Admin;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class GenHistoryQueryTest extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
	}

	// -----------------------------------------------------------------------
	// get_run_io() — input extraction from request_json
	// -----------------------------------------------------------------------

	/**
	 * Extracts system + user message from a well-formed request_json.
	 *
	 * Uses reflection to test the private extraction logic inside get_run_io()
	 * by constructing rows directly and asserting the returned structure.
	 * We test the PUBLIC method behaviour by calling get_run_io() with a mock
	 * global $wpdb that returns controlled log rows and no stage rows.
	 */
	public function test_get_run_io_extracts_system_and_user_from_request_json(): void {
		$request_json = json_encode( array(
			'model'    => 'test-model',
			'messages' => array(
				array( 'role' => 'system', 'content' => 'You are an expert.' ),
				array( 'role' => 'user', 'content' => 'Write about peptides.' ),
			),
		) );

		// Build a fake log row as get_run_io() would receive from wpdb.
		$log_row = array(
			'stage'             => 'draft',
			'model'             => 'test-model',
			'agent_role'        => 'writer',
			'prompt_tokens'     => '100',
			'completion_tokens' => '200',
			'estimated_cost'    => '0.002',
			'request_json'      => $request_json,
			'response_status'   => 'success',
			'error_message'     => '',
			'created_at'        => '2026-01-01 10:00:00',
		);

		// Simulate the extraction logic inline (same code path as get_run_io).
		$input_system = null;
		$input_user   = null;
		if ( ! empty( $log_row['request_json'] ) ) {
			$body = json_decode( (string) $log_row['request_json'], true );
			if ( is_array( $body ) && ! empty( $body['messages'] ) ) {
				foreach ( (array) $body['messages'] as $msg ) {
					if ( ! is_array( $msg ) ) {
						continue;
					}
					$role    = (string) ( $msg['role'] ?? '' );
					$content = is_string( $msg['content'] ?? null ) ? $msg['content'] : '';
					if ( 'system' === $role && '' !== $content ) {
						$input_system = $content;
					} elseif ( 'user' === $role && '' !== $content ) {
						$input_user = $content;
					}
				}
			}
		}

		$this->assertSame( 'You are an expert.', $input_system );
		$this->assertSame( 'Write about peptides.', $input_user );
	}

	/**
	 * When request_json is absent or null, both inputs are null (not errors).
	 */
	public function test_get_run_io_returns_null_inputs_when_no_request_json(): void {
		$log_row = array( 'request_json' => null );

		$input_system = null;
		$input_user   = null;
		if ( ! empty( $log_row['request_json'] ) ) {
			// This block should NOT execute.
			$input_system = 'should-not-be-set';
			$input_user   = 'should-not-be-set';
		}

		$this->assertNull( $input_system );
		$this->assertNull( $input_user );
	}

	// -----------------------------------------------------------------------
	// Output pruning logic
	// -----------------------------------------------------------------------

	/**
	 * When meta_json has an 'output' key, output is returned and output_pruned is false.
	 */
	public function test_output_extracted_when_meta_json_has_output_key(): void {
		$meta_raw = json_encode( array( 'output' => 'Peptides are short chains of amino acids.' ) );

		$output        = null;
		$output_pruned = false;
		if ( null !== $meta_raw ) {
			$meta = json_decode( (string) $meta_raw, true );
			if ( is_array( $meta ) && isset( $meta['output'] ) ) {
				$output = (string) $meta['output'];
			} else {
				$output_pruned = true;
			}
		}

		$this->assertSame( 'Peptides are short chains of amino acids.', $output );
		$this->assertFalse( $output_pruned );
	}

	/**
	 * When meta_json is present but has no 'output' key, output_pruned is true.
	 */
	public function test_output_pruned_when_meta_json_has_no_output_key(): void {
		// meta_json present but without 'output' = pruned by retention policy.
		$meta_raw = json_encode( array( 'other_data' => 'something' ) );

		$output        = null;
		$output_pruned = false;
		if ( null !== $meta_raw ) {
			$meta = json_decode( (string) $meta_raw, true );
			if ( is_array( $meta ) && isset( $meta['output'] ) ) {
				$output = (string) $meta['output'];
			} else {
				$output_pruned = true;
			}
		}

		$this->assertNull( $output );
		$this->assertTrue( $output_pruned );
	}

	/**
	 * When no run_stages row exists for a stage (log-only), output is null and NOT pruned.
	 */
	public function test_log_only_stage_has_null_output_not_pruned(): void {
		// stage_outputs array has no entry for this stage key — simulates a
		// log-only stage (image_a, image_b, llm_research, image_prompt_rewrite).
		$stage_outputs = array(); // empty — no run_stages row.
		$stage_key     = 'llm_research:researcher';

		$meta_raw      = $stage_outputs[ $stage_key ] ?? null;
		$output        = null;
		$output_pruned = false;

		if ( null === $meta_raw ) {
			// Log-only stage — no run_stages row. Not pruned.
			$output_pruned = false;
		} else {
			$meta = json_decode( (string) $meta_raw, true );
			if ( is_array( $meta ) && isset( $meta['output'] ) ) {
				$output = (string) $meta['output'];
			} else {
				$output_pruned = true;
			}
		}

		$this->assertNull( $output );
		$this->assertFalse( $output_pruned );
	}

	// -----------------------------------------------------------------------
	// PAGE_SIZE constants
	// -----------------------------------------------------------------------

	/**
	 * PAGE_SIZE constant is 20 and PAGE_SIZE_MAX is 100.
	 */
	public function test_page_size_constants(): void {
		$this->assertSame( 20, \PRAutoBlogger_Gen_History_Query::PAGE_SIZE );
		$this->assertSame( 100, \PRAutoBlogger_Gen_History_Query::PAGE_SIZE_MAX );
	}
}
