<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Builds the edit + re-run view-model layer for the dossier (v0.20.0, M3).
 *
 * What: Per-stage affordance data (editable? why not? latest fork,
 *       prefill messages from the fork or the stage's recorded
 *       request_json) plus the run-level spend strip (settled + reserved
 *       vs ceiling, warn fraction — guardrail 4's visible cost
 *       implication) and the overall eligibility verdict. Pure
 *       assembly: reads the rows the Dossier_Data_Assembler already
 *       fetched plus the stage_inputs store; performs NO mutations.
 *       Disabled affordances always carry an operator-readable reason —
 *       the UI never hides why an edit is unavailable.
 * Who triggers it: PRAutoBlogger_Dossier_Data_Assembler::assemble().
 * Dependencies: Rerun_Eligibility (policy), Stage_Input_Store (forks),
 *               Stage_Replay (body decode).
 *
 * @see admin/class-dossier-data-assembler.php — Caller.
 * @see templates/admin/dossier-edit-panel.php — Renders this data.
 * @see ARCHITECTURE.md #24                    — Edit + re-run design.
 */
class PRAutoBlogger_Dossier_Rerun_Panel_Data {

	/** Default warn threshold as a fraction of the per-run ceiling. */
	private const DEFAULT_WARN_FRACTION = 0.8;

	/**
	 * Run-level spend strip data (guardrail 4 visibility).
	 *
	 * @param array<string, mixed>|null $run Run ledger row, or null.
	 * @return array{settled: float, reserved: float, ceiling: float, fraction: float, warn: bool}
	 */
	public static function spend( ?array $run ): array {
		$settled  = null !== $run ? (float) ( $run['settled_usd'] ?? 0 ) : 0.0;
		$reserved = null !== $run ? (float) ( $run['reserved_usd'] ?? 0 ) : 0.0;
		$ceiling  = null !== $run ? (float) ( $run['ceiling_usd'] ?? 0 ) : 0.0;
		$fraction = $ceiling > 0 ? ( $settled + $reserved ) / $ceiling : 0.0;

		/**
		 * Filters the spend fraction at which re-run confirms warn about
		 * approaching the per-run ceiling.
		 *
		 * @param float $warn_fraction Fraction of ceiling (default 0.8).
		 */
		$warn_fraction = (float) apply_filters( 'prautoblogger_rerun_ceiling_warn_fraction', self::DEFAULT_WARN_FRACTION );

		return array(
			'settled'  => $settled,
			'reserved' => $reserved,
			'ceiling'  => $ceiling,
			'fraction' => $fraction,
			'warn'     => $ceiling > 0 && $fraction >= $warn_fraction,
		);
	}

