<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Research Judge — the `curate` stage of the Authority pipeline.
 *
 * What: Deduplicates sources across all fan-out agent results using
 *       URL-exact matching first, then semantic similarity (via the
 *       OpenRouter embedding API + cosine similarity, matching the
 *       Semantic_Dedup pattern) with keyword-overlap fallback. Assigns
 *       a quality_score to each source (relevance × source-type weight).
 *       Writes one run_sources row per source (kept=1 or kept=0) with
 *       reason + quality_score via PRAutoBlogger_Research_Source_Writer.
 *       Returns the kept, scored list for the draft stage.
 * Who triggers it: PRAutoBlogger_Authority_Pipeline (Phase 2b.4, the
 *       tier router). NOT wired into the Economy path — additive only.
 * Dependencies: PRAutoBlogger_Research_Source_Writer (run_sources writes),
 *       PRAutoBlogger_Research_Source_Scorer (quality scoring),
 *       PRAutoBlogger_Audit_Writer (run_decisions write),
 *       PRAutoBlogger_OpenRouter_Embedding_Provider (semantic dedup),
 *       PRAutoBlogger_Run_Stage_State (curate stage row),
 *       PRAutoBlogger_Stage_Display_Map (default agent role),
 *       WordPress $wpdb, Logger.
 *
 * @see providers/interface-research-judge.php       — Interface.
 * @see core/class-research-fanout.php               — Produces the input.
 * @see core/class-research-source-writer.php        — Writes run_sources rows.
 * @see core/class-research-source-scorer.php        — Source-type weighting delegate.
 * @see core/class-semantic-dedup.php                — Embedding pattern reference.
 * @see ARCHITECTURE.md                              — Phase 2b curate stage.
 */
class PRAutoBlogger_Research_Judge implements PRAutoBlogger_Research_Judge_Interface {

	/** Cosine similarity threshold above which two sources are duplicates. */
	private const SEMANTIC_DEDUP_THRESHOLD = 0.90;

	/** Maximum kept sources passed to the draft stage. */
	private const MAX_KEPT_SOURCES = 12;

	/** @var PRAutoBlogger_Research_Source_Scorer Scores source authority. */
	private PRAutoBlogger_Research_Source_Scorer $scorer;

	/** @var PRAutoBlogger_Research_Source_Writer Persists run_sources rows. */
	private PRAutoBlogger_Research_Source_Writer $writer;

	/**
	 * @param PRAutoBlogger_Research_Source_Scorer|null $scorer Optional scorer override.
	 * @param PRAutoBlogger_Research_Source_Writer|null $writer Optional writer override.
	 */
	public function __construct(
		?PRAutoBlogger_Research_Source_Scorer $scorer = null,
		?PRAutoBlogger_Research_Source_Writer $writer = null
	) {
		$this->scorer = $scorer ?? new PRAutoBlogger_Research_Source_Scorer();
		$this->writer = $writer ?? new PRAutoBlogger_Research_Source_Writer();
	}

	/**
	 * Deduplicate and score fan-out results; write run_sources rows; return kept.
	 *
	 * Dedup order: (1) URL canonical match; (2) semantic similarity via embedding
	 * API (fallback to keyword-overlap when the API is unavailable). Quality
	 * score = relevance × source-type weight. Sources are sorted by quality_score
	 * descending and capped at MAX_KEPT_SOURCES; overflow rows are written to
	 * run_sources with kept=0 ("exceeded max").
	 *
	 * Side effects: run_sources DB writes, run_stages DB write (curate stage),
	 *       possible embedding API call, Logger calls.
	 *
	 * @param string $run_id        Run UUID.
	 * @param string $item_key      Article-scoped item key.
	 * @param array<int, array{sources: array<int, array{url: string, title: string, excerpt: string, relevance: float}>, agent_role: string}> $fanout_results Agent results from Research_Fanout::dispatch().
	 * @return array<int, array{url: string, title: string, excerpt: string, relevance: float, quality_score: float}> Kept, scored sources.
	 */
	public function curate(
		string $run_id,
		string $item_key,
		array $fanout_results
	): array {
		PRAutoBlogger_Run_Stage_State::start(
			$run_id,
			'curate',
			(string) PRAutoBlogger_Stage_Display_Map::default_agent_role( 'curate' ),
			$item_key
		);

		$all_sources = $this->flatten( $fanout_results );
		$deduped     = $this->deduplicate( $all_sources );
		$scored      = array_map( array( $this->scorer, 'score' ), $deduped );

		usort(
			$scored,
			static fn( array $a, array $b ): int => $b['quality_score'] <=> $a['quality_score']
		);

		$kept      = array_slice( $scored, 0, self::MAX_KEPT_SOURCES );
		$discarded = array_slice( $scored, self::MAX_KEPT_SOURCES );

		$this->writer->write( $run_id, $kept, $discarded );

		PRAutoBlogger_Audit_Writer::record_decision(
			$run_id,
			'curate',
			'completed',
			sprintf(
				'Curated %d sources from %d candidates across %d agents (discarded %d).',
				count( $kept ),
				count( $all_sources ),
				count( $fanout_results ),
				count( $discarded )
			)
		);

		PRAutoBlogger_Run_Stage_State::done(
			$run_id,
			'curate',
			(string) PRAutoBlogger_Stage_Display_Map::default_agent_role( 'curate' ),
			$item_key,
			wp_json_encode(
				array(
					'kept' => count( $kept ),
					'total' => count( $all_sources ),
				)
			)
		);

		PRAutoBlogger_Logger::instance()->info(
			sprintf(
				'Research Judge: kept %d/%d sources (max=%d).',
				count( $kept ),
				count( $all_sources ),
				self::MAX_KEPT_SOURCES
			),
			'research-judge'
		);

		return $kept;
	}

