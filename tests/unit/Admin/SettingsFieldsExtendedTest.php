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
	 * get_fields() must return exactly 29 fields (18 local + 11 image).
	 *
	 * This pins the array_merge fix: if a + operator re-appears the image
	 * fields are silently dropped to 18 and this test fails CI.
	 * Count breakdown: 18 schedule/publishing/analytics/display/sources fields
	 * from the local array + 11 image fields from Settings_Fields_Images.
	 */
	public function test_get_fields_returns_29_fields(): void {
		$fields = \PRAutoBlogger_Settings_Fields_Extended::get_fields();
		$this->assertCount(
			29,
			$fields,
			'Expected 29 fields (18 local + 11 image). A + union instead of array_merge would return only 18.'
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
