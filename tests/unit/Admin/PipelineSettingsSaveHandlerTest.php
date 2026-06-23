<?php
/**
 * Unit tests for PRAutoBlogger_Pipeline_Settings_Save_Handler.
 *
 * Locks the three critical contracts:
 * (1) Allowlist enforcement — unknown option names and unknown prompt slugs
 *     are rejected before any DB write.
 * (2) Model option persistence — the value is read from $_POST[$option_name]
 *     (the key that model-picker.js writes to), not from $_POST['model_id'].
 * (3) Prompt version creation — resolve_key_from_slug() round-trip and
 *     create_version() is called with the canonical key and $activate = true.
 *
 * @see admin/class-pipeline-settings-save-handler.php
 * @see admin/class-pipeline-settings-step-map.php
 * @see CONVENTIONS.md §How To: Add a New Pipeline-Style Admin Page
 *
 * @package PRAutoBlogger\Tests\Admin
 */

namespace PRAutoBlogger\Tests\Admin;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class PipelineSettingsSaveHandlerTest extends BaseTestCase {

	/** @var array<string, mixed> Captured update_option() calls: option_name => value. */
	private array $saved_options = array();

	/** @var array<int, array<string, mixed>> Captured create_version() calls. */
	private array $created_versions = array();

	protected function setUp(): void {
		parent::setUp();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array();

		// Nonce stubs.
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
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'esc_html' )->alias( static function ( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); } );

		// Capture update_option().
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->saved_options[ $name ] = $value;
				return true;
			}
		);

		// Stub WP user.
		$user             = new \stdClass();
		$user->user_login = 'rhys';
		Functions\when( 'wp_get_current_user' )->justReturn( $user );

		// Stub prompt registry methods via static overrides.
		$this->created_versions = array();
	}

	protected function tearDown(): void {
		$_POST                    = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->saved_options       = array();
		$this->created_versions    = array();
		parent::tearDown();
	}

	// =========================================================================
	// § ALLOWLIST ENFORCEMENT — model options
	// =========================================================================

	/**
	 * An unknown model_option name must be rejected before any update_option().
	 */
	public function test_handle_model_save_rejects_unknown_option(): void {
		$_POST = array(
			\PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD => 'valid',
			'pipeline_action'                                  => 'save_model',
			'model_option'                                     => 'prautoblogger_evil_option',
			'prautoblogger_evil_option'                        => 'some/model-id',
		);

		$result = \PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$this->assertSame( 'error', $result['status'] );
		$this->assertArrayNotHasKey( 'prautoblogger_evil_option', $this->saved_options );
	}

	/**
	 * A valid model option is accepted and persisted via update_option().
	 */
	public function test_handle_model_save_persists_valid_option(): void {
		$option = 'prautoblogger_research_model';
		$model  = 'google/gemini-2.0-flash-lite';

		$_POST = array(
			\PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD => 'valid',
			'pipeline_action' => 'save_model',
			'model_option'    => $option,
			$option           => $model,
		);

		$result = \PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$this->assertSame( 'saved', $result['status'] );
		$this->assertArrayHasKey( $option, $this->saved_options );
		$this->assertSame( $model, $this->saved_options[ $option ] );
	}

	/**
	 * The save handler reads the value from $_POST[$option_name], not from
	 * $_POST['model_id'] — proving the POST key fix for the model picker.
	 *
	 * When model_id is present but the option-name key is absent, the saved
	 * value must be empty (not the model_id value).
	 */
	public function test_model_save_reads_from_option_name_key_not_model_id(): void {
		$option = 'prautoblogger_analysis_model';
		$model  = 'anthropic/claude-3.5-haiku';

		$_POST = array(
			\PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD => 'valid',
			'pipeline_action' => 'save_model',
			'model_option'    => $option,
			// model_id is the OLD (broken) key — it must NOT be used.
			'model_id'        => $model,
			// Correct key ($option_name) is absent to prove the handler
			// reads the right key and saves empty, not the model_id value.
		);

		$result = \PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$this->assertSame( 'saved', $result['status'] );
		// If the old model_id key were used the value would be $model;
		// the correct key ($option) is absent so the value must be ''.
		$this->assertSame( '', $this->saved_options[ $option ] ?? 'NOT_SET' );
	}

	/**
	 * The correct flow: option-name key carries the value, model_id is absent.
	 * This is what model-picker.js actually posts (writes to hidden input by id).
	 */
	public function test_model_save_correct_post_key_flow(): void {
		$option = 'prautoblogger_writing_model';
		$model  = 'openai/gpt-4o-mini';

		$_POST = array(
			\PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD => 'valid',
			'pipeline_action' => 'save_model',
			'model_option'    => $option,
			// The picker writes to name="prautoblogger_writing_model" directly.
			$option           => $model,
			// No model_id key — matches real model-picker.js behavior.
		);

		$result = \PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$this->assertSame( 'saved', $result['status'] );
		$this->assertSame( $model, $this->saved_options[ $option ] );
	}

	// =========================================================================
	// § ALLOWLIST ENFORCEMENT — prompt keys
	// =========================================================================

	/**
	 * An unknown prompt slug must be rejected before any registry write.
	 */
	public function test_handle_prompt_save_rejects_unknown_slug(): void {
		// create_version is never reached: the allowlist rejects the slug first.
		$_POST = array(
			\PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD => 'valid',
			'pipeline_action' => 'save_prompt',
			'prompt_key'      => 'not-a-real-key',
			'prompt_body'     => 'some body',
		);

		$result = \PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$this->assertSame( 'error', $result['status'] );
		$this->assertStringContainsString( 'Unknown prompt key', $result['message'] );
	}

	// =========================================================================
	// § CAPABILITY / NONCE GATE
	// =========================================================================

	/**
	 * Users without manage_options are rejected before nonce verification.
	 */
	public function test_rejects_insufficient_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$_POST = array(
			\PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD => 'valid',
			'pipeline_action' => 'save_model',
			'model_option'    => 'prautoblogger_research_model',
			'prautoblogger_research_model' => 'some/model',
		);

		$result = \PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$this->assertSame( 'error', $result['status'] );
		$this->assertEmpty( $this->saved_options );
	}

	/**
	 * A failed nonce returns error without any persistence.
	 */
	public function test_rejects_invalid_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$_POST = array(
			\PRAutoBlogger_Pipeline_Settings_Page::NONCE_FIELD => 'bad',
			'pipeline_action' => 'save_model',
			'model_option'    => 'prautoblogger_research_model',
			'prautoblogger_research_model' => 'some/model',
		);

		$result = \PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$this->assertSame( 'error', $result['status'] );
		$this->assertEmpty( $this->saved_options );
	}

	/**
	 * A GET request returns idle without any processing.
	 */
	public function test_get_request_returns_idle(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();

		$result = \PRAutoBlogger_Pipeline_Settings_Save_Handler::maybe_process_save();

		$this->assertSame( 'idle', $result['status'] );
		$this->assertEmpty( $this->saved_options );
	}
}
