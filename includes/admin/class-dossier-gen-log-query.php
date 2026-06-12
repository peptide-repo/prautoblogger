<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Raw generation_log queries for the Article Dossier (M2).
 *
 * Returns per-stage generation log data indexed by stage name: model,
 * prompt_version, agent_role, token counts, estimated cost, request_json
 * (raw trace), and response_status. Amortized research rows (pv=null,
 * role='') are included and rendered gracefully by the template.
 *
 * Triggered by: PRAutoBlogger_Dossier_Data_Assembler::assemble().
 * Dependencies: WordPress $wpdb.
 *
 * @see admin/class-dossier-data-assembler.php -- Calls get_by_run().
 * @see ARCHITECTURE.md                         -- generation_log schema.
 */
class PRAutoBlogger_Dossier_Gen_Log_Query {

	/**
	 * Fetch all generation_log rows for a run, indexed by stage.
	 *
	 * Multiple rows per stage (e.g. multi-step draft) are accumulated as an
	 * array so the template can render multiple raw-trace entries per section.
	 *
	 * SECURITY: request_json is stored without API keys (never logged per
	 * architecture). The template must escape this via esc_html() before render.
	 *
	 * @param string $run_id Pipeline run UUID.
	 * @return array<string, array<int, array<string, mixed>>> Rows keyed by stage.
	 */
	public function get_by_run( string $run_id ): array {
		global $wpdb;
		if ( null === $wpdb || '' === $run_id ) {
			return array();
		}

		$table = $wpdb->prefix . 'prautoblogger_generation_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, stage, model, agent_role, prompt_version,
				        prompt_tokens, completion_tokens, estimated_cost,
				        request_json, response_status, error_message, created_at
				 FROM {$table}
				 WHERE run_id = %s
				 ORDER BY id ASC",
				$run_id
			),
			ARRAY_A
		);

		$index = array();
		foreach ( ( $rows ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$stage = (string) ( $row['stage'] ?? 'unknown' );
			if ( ! isset( $index[ $stage ] ) ) {
				$index[ $stage ] = array();
			}
			$index[ $stage ][] = $row;
		}
		return $index;
	}

	/**
	 * Aggregate cost + token totals per stage for the sidebar receipt.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $log_index Output of get_by_run().
	 * @return array<string, array{cost: float, prompt_tokens: int, completion_tokens: int, calls: int}>
	 */
	public function aggregate_per_stage( array $log_index ): array {
		$totals = array();
		foreach ( $log_index as $stage => $rows ) {
			$cost              = 0.0;
			$prompt_tokens     = 0;
			$completion_tokens = 0;
			foreach ( $rows as $row ) {
				$cost              += (float) ( $row['estimated_cost'] ?? 0 );
				$prompt_tokens     += (int) ( $row['prompt_tokens'] ?? 0 );
				$completion_tokens += (int) ( $row['completion_tokens'] ?? 0 );
			}
			$totals[ $stage ] = array(
				'cost'              => $cost,
				'prompt_tokens'     => $prompt_tokens,
				'completion_tokens' => $completion_tokens,
				'calls'             => count( $rows ),
			);
		}
		return $totals;
	}
}
