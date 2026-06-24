<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Per-category generation tier router for the Authority pipeline.
 *
 * What: Resolves whether a given article idea should enter the Authority
 *       pipeline or fall through to the Economy single-pass. The master
 *       switch (`prautoblogger_authority_pipeline_enabled`) defaults FALSE,
 *       which guarantees byte-identical behaviour to today — no articles
 *       ever touch the Authority path unless an operator explicitly enables
 *       the flag. When the flag is ON, per-category overrides in
 *       `prautoblogger_category_tiers` demote specific categories to
 *       Economy; every other category (unclassified, new, missing) stays
 *       at the Authority default.
 *
 * Who triggers it: PRAutoBlogger_Article_Worker::generate() (P2b.4).
 * Dependencies: WordPress get_option(), PRAutoBlogger_Article_Idea.
 *
 * @see providers/interface-tier-router.php    — Interface this class implements.
 * @see core/class-article-worker.php          — Call site (3-line tier check).
 * @see core/class-authority-pipeline.php      — Authority path executed on 'authority'.
 * @see ARCHITECTURE.md                        — Phase 2b tier routing.
 */
class PRAutoBlogger_Tier_Router implements PRAutoBlogger_Tier_Router_Interface {

	/** Option key for the master Authority pipeline feature flag. */
	public const OPTION_MASTER_FLAG = 'prautoblogger_authority_pipeline_enabled';

	/** Option key for the per-category tier map (serialized array). */
	public const OPTION_CATEGORY_TIERS = 'prautoblogger_category_tiers';

	/** Default tier when a category has no explicit override: Authority. */
	private const DEFAULT_TIER = 'authority';

	/**
	 * Resolve the generation tier for an article idea.
	 *
	 * Returns 'economy' immediately when the master flag is OFF — this is
	 * the production default and must guarantee zero behaviour change
	 * relative to the pre-P2b.4 codebase.
	 *
	 * When the master flag is ON, looks up the idea's article_type in the
	 * per-category tier map. Values of 'economy' explicitly demote that
	 * category. Any other value, or no entry at all, keeps the Authority
	 * default.
	 *
	 * Side effects: two get_option() calls (cached by WordPress object cache).
	 *
	 * @param PRAutoBlogger_Article_Idea $idea The article idea to route.
	 * @return string 'authority' or 'economy'
	 */
	public function resolve( PRAutoBlogger_Article_Idea $idea ): string {
		if ( ! $this->is_master_flag_on() ) {
			return 'economy';
		}

		return $this->resolve_from_category( $idea->get_article_type() );
	}

	// ── Private helpers ──────────────────────────────────────────────────

	/**
	 * Whether the Authority pipeline master switch is enabled.
	 *
	 * The option defaults to false (disabled). Operators must explicitly
	 * set it to '1' or true via wp_options / WP admin to enable the path.
	 *
	 * @return bool
	 */
	private function is_master_flag_on(): bool {
		$flag = get_option( self::OPTION_MASTER_FLAG, false );
		return in_array( $flag, array( '1', true, 1 ), true );
	}

	/**
	 * Resolve tier from the per-category tier map.
	 *
	 * The map is a serialized PHP array (stored via WordPress options) of
	 * `['category_slug' => 'economy']`. Only 'economy' is a meaningful
	 * explicit value — anything else (including 'authority') is treated
	 * as "keep the default".
	 *
	 * Unrecognised or empty maps → default Authority tier (additive safety).
	 *
	 * @param string $article_type Article type / category slug from the idea.
	 * @return string 'authority' or 'economy'
	 */
	private function resolve_from_category( string $article_type ): string {
		$raw = get_option( self::OPTION_CATEGORY_TIERS, array() );
		$map = is_array( $raw ) ? $raw : array();

		$tier = $map[ $article_type ] ?? self::DEFAULT_TIER;

		// Only 'economy' is a valid explicit demote; any other value defaults.
		return 'economy' === $tier ? 'economy' : 'authority';
	}
}
