<?php
/**
 * Tests for PRAutoBlogger_Content_Generator (v0.18.1 writer-path guard).
 *
 * Validates that a writing stage whose LLM output is empty/whitespace
 * throws instead of passing an empty article downstream, that healthy
 * output passes through, and that the stage metadata for the provider's
 * empty-completion guard rides the options array.
 *
 * execute_stage() is exercised via reflection: generate() defines the
 * PRAUTOBLOGGER_EVAL_MODE constant, which cannot be redefined across
 * tests in one PHP process.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class ContentGeneratorTest extends BaseTestCase {

    private \PRAutoBlogger_Content_Request $request;

    protected function setUp(): void {
        parent::setUp();

        \PRAutoBlogger_Prompt_Registry::flush_cache();

        $this->stub_get_option( [
            'prautoblogger_log_level' => 'error',
        ] );

        // Content_Prompts::build_system() lists recent posts for the
        // linking rules — none exist in the unit environment.
        Functions\when( 'get_posts' )->justReturn( [] );

        $idea = new \PRAutoBlogger_Article_Idea( $this->get_article_idea_fixture() );

        $this->request = new \PRAutoBlogger_Content_Request(
            $idea,
            'single_pass',
            'informational',
            800,
            2000,
            '',
            [],
            ''
        );
    }

    protected function tearDown(): void {
        \PRAutoBlogger_Prompt_Registry::flush_cache();
        parent::tearDown();
    }

    /**
     * Invoke the private execute_stage() on a generator wired to the
     * given LLM response.
     *
     * @param array $llm_response   Response the mock provider returns.
     * @param array|null $captured  Receives the options passed to the LLM.
     * @return string Stage output.
     */
    private function run_stage( array $llm_response, ?array &$captured = null ): string {
        $llm = $this->createMock( \PRAutoBlogger_LLM_Provider_Interface::class );
        $llm->method( 'send_chat_completion' )->willReturnCallback(
            function ( array $messages, string $model, array $options ) use ( $llm_response, &$captured ) {
                $captured = $options;
                return $llm_response;
            }
        );
        $llm->method( 'get_provider_name' )->willReturn( 'OpenRouter' );
        $llm->method( 'estimate_cost' )->willReturn( 0.001 );

        $cost_tracker = $this->createMock( \PRAutoBlogger_Cost_Tracker::class );

        $generator = new \PRAutoBlogger_Content_Generator( $llm, $cost_tracker );

        $method = new \ReflectionMethod( \PRAutoBlogger_Content_Generator::class, 'execute_stage' );
        $method->setAccessible( true );

        return $method->invoke(
            $generator,
            'draft',
            'draft_generation',
            'Write the article.',
            $this->request,
            'model/test',
            [
                'temperature' => 0.7,
                'max_tokens'  => 4000,
            ],
            'content.single_pass'
        );
    }

    /**
     * Healthy stage output passes through unchanged.
     */
    public function test_non_empty_stage_output_passes_through(): void {
        $output = $this->run_stage( [
            'content'           => '<p>A real article.</p>',
            'model'             => 'model/test',
            'prompt_tokens'     => 100,
            'completion_tokens' => 200,
        ] );

        $this->assertSame( '<p>A real article.</p>', $output );
    }

    /**
     * Empty stage output throws — no empty article may leave the writer.
     */
    public function test_empty_stage_output_throws(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'produced empty content' );

        $this->run_stage( [
            'content'           => '',
            'model'             => 'model/test',
            'prompt_tokens'     => 100,
            'completion_tokens' => 4000,
        ] );
    }

    /**
     * Whitespace-only output counts as empty (the guard trims).
     */
    public function test_whitespace_stage_output_throws(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'draft' );

        $this->run_stage( [
            'content'           => "  \n\t  ",
            'model'             => 'model/test',
            'prompt_tokens'     => 100,
            'completion_tokens' => 4000,
        ] );
    }

    /**
     * The stage + prompt_key metadata for the provider's empty-completion
     * guard rides the LLM options (v0.18.1).
     */
    public function test_stage_metadata_rides_the_llm_options(): void {
        $captured = null;

        $this->run_stage(
            [
                'content'           => 'ok',
                'model'             => 'model/test',
                'prompt_tokens'     => 1,
                'completion_tokens' => 1,
            ],
            $captured
        );

        $this->assertSame( 'draft', $captured['stage'] );
        $this->assertSame( 'content.single_pass', $captured['prompt_key'] );
        $this->assertSame( 4000, $captured['max_tokens'] );
    }
}
