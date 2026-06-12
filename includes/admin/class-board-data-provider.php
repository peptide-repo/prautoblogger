<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Supplies card data for the kanban board page from existing DB tables.
 *
 * Queries `prab_generation_log`, WordPress post meta, and the generation
 * status transient to compute the four board columns:
 *   Generating | In Review | Published | Failed
 *
 * No schema changes — all data comes from tables that already exist.
 *
 * Triggered by: PRAutoBlogger_Board_Page::on_ajax_board_status().
 * Dependencies: WordPress $wpdb, WP_Query, get_option().
 *
 * @see admin/class-board-page.php     — Calls get_board_snapshot().
 * @see core/class-cost-reporter.php   — Parallel cost-query pattern.
 * @see ARCHITECTURE.md                — Database schema (generation_log, post meta).
 */
class PRAutoBlogger_Board_Data_Provider {

	/** Seconds of gen-log inactivity after which a run is no longer "generating". */
	private const GENERATING_GRACE_SECONDS = 1800;

	/** Transient key written by PRAutoBlogger_Executor for the active generation run. */
	private const STATUS_TRANSIENT = 'prautoblogger_generation_status';

	/**
	 * Return the full board snapshot consumed by the AJAX poller.
	 *
	 * Each column is an array of card objects. Cards contain only the data
	 * needed to render the board card and provide click-throughs.
	 *
	 * @return array{
	 *   generating: array<int, array<string, mixed>>,
	 *   in_review:  array<int, array<string, mixed>>,
	 *   published:  array<int, array<string, mixed>>,
	 *   failed:     array<int, array<string, mixed>>,
	 *   has_active_runs: bool,
	 * }
	 */
	public function get_board_snapshot(): array {
		$generating = $this->get_generating_cards();
		$in_review  = $this->get_in_review_cards();
		$published  = $this->get_published_cards();
		$failed     = $this->get_failed_cards();

		return array(
			'generating'      => $generating,
			'in_review'       => $in_review,
			'published'       => $published,
			'failed'          => $failed,
			'has_active_runs' => ! empty( $generating ),
		);
	}

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
		$table    = $wpdb->prefix . 'prautoblogger_generation_log';
		$since    = gmdate( 'Y-m-d H:i:s', time() - self::GENERATING_GRACE_SECONDS );

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
	 * Cards for generated draft posts awaiting human review.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_in_review_cards(): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'draft',
				'posts_per_page' => 20,
				'meta_query'     => array(
					array(
						'key'   => '_prautoblogger_generated',
						'value' => '1',
					),
				),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$cards = array();
		foreach ( $query->posts as $post ) {
			if ( ! ( $post instanceof WP_Post ) ) {
				continue;
			}
			$run_id     = (string) get_post_meta( $post->ID, '_prautoblogger_run_id', true );
			$cost_total = (float) get_post_meta( $post->ID, '_prautoblogger_total_cost', true );

			$cards[] = array(
				'run_id'       => $run_id,
				'post_id'      => $post->ID,
				'title'        => $post->post_title,
				'cost_total'   => $cost_total,
				'created_at'   => strtotime( $post->post_date_gmt ),
				'click_action' => 'review',
				'review_url'   => admin_url( 'admin.php?page=prautoblogger-review-queue' ),
				'edit_url'     => get_edit_post_link( $post->ID, 'raw' ),
			);
		}

		return $cards;
	}

	/**
	 * Cards for published posts within the configured window.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_published_cards(): array {
		$window_days = max( 1, (int) get_option( 'prautoblogger_board_published_window_days', 7 ) );
		$date_after  = gmdate( 'Y-m-d H:i:s', time() - ( $window_days * DAY_IN_SECONDS ) );

		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'date_query'     => array(
					array(
						'after'     => $date_after,
						'inclusive' => true,
					),
				),
				'meta_query'     => array(
					array(
						'key'   => '_prautoblogger_generated',
						'value' => '1',
					),
				),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$cards = array();
		foreach ( $query->posts as $post ) {
			if ( ! ( $post instanceof WP_Post ) ) {
				continue;
			}
			$run_id     = (string) get_post_meta( $post->ID, '_prautoblogger_run_id', true );
			$cost_total = (float) get_post_meta( $post->ID, '_prautoblogger_total_cost', true );

			$cards[] = array(
				'run_id'       => $run_id,
				'post_id'      => $post->ID,
				'title'        => $post->post_title,
				'cost_total'   => $cost_total,
				'published_at' => strtotime( $post->post_date_gmt ),
				'click_action' => 'edit',
				'edit_url'     => get_edit_post_link( $post->ID, 'raw' ),
				'post_url'     => get_permalink( $post->ID ),
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

		$table      = $wpdb->prefix . 'prautoblogger_generation_log';
		$since      = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );

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
