<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Paginated query over the runs ledger for the Generation History page (M4).
 *
 * What: Returns a bounded, paginated list of previous generation runs,
 *       newest first. Each row includes the linked article title (when
 *       the run produced a published/draft post), final status, total
 *       settled cost, duration, and the distinct model(s) used (from
 *       generation_log). Query is bounded (LIMIT/OFFSET) — never does an
 *       unbounded SELECT *.
 *
 *       Also exposes a per-run stage I/O method used by the AJAX drill-
 *       down: returns every generation_log row for a run (input from
 *       request_json) plus the corresponding run_stages output
 *       (meta_json). This is intentionally separate from the dossier's
 *       Dossier_Data_Assembler so it works without a post_id (orphan/
 *       failed runs have no post).
 *
 * SECURITY: request_json is stored without API keys (the provider's
 *   build_body() never includes the Authorization header; only the JSON
 *   body is captured by Request_Recorder). All output is plain data;
 *   escaping is the caller's responsibility (Gen_Run_IO_Handler and the
 *   template call esc_html).
 *
 * Triggered by: PRAutoBlogger_Gen_History_Page (list) and
 *               PRAutoBlogger_Gen_Run_IO_Handler (drill-down).
 * Dependencies: WordPress $wpdb.
 *
 * @see admin/class-gen-history-page.php     -- List page (entry point).
 * @see ajax/class-gen-run-io-handler.php    -- Per-run I/O drill-down AJAX.
 * @see admin/class-dossier-gen-log-query.php -- Dossier counterpart (post-scoped).
 * @see ARCHITECTURE.md                       -- Database schema (runs, generation_log).
 */
class PRAutoBlogger_Gen_History_Query {

	/** Default page size (max runs per page). */
	public const PAGE_SIZE = 20;

	/** Maximum page size to prevent abuse. */
	public const PAGE_SIZE_MAX = 100;

	/**
	 * Fetch a paginated list of generation runs, newest first.
	 *
	 * Each row in the result carries:
	 *   run_id, status, ceiling_usd, settled_usd, started_at, finished_at,
	 *   post_id (nullable — from postmeta), post_title (nullable),
	 *   cost_total (SUM from gen_log), models (comma-separated distinct),
	 *   duration_seconds (UNIX diff finished_at - started_at, or null).
	 *
	 * @param int $page     1-based page number.
	 * @param int $per_page Rows per page (capped at PAGE_SIZE_MAX).
	 * @return array{rows: list<array<string,mixed>>, total: int}
	 */
	public function get_page( int $page = 1, int $per_page = self::PAGE_SIZE ): array {
		global $wpdb;
		if ( null === $wpdb ) {
			return array( 'rows' => array(), 'total' => 0 );
		}

		$per_page = min( max( 1, $per_page ), self::PAGE_SIZE_MAX );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;

		$runs_table = $wpdb->prefix . 'prautoblogger_runs';
		$log_table  = $wpdb->prefix . 'prautoblogger_generation_log';
		$meta_table = $wpdb->postmeta;

		// Total count for pagination.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$runs_table}" );

		// Join runs → postmeta (run_id stored in _prautoblogger_run_id) for title.
		// LEFT JOIN gen_log to aggregate cost + models. Bounded by LIMIT.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					r.run_id,
					r.status,
					r.ceiling_usd,
					r.settled_usd,
					r.started_at,
					r.finished_at,
					pm.post_id,
					p.post_title,
					p.post_status as post_status,
					COALESCE(SUM(CASE WHEN gl.response_status = 'success' THEN gl.estimated_cost ELSE 0 END), 0) AS cost_total,
					GROUP_CONCAT(DISTINCT gl.model ORDER BY gl.model SEPARATOR ', ') AS models,
					CASE WHEN r.finished_at IS NOT NULL
						THEN TIMESTAMPDIFF(SECOND, r.started_at, r.finished_at)
						ELSE NULL
					END AS duration_seconds
				 FROM {$runs_table} r
				 LEFT JOIN {$meta_table} pm
					ON pm.meta_key = '_prautoblogger_run_id'
					AND pm.meta_value = r.run_id
				 LEFT JOIN {$wpdb->posts} p
					ON p.ID = pm.post_id
				 LEFT JOIN {$log_table} gl
					ON gl.run_id = r.run_id
				 GROUP BY r.run_id, r.status, r.ceiling_usd, r.settled_usd,
					r.started_at, r.finished_at, pm.post_id, p.post_title, p.post_status
				 ORDER BY r.started_at DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Fetch per-stage input + output for one run.
	 *
	 * INPUT: extracted from generation_log.request_json (the assembled
	 *   messages the LLM received). Auth headers are never stored — only
	 *   the body. Returns the user-role message content (the rendered
	 *   instruction) and the system message when present.
	 *
	 * OUTPUT: extracted from run_stages.meta_json.output (the LLM's
	 *   response text). When meta_json is NULL (pruned per retention
	 *   policy) or has no output key, output is null — reported honestly.
	 *
	 * Stages are ordered by generation_log.id ASC (insertion order),
	 * then enriched with the matching run_stages snapshot where found.
	 *
	 * @param string $run_id Pipeline run UUID.
	 * @return list<array<string, mixed>> Stage rows, each with keys:
	 *   stage, model, agent_role, prompt_tokens, completion_tokens,
	 *   estimated_cost, response_status, error_message, created_at,
	 *   input_system (string|null), input_user (string|null),
	 *   output (string|null), output_pruned (bool).
	 */
	public function get_run_io( string $run_id ): array {
		global $wpdb;
		if ( null === $wpdb || '' === $run_id ) {
			return array();
		}

		$log_table    = $wpdb->prefix . 'prautoblogger_generation_log';
		$stages_table = $wpdb->prefix . 'prautoblogger_run_stages';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$log_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, stage, model, agent_role,
					prompt_tokens, completion_tokens, estimated_cost,
					request_json, response_status, error_message, created_at
				 FROM {$log_table}
				 WHERE run_id = %s
				 ORDER BY id ASC",
				$run_id
			),
			ARRAY_A
		);

