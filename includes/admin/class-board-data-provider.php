<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Supplies run-list data for the Mission Brief board (M5).
 *
 * Orchestrates the four status sections:
 *   Generating | In Review | Published | Failed
 *
 * M5 additions (v0.27.0): each card now includes `run_stages_summary`
 * -- a lightweight array of { stage, status } entries derived from the
 * run_stages table, used by the board's dot-rail (stage-progress indicator).
 * The inspector's full I/O is fetched separately via Board_Inspector_Handler
 * on row-click (not loaded up-front for every card).
 *
 * Generation-log queries (Generating, Failed) are delegated to
 * PRAutoBlogger_Board_Gen_Log_Query. WP_Query sections (In Review, Published)
 * are handled here.
 *
 * No schema changes -- all data comes from tables that already exist.
 *
 * Triggered by: PRAutoBlogger_Board_Page::on_ajax_board_status().
 * Dependencies: PRAutoBlogger_Board_Gen_Log_Query, PRAutoBlogger_Board_Stage_Dots,
 *               PRAutoBlogger_Dossier_Page, WordPress WP_Query, get_option().
 *
 * @see admin/class-board-gen-log-query.php -- Raw gen_log queries (Generating/Failed).
 * @see admin/class-board-stage-dots.php      -- Stage dot-rail enrichment (M5).
 * @see admin/class-board-page.php          -- Calls get_board_snapshot().
 * @see admin/class-dossier-page.php        -- Provides url_for_post() for deep-links.
 * @see ARCHITECTURE.md                     -- Database schema.
 */
class PRAutoBlogger_Board_Data_Provider {

	/** @var PRAutoBlogger_Board_Gen_Log_Query Handles raw gen_log DB queries. */
	private PRAutoBlogger_Board_Gen_Log_Query $gen_log_query;

	/**
	 * @param PRAutoBlogger_Board_Gen_Log_Query|null $gen_log_query Optional injection for testing.
	 */
	public function __construct( ?PRAutoBlogger_Board_Gen_Log_Query $gen_log_query = null ) {
		$this->gen_log_query = $gen_log_query ?? new PRAutoBlogger_Board_Gen_Log_Query();
	}

	/**
	 * Return the full board snapshot consumed by the AJAX poller.
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
		$generating = $this->gen_log_query->get_generating_cards();
		$in_review  = $this->get_in_review_cards();
		$published  = $this->get_published_cards();
		$failed     = $this->gen_log_query->get_failed_cards();

		// Enrich cards with lightweight stage dots.
		$generating = PRAutoBlogger_Board_Stage_Dots::enrich( $generating );
		$in_review  = PRAutoBlogger_Board_Stage_Dots::enrich( $in_review );
		$published  = PRAutoBlogger_Board_Stage_Dots::enrich( $published );
		$failed     = PRAutoBlogger_Board_Stage_Dots::enrich( $failed );

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
	 * @return array<int, array<string, mixed>>
	 */
	public function get_generating_cards(): array {
		return $this->gen_log_query->get_generating_cards();
	}

	/**
	 * Cards for generation runs that ended in an error state.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_failed_cards(): array {
		return $this->gen_log_query->get_failed_cards();
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
				'click_action' => 'dossier',
				'dossier_url'  => PRAutoBlogger_Dossier_Page::url_for_post( $post->ID ),
				'review_url'   => admin_url( 'admin.php?page=prautoblogger-review-queue' ),
				'edit_url'     => get_edit_post_link( $post->ID, 'raw' ),
			);
		}

		return $this->flag_human_modified( $cards );
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
				'click_action' => 'dossier',
				'dossier_url'  => PRAutoBlogger_Dossier_Page::url_for_post( $post->ID ),
				'edit_url'     => get_edit_post_link( $post->ID, 'raw' ),
				'post_url'     => get_permalink( $post->ID ),
			);
		}

		return $this->flag_human_modified( $cards );
	}

	/**
	 * Set 'human_modified' on each card in ONE batched query (v0.20.0).
	 * No per-card queries; self-healing on a missing table/column.
	 *
	 * @param array<int, array<string, mixed>> $cards Column cards.
	 * @return array<int, array<string, mixed>> Cards with the flag set.
	 */
	private function flag_human_modified( array $cards ): array {
		$run_ids = array();
		foreach ( $cards as $i => $card ) {
			$cards[ $i ]['human_modified'] = false;
			if ( ! empty( $card['run_id'] ) ) {
				$run_ids[] = (string) $card['run_id'];
			}
		}
		if ( empty( $run_ids ) || ! PRAutoBlogger_Run_Stage_State::is_available() ) {
			return $cards;
		}
		global $wpdb;
		$table        = PRAutoBlogger_Run_Stage_State::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $run_ids ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $placeholders is a fixed %s list.
		$flagged = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT run_id FROM {$table} WHERE human_modified = 1 AND run_id IN ({$placeholders})",
				$run_ids
			)
		);
		if ( ! is_array( $flagged ) || empty( $flagged ) ) {
			return $cards;
		}
		$flagged = array_flip( array_map( 'strval', $flagged ) );
		foreach ( $cards as $i => $card ) {
			if ( isset( $flagged[ (string) ( $card['run_id'] ?? '' ) ] ) ) {
				$cards[ $i ]['human_modified'] = true;
			}
		}
		return $cards;
	}
}
