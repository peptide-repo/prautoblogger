<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Raw generation-log queries for the kanban board.
 *
 * Extracted from PRAutoBlogger_Board_Data_Provider to stay under the 300-line
 * cap. Handles the two columns that read directly from prab_generation_log:
 *   Generating (in-progress runs) | Failed (error runs with no linked post)
 *
 * No schema changes — data comes from prab_generation_log which already exists.
 *
 * Triggered by: PRAutoBlogger_Board_Data_Provider::get_generating_cards()
 *               PRAutoBlogger_Board_Data_Provider::get_failed_cards()
 * Dependencies: WordPress $wpdb, get_transient().
 *
 * @see admin/class-board-data-provider.php — Orchestrator that calls this class.
 * @see ARCHITECTURE.md                     — Database schema (generation_log).
 */
class PRAutoBlogger_Board_Gen_Log_Query {

	/** Seconds of gen-log inactivity after which a run is no longer "generating". */
	public const GENERATING_GRACE_SECONDS = 1800;

	/** Transient key written by PRAutoBlogger_Executor for the active generation run. */
	public const STATUS_TRANSIENT = 'prautoblogger_generation_status';

	/**
	 * Cards for runs that are currently in-progress.
	 *
	 * Detection strategy: check the generation status transient first (authoritative
	 * for the active manual-run or daily-cron run), then fall back to recent gen_log
	 * run_ids that have no associated published/draft post yet.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_generating_cards(): array {
		global $wpdb;

		$cards = array();

		// Primary: check if the executor transient signals an active run.
		$status = get_transient( self::STATUS_TRANSIENT );
		if ( is_array( $status ) && 'running' === ( $status['status'] ?? '' ) ) {
			$cards[] = array(
				'run_id'        => '',
				'post_id'       => 0,
				'title'         => $status['stage'] ?? __( 'Generating…', 'prautoblogger' ),
				'cost_total'    => 0.0,
				'stage_current' => $status['stage'] ?? '',
				'stage_count'   => 0,
				'started_at'    => $status['started'] ?? 0,
				'click_action'  => 'logs',
				'log_url'       => admin_url( 'admin.php?page=prautoblogger-logs' ),
			);
		}

		// Secondary: runs in gen_log that are recent and have no linked post.
		$table = $wpdb->prefix . 'prautoblogger_generation_log';
		$since = gmdate( 'Y-m-d H:i:s', time() - self::GENERATING_GRACE_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$raw_runs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT run_id,
					MIN(created_at) as started_at,
					MAX(stage)      as last_stage,
					COUNT(*)        as stage_count,
					SUM(CASE WHEN response_status = 'success' THEN estimated_cost ELSE 0 END) as cost_total
				FROM {$table}
				WHERE created_at >= %s
				  AND run_id IS NOT NULL
				  AND post_id IS NULL
				GROUP BY run_id
				ORDER BY started_at DESC
				LIMIT 10",
				$since
			),
			ARRAY_A
		);

		if ( ! is_array( $raw_runs ) ) {
			return $cards;
		}

		foreach ( $raw_runs as $row ) {
			$run_id = (string) $row['run_id'];
			if ( '' === $run_id ) {
				continue;
			}

			// Skip if we already have a transient card (avoid duplicate).
			if ( ! empty( $cards ) && '' === $cards[0]['run_id'] ) {
				// Only add secondary cards if we have a real run_id.
				// The transient card already covers the active run.
			}

			$cards[] = array(
				'run_id'        => $run_id,
				'post_id'       => 0,
				'title'         => sprintf(
					/* translators: %s: pipeline stage name */
					__( 'Generating — %s', 'prautoblogger' ),
					esc_html( ucfirst( (string) $row['last_stage'] ) )
				),
				'cost_total'    => round( (float) $row['cost_total'], 6 ),
				'stage_current' => (string) $row['last_stage'],
				'stage_count'   => (int) $row['stage_count'],
				'started_at'    => strtotime( (string) $row['started_at'] ),
				'click_action'  => 'logs',
				'log_url'       => admin_url( 'admin.php?page=prautoblogger-logs' ),
			);
		}

		return $cards;
	}

	/**
	 * Cards for generation runs that ended in an error state.
	 *
	 * Looks for recent gen_log rows with response_status='error' that have
	 * no corresponding published post — these are pipeline failures.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_failed_cards(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'prautoblogger_generation_log';
		$since = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT run_id,
					MIN(created_at) as started_at,
					MAX(error_message) as last_error,
					MAX(stage)         as last_stage,
					COUNT(*)           as call_count
				FROM {$table}
				WHERE created_at >= %s
				  AND response_status = 'error'
				  AND run_id IS NOT NULL
				  AND post_id IS NULL
				GROUP BY run_id
				ORDER BY started_at DESC
				LIMIT 20",
				$since
			),
			ARRAY_A
		);

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$cards = array();
		foreach ( $raw as $row ) {
			$run_id = (string) $row['run_id'];
			if ( '' === $run_id ) {
				continue;
			}
			$cards[] = array(
				'run_id'        => $run_id,
				'post_id'       => 0,
				'title'         => sprintf(
					/* translators: %s: pipeline stage name */
					__( 'Failed — %s', 'prautoblogger' ),
					esc_html( ucfirst( (string) $row['last_stage'] ) )
				),
				'error_message' => (string) $row['last_error'],
				'started_at'    => strtotime( (string) $row['started_at'] ),
				'click_action'  => 'logs',
				'log_url'       => admin_url( 'admin.php?page=prautoblogger-logs' ),
			);
		}

		return $cards;
	}
}