		if ( ! is_array( $log_rows ) ) {
			return array();
		}

		// Load run_stages output snapshot (keyed by stage+agent_role).
		$stage_outputs = array();
		if ( PRAutoBlogger_Run_Stage_State::is_available() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$stage_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT stage, agent_role, meta_json
					 FROM {$stages_table}
					 WHERE run_id = %s",
					$run_id
				),
				ARRAY_A
			);
			foreach ( ( $stage_rows ?? array() ) as $sr ) {
				$key = (string) $sr['stage'] . ':' . (string) ( $sr['agent_role'] ?? '' );
				$stage_outputs[ $key ] = $sr['meta_json'];
			}
		}

		$result = array();
		foreach ( $log_rows as $row ) {
			$stage      = (string) ( $row['stage'] ?? '' );
			$agent_role = (string) ( $row['agent_role'] ?? '' );
			$stage_key  = $stage . ':' . $agent_role;

			// Extract INPUT from request_json messages array.
			$input_system = null;
			$input_user   = null;
			if ( ! empty( $row['request_json'] ) ) {
				$body = json_decode( (string) $row['request_json'], true );
				if ( is_array( $body ) && ! empty( $body['messages'] ) ) {
					foreach ( (array) $body['messages'] as $msg ) {
						if ( ! is_array( $msg ) ) {
							continue;
						}
						$role    = (string) ( $msg['role'] ?? '' );
						$content = is_string( $msg['content'] ?? null ) ? $msg['content'] : '';
						if ( 'system' === $role && '' !== $content ) {
							$input_system = $content;
						} elseif ( 'user' === $role && '' !== $content ) {
							$input_user = $content;
						}
					}
				}
			}

			// Extract OUTPUT from run_stages meta_json.
			$output        = null;
			$output_pruned = false;
			$meta_raw      = $stage_outputs[ $stage_key ] ?? null;
			if ( null === $meta_raw ) {
				// Stage may not exist in run_stages (log-only stage e.g. image_a).
				$output_pruned = false;
			} else {
				$meta = json_decode( (string) $meta_raw, true );
				if ( is_array( $meta ) && isset( $meta['output'] ) ) {
					$output = (string) $meta['output'];
				} else {
					// meta_json present but no 'output' key = pruned by retention.
					$output_pruned = true;
				}
			}

			$result[] = array(
				'stage'             => $stage,
				'model'             => (string) ( $row['model'] ?? '' ),
				'agent_role'        => $agent_role,
				'prompt_tokens'     => (int) ( $row['prompt_tokens'] ?? 0 ),
				'completion_tokens' => (int) ( $row['completion_tokens'] ?? 0 ),
				'estimated_cost'    => (float) ( $row['estimated_cost'] ?? 0 ),
				'response_status'   => (string) ( $row['response_status'] ?? '' ),
				'error_message'     => (string) ( $row['error_message'] ?? '' ),
				'created_at'        => (string) ( $row['created_at'] ?? '' ),
				'input_system'      => $input_system,
				'input_user'        => $input_user,
				'output'            => $output,
				'output_pruned'     => $output_pruned,
			);
		}

		return $result;
	}

	/**
	 * Fetch minimal run metadata (status, started_at, post_id/title) for
	 * the drill-down header. Used by Gen_Run_IO_Handler.
	 *
	 * @param string $run_id Pipeline run UUID.
	 * @return array<string, mixed>|null
	 */
	public function get_run_meta( string $run_id ): ?array {
		global $wpdb;
		if ( null === $wpdb || '' === $run_id ) {
			return null;
		}
		$runs_table = $wpdb->prefix . 'prautoblogger_runs';
		$meta_table = $wpdb->postmeta;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT r.run_id, r.status, r.settled_usd, r.started_at, r.finished_at,
					pm.post_id, p.post_title
				 FROM {$runs_table} r
				 LEFT JOIN {$meta_table} pm
					ON pm.meta_key = '_prautoblogger_run_id'
					AND pm.meta_value = r.run_id
				 LEFT JOIN {$wpdb->posts} p
					ON p.ID = pm.post_id
				 WHERE r.run_id = %s
				 LIMIT 1",
				$run_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}
}
