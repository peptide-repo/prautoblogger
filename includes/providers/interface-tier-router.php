<?php
/**
 * Tier Router Interface
 *
 * Contract for resolving the generation tier (authority / economy) for an article idea.
 *
 * @package PRAutoBlogger
 * @since 0.31.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves Authority vs Economy generation tier for a given article idea.
 *
 * Implementations read the per-category tier map and the master feature flag.
 * When the master flag is OFF, resolve() MUST return 'economy' unconditionally
 * to guarantee zero behaviour change in production.
 *
 * @see core/class-tier-router.php — Production implementation.
 */
interface PRAutoBlogger_Tier_Router_Interface {

	/**
	 * Resolve the generation tier for an article idea.
	 *
	 * @param PRAutoBlogger_Article_Idea $idea The article idea to route.
	 * @return string 'authority' or 'economy'
	 */
	public function resolve( PRAutoBlogger_Article_Idea $idea ): string;
}
