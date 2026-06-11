<?php
/**
 * Tests for PRAutoBlogger_Stage_Display_Map.
 *
 * Locks the stage vocabulary contract from the Pipeline v2 Phase 1 brief:
 * every historical prod stage value (incl. the image stages) and every
 * Pipeline v2 stage value must render, carry a default agent role, and —
 * where applicable — map to a prompt-registry key. Unknown values must
 * humanize, never render blank.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;

class StageDisplayMapTest extends BaseTestCase {

	/**
	 * Every historical stage value observed on prod (90d window, 2026-06-11)
	 * plus the code-only opik_eval_judge must be known to the map.
	 */
	public function test_all_historical_stages_are_known(): void {
		$historical = array(
			'analysis',
			'outline',
			'draft',
			'polish',
			'review',
			'llm_research',
			'image_a',
			'image_b',
			'image_prompt_rewrite',
			'opik_eval_judge',
		);
		foreach ( $historical as $stage ) {
			$this->assertTrue(
				\PRAutoBlogger_Stage_Display_Map::is_known( $stage ),
				"Historical stage '{$stage}' must be in the display map."
			);
			$this->assertNotSame( '', \PRAutoBlogger_Stage_Display_Map::label( $stage ) );
		}
	}

	/**
	 * The Pipeline v2 vocabulary must be known to the map.
	 */
	public function test_all_v2_stages_are_known(): void {
		foreach ( array( 'research', 'curate', 'draft', 'editorial', 'seo', 'publish' ) as $stage ) {
			$this->assertTrue( \PRAutoBlogger_Stage_Display_Map::is_known( $stage ) );
		}
	}

	/**
	 * Unknown stages humanize instead of rendering blank.
	 */
	public function test_unknown_stage_humanizes(): void {
		$this->assertSame(
			'Some Future Stage',
			\PRAutoBlogger_Stage_Display_Map::label( 'some_future_stage' )
		);
		$this->assertFalse( \PRAutoBlogger_Stage_Display_Map::is_known( 'some_future_stage' ) );
		$this->assertNull( \PRAutoBlogger_Stage_Display_Map::default_agent_role( 'some_future_stage' ) );
		$this->assertNull( \PRAutoBlogger_Stage_Display_Map::default_prompt_key( 'some_future_stage' ) );
	}

	/**
	 * Stage → default agent role mapping (stamped on generation_log rows
	 * when the call site does not pass a role).
	 */
	public function test_default_agent_roles(): void {
		$this->assertSame( 'analyst', \PRAutoBlogger_Stage_Display_Map::default_agent_role( 'analysis' ) );
		$this->assertSame( 'writer', \PRAutoBlogger_Stage_Display_Map::default_agent_role( 'draft' ) );
		$this->assertSame( 'writer', \PRAutoBlogger_Stage_Display_Map::default_agent_role( 'polish' ) );
		$this->assertSame( 'editor', \PRAutoBlogger_Stage_Display_Map::default_agent_role( 'review' ) );
		$this->assertSame( 'researcher', \PRAutoBlogger_Stage_Display_Map::default_agent_role( 'llm_research' ) );
		$this->assertSame( 'illustrator', \PRAutoBlogger_Stage_Display_Map::default_agent_role( 'image_a' ) );
		$this->assertSame( 'illustrator', \PRAutoBlogger_Stage_Display_Map::default_agent_role( 'image_prompt_rewrite' ) );
	}

	/**
	 * Stage → primary prompt-registry key (resolves the pinned
	 * prompt_version stamped on rows). The image keys are the composer-PR
	 * seam and must not change without coordinating on the thread.
	 */
	public function test_default_prompt_keys(): void {
		$this->assertSame( 'analysis.system', \PRAutoBlogger_Stage_Display_Map::default_prompt_key( 'analysis' ) );
		$this->assertSame( 'content.outline', \PRAutoBlogger_Stage_Display_Map::default_prompt_key( 'outline' ) );
		$this->assertSame( 'content.draft', \PRAutoBlogger_Stage_Display_Map::default_prompt_key( 'draft' ) );
		$this->assertSame( 'content.polish', \PRAutoBlogger_Stage_Display_Map::default_prompt_key( 'polish' ) );
		$this->assertSame( 'editor.system', \PRAutoBlogger_Stage_Display_Map::default_prompt_key( 'review' ) );
		$this->assertSame( 'research.system', \PRAutoBlogger_Stage_Display_Map::default_prompt_key( 'llm_research' ) );
		$this->assertSame( 'image.style_template', \PRAutoBlogger_Stage_Display_Map::default_prompt_key( 'image_a' ) );
		$this->assertSame( 'image.style_template', \PRAutoBlogger_Stage_Display_Map::default_prompt_key( 'image_b' ) );
		$this->assertSame( 'image.rewriter_system', \PRAutoBlogger_Stage_Display_Map::default_prompt_key( 'image_prompt_rewrite' ) );
	}
}
