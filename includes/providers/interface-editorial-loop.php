<?php
declare(strict_types=1);

/**
 * Contract for the Authority-tier editorial loop.
 *
 * Wraps the Chief_Editor review into a bounded iterative loop (≤
 * `editorial_max_rounds`) where the editor critiques and the writer
 * revises until the article is approved or rounds are exhausted.
 *
 * @see core/class-editorial-loop.php  — Primary implementation.
 * @see ARCHITECTURE.md                — Phase 2b editorial stage design.
 */
interface PRAutoBlogger_Editorial_Loop_Interface {

	/**
	 * Run the iterative editorial loop for one article.
	 *
	 * Each round: editor critiques → writer revises → if approved, stop.
	 * After max rounds without approval the article is escalated to the
	 * Review Queue (saved as draft) and an empty string is returned so
	 * the caller can detect the escalation.
	 *
	 * Side effects: multiple LLM API calls (reserved via cost governor),
	 * `run_stages` + `run_decisions` DB writes per round.
	 *
	 * @param string                     $run_id   Pipeline run UUID.
	 * @param string                     $item_key Article-scoped stage key.
	 * @param string                     $content  Initial draft HTML from the writer.
	 * @param PRAutoBlogger_Article_Idea $idea     The article idea under review.
	 * @param PRAutoBlogger_Cost_Tracker $cost_tracker Pipeline cost tracker.
	 * @return string Approved (or final-revised) HTML, or '' on escalation.
	 */
	public function run(
		string $run_id,
		string $item_key,
		string $content,
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Cost_Tracker $cost_tracker
	): string;

	/**
	 * Whether the last run() call resulted in an escalation to the Review Queue.
	 *
	 * Callers must check this after run() returns ''.
	 *
	 * @return bool
	 */
	public function was_escalated(): bool;
}
