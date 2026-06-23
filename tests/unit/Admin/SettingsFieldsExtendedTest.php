<?php
/**
 * Tests for PRAutoBlogger_Settings_Fields_Extended.
 *
 * Pins the cardinality contract on get_fields() so any future split that
 * accidentally re-introduces a + array union (dropping keys) fails CI
 * rather than silently at runtime.
 *
 * @package PRAutoBlogger\Tests\Admin
 */

namespace PRAutoBlogger\Tests\Admin;

use PRAutoBlogger\Tests\BaseTestCase;

class SettingsFieldsExtendedTest extends BaseTestCase {

	/**
	 * Stub WP functions that the settings-field classes invoke.
	 */
	protected function setUp(): void {
		parent::setUp();
		// Image_Model_Registry checks option cache; return false to use hardcoded list.
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( false );
		\Brain\Monkey\Functions\when( 'update_option' )->justReturn( true );
		// wp_timezone_string is referenced in schedule-time field description.
		\Brain\Monkey\Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
	}

	/**
	 * get_fields() must return exactly 25 fields (14 local + 11 image).
	 *
	 * M2: 4 fields removed (reasoning_enabled, reasoning_effort from AI Models;
	 * research_model, research_prompt from Sources) — now owned by Pipeline Settings.
	 * This pins the array_merge fix: if a + operator re-appears the image fields
	 * are silently dropped to 14 and this test fails CI.
	 * Count breakdown: 14 schedule/publishing/analytics/display fields
	 * from the local array + 11 image fields from Settings_Fields_Images.
	 */
	public function test_get_fields_returns_25_fields(): void {
		$fields = \PRAutoBlogger_Settings_Fields_Extended::get_fields();
		$this->assertCount(
			25,
			$fields,
			'Expected 25 fields (14 local + 11 image). M2 retired AI Models + Sources from Extended.'
		);
	}

	/**
	 * Every field must carry a non-empty id and a section string.
	 * This catches a malformed field entry that would fail register_setting().
	 */
	public function test_every_field_has_id_and_section(): void {
		$fields = \PRAutoBlogger_Settings_Fields_Extended::get_fields();
		foreach ( $fields as $field ) {
			$this->assertArrayHasKey( 'id', $field, 'Field missing id key' );
			$this->assertNotEmpty( $field['id'], 'Field has empty id' );
			$this->assertArrayHasKey( 'section', $field, sprintf( 'Field %s missing section key', $field['id'] ) );
		}
	}

	/**
	 * The image fields from Settings_Fields_Images must all be present.
	 *
	 * Checks that prautoblogger_image_enabled (first image field)
	 * exists in the merged result — if the + union regresses, this field
	 * would be missing because it is at index 0 in the image array (which
	 * collides with index 0 in the local array under +).
	 */
	public function test_image_fields_present_in_merged_result(): void {
		$fields = \PRAutoBlogger_Settings_Fields_Extended::get_fields();
		$ids    = array_column( $fields, 'id' );
		$this->assertContains(
			'prautoblogger_image_enabled',
			$ids,
			'Enable Image Generation toggle missing — array_merge may have regressed to +'
		);
	}
}
