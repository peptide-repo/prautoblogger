<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Source authority scorer for the Authority pipeline curate stage.
 *
 * What: Computes a quality_score for each research source by multiplying
 *       its agent-assigned relevance by a source-type authority weight
 *       derived from the URL scheme and hostname. Weight tiers:
 *       peer-reviewed (DOI/PubMed/NCBI → 1.0), institutional (.gov /
 *       .edu / WHO / FDA → 0.85), preprint (medRxiv/bioRxiv → 0.70),
 *       general HTTPS (→ 0.60), HTTP or missing (→ 0.40).
 * Who triggers it: PRAutoBlogger_Research_Judge (curate stage) only.
 *       Not wired into the Economy path.
 * Dependencies: none — pure computation.
 *
 * @see core/class-research-judge.php — Sole consumer.
 * @see ARCHITECTURE.md              — Phase 2b curate stage.
 */
class PRAutoBlogger_Research_Source_Scorer {

	/**
	 * Add a quality_score field to a source array in place.
	 *
	 * @param array{url: string, title: string, excerpt: string, relevance: float, agent_role: string} $source Source from a research agent.
	 * @return array{url: string, title: string, excerpt: string, relevance: float, agent_role: string, quality_score: float} Source with quality_score added.
	 *
	 * Side effects: none.
	 */
	public function score( array $source ): array {
		$source['quality_score'] = round(
			$source['relevance'] * $this->weight( $source['url'] ?? '' ),
			4
		);
		return $source;
	}

	// ── Private helpers ─────────────────────────────────────────────────

	/**
	 * Authority weight for a source URL.
	 *
	 * @param string $url Source URL.
	 * @return float Weight in [0.40, 1.0].
	 */
	private function weight( string $url ): float {
		$u = strtolower( $url );

		if (
			false !== strpos( $u, 'doi.org' ) ||
			false !== strpos( $u, 'pubmed.ncbi' ) ||
			false !== strpos( $u, 'ncbi.nlm.nih' )
		) {
			return 1.0;
		}

		if (
			false !== strpos( $u, '.gov' ) ||
			false !== strpos( $u, '.edu' ) ||
			false !== strpos( $u, 'who.int' ) ||
			false !== strpos( $u, 'fda.gov' )
		) {
			return 0.85;
		}

		if (
			false !== strpos( $u, 'medrxiv' ) ||
			false !== strpos( $u, 'biorxiv' ) ||
			false !== strpos( $u, 'preprint' )
		) {
			return 0.70;
		}

		if ( 0 === strpos( $u, 'https://' ) ) {
			return 0.60;
		}

		return 0.40;
	}
}
