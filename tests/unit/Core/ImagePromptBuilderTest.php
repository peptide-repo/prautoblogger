<?php
/**
 * Tests for PRAutoBlogger_Image_Prompt_Builder.
 *
 * Validates prompt generation from article content and source data,
 * including editorial-template substitution ({{ topic_summary }}) and HTML stripping.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class ImagePromptBuilderTest extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Mock WordPress functions.
		Functions\when( 'wp_strip_all_tags' )->alias( function ( $str ) {
			return trim( strip_tags( $str ) );
		} );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			static $options = [];
			return $options[ $key ] ?? $default;
		} );
	}

	/**
	 * Inject a mock OpenRouter provider into the Prompt_Builder via
	 * reflection so the rewrite path can be exercised without a real HTTP
	 * call. Used by the system-prompt override tests below.
	 */
	private function inject_llm(
		\PRAutoBlogger_Image_Prompt_Builder $builder,
		$mock_llm
	): void {
		$ref  = new \ReflectionClass( \PRAutoBlogger_Image_Prompt_Builder::class );
		$prop = $ref->getProperty( 'llm' );
		$prop->setAccessible( true );
		$prop->setValue( $builder, $mock_llm );
	}

	/**
	 * Test build_article_prompt() generates a prompt from article title.
	 *
	 * `build_article_prompt()` has returned `array{prompt: string, caption: string}`
	 * since v0.4.x. Destructure on receipt so the assertions run against the
	 * scene string, not the envelope.
	 *
	 * Note: this test does NOT assert on the article content substring. With no
	 * OpenRouter API key in the unit-test env, `rewrite_via_llm()` catches and
	 * falls back to `synthesize_visual_concepts_fallback()`, which reliably
	 * includes only the title — not the content. Content-based assertions
	 * belong in a test that mocks the LLM (see `test_rewrite_uses_setting_when_present`).
	 */
	public function test_build_article_prompt_from_content(): void {
		$builder = new \PRAutoBlogger_Image_Prompt_Builder();

		$article_data = [
			'post_title'   => 'How to Train Your Dragon',
			'post_content' => '<p>Dragons are fascinating creatures. Learn the secrets.</p>',
		];

		[ 'prompt' => $scene, 'caption' => $caption ] = $builder->build_article_prompt( $article_data );

		$this->assertStringContainsString( 'How to Train Your Dragon', $scene );
		// Editorial template wraps the topic summary; token must be substituted.
		$this->assertStringNotContainsString( '{{ topic_summary }}', $scene );
		$this->assertStringContainsString( 'Editorial scientific illustration', $scene );
		$this->assertNotEmpty( $scene );
		$this->assertGreaterThan( 50, strlen( $scene ) );
		$this->assertIsString( $caption );
	}

	/**
	 * Test build_article_prompt() handles missing content gracefully.
	 */
	public function test_build_article_prompt_with_missing_content(): void {
		$builder = new \PRAutoBlogger_Image_Prompt_Builder();

		$article_data = [
			'post_title' => 'Minimal Article',
		];

		[ 'prompt' => $scene, 'caption' => $caption ] = $builder->build_article_prompt( $article_data );

		$this->assertStringContainsString( 'Minimal Article', $scene );
		$this->assertNotEmpty( $scene );
		$this->assertIsString( $caption );
	}

	/**
	 * Test build_source_prompt() generates a prompt from Reddit data.
	 */
	public function test_build_source_prompt_from_reddit_data(): void {
		$builder = new \PRAutoBlogger_Image_Prompt_Builder();

		$source_data = [
			'title'    => 'Help: My Robot Won\'t Stop Dancing',
			'comments' => [
				'Have you tried unplugging it?',
				'Maybe it\'s just happy.',
			],
		];

		[ 'prompt' => $scene, 'caption' => $caption ] = $builder->build_source_prompt( $source_data );

		$this->assertStringContainsString( 'Robot', $scene );
		$this->assertStringContainsString( 'Dancing', $scene );
		$this->assertNotEmpty( $scene );
		$this->assertIsString( $caption );
	}

	/**
	 * Test build_source_prompt() handles missing comments.
	 */
	public function test_build_source_prompt_without_comments(): void {
		$builder = new \PRAutoBlogger_Image_Prompt_Builder();

		$source_data = [
			'title' => 'Standalone Title',
		];

		[ 'prompt' => $scene, 'caption' => $caption ] = $builder->build_source_prompt( $source_data );

		$this->assertStringContainsString( 'Standalone Title', $scene );
		$this->assertNotEmpty( $scene );
		$this->assertIsString( $caption );
	}

	/**
	 * Test that prompts don't exceed reasonable length.
	 *
	 * The 500-char cap applies to the scene + style suffix (the `prompt`
	 * field), not the full array envelope.
	 */
	public function test_prompts_are_reasonably_sized(): void {
		$builder = new \PRAutoBlogger_Image_Prompt_Builder();

		$long_content = '<p>' . str_repeat( 'a', 5000 ) . '</p>';
		$article_data = [
			'post_title'   => 'Test',
			'post_content' => $long_content,
		];

		[ 'prompt' => $scene ] = $builder->build_article_prompt( $article_data );

		// Scene + style suffix should be under 500 chars.
		$this->assertLessThan( 500, strlen( $scene ) );
	}

	/**
	 * When `prautoblogger_image_prompt_instructions` is non-empty, the
	 * rewriter LLM must receive that content as its system message —
	 * NOT the hardcoded REWRITER_SYSTEM_PROMPT constant.
	 */
	public function test_rewrite_uses_setting_when_present(): void {
		$custom_system = 'CUSTOM-OVERRIDE: tell the LLM a very different thing.';

		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $custom_system ) {
			if ( 'prautoblogger_image_prompt_instructions' === $key ) {
				return $custom_system;
			}
			if ( 'prautoblogger_image_style_suffix' === $key ) {
				return 'Style: x';
			}
			return $default;
		} );

		$captured_messages = [];
		$mock_llm          = $this->createMock( \PRAutoBlogger_OpenRouter_Provider::class );
		$mock_llm->method( 'send_chat_completion' )->willReturnCallback(
			function ( $messages ) use ( &$captured_messages ) {
				$captured_messages = $messages;
				return [
					'content'           => "A scene.\n\n\"A caption.\"",
					'prompt_tokens'     => 10,
					'completion_tokens' => 10,
				];
			}
		);
		$mock_llm->method( 'estimate_cost' )->willReturn( 0.0 );

		// Stub $wpdb so Cost_Tracker::log_api_call inside rewrite_via_llm
		// can insert without blowing up.
		$mock_wpdb            = $this->create_mock_wpdb();
		$mock_wpdb->insert_id = 1;
		$mock_wpdb->method( 'insert' )->willReturn( 1 );
		$GLOBALS['wpdb'] = $mock_wpdb;

		$builder = new \PRAutoBlogger_Image_Prompt_Builder();
		$this->inject_llm( $builder, $mock_llm );

		$builder->build_article_prompt( [ 'post_title' => 'Test', 'post_content' => '<p>Body.</p>' ] );

		unset( $GLOBALS['wpdb'] );

		$this->assertNotEmpty( $captured_messages, 'Rewriter LLM was not called.' );
		$this->assertSame( 'system', $captured_messages[0]['role'] ?? '' );
		$this->assertSame( $custom_system, $captured_messages[0]['content'] ?? '' );
	}

	/**
	 * When the option is empty (or whitespace-only), the rewriter must
	 * fall back to REWRITER_SYSTEM_PROMPT — belt-and-braces so a blank
	 * save from the admin UI cannot brick image generation.
	 */
	public function test_rewrite_falls_back_to_default_when_setting_empty(): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( 'prautoblogger_image_prompt_instructions' === $key ) {
				return '   '; // whitespace — should trigger fallback.
			}
			if ( 'prautoblogger_image_style_suffix' === $key ) {
				return 'Style: x';
			}
			return $default;
		} );

		$captured_messages = [];
		$mock_llm          = $this->createMock( \PRAutoBlogger_OpenRouter_Provider::class );
		$mock_llm->method( 'send_chat_completion' )->willReturnCallback(
			function ( $messages ) use ( &$captured_messages ) {
				$captured_messages = $messages;
				return [
					'content'           => "A scene.\n\n\"A caption.\"",
					'prompt_tokens'     => 10,
					'completion_tokens' => 10,
				];
			}
		);
		$mock_llm->method( 'estimate_cost' )->willReturn( 0.0 );

		// Stub $wpdb so Cost_Tracker::log_api_call inside rewrite_via_llm
		// can insert without blowing up.
		$mock_wpdb            = $this->create_mock_wpdb();
		$mock_wpdb->insert_id = 1;
		$mock_wpdb->method( 'insert' )->willReturn( 1 );
		$GLOBALS['wpdb'] = $mock_wpdb;

		$builder = new \PRAutoBlogger_Image_Prompt_Builder();
		$this->inject_llm( $builder, $mock_llm );

		$builder->build_article_prompt( [ 'post_title' => 'Test', 'post_content' => '<p>Body.</p>' ] );

		unset( $GLOBALS['wpdb'] );

		$this->assertSame(
			\PRAutoBlogger_Image_Prompt_Builder::REWRITER_SYSTEM_PROMPT,
			$captured_messages[0]['content'] ?? ''
		);
	}
}
