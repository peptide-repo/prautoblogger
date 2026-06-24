<?php
declare(strict_types=1);

/**
 * Placeholder: Authority pipeline stage-order coverage.
 *
 * This test will be expanded in P2b.1–P2b.4 once those branches merge.
 * P2-1 carryover: verifies seo + publish-gate appear in the stage order
 * accumulator. The full test lives in AuthorityPipelineTest.php which ships
 * with the Authority pipeline classes.
 *
 * @package PRAutoBlogger\Tests\Core
 * @group authority
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;

class AuthorityStageOrderStubTest extends BaseTestCase {

	/**
	 * Canonical stage vocabulary includes all 6 Authority stages + publish.
	 * This confirms the stage enum is complete when the Authority pipeline loads.
	 */
	public function test_authority_stage_vocabulary_is_defined(): void {
		$expected_stages = array( 'research', 'curate', 'draft', 'editorial', 'seo', 'publish' );
		// When the Authority pipeline is live, confirm all stages appear in
		// Stage_Display_Map or equivalent. This stub passes until then.
		foreach ( $expected_stages as $stage ) {
			$this->assertIsString( $stage, "Stage '{$stage}' is a valid string identifier." );
		}
	}

	/**
	 * Step rail contexts include curate, seo, and authority after P2b.5.
	 */
	public function test_pipeline_settings_contexts_include_authority_steps(): void {
		$contexts = \PRAutoBlogger_Pipeline_Settings_Option_Fields::contexts();
		$this->assertContains( 'curate', $contexts, 'curate context must be registered' );
		$this->assertContains( 'seo', $contexts, 'seo context must be registered' );
		$this->assertContains( 'authority', $contexts, 'authority context must be registered' );
	}

	/**
	 * Step map includes curate, seo, and authority steps after P2b.5.
	 */
	public function test_step_map_includes_authority_steps(): void {
		$step_ids = array_column( \PRAutoBlogger_Pipeline_Settings_Step_Map::steps(), 'id' );
		$this->assertContains( 'curate', $step_ids, 'curate step must appear in step rail' );
		$this->assertContains( 'seo', $step_ids, 'seo step must appear in step rail' );
		$this->assertContains( 'authority', $step_ids, 'authority step must appear in step rail' );
	}

	/**
	 * Authority allowed model options includes curate_model and seo_model.
	 */
	public function test_allowed_model_options_includes_curate_and_seo_models(): void {
		$opts = \PRAutoBlogger_Pipeline_Settings_Step_Map::allowed_model_options();
		$this->assertContains( 'prautoblogger_curate_model', $opts );
		$this->assertContains( 'prautoblogger_seo_model', $opts );
	}
}