	/**
	 * Edit + re-run affordance data for one stage row.
	 *
	 * @param array<string, mixed>                            $stage_row   run_stages row.
	 * @param array<int, array<string, mixed>>                $log_rows    generation_log rows for the stage.
	 * @param string                                          $run_id      Run UUID.
	 * @param array{ok: bool, reason: string}                 $eligibility Overall run eligibility.
	 * @return array<string, mixed> Affordance view model (see keys below).
	 */
	public static function for_stage( array $stage_row, array $log_rows, string $run_id, array $eligibility ): array {
		$stage    = (string) ( $stage_row['stage'] ?? '' );
		$role     = (string) ( $stage_row['agent_role'] ?? '' );
		$item_key = (string) ( $stage_row['item_key'] ?? '' );

		$data = array(
			'stale'          => ! empty( $stage_row['stale'] ),
			'human_modified' => ! empty( $stage_row['human_modified'] ),
			'attempt'        => (int) ( $stage_row['attempt'] ?? 1 ),
			'editable'       => false,
			'edit_reason'    => '',
			'fork'           => null,
			'prefill'        => null,
			'rerun_from'     => $eligibility['ok'] && PRAutoBlogger_Rerun_Eligibility::is_chain_stage( $stage ),
		);

		if ( ! PRAutoBlogger_Rerun_Eligibility::is_editable_stage( $stage ) ) {
			$data['edit_reason'] = self::policy_reason( $stage );
			return $data;
		}
		if ( ! $eligibility['ok'] ) {
			$data['edit_reason'] = $eligibility['reason'];
			return $data;
		}

		$fork = PRAutoBlogger_Stage_Input_Store::latest_fork( $run_id, $stage, $role, $item_key );
		if ( null !== $fork ) {
			$data['fork'] = array(
				'version'    => (int) $fork['version'],
				'author'     => (string) $fork['author'],
				'created_at' => (string) $fork['created_at'],
				'has_body'   => ! empty( $fork['request_json'] ),
			);
		}

		$base_json = ( null !== $fork && ! empty( $fork['request_json'] ) )
			? (string) $fork['request_json']
			: self::latest_request_json( $log_rows );

		if ( null === $base_json ) {
			$days                = (int) get_option( 'prautoblogger_request_json_retention_days', PRAUTOBLOGGER_DEFAULT_REQUEST_JSON_RETENTION_DAYS );
			$data['edit_reason'] = sprintf(
				/* translators: %d: retention days. */
				__( 'No persisted input for this stage — request bodies are recorded from v0.20.0 onward and retained for %d days.', 'prautoblogger' ),
				$days
			);
			return $data;
		}

		$body = PRAutoBlogger_Stage_Replay::decode_body( $base_json );
		if ( null === $body ) {
			$data['edit_reason'] = __( 'The persisted input for this stage is not a replayable chat request.', 'prautoblogger' );
			return $data;
		}

		$data['editable'] = true;
		$data['prefill']  = array(
			'model'    => (string) $body['model'],
			'messages' => $body['messages'],
		);
		return $data;
	}

	/**
	 * Why a non-writer stage has no edit affordance (always shown —
	 * the UI never silently hides the option).
	 *
	 * @param string $stage Stage name.
	 * @return string Operator-readable reason.
	 */
	private static function policy_reason( string $stage ): string {
		if ( 'review' === $stage ) {
			return __( 'The review input is derived entirely from the article content — edit a writing stage, then use "Re-run from here" on this one.', 'prautoblogger' );
		}
		if ( 'publish' === $stage ) {
			return __( 'Publish is not an LLM stage — use "Re-run from here" to refresh the post from current outputs.', 'prautoblogger' );
		}
		if ( in_array( $stage, array( 'analysis', 'research', 'llm_research' ), true ) ) {
			return __( 'Run-level stage: its output fed idea selection for the whole run and cannot be re-flowed into a single article.', 'prautoblogger' );
		}
		if ( 0 === strpos( $stage, 'image' ) ) {
			return __( 'Image stages are not replayable yet (Phase 2b restructure).', 'prautoblogger' );
		}
		return __( 'This stage cannot be replayed with an edited input.', 'prautoblogger' );
	}

	/**
	 * The most recent recorded request body among a stage's log rows
	 * (success rows preferred, then latest id).
	 *
	 * @param array<int, array<string, mixed>> $log_rows generation_log rows.
	 * @return string|null
	 */
	private static function latest_request_json( array $log_rows ): ?string {
		$best = null;
		foreach ( $log_rows as $row ) {
			if ( empty( $row['request_json'] ) ) {
				continue;
			}
			if ( null === $best ) {
				$best = $row;
				continue;
			}
			$row_success  = ( 'success' === ( $row['response_status'] ?? '' ) );
			$best_success = ( 'success' === ( $best['response_status'] ?? '' ) );
			if ( ( $row_success && ! $best_success )
				|| ( $row_success === $best_success && (int) ( $row['id'] ?? 0 ) > (int) ( $best['id'] ?? 0 ) ) ) {
				$best = $row;
			}
		}
		return null !== $best ? (string) $best['request_json'] : null;
	}
}
