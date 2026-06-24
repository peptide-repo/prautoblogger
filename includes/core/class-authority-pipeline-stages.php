<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Stage execution helpers for the Authority pipeline orchestrator.
 *
 * What: Extracted from PRAutoBlogger_Authority_Pipeline to keep the
 *       orchestrator under 300 lines. Contains per-stage call wrappers,
 *       the imagery gate, and the hold-article helper. No external I/O
 *       beyond what the individual stage classes already require.
 *
 * Who triggers it: PRAutoBlogger_Authority_Pipeline (P2b.4).
 * Dependencies: All P2b.1–P2b.3 stage classes + Content_Generator,
 *       Publisher, Image_Pipeline, Run_Stage_State, Audit_Writer.
 *
 * @see core/class-authority-pipeline.php — Orchestrator that calls these helpers.
 * @see ARCHITECTURE.md                   — Phase 2b data flow.
 */
class PRAutoBlogger_Authority_Pipeline_Stages {

	/**
	 * Run the research fan-out stage.
	 *
	 * @param string                       $run_id       Pipeline run UUID.
	 * @param string                       $item_key     Article-scoped item key.
	 * @param PRAutoBlogger_Article_Idea   $idea         The idea being researched.
	 * @param PRAutoBlogger_Cost_Tracker   $cost_tracker Pipeline cost tracker.
	 * @param PRAutoBlogger_Research_Fanout $fanout       Fan-out instance.
	 * @return array<int, array{sources: array, agent_role: string}> Per-agent results, or empty on quorum miss.
	 */
	public static function run_research(
		string $run_id,
		string $item_key,
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Cost_Tracker $cost_tracker,
		PRAutoBlogger_Research_Fanout $fanout
	): array {
		PRAutoBlogger_Pipeline_Status::broadcast( __( 'Authority: running specialist research fan-out…', 'prautoblogger' ) );
		PRAutoBlogger_Run_Stage_State::start( $run_id, 'research', 'researcher', $item_key );
		$results = $fanout->dispatch( $run_id, $item_key, $idea, $cost_tracker );
		if ( ! empty( $results ) ) {
			PRAutoBlogger_Run_Stage_State::done( $run_id, 'research', 'researcher', $item_key, (string) wp_json_encode( array( 'agent_count' => count( $results ) ) ) );
		} else {
			PRAutoBlogger_Run_Stage_State::fail( $run_id, 'research', 'researcher', $item_key );
		}
		return $results;
	}

	/**
	 * Run the curate (Research_Judge) stage.
	 *
	 * @param string                      $run_id       Pipeline run UUID.
	 * @param string                      $item_key     Article-scoped item key.
	 * @param array                       $fanout_results Results from research stage.
	 * @param PRAutoBlogger_Research_Judge $judge        Judge instance.
	 * @return array<int, array{url: string, title: string, quality_score?: float}> Kept sources.
	 */
	public static function run_curate(
		string $run_id,
		string $item_key,
		array $fanout_results,
		PRAutoBlogger_Research_Judge $judge
	): array {
		PRAutoBlogger_Pipeline_Status::broadcast( __( 'Authority: curating research sources…', 'prautoblogger' ) );
		return $judge->curate( $run_id, $item_key, $fanout_results );
	}

	/**
	 * Run the draft (Content_Generator) stage.
	 *
	 * @param string                                   $run_id       Pipeline run UUID.
	 * @param string                                   $item_key     Article-scoped item key.
	 * @param PRAutoBlogger_Article_Idea               $idea         The idea to draft.
	 * @param PRAutoBlogger_Cost_Tracker               $cost_tracker Pipeline cost tracker.
	 * @param PRAutoBlogger_Content_Generator|null     $generator    Optional override (tests).
	 * @return string Draft HTML content.
	 */
	public static function run_draft(
		string $run_id,
		string $item_key,
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Cost_Tracker $cost_tracker,
		?PRAutoBlogger_Content_Generator $generator = null
	): string {
		PRAutoBlogger_Pipeline_Status::broadcast( __( 'Authority: generating article draft…', 'prautoblogger' ) );
		if ( null === $generator ) {
			$llm       = new PRAutoBlogger_OpenRouter_Provider();
			$generator = new PRAutoBlogger_Content_Generator( $llm, $cost_tracker );
			$generator->set_run_item( $run_id, $item_key );
		}
		return $generator->generate( $idea );
	}

