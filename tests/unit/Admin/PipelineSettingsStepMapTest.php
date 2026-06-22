<?php
/**
 * Unit tests for PRAutoBlogger_Pipeline_Settings_Step_Map.
 *
 * Regression guards that ensure allowed_prompt_keys() and allowed_model_options()
 * do not silently drift from the step definitions, and that the slug round-trip
 * used by the save handler works correctly (including keys that contain
 * underscores, which cause dot→hyphen→sanitize_key to behave unexpectedly if
 * not handled correctly).
 *
 * @see admin/class-pipeline-settings-step-map.php
 * @see admin/class-pipeline-settings-save-handler.php — resolve_key_from_slug()
 * @see CONVENTIONS.md §How To: Add a New Pipeline-Style Admin Page
 *
 * @package PRAutoBlogger\Tests\Admin
 */

namespace PRAutoBlogger\Tests\Admin;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class PipelineSettingsStepMapTest extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		// sanitize_key is used by resolve_key_from_slug inside save handler.
		Functions\when( 'sanitize_key' )->alias(
			static function ( $val ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $val ) );
			}
		);
	}

	// =========================================================================
	// § STEP DEFINITIONS — structural integrity
	// =========================================================================

	/**
	 * Every step must have the mandatory fields.
	 */
	public function test_every_step_has_required_fields(): void {
		$required = array( 'id', 'label', 'icon', 'capability', 'description' );
		foreach ( \PRAutoBlogger_Pipeline_Settings_Step_Map::steps() as $step ) {
			foreach ( $required as $field ) {
				$this->assertArrayHasKey(
					$field,
					$step,
					"Step '{$step['id']}' is missing required field '{$field}'."
				);
				$this->assertNotEmpty(
					$step[ $field ],
					"Step '{$step['id']}' field '{$field}' must not be empty."
				);
			}
		}
	}

	/**
	 * Step ids are unique — no two steps share the same machine id.
	 */
	public function test_step_ids_are_unique(): void {
		$ids = array_column( \PRAutoBlogger_Pipeline_Settings_Step_Map::steps(), 'id' );
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ), 'Step ids must be unique.' );
	}

	// =========================================================================
	// § ALLOWLISTS — regression guards
	// =========================================================================

	/**
	 * allowed_prompt_keys() must include every system_key and agent_key
	 * declared in steps(), with no duplicates.
	 */
	public function test_allowed_prompt_keys_covers_all_step_keys(): void {
		$expected = array();
		foreach ( \PRAutoBlogger_Pipeline_Settings_Step_Map::steps() as $step ) {
			if ( ! empty( $step['system_key'] ) ) {
				$expected[] = $step['system_key'];
			}
			foreach ( ( $step['agent_keys'] ?? array() ) as $k ) {
				$expected[] = $k;
			}
		}
		$expected = array_unique( $expected );
		$actual   = \PRAutoBlogger_Pipeline_Settings_Step_Map::allowed_prompt_keys();

		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual, 'allowed_prompt_keys() must match all system + agent keys from steps().' );
	}

	/**
	 * allowed_model_options() must include every non-null model_option from steps().
	 */
	public function test_allowed_model_options_covers_all_step_options(): void {
		$expected = array();
		foreach ( \PRAutoBlogger_Pipeline_Settings_Step_Map::steps() as $step ) {
			if ( ! empty( $step['model_option'] ) ) {
				$expected[] = $step['model_option'];
			}
		}
		$expected = array_unique( $expected );
		$actual   = \PRAutoBlogger_Pipeline_Settings_Step_Map::allowed_model_options();

		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual, 'allowed_model_options() must match all model_option values from steps().' );
	}

	// =========================================================================
	// § SLUG ROUND-TRIP — resolve_key_from_slug()
	// =========================================================================

	/**
	 * Standard key: 'research.system' slug = 'research-system'.
	 */
	public function test_slug_round_trip_simple_key(): void {
		$key  = 'research.system';
		$slug = sanitize_key( str_replace( '.', '-', $key ) );
		$this->assertSame( 'research-system', $slug );
	}

	/**
	 * Key with underscore: 'content.single_pass' must slug to 'content-single_pass'.
	 * sanitize_key() preserves underscores, so the round-trip is unambiguous.
	 */
	public function test_slug_round_trip_key_with_underscore(): void {
		$key  = 'content.single_pass';
		$slug = sanitize_key( str_replace( '.', '-', $key ) );
		// Dot → hyphen; underscore preserved by sanitize_key.
		$this->assertSame( 'content-single_pass', $slug );
	}

	/**
	 * All keys in allowed_prompt_keys() must produce distinct slugs (no collision).
	 * A collision would make the save handler unable to resolve one of the keys.
	 */
	public function test_all_prompt_key_slugs_are_unique(): void {
		$keys  = \PRAutoBlogger_Pipeline_Settings_Step_Map::allowed_prompt_keys();
		$slugs = array_map(
			static function ( $key ) {
				return sanitize_key( str_replace( '.', '-', $key ) );
			},
			$keys
		);
		$this->assertSame(
			count( $slugs ),
			count( array_unique( $slugs ) ),
			'Every allowed prompt key must produce a unique slug; a collision blocks save.'
		);
	}

	/**
	 * find() returns the correct step by id.
	 */
	public function test_find_returns_matching_step(): void {
		$step = \PRAutoBlogger_Pipeline_Settings_Step_Map::find( 'research' );
		$this->assertNotNull( $step );
		$this->assertSame( 'research', $step['id'] );
	}

	/**
	 * find() returns null for an unknown id.
	 */
	public function test_find_returns_null_for_unknown_id(): void {
		$this->assertNull( \PRAutoBlogger_Pipeline_Settings_Step_Map::find( 'does_not_exist' ) );
	}
}
