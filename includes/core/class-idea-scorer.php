<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Ranks and deduplicates article ideas from analysis results.
 *
 * Uses PRAutoBlogger_Semantic_Dedup for similarity detection — embedding-based
 * cosine similarity with automatic fallback to keyword overlap if the embedding
 * API is unavailable.
 *
 * Triggered by: PRAutoBlogger_Pipeline_Runner (step 3).
 * Dependencies: Semantic_Dedup, Keyword_Extractor, WP_Query.
 *
 * @see core/class-semantic-dedup.php      — Embedding + keyword dedup engine.
 * @see core/class-keyword-extractor.php  — Keyword tokeniser (fallback dedup).
 * @see core/class-content-analyzer.php  — Produces the analysis results we consume.
 * @see core/class-content-generator.php — Consumes the ranked ideas we produce.
 * @see ARCHITECTURE.md                  — Data flow step 3.
 */
class PRAutoBlogger_Idea_Scorer {

	/** @var bool Whether to skip deduplication entirely. */
	private bool $skip_dedup = false;

	/**
	 * @param bool $skip True to skip deduplication.
	 * @return $this
	 */
	public function set_skip_dedup( bool $skip ): self {
		$this->skip_dedup = $skip;
		return $this;
	}

	/**
	 * Score, deduplicate, and rank article ideas.
	 *
	 * Initializes the semantic dedup engine once, then checks each candidate
	 * against (1) intra-batch duplicates and (2) recently published posts.
	 *
	 * Side effects: embedding API calls (via Semantic_Dedup), WP_Query.
	 *
	 * @param PRAutoBlogger_Analysis_Result[] $analysis_results Raw analysis output.
	 * @param int                             $target_count     Max ideas to return.
	 *
	 * @return PRAutoBlogger_Article_Idea[] Top-ranked ideas, sorted by score desc.
	 */
	public function score_and_rank( array $analysis_results, int $target_count ): array {
		$exclusions     = json_decode( get_option( 'prautoblogger_topic_exclusions', '[]' ), true ) ?? array();
		$ideas          = array();
		$excluded_count = 0;
		$deduped_count  = 0;

		$dedup = new PRAutoBlogger_Semantic_Dedup();
		if ( ! $this->skip_dedup ) {
			$dedup->initialize();
		}

		// Keyword sets kept for fallback (passed through to Semantic_Dedup).
		$accepted_keyword_sets = array();

		foreach ( $analysis_results as $result ) {
			if ( $this->is_excluded( $result->get_topic(), $exclusions ) ) {
				++$excluded_count;
				continue;
			}

			if ( ! $this->skip_dedup ) {
				$topic          = $result->get_topic();
				$topic_keywords = PRAutoBlogger_Keyword_Extractor::extract( $topic );

				if ( $dedup->is_batch_duplicate( $topic, $topic_keywords, $accepted_keyword_sets ) ) {
					++$deduped_count;
					PRAutoBlogger_Logger::instance()->info(
						sprintf( 'Dedup(batch): skipping "%s"', substr( $topic, 0, 80 ) ),
						'scorer'
					);
					continue;
				}

				if ( $dedup->is_recent_duplicate( $topic, $topic_keywords ) ) {
					++$deduped_count;
					PRAutoBlogger_Logger::instance()->info(
						sprintf( 'Dedup(recent): skipping "%s"', substr( $topic, 0, 80 ) ),
						'scorer'
					);
					continue;
				}

				$accepted_keyword_sets[] = $topic_keywords;
				$dedup->record_accepted( $topic );
			}

			$metadata = $result->get_metadata() ?? array();

			$ideas[] = new PRAutoBlogger_Article_Idea(
				array(
					'topic'           => $result->get_topic(),
					'article_type'    => $this->map_type( $result->get_analysis_type() ),
					'suggested_title' => $metadata['suggested_title'] ?? $result->get_topic(),
					'summary'         => $result->get_summary() ?? '',
					'score'           => $this->compute_score( $result ),
					'analysis_id'     => $result->get_id(),
					'source_ids'      => $result->get_source_ids(),
					'key_points'      => $metadata['key_points'] ?? array(),
					'target_keywords' => $metadata['target_keywords'] ?? array(),
				)
			);
		}

		PRAutoBlogger_Logger::instance()->info(
			sprintf(
				'Scorer: %d input → %d viable, %d deduped, %d excluded. Returning top %d.',
				count( $analysis_results ),
				count( $ideas ),
				$deduped_count,
				$excluded_count,
				min( $target_count, count( $ideas ) )
			),
			'scorer'
		);

		usort(
			$ideas,
			static function ( PRAutoBlogger_Article_Idea $a, PRAutoBlogger_Article_Idea $b ): int {
				return $b->get_score() <=> $a->get_score();
			}
		);

		return array_slice( $ideas, 0, $target_count );
	}

	/**
	 * Compute a composite score for an analysis result.
	 *
	 * Factors: LLM relevance score (50%), frequency (30%), recency bonus (20%).
	 *
	 * @param PRAutoBlogger_Analysis_Result $result
	 * @return float Score between 0 and 1.
	 */
	private function compute_score( PRAutoBlogger_Analysis_Result $result ): float {
		$relevance = min( $result->get_relevance_score(), 1.0 );
		$frequency = min( $result->get_frequency() / 10.0, 1.0 );

		$hours_ago = ( time() - strtotime( $result->get_analyzed_at() ) ) / 3600.0;
		$recency   = max( 0.0, 1.0 - ( $hours_ago / 48.0 ) );

		return ( $relevance * 0.5 ) + ( $frequency * 0.3 ) + ( $recency * 0.2 );
	}

	/**
	 * Check if a topic should be excluded based on user-configured exclusions.
	 *
	 * @param string   $topic      Topic text.
	 * @param string[] $exclusions List of excluded terms/phrases.
	 * @return bool
	 */
	private function is_excluded( string $topic, array $exclusions ): bool {
		$topic_lower = strtolower( $topic );
		foreach ( $exclusions as $exclusion ) {
			if ( false !== strpos( $topic_lower, strtolower( trim( $exclusion ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Map analysis type to a reader-friendly article type.
	 *
	 * @param string $analysis_type 'question', 'complaint', 'comparison', etc.
	 * @return string Article type label.
	 */
	private function map_type( string $analysis_type ): string {
		$map = array(
			'question'   => 'guide',
			'complaint'  => 'solution',
			'comparison' => 'comparison',
			'news'       => 'news',
			'guide'      => 'guide',
		);
		return $map[ $analysis_type ] ?? 'article';
	}
}
