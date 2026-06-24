<?php
/**
 * Authority pipeline stage vocabulary and admin integration tests.
 *
 * Tests 1–3 were originally stubs shipping before the Authority pipeline classes.
 * Since P2b.4 + P2b.5 are now on main, test 1 is upgraded to a real assertion:
 * Stage_Display_Map must return non-empty labels for every v2 Authority stage.
 * Tests 2–4 exercise live admin classes (step rail + model option allowlist).
 *
 * @package PRAutoBlogger\Tests\Core
 * @group authority
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;

class AuthorityStageOrderStubTest extends BaseTestCase {

	/**
	 * Stage_Display_Map must return non-empty, distinct labels for the six
	 * canonical Authority v2 stages: research, curate, draft, editorial, seo, publish.
	 *
	 * This replaces the tautological "$stage is a string" stub shipped in P2b.5.
	 * We verify the map has real entries (not the humanized fallback) for every stage
	 * so the audit/dossier UI never renders a blank or auto-generated label.
	 */
	public function test_authority_stage_vocabulary_is_defined(): void {
		$v2_stages = array( 'research', 'curate', 'draft', 'editorial', 'seo', 'publish' );

		// All 6 must be registered as known (avoids the humanize fallback).
		foreach ( $v2_stages as $stage ) {
			$this->assertTrue(
				\PRAutoBlogger_Stage_Display_Map::is_known( $stage ),
				"Stage '$stage' must be known to Stage_Display_Map (not falling through to humanizer)."
			);
		}

		// Each must have a non-empty label returned by the map.
		foreach ( $v2_stages as $stage ) {
			$label = \PRAutoBlogger_Stage_Display_Map::label( $stage );
			$this->assertNotSame( '', $label, "Stage '$stage' must produce a non-empty label." );
		}

		// Labels must be distinct (no two stages share the same display label).
		$labels = array_map(
			static fn( string $s ) => \PRAutoBlogger_Stage_Display_Map::label( $s ),
			$v2_stages
		);
		$this->assertCount(
			count( $v2_stages ),
			array_unique( $labels ),
			'Every Authority stage must have a unique display label.'
		);
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
