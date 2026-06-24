<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Research Dedup — URL-exact and semantic deduplication for the curate stage.
 *
 * What: Deduplicates a flat source list using (1) canonical URL matching,
 *       then (2) cosine-similarity on OpenRouter embeddings with a
 *       keyword-overlap fallback when the embedding API is unavailable.
 *       Pure computation — no DB writes, no side effects beyond logging.
 * Who triggers it: PRAutoBlogger_Research_Judge (curate stage) only.
 *       Not wired into the Economy path.
 * Dependencies: PRAutoBlogger_OpenRouter_Embedding_Provider (semantic dedup),
 *       WordPress wp_parse_url(), Logger (warning on embedding failure).
 *
 * @see core/class-research-judge.php        — Sole consumer.
 * @see core/class-semantic-dedup.php        — Embedding pattern reference.
 * @see ARCHITECTURE.md                      — Phase 2b curate stage.
 */
class PRAutoBlogger_Research_Dedup {

	/** Cosine similarity threshold above which two sources are duplicates. */
	private const SEMANTIC_DEDUP_THRESHOLD = 0.90;

	/**
	 * Deduplicate: URL-exact first, then semantic similarity via embeddings
	 * (falls back to keyword-overlap when the embedding API is unavailable).
	 *
	 * @param array<int, array{url: string, title: string, excerpt: string, relevance: float, agent_role: string}> $sources Flat source list.
	 * @return array<int, array{url: string, title: string, excerpt: string, relevance: float, agent_role: string}> Deduplicated sources.
	 *
	 * Side effects: Logger::warning() when embedding API is unreachable.
	 */
	public function deduplicate( array $sources ): array {
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
				foreach ( $unique_kw as $existing ) {
					$overlap = count( array_intersect( $kw, $existing ) );
					if ( count( $kw ) > 0 && ( $overlap / count( $kw ) ) >= 0.6 ) {
						continue 2;
					}
				}
				$unique_kw[] = $kw;
			}

			$unique[] = $source;
		}

		return $unique;
	}

	// ── Private helpers ─────────────────────────────────────────────────

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
