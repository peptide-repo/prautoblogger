<?php
/**
 * Authority Pipeline Interface
 *
 * Contract for the full Authority-tier article generation pipeline.
 *
 * @package PRAutoBlogger
 * @since 0.31.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Full Authority-tier article generation pipeline.
 *
 * Implementations must orchestrate: research → curate → draft →
 * editorial → seo → publish gate, with cost-governor reserve/settle
 * on every stage and cost-ceiling halt → HOLD semantics.
 *
 * @see core/class-authority-pipeline.php — Production implementation.
 */
interface PRAutoBlogger_Authority_Pipeline_Interface {

	/**
	 * Run the full Authority-tier pipeline for one article.
	 *
	 * @param string                     $run_id       Unique run identifier.
	 * @param PRAutoBlogger_Article_Idea $idea         The article idea to process.
	 * @param PRAutoBlogger_Cost_Tracker $cost_tracker Active cost tracker for this run.
	 * @return array{generated: int, published: int, rejected: int, cost: float, status: string} Pipeline result.
	 */
	public function run(
		string $run_id,
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Cost_Tracker $cost_tracker
	): array;
}
