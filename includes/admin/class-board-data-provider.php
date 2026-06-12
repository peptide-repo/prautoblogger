<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Supplies card data for the kanban board page from existing DB tables.
 *
 * Orchestrates the four board columns:
 *   Generating | In Review | Published | Failed
 *
 * Generation-log queries (Generating, Failed) are delegated to
 * PRAutoBlogger_Board_Gen_Log_Query. WP_Query columns (In Review, Published)
 * are handled here.
 *
 * M2: cards now include `dossier_url` so board.js can deep-link to the
 * Article Dossier instead of the old per-column destinations.
 *
 * No schema changes -- all data comes from tables that already exist.
 *
 * Triggered by: PRAutoBlogger_Board_Page::on_ajax_board_status().
 * Dependencies: PRAutoBlogger_Board_Gen_Log_Query, PRAutoBlogger_Dossier_Page,
 *               WordPress WP_Query, get_option().
 *
 * @see admin/class-board-gen-log-query.php -- Raw gen_log queries (Generating/Failed).
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
	 * M2: cards include dossier_url for deep-linking.
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

		return $cards;
	}

	/**
	 * Cards for published posts within the configured window.
	 * M2: cards include dossier_url for deep-linking.
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

		return $cards;
	}
}
