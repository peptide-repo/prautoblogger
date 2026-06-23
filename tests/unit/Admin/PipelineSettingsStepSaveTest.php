<?php
/**
 * Integration tests for save_step_settings action in the Pipeline Settings save handler.
 *
 * Covers:
 * (1) Unknown step_context is rejected before any update_option().
 * (2) Valid contexts (global, analysis, editorial, writer) persist the right options.
 * (3) Failed nonce prevents saves.
 * (4) Insufficient capability prevents saves.
 * (5) Missing nonce field returns idle (not error).
 *
 * @see admin/class-pipeline-settings-save-handler.php
 * @see admin/class-pipeline-settings-option-fields.php
 *
 * @package PRAutoBlogger\Tests\Admin
 */

namespace PRAutoBlogger\Tests\Admin;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class PipelineSettingsStepSaveTest extends BaseTestCase {

	/** @var array<string, mixed> Captured update_option() calls. */
	private array $saved_options = array();

	protected function setUp(): void {
		parent::setUp();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array();

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
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
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'esc_html' )->alias(
			static function ( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->saved_options[ $name ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->saved_options       = array();
		parent::tearDown();
	}

	// =========================================================================
	// § CONTEXT VALIDATION
	// =========================================================================

	public function test_unknown_context_returns_error_and_no_saves(): void {
		$_POST = array(
			\PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD => 'valid',
			'pipeline_action' => 'save_step_settings',
			'step_context'    => 'bad_context',
		);

		$result = \PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$this->assertSame( 'error', $result['status'] );
		$this->assertEmpty( $this->saved_options );
	}

	// =========================================================================
	// § GLOBAL CONTEXT
	// =========================================================================

	public function test_global_context_saves_niche_description(): void {
		$_POST = array(
			\PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD => 'valid',
			'pipeline_action'                 => 'save_step_settings',
			'step_context'                    => 'global',
			'prautoblogger_niche_description' => 'Peptides and biohacking',
		);

		$result = \PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$this->assertSame( 'saved', $result['status'] );
		$this->assertSame( 'Peptides and biohacking', $this->saved_options['prautoblogger_niche_description'] );
	}

	// =========================================================================
	// § ANALYSIS CONTEXT
	// =========================================================================

	public function test_analysis_context_saves_both_fields(): void {
		$_POST = array(
			\PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD => 'valid',
			'pipeline_action'                     => 'save_step_settings',
			'step_context'                        => 'analysis',
			'prautoblogger_analysis_instructions' => 'Focus on safety topics.',
			'prautoblogger_topic_exclusions'      => 'politics, religion',
		);

		$result = \PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$this->assertSame( 'saved', $result['status'] );
		$this->assertArrayHasKey( 'prautoblogger_analysis_instructions', $this->saved_options );
		$this->assertArrayHasKey( 'prautoblogger_topic_exclusions', $this->saved_options );
	}

	// =========================================================================
	// § EDITORIAL CONTEXT
	// =========================================================================

	public function test_editorial_context_saves_editor_instructions(): void {
		$_POST = array(
			\PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD => 'valid',
			'pipeline_action'                   => 'save_step_settings',
			'step_context'                      => 'editorial',
			'prautoblogger_editor_instructions' => 'Be strict about disclaimers.',
		);

		$result = \PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$this->assertSame( 'saved', $result['status'] );
		$this->assertSame( 'Be strict about disclaimers.', $this->saved_options['prautoblogger_editor_instructions'] );
	}

	// =========================================================================
	// § WRITER CONTEXT
	// =========================================================================

	public function test_writer_context_saves_tone_and_pipeline(): void {
		$_POST = array(
			\PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD => 'valid',
			'pipeline_action'                  => 'save_step_settings',
			'step_context'                     => 'writer',
			'prautoblogger_tone'               => 'authoritative',
			'prautoblogger_writing_pipeline'   => 'single_pass',
			'prautoblogger_min_word_count'     => '600',
			'prautoblogger_max_word_count'     => '1800',
			'prautoblogger_reasoning_enabled'  => '0',
			'prautoblogger_reasoning_effort'   => 'medium',
		);

		$result = \PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$this->assertSame( 'saved', $result['status'] );
		$this->assertSame( 'authoritative', $this->saved_options['prautoblogger_tone'] );
		$this->assertSame( 'single_pass', $this->saved_options['prautoblogger_writing_pipeline'] );
	}

	public function test_writer_context_number_fields_are_integers(): void {
		$_POST = array(
			\PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD => 'valid',
			'pipeline_action'                => 'save_step_settings',
			'step_context'                   => 'writer',
			'prautoblogger_tone'             => 'informational',
			'prautoblogger_writing_pipeline' => 'multi_step',
			'prautoblogger_min_word_count'   => '1000',
			'prautoblogger_max_word_count'   => '2500',
			'prautoblogger_reasoning_enabled' => '0',
			'prautoblogger_reasoning_effort'  => 'medium',
		);

		$result = \PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$this->assertSame( 'saved', $result['status'] );
		$this->assertIsInt( $this->saved_options['prautoblogger_min_word_count'] );
		$this->assertIsInt( $this->saved_options['prautoblogger_max_word_count'] );
	}

	// =========================================================================
	// § SECURITY GATES
	// =========================================================================

	public function test_invalid_nonce_prevents_save(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$_POST = array(
			\PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD => 'bad',
			'pipeline_action'                 => 'save_step_settings',
			'step_context'                    => 'global',
			'prautoblogger_niche_description' => 'Should not save.',
		);

		$result = \PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$this->assertSame( 'error', $result['status'] );
		$this->assertEmpty( $this->saved_options );
	}

	public function test_insufficient_capability_prevents_save(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$_POST = array(
			\PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD => 'valid',
			'pipeline_action' => 'save_step_settings',
			'step_context'    => 'global',
		);

		$result = \PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$this->assertSame( 'error', $result['status'] );
		$this->assertEmpty( $this->saved_options );
	}

	public function test_missing_nonce_field_returns_idle(): void {
		$_POST = array(
			'pipeline_action' => 'save_step_settings',
			'step_context'    => 'global',
		);

		$result = \PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$this->assertSame( 'idle', $result['status'] );
		$this->assertEmpty( $this->saved_options );
	}
}

	// =========================================================================
	// § RESEARCH CONTEXT
	// =========================================================================

	/**
	 * The research context is the only step context containing a `checkboxes`-type
	 * field (prautoblogger_enabled_sources). Its save path is distinct: the POST
	 * value is an array → each item sanitize_key'd → filtered against the choices
	 * allowlist → JSON-encoded string persisted via update_option().
	 *
	 * This test exercises the full integration path for that field and asserts
	 * that an unknown source key is stripped and only allowlisted keys survive.
	 */
	public function test_research_context_saves_source_settings(): void {
		$_POST = array(
			\PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD => 'valid',
			'pipeline_action'                          => 'save_step_settings',
			'step_context'                             => 'research',
			'prautoblogger_enabled_sources'            => array( 'reddit' ),
			'prautoblogger_target_subreddits'          => 'peptides, Nootropics',
			'prautoblogger_reddit_time_filter'         => 'week',
			'prautoblogger_reddit_posts_per_subreddit' => '20',
			'prautoblogger_pullpush_cache_ttl'         => '12',
			'prautoblogger_research_prompt'            => 'Find trending topics in {niche}.',
		);

		$result = \PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$this->assertSame( 'saved', $result['status'] );
		$decoded = json_decode( $this->saved_options['prautoblogger_enabled_sources'], true );
		$this->assertSame( array( 'reddit' ), $decoded );
		$this->assertSame( 'week', $this->saved_options['prautoblogger_reddit_time_filter'] );
		$this->assertIsInt( $this->saved_options['prautoblogger_reddit_posts_per_subreddit'] );
	}

	/**
	 * Unknown source keys supplied in the checkboxes array must be stripped —
	 * only values present in the field's `choices` allowlist survive.
	 */
	public function test_research_context_checkboxes_filters_unknown_sources(): void {
		$_POST = array(
			\PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD => 'valid',
			'pipeline_action'               => 'save_step_settings',
			'step_context'                  => 'research',
			'prautoblogger_enabled_sources' => array( 'reddit', 'unknown_source', 'llm_research' ),
			'prautoblogger_target_subreddits'          => '',
			'prautoblogger_reddit_time_filter'         => 'day',
			'prautoblogger_reddit_posts_per_subreddit' => '25',
			'prautoblogger_pullpush_cache_ttl'         => '6',
			'prautoblogger_research_prompt'            => '',
		);

		$result = \PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$this->assertSame( 'saved', $result['status'] );
		$decoded = json_decode( $this->saved_options['prautoblogger_enabled_sources'], true );
		$this->assertContains( 'reddit', $decoded );
		$this->assertContains( 'llm_research', $decoded );
		$this->assertNotContains( 'unknown_source', $decoded );
	}
}
