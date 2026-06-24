<?php
/**
 * Tests for PRAutoBlogger_Tier_Router.
 *
 * Covers: master flag OFF always returns 'economy', unclassified category
 * defaults to authority, explicit 'economy' demotion, explicit 'authority'
 * entry treated as default authority.
 *
 * No LLM calls — the router is a pure option-read + conditional.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class TierRouterTest extends BaseTestCase {

	/** @var \PRAutoBlogger_Article_Idea Shared idea fixture. */
	private \PRAutoBlogger_Article_Idea $idea;

	protected function setUp(): void {
		parent::setUp();

		$this->idea = new \PRAutoBlogger_Article_Idea( array(
			'topic'           => 'BPC-157 overview',
			'article_type'    => 'guide',
			'suggested_title' => 'BPC-157 Guide',
			'summary'         => 'Comprehensive guide.',
			'score'           => 0.9,
		) );
	}

	// ── Master flag OFF ──────────────────────────────────────────────────

	/**
	 * When the master flag is OFF (default false), resolve() must always
	 * return 'economy' regardless of category map contents.
	 *
	 * This is the critical proof that P2b.4 deployment = zero behavior change.
	 */
	public function test_master_flag_off_always_returns_economy(): void {
		$this->stub_get_option( array(
			'prautoblogger_authority_pipeline_enabled' => false,
			'prautoblogger_category_tiers'             => array( 'guide' => 'authority' ),
		) );

		$router = new \PRAutoBlogger_Tier_Router();
		$this->assertSame( 'economy', $router->resolve( $this->idea ) );
	}

	/**
	 * String '0' is also a falsy master flag — must return 'economy'.
	 */
	public function test_master_flag_string_zero_returns_economy(): void {
		$this->stub_get_option( array(
			'prautoblogger_authority_pipeline_enabled' => '0',
		) );

		$router = new \PRAutoBlogger_Tier_Router();
		$this->assertSame( 'economy', $router->resolve( $this->idea ) );
	}

	// ── Master flag ON — category routing ────────────────────────────────

	/**
	 * When flag is ON and the idea's category is NOT in the tier map,
	 * the default Authority tier must apply.
	 */
	public function test_unclassified_category_defaults_authority(): void {
		$this->stub_get_option( array(
			'prautoblogger_authority_pipeline_enabled' => '1',
			'prautoblogger_category_tiers'             => array( 'news' => 'economy' ),
		) );

		$router = new \PRAutoBlogger_Tier_Router();
		// 'guide' is not in the tier map → default authority.
		$this->assertSame( 'authority', $router->resolve( $this->idea ) );
	}

	/**
	 * When flag is ON and the idea's category is explicitly set to 'economy'
	 * in the tier map, resolve() must return 'economy'.
	 */
	public function test_explicitly_demoted_category_returns_economy(): void {
		$this->stub_get_option( array(
			'prautoblogger_authority_pipeline_enabled' => '1',
			'prautoblogger_category_tiers'             => array( 'guide' => 'economy' ),
		) );

		$router = new \PRAutoBlogger_Tier_Router();
		$this->assertSame( 'economy', $router->resolve( $this->idea ) );
	}

	/**
	 * When flag is ON and the idea's category is explicitly set to 'authority'
	 * in the tier map (redundant but valid), resolve() must return 'authority'.
	 */
	public function test_authority_category_returns_authority(): void {
		$this->stub_get_option( array(
			'prautoblogger_authority_pipeline_enabled' => '1',
			'prautoblogger_category_tiers'             => array( 'guide' => 'authority' ),
		) );

		$router = new \PRAutoBlogger_Tier_Router();
		$this->assertSame( 'authority', $router->resolve( $this->idea ) );
	}

	/**
	 * When flag is ON and the tier map is empty, all categories default to 'authority'.
	 */
	public function test_empty_tier_map_defaults_authority(): void {
		$this->stub_get_option( array(
			'prautoblogger_authority_pipeline_enabled' => '1',
			'prautoblogger_category_tiers'             => array(),
		) );

		$router = new \PRAutoBlogger_Tier_Router();
		$this->assertSame( 'authority', $router->resolve( $this->idea ) );
	}
}
