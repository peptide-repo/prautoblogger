<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Admin page listing generated draft posts for editorial review.
 *
 * Provides approve (publish), edit (opens WP editor), and reject (trash)
 * actions on each draft. Supports bulk approve/reject via checkboxes.
 *
 * Triggered by: PRAutoBlogger::register_admin_hooks() on `admin_menu`.
 * Dependencies: WordPress WP_Query, post meta API.
 *
 * @see class-prautoblogger.php          — Registers the hook and AJAX handlers.
 * @see core/class-publisher.php       — Creates the drafts this page displays.
 * @see templates/admin/review-queue.php — Renders the HTML.
 */
class PRAutoBlogger_Review_Queue {

	/**
	 * Register the review queue submenu page under PRAutoBlogger.
	 *
	 * @return void
	 */
	public function on_register_menu(): void {
		$hook = add_submenu_page(
			'prautoblogger-settings',
			__( 'Review Queue', 'prautoblogger' ),
			__( 'Review Queue', 'prautoblogger' ),
			'manage_options',
			'prautoblogger-review-queue',
			array( $this, 'render_page' )
		);

		if ( false !== $hook ) {
			add_action( "load-{$hook}", array( $this, 'on_handle_bulk_actions' ) );
		}
	}

	/**
	 * Render the review queue page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$query = $this->get_pending_posts( $paged );

		// v0.18.0 — runs halted by the per-run cost governor are routed
		// here for human attention (they may have no draft post to list).
		$halted_runs = $this->get_halted_runs();

		include PRAUTOBLOGGER_PLUGIN_DIR . 'templates/admin/review-queue.php';
	}

	/**
	 * Runs halted by the per-run cost governor, newest first.
	 *
	 * @return array<int, array<string, mixed>> Halted run rows (max 10).
	 */
	public function get_halted_runs(): array {
		return PRAutoBlogger_Run_State::halted_runs( 10 );
	}

	/**
	 * Query generated draft posts awaiting review.
	 *
	 * @param int $paged Page number.
	 *
	 * @return \WP_Query
	 */
	public function get_pending_posts( int $paged = 1 ): \WP_Query {
		return new \WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'draft',
				'posts_per_page' => 20,
				'paged'          => $paged,
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
	}

	/**
	 * Handle bulk approve/reject actions submitted via form POST.
	 *
	 * Verifies nonce and capability before processing. Redirects back with
	 * a status message query arg.
	 *
	 * Side effects: changes post status, may trash posts.
	 *
	 * @return void
	 */
	public function on_handle_bulk_actions(): void {
		if ( ! isset( $_POST['prautoblogger_bulk_action'] ) ) {
			return;
		}

		check_admin_referer( 'prautoblogger_review_queue_bulk', 'prautoblogger_review_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action   = sanitize_text_field( wp_unslash( $_POST['prautoblogger_bulk_action'] ) );
		$post_ids = isset( $_POST['prautoblogger_post_ids'] ) && is_array( $_POST['prautoblogger_post_ids'] )
			? array_map( 'absint', $_POST['prautoblogger_post_ids'] )
			: array();

		if ( empty( $post_ids ) ) {
			return;
		}

		$count = 0;
		foreach ( $post_ids as $post_id ) {
			if ( $post_id <= 0 ) {
				continue;
			}

			if ( 'approve' === $action ) {
				$result = wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => 'publish',
					),
					true
				);
				if ( ! is_wp_error( $result ) ) {
					update_post_meta( $post_id, '_prautoblogger_approved_at', gmdate( 'c' ) );
					++$count;
				}
			} elseif ( 'reject' === $action ) {
				$result = wp_trash_post( $post_id );
				if ( false !== $result ) {
					++$count;
				}
			}
		}

		$redirect = add_query_arg(
			array(
				'page'                    => 'prautoblogger-review-queue',
				'prautoblogger_bulk_done' => $count,
				'prautoblogger_bulk_type' => $action,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * AJAX handler: approve a single post (publish it).
	 *
	 * Side effects: changes post status to 'publish'.
	 *
	 * @return void
	 */
	public function on_ajax_approve_post(): void {
		check_ajax_referer( 'prautoblogger_review_queue', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ), 403 );
			return;
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'prautoblogger' ) ) );
			return;
		}

		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}

		update_post_meta( $post_id, '_prautoblogger_approved_at', gmdate( 'c' ) );
		wp_send_json_success(
			array(
				'message' => __( 'Post published.', 'prautoblogger' ),
				'post_id' => $post_id,
			)
		);
	}

	/**
	 * AJAX handler: reject a single post (trash it).
	 *
	 * Side effects: trashes the post.
	 *
	 * @return void
	 */
	public function on_ajax_reject_post(): void {
		check_ajax_referer( 'prautoblogger_review_queue', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ), 403 );
			return;
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'prautoblogger' ) ) );
			return;
		}

		$result = wp_trash_post( $post_id );
		if ( false === $result || null === $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to reject post.', 'prautoblogger' ) ) );
			return;
		}

		wp_send_json_success(
			array(
				'message' => __( 'Post rejected.', 'prautoblogger' ),
				'post_id' => $post_id,
			)
		);
	}
}