	/**
	 * Run the editorial loop stage.
	 *
	 * @param string                          $run_id       Pipeline run UUID.
	 * @param string                          $item_key     Article-scoped item key.
	 * @param string                          $content      Draft HTML.
	 * @param PRAutoBlogger_Article_Idea      $idea         The idea under review.
	 * @param PRAutoBlogger_Cost_Tracker      $cost_tracker Pipeline cost tracker.
	 * @param PRAutoBlogger_Editorial_Loop    $editorial    Editorial loop instance.
	 * @return array{content: string, escalated: bool} Result: content and escalation flag.
	 */
	public static function run_editorial(
		string $run_id,
		string $item_key,
		string $content,
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Cost_Tracker $cost_tracker,
		PRAutoBlogger_Editorial_Loop $editorial
	): array {
		PRAutoBlogger_Pipeline_Status::broadcast( __( 'Authority: running editorial loop…', 'prautoblogger' ) );
		$reviewed = $editorial->run( $run_id, $item_key, $content, $idea, $cost_tracker );
		return array(
			'content'   => '' === $reviewed ? $content : $reviewed,
			'escalated' => $editorial->was_escalated(),
		);
	}

	/**
	 * Run the SEO stage.
	 *
	 * @param string $run_id      Pipeline run UUID.
	 * @param string $item_key    Article-scoped item key.
	 * @param int    $post_id     Published/draft post ID.
	 * @param array  $kept_sources Kept research sources from curate stage.
	 * @param array  $peptide_ids  Related peptide post IDs.
	 * @return float The computed citation_score.
	 */
	public static function run_seo(
		string $run_id,
		string $item_key,
		int $post_id,
		array $kept_sources,
		array $peptide_ids
	): float {
		PRAutoBlogger_Pipeline_Status::broadcast( __( 'Authority: writing SEO meta…', 'prautoblogger' ) );
		$seo_stage = new PRAutoBlogger_Seo_Stage();
		return $seo_stage->run( $run_id, $item_key, $post_id, $kept_sources, $peptide_ids );
	}

	/**
	 * Hold an article as draft (citation gate, escalation, or cost ceiling).
	 *
	 * Creates the draft post and suppresses image generation on it. Writes
	 * a run_decisions row for the hold reason. Returns the decision verdict
	 * string suitable for the result 'status' key.
	 *
	 * @param string                     $run_id       Pipeline run UUID.
	 * @param string                     $item_key     Article-scoped item key.
	 * @param string                     $content      Draft HTML content.
	 * @param PRAutoBlogger_Article_Idea $idea         The article idea.
	 * @param string                     $hold_reason  Short human-readable hold reason.
	 * @param string                     $verdict      Decision verdict for audit row.
	 * @param string                     $run_id_for_publisher Run ID passed to Publisher.
	 * @return void
	 */
	public static function hold_as_draft(
		string $run_id,
		string $item_key,
		string $content,
		PRAutoBlogger_Article_Idea $idea,
		string $hold_reason,
		string $verdict,
		string $run_id_for_publisher
	): void {
		PRAutoBlogger_Logger::instance()->info(
			sprintf( 'Authority pipeline HOLD for "%s": %s', $idea->get_topic(), $hold_reason ),
			'authority-pipeline'
		);

		// Save as draft with a neutral editorial review stub.
		$publisher = new PRAutoBlogger_Publisher();
		$review    = new PRAutoBlogger_Editorial_Review( array(
			'verdict'         => 'rejected',
			'notes'           => $hold_reason,
			'revised_content' => null,
			'quality_score'   => 0.0,
			'seo_score'       => 0.0,
			'issues'          => array(),
		) );
		$post_id = $publisher->save_as_draft( $content, $idea, $review, $run_id_for_publisher, null );

		// Imagery gate: suppress image generation on held articles.
		if ( $post_id > 0 ) {
			update_post_meta( $post_id, '_prautoblogger_imagery_suppressed', '1' );
		}

		// Record the hold decision.
		PRAutoBlogger_Audit_Writer::record_decision( $run_id, 'publish-gate', $verdict, $hold_reason );
	}
}
