<?php
/**
 * Unit tests for PRAutoBlogger_Pipeline_Settings_Option_Fields (and _Data).
 *
 * Locks the contracts for:
 * (1) Allowlist completeness — every context yields at least one field; no duplicates.
 * (2) sanitize_option() per type — textarea, select, number, toggle, checkboxes.
 * (3) select out-of-range falls back to default.
 * (4) number bounds clamping.
 * (5) checkboxes unknown values are filtered out.
 * (6) Field type assertions per context.
 *
 * Save-handler integration tests are in PipelineSettingsStepSaveTest.php.
 *
 * @see admin/class-pipeline-settings-option-fields.php
 * @see admin/class-pipeline-settings-option-fields-data.php
 *
 * @package PRAutoBlogger\Tests\Admin
 */

namespace PRAutoBlogger\Tests\Admin;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class PipelineSettingsOptionFieldsTest extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_key' )->alias(
			static function ( $val ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $val ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $val ) { return trim( (string) $val ); }
		);
		Functions\when( 'sanitize_textarea_field' )->alias(
			static function ( $val ) { return trim( strip_tags( (string) $val ) ); }
		);
		Functions\when( 'absint' )->alias(
			static function ( $val ) { return abs( (int) $val ); }
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	// =========================================================================
	// § CONTEXTS AND ALLOWLIST
	// =========================================================================

	public function test_every_context_yields_fields(): void {
		foreach ( \PRAutoBlogger_Pipeline_Settings_Option_Fields::contexts() as $ctx ) {
			$fields = \PRAutoBlogger_Pipeline_Settings_Option_Fields::get_fields_for_context( $ctx );
			$this->assertNotEmpty( $fields, "Context '$ctx' returned no fields." );
		}
	}

	public function test_unknown_context_returns_empty(): void {
		$fields = \PRAutoBlogger_Pipeline_Settings_Option_Fields::get_fields_for_context( 'nonexistent' );
		$this->assertSame( array(), $fields );
	}

	public function test_allowed_options_no_duplicates(): void {
		$opts   = \PRAutoBlogger_Pipeline_Settings_Option_Fields::allowed_options();
		$unique = array_unique( $opts );
		$this->assertCount( count( $unique ), $opts, 'Duplicate option names found.' );
	}

	public function test_allowlist_contains_all_expected_keys(): void {
		$opts = \PRAutoBlogger_Pipeline_Settings_Option_Fields::allowed_options();
		foreach ( array(
			'prautoblogger_niche_description',
			'prautoblogger_enabled_sources',
			'prautoblogger_research_prompt',
			'prautoblogger_analysis_instructions',
			'prautoblogger_topic_exclusions',
			'prautoblogger_writing_pipeline',
			'prautoblogger_tone',
			'prautoblogger_min_word_count',
			'prautoblogger_max_word_count',
			'prautoblogger_reasoning_enabled',
			'prautoblogger_reasoning_effort',
			'prautoblogger_editor_instructions',
		) as $key ) {
			$this->assertContains( $key, $opts, "Key '$key' missing from allowlist." );
		}
	}

	// =========================================================================
	// § SANITIZE — textarea
	// =========================================================================

	public function test_sanitize_textarea_strips_tags(): void {
		$field  = array( 'type' => 'textarea' );
		$result = \PRAutoBlogger_Pipeline_Settings_Option_Fields::sanitize_option( '<b>hello</b>', $field );
		$this->assertSame( 'hello', $result );
	}

	// =========================================================================
	// § SANITIZE — toggle
	// =========================================================================

	public function test_sanitize_toggle_one_returns_one(): void {
		$field  = array( 'type' => 'toggle' );
		$this->assertSame( '1', \PRAutoBlogger_Pipeline_Settings_Option_Fields::sanitize_option( '1', $field ) );
	}

	public function test_sanitize_toggle_non_one_returns_zero(): void {
		$field  = array( 'type' => 'toggle' );
		$this->assertSame( '0', \PRAutoBlogger_Pipeline_Settings_Option_Fields::sanitize_option( 'yes', $field ) );
	}

	// =========================================================================
	// § SANITIZE — number
	// =========================================================================

	public function test_sanitize_number_clamps_below_min(): void {
		$field  = array( 'type' => 'number', 'min' => 200 );
		$this->assertSame( 200, \PRAutoBlogger_Pipeline_Settings_Option_Fields::sanitize_option( 50, $field ) );
	}

	public function test_sanitize_number_clamps_above_max(): void {
		$field  = array( 'type' => 'number', 'min' => 1, 'max' => 72 );
		$this->assertSame( 72, \PRAutoBlogger_Pipeline_Settings_Option_Fields::sanitize_option( 100, $field ) );
	}

	public function test_sanitize_number_valid_value_unchanged(): void {
		$field  = array( 'type' => 'number', 'min' => 5, 'max' => 100 );
		$this->assertSame( 25, \PRAutoBlogger_Pipeline_Settings_Option_Fields::sanitize_option( 25, $field ) );
	}

	// =========================================================================
	// § SANITIZE — select
	// =========================================================================

	public function test_sanitize_select_valid_value_accepted(): void {
		$field = array(
			'type'    => 'select',
			'default' => 'day',
			'options' => array( 'day' => 'Day', 'week' => 'Week', 'month' => 'Month' ),
		);
		$this->assertSame( 'week', \PRAutoBlogger_Pipeline_Settings_Option_Fields::sanitize_option( 'week', $field ) );
	}

	public function test_sanitize_select_invalid_falls_back_to_default(): void {
		$field = array(
			'type'    => 'select',
			'default' => 'day',
			'options' => array( 'day' => 'Day', 'week' => 'Week' ),
		);
		$this->assertSame( 'day', \PRAutoBlogger_Pipeline_Settings_Option_Fields::sanitize_option( 'evil', $field ) );
	}

	// =========================================================================
	// § SANITIZE — checkboxes
	// =========================================================================

	public function test_sanitize_checkboxes_filters_unknown_values(): void {
		$field = array(
			'type'    => 'checkboxes',
			'choices' => array( 'reddit' => 'Reddit', 'llm_research' => 'LLM' ),
		);
		$result  = \PRAutoBlogger_Pipeline_Settings_Option_Fields::sanitize_option( array( 'reddit', 'evil' ), $field );
		$decoded = json_decode( $result, true );
		$this->assertSame( array( 'reddit' ), $decoded );
	}

	public function test_sanitize_checkboxes_non_array_returns_json_empty(): void {
		$field = array(
			'type'    => 'checkboxes',
			'choices' => array( 'reddit' => 'Reddit' ),
		);
		$result = \PRAutoBlogger_Pipeline_Settings_Option_Fields::sanitize_option( 'reddit', $field );
		$this->assertSame( '[]', $result );
	}

	// =========================================================================
	// § FIELD TYPE ASSERTIONS PER CONTEXT
	// =========================================================================

	public function test_writer_context_reasoning_enabled_is_toggle(): void {
		$fields = \PRAutoBlogger_Pipeline_Settings_Option_Fields::get_fields_for_context( 'writer' );
		foreach ( $fields as $f ) {
			if ( 'prautoblogger_reasoning_enabled' === $f['id'] ) {
				$this->assertSame( 'toggle', $f['type'] );
				return;
			}
		}
		$this->fail( 'prautoblogger_reasoning_enabled not found in writer context.' );
	}

	public function test_research_context_enabled_sources_is_checkboxes(): void {
		$fields = \PRAutoBlogger_Pipeline_Settings_Option_Fields::get_fields_for_context( 'research' );
		foreach ( $fields as $f ) {
			if ( 'prautoblogger_enabled_sources' === $f['id'] ) {
				$this->assertSame( 'checkboxes', $f['type'] );
				return;
			}
		}
		$this->fail( 'prautoblogger_enabled_sources not found in research context.' );
	}

	public function test_writer_context_tone_is_select_with_five_options(): void {
		$fields = \PRAutoBlogger_Pipeline_Settings_Option_Fields::get_fields_for_context( 'writer' );
		foreach ( $fields as $f ) {
			if ( 'prautoblogger_tone' === $f['id'] ) {
				$this->assertSame( 'select', $f['type'] );
				$this->assertCount( 5, $f['options'] );
				return;
			}
		}
		$this->fail( 'prautoblogger_tone not found in writer context.' );
	}
}
