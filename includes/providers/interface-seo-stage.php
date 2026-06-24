<?php
declare(strict_types=1);

/**
 * Contract for the SEO stage of the Authority pipeline.
 *
 * Implementations write _prab_* post-meta per the ratified JSON-LD contract
 * (convo/prcore/decisions/2026-06-11-jsonld-contract-v1.md) and compute
 * citation_score from kept research sources.
 *
 * @see core/class-seo-stage.php — Production implementation.
 */
interface PRAutoBlogger_Seo_Stage_Interface {

	/**
	 * Execute the SEO stage for a published article.
	 *
	 * Writes _prab_* post-meta, computes + stores citation_score, records
	 * run_stages start→done and a run_decisions row.
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
	): float;
}
