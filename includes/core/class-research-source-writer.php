<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Run-sources DB writer for the Authority pipeline curate stage.
 *
 * What: Persists kept and discarded source records to the
 *       wp_prautoblogger_run_sources table via PRAutoBlogger_Audit_Writer.
 *       Builds the reason string for kept rows (quality/relevance) and the
 *       fixed "exceeded max" reason for discarded rows. Also extracts DOI
 *       identifiers from source URLs for storage.
 * Who triggers it: PRAutoBlogger_Research_Judge (curate stage) only.
 *       Not wired into the Economy path.
 * Dependencies: PRAutoBlogger_Audit_Writer (availability check + DB insert),
 *       WordPress $wpdb, current_time().
 *
 * @see core/class-research-judge.php — Sole consumer.
 * @see core/class-audit-writer.php  — Underlying DB insert layer.
 * @see ARCHITECTURE.md              — Phase 2b curate stage.
 */
class PRAutoBlogger_Research_Source_Writer {

	/**
	 * Write run_sources rows for kept (kept=1) and discarded (kept=0) sources.
	 *
	 * @param string                                                                                   $run_id    Run UUID.
	 * @param array<int, array{url: string, agent_role?: string, quality_score: float, relevance: float}> $kept      Scored kept sources.
	 * @param array<int, array{url: string, agent_role?: string, quality_score: float, relevance: float}> $discarded Scored discarded sources (over max).
	 * @return void Side effects: DB inserts; no-op when audit tables absent.
	 */
	public function write( string $run_id, array $kept, array $discarded ): void {
		if ( ! PRAutoBlogger_Audit_Writer::is_available() ) {
			return;
		}
		global $wpdb;
		$table   = $wpdb->prefix . 'prautoblogger_run_sources';
		$now     = current_time( 'mysql' );
		$sources = array_merge(
			array_map(
				static fn( $s ) => $s + array(
					'is_kept' => 1,
					'discard_reason' => '',
				),
				$kept
			),
			array_map(
				static fn( $s ) => $s + array(
					'is_kept' => 0,
					'discard_reason' => 'Exceeded maximum kept sources per run',
				),
				$discarded
			)
		);
		foreach ( $sources as $s ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$table,
				array(
					'run_id'        => $run_id,
					'agent_role'    => $s['agent_role'] ?? 'curator',
					'source_url'    => $s['url'],
					'doi'           => $this->extract_doi( $s['url'] ),
					'kept'          => $s['is_kept'],
					'reason'        => $s['is_kept'] ? sprintf( 'Quality %.3f (relevance=%.2f)', $s['quality_score'], $s['relevance'] ) : $s['discard_reason'],
					'quality_score' => $s['quality_score'],
					'created_at'    => $now,
				)
			);
		}
	}

	// ── Private helpers ─────────────────────────────────────────────────

	/**
	 * Extract a DOI from a URL if one is present.
	 *
	 * @param string $url Source URL.
	 * @return string|null DOI, or null.
	 */
	private function extract_doi( string $url ): ?string {
		if ( preg_match( '#10\.\d{4,}/\S+#', $url, $matches ) ) {
			return rtrim( $matches[0], '.' );
		}
		return null;
	}
}
