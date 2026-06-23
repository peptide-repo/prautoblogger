<?php
/**
 * Unit tests for PRAutoBlogger_Pipeline_Preview_Source.
 *
 * Tests cover pure static helper methods via reflection:
 * (1) extract_rendered_from_messages() role-preference logic.
 * (2) stage_for_key() mapping from key to stage slug.
 *
 * sample_render() and last_run() require WP DB integration so are
 * deferred to the functional smoke test suite on prod.
 *
 * NOTE: PHP is unavailable in the sandbox. Structure self-verified
 * prior to push (grep + brace-count proof in PR description).
 *
 * @see admin/class-pipeline-preview-source.php
 * @see core/class-stage-display-map.php
 *
 * @package PRAutoBlogger\Tests\Ajax
 */

namespace PRAutoBlogger\Tests\Ajax;

use PRAutoBlogger\Tests\BaseTestCase;

class PipelinePreviewSourceTest extends BaseTestCase {

	/**
	 * extract_rendered_from_messages prefers system role for *.system keys.
	 */
	public function test_extract_prefers_system_role_for_system_keys(): void {
		$messages = array(
			array( 'role' => 'system', 'content' => 'System prompt text here' ),
			array( 'role' => 'user', 'content' => 'User message here' ),
		);

		$method = new \ReflectionMethod( 'PRAutoBlogger_Pipeline_Preview_Source', 'extract_rendered_from_messages' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $messages, 'analysis.system' );
		$this->assertEquals( 'System prompt text here', $result );
	}

	/**
	 * extract_rendered_from_messages prefers user role for agent keys.
	 */
	public function test_extract_prefers_user_role_for_agent_keys(): void {
		$messages = array(
			array( 'role' => 'system', 'content' => 'System prompt text here' ),
			array( 'role' => 'user', 'content' => 'User message here' ),
		);

		$method = new \ReflectionMethod( 'PRAutoBlogger_Pipeline_Preview_Source', 'extract_rendered_from_messages' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $messages, 'analysis.user' );
		$this->assertEquals( 'User message here', $result );
	}

	/**
	 * extract_rendered_from_messages falls back to longest message when no role match.
	 */
	public function test_extract_fallback_longest_when_no_role_match(): void {
		$messages = array(
			array( 'role' => 'assistant', 'content' => 'Short' ),
			array( 'role' => 'assistant', 'content' => 'This is the longest message in the array by far' ),
		);

		$method = new \ReflectionMethod( 'PRAutoBlogger_Pipeline_Preview_Source', 'extract_rendered_from_messages' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $messages, 'content.draft' );
		$this->assertEquals( 'This is the longest message in the array by far', $result );
	}

	/**
	 * extract returns empty string when messages array is empty.
	 */
	public function test_extract_empty_messages_returns_empty(): void {
		$method = new \ReflectionMethod( 'PRAutoBlogger_Pipeline_Preview_Source', 'extract_rendered_from_messages' );
		$method->setAccessible( true );

		$result = $method->invoke( null, array(), 'analysis.system' );
		$this->assertSame( '', $result );
	}

	/**
	 * stage_for_key maps 'analysis.system' to 'analysis' stage.
	 */
	public function test_stage_for_key_maps_analysis_system(): void {
		$method = new \ReflectionMethod( 'PRAutoBlogger_Pipeline_Preview_Source', 'stage_for_key' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'analysis.system' );
		$this->assertEquals( 'analysis', $result );
	}

	/**
	 * stage_for_key maps 'research.system' to 'llm_research' stage.
	 *
	 * Stage_Display_Map::MAP lists 'llm_research' before the Phase 2b 'research'
	 * entry; both share prompt_key => 'research.system' so the first-match-wins
	 * foreach returns 'llm_research'. This is correct: current prod runs write
	 * 'llm_research' to prab_generation_log.stage (LLM_Research_Provider line 74).
	 */
	public function test_stage_for_key_maps_research_system(): void {
		$method = new \ReflectionMethod( 'PRAutoBlogger_Pipeline_Preview_Source', 'stage_for_key' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'research.system' );
		$this->assertEquals( 'llm_research', $result );
	}

	/**
	 * stage_for_key returns null for an unmapped key.
	 */
	public function test_stage_for_key_returns_null_for_unknown(): void {
		$method = new \ReflectionMethod( 'PRAutoBlogger_Pipeline_Preview_Source', 'stage_for_key' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'not.a.real.key' );
		$this->assertNull( $result );
	}
}