	// ── Private helpers ─────────────────────────────────────────────────

	/**
	 * Flatten per-agent source lists into one array with agent_role attached.
	 *
	 * @param array<int, array{sources: array<int, array{url: string, title: string, excerpt: string, relevance: float}>, agent_role: string}> $fanout_results
	 * @return array<int, array{url: string, title: string, excerpt: string, relevance: float, agent_role: string}>
	 */
	private function flatten( array $fanout_results ): array {
		$flat = array();
		foreach ( $fanout_results as $agent ) {
			foreach ( $agent['sources'] as $source ) {
				$flat[] = array_merge( $source, array( 'agent_role' => $agent['agent_role'] ) );
			}
		}
		return $flat;
	}

	/**
	 * Deduplicate: URL-exact first, then semantic similarity.
	 *
	 * @param array<int, array{url: string, title: string, excerpt: string, relevance: float, agent_role: string}> $sources
	 * @return array<int, array{url: string, title: string, excerpt: string, relevance: float, agent_role: string}>
	 */
	private function deduplicate( array $sources ): array {
		$seen_urls         = array();
		$unique            = array();
		$unique_embeddings = array();
		$unique_kw         = array();
		$embeddings_ok     = true;
		$embed_provider    = null;

		foreach ( $sources as $source ) {
			$canonical = $this->canonical_url( $source['url'] );
			if ( '' !== $canonical ) {
				if ( isset( $seen_urls[ $canonical ] ) ) {
					continue;
				}
				$seen_urls[ $canonical ] = true;
			}

			$text = trim( $source['title'] . ' ' . $source['excerpt'] );

			if ( $embeddings_ok && '' !== $text ) {
				try {
					if ( null === $embed_provider ) {
						$embed_provider = new PRAutoBlogger_OpenRouter_Embedding_Provider();
					}
					$emb = $embed_provider->get_embeddings( array( $text ) );
					$vec = $emb[0] ?? null;
					if ( null !== $vec ) {
						foreach ( $unique_embeddings as $ev ) {
							if ( PRAutoBlogger_OpenRouter_Embedding_Provider::cosine_similarity( $vec, $ev ) >= self::SEMANTIC_DEDUP_THRESHOLD ) {
								continue 2;
							}
						}
						$unique_embeddings[] = $vec;
					}
				} catch ( \Throwable $e ) {
					$embeddings_ok = false;
					PRAutoBlogger_Logger::instance()->warning(
						'Research Judge: embedding unavailable, using keyword fallback. ' . $e->getMessage(),
						'research-judge'
					);
				}
			}

			if ( ! $embeddings_ok && '' !== $text ) {
				$kw = $this->extract_keywords( $text );
				// Mirror Semantic_Dedup: require >=3 keywords before applying overlap
				// check. Sparse keyword sets (e.g. generic titles like "Source 0")
				// are kept unconditionally; URL-exact dedup already filtered real
				// duplicates with identical URLs.
				if ( count( $kw ) >= 3 ) {
					foreach ( $unique_kw as $existing ) {
						$overlap = count( array_intersect( $kw, $existing ) );
						if ( ( $overlap / count( $kw ) ) >= 0.6 ) {
							continue 2;
						}
					}
				}
				$unique_kw[] = $kw;
			}

			$unique[] = $source;
		}

		return $unique;
	}

	/**
	 * Canonical URL: lowercase scheme+host+path, no query/fragment.
	 *
	 * @param string $url Raw URL.
	 * @return string Normalised, or empty on unparseable.
	 */
	private function canonical_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return strtolower( $url );
		}
		return strtolower( ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . rtrim( $parts['path'] ?? '', '/' ) );
	}

	/**
	 * Keyword extractor used for the embedding-unavailable fallback.
	 *
	 * @param string $text Input text.
	 * @return string[] Lowercase unique keywords (3+ chars).
	 */
	private function extract_keywords( string $text ): array {
		static $sw = array( 'a', 'an', 'the', 'and', 'or', 'in', 'of', 'to', 'is', 'was', 'for', 'on', 'at' );
		$words = preg_split( '/[^a-z0-9-]+/', strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY ) ?? array();
		return array_values(
			array_unique(
				array_filter( $words, static fn( string $w ): bool => strlen( $w ) >= 3 && ! in_array( $w, $sw, true ) )
			)
		);
	}
}
