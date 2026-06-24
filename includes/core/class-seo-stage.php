<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Authority-tier SEO stage — writes _prab_* post-meta and computes citation_score.
 *
 * What: Deterministic (no LLM calls) meta-writer for the Authority pipeline.
 *       Writes every key from the ratified JSON-LD contract onto the published
 *       post so that peptide-repo-core can emit JSON-LD schema from them.
 *       Computes citation_score = average quality_score of kept sources (0.0–1.0).
 *       Records a run_decisions row for the 'seo' stage and run_stages start→done.
 *       The publish gate that acts on citation_score is P2b.4; this stage only
 *       computes and stores it. Not wired into the live Economy path until P2b.4.
 *
 * Who triggers it: PRAutoBlogger_Authority_Pipeline (P2b.4, NOT live yet).
 * Dependencies: PRAutoBlogger_Run_Stage_State, PRAutoBlogger_Audit_Writer,
 *       PRAutoBlogger_Logger, WordPress post-meta API (update_post_meta).
 *
 * @see providers/interface-seo-stage.php            — Interface this implements.
 * @see core/class-audit-writer.php                  — Decision audit row writer.
 * @see core/class-run-stage-state.php               — Stage lifecycle tracker.
 * @see convo/prcore/decisions/2026-06-11-jsonld-contract-v1.md — Ratified contract.
 * @see ARCHITECTURE.md                              — Phase 2b SEO stage.
 */
class PRAutoBlogger_Seo_Stage implements PRAutoBlogger_Seo_Stage_Interface {

	/** Default citation threshold when option is unset (intentionally uncalibrated). */
	public const DEFAULT_THRESHOLD = 0.0;

	/** Option key for the citation score threshold (calibrated after ~10 Authority runs). */
	public const OPTION_THRESHOLD = 'prautoblogger_citation_score_threshold';

	/**
	 * Execute the SEO stage for a published article.
	 *
	 * Writes _prab_* post-meta per the ratified JSON-LD contract, computes
	 * + stores citation_score, records run_stages start→done and a
	 * run_decisions row for the 'seo' stage.
	 *
	 * Side effects: update_post_meta() calls (7 keys max — 6 written here;
	 * `_prab_reviewed_by` is P2b.4/human-approval only), run_stages DB
	 * write (start + done), run_decisions DB insert, Logger::info() calls.
	 *
	 * @param string $run_id      Pipeline run UUID.
	 * @param string $item_key    Article-scoped stage key.
	 * @param int    $post_id     Published WordPress post ID.
	 * @param array<int, array{url: string, title: string, doi?: string, quality_score?: float}> $kept_sources Kept research sources from the curate stage.
	 * @param array<int, int> $peptide_ids Related peptide post IDs (may be empty).
	 * @return float The computed citation_score (0.0–1.0).
	 */
	public function run(
		string $run_id,
		string $item_key,
		int $post_id,
		array $kept_sources,
		array $peptide_ids
	): float {
		PRAutoBlogger_Run_Stage_State::start( $run_id, 'seo', 'seo', $item_key );

		$score     = $this->compute_citation_score( $kept_sources );
		$threshold = (float) get_option( self::OPTION_THRESHOLD, self::DEFAULT_THRESHOLD );

		PRAutoBlogger_Logger::instance()->info(
			sprintf(
				'SEO stage: post=%d citation_score=%.4f threshold=%.4f sources=%d',
				$post_id,
				$score,
				$threshold,
				count( $kept_sources )
			),
			'seo'
		);

		$this->write_post_meta( $post_id, $kept_sources, $peptide_ids, $score );

		$rationale = sprintf(
			'citation_score=%.4f threshold=%.4f sources=%d',
			$score,
			$threshold,
			count( $kept_sources )
		);

		PRAutoBlogger_Audit_Writer::record_decision(
			$run_id,
			'seo',
			'scored',
			$rationale,
			$score
		);

		$done_payload = wp_json_encode(
			array(
				'citation_score'   => $score,
				'meta_keys_written' => 6,
			)
		);

		PRAutoBlogger_Run_Stage_State::done( $run_id, 'seo', 'seo', $item_key, $done_payload, 0.0 );

		return $score;
	}

	// ── Private helpers ──────────────────────────────────────────────────

	/**
	 * Compute citation_score as the average quality_score of kept sources.
	 *
	 * Formula: sum(quality_score) / max(count, 1). Returns 0.0 when no
	 * sources are provided (empty kept_sources array).
	 *
	 * @param array<int, array{url: string, title: string, doi?: string, quality_score?: float}> $kept_sources Kept sources from the curate stage.
	 * @return float Score in range 0.0–1.0.
	 */
	private function compute_citation_score( array $kept_sources ): float {
		if ( empty( $kept_sources ) ) {
			return 0.0;
		}

		$total = 0.0;
		foreach ( $kept_sources as $source ) {
			$total += (float) ( $source['quality_score'] ?? 0.0 );
		}

		return $total / count( $kept_sources );
	}

	/**
	 * Write all _prab_* post-meta keys to the published post.
	 *
	 * Keys written per the ratified JSON-LD contract (v1):
	 *   _prab_schema_version   — int 1 (opt-in trigger for prcore JSON-LD).
	 *   _prab_citations        — JSON array of kept sources.
	 *   _prab_about_peptides   — JSON array of related peptide post IDs.
	 *   _prab_review_mode      — 'editorial-system' (automated stage).
	 *   _prab_reviewed_at      — ISO 8601 datetime.
	 *   _prab_citation_score   — float stored as string.
	 *
	 * Note: _prab_reviewed_by is NOT written by this stage (automated path).
	 * It is set in P2b.4 when a human approves via the Review Queue.
	 *
	 * @param int $post_id Published WordPress post ID.
	 * @param array<int, array{url: string, title: string, doi?: string, quality_score?: float}> $kept_sources Kept sources.
	 * @param array<int, int> $peptide_ids Related peptide post IDs.
	 * @param float $score Computed citation_score.
	 * @return void
	 */
	private function write_post_meta( int $post_id, array $kept_sources, array $peptide_ids, float $score ): void {
		$citations = array_map(
			static function ( array $source ): array {
				$entry = array(
					'url'   => (string) ( $source['url'] ?? '' ),
					'title' => (string) ( $source['title'] ?? '' ),
				);
				if ( ! empty( $source['doi'] ) ) {
					$entry['doi'] = (string) $source['doi'];
				}
				if ( isset( $source['quality_score'] ) ) {
					$entry['quality_score'] = (float) $source['quality_score'];
				}
				return $entry;
			},
			$kept_sources
		);

		update_post_meta( $post_id, '_prab_schema_version', 1 );
		update_post_meta( $post_id, '_prab_citations', wp_json_encode( $citations ) );
		update_post_meta( $post_id, '_prab_about_peptides', wp_json_encode( array_values( $peptide_ids ) ) );
		update_post_meta( $post_id, '_prab_review_mode', 'editorial-system' );
		update_post_meta( $post_id, '_prab_reviewed_at', gmdate( 'Y-m-d\TH:i:s' ) );
		update_post_meta( $post_id, '_prab_citation_score', (string) $score );
	}
}
