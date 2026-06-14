<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Admin page listing analysis results (article ideas) with per-idea generation.
 *
 * Triggered by: PRAutoBlogger::register_admin_hooks() on `admin_menu`.
 * Dependencies: WordPress $wpdb, Article_Worker, Cost_Tracker, Generation_Lock.
 * Query helpers: class-ideas-browser-query.php (split for 300-line rule, CONVENTIONS §1).
 *
 * @see core/class-article-worker.php         — Generates articles from ideas.
 * @see templates/admin/ideas-browser.php     — Renders the HTML.
 * @see class-ideas-browser-query.php         — Static query helpers.
 */
class PRAutoBlogger_Ideas_Browser {

	/** Register the Ideas submenu page under PRAutoBlogger. */
	public function on_register_menu(): void {
		add_submenu_page(
			'prautoblogger-settings',
			__( 'Ideas', 'prautoblogger' ),
			__( 'Ideas', 'prautoblogger' ),
			'manage_options',
			'prautoblogger-ideas',
			array( $this, 'render_page' )
		);
	}

	/** Render the ideas browser page. */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$paged       = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$type_filter = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';

		$result        = PRAutoBlogger_Ideas_Browser_Query::query_ideas( $paged, $type_filter );
		$rows          = $result['rows'];
		$total         = $result['total'];
		$per_page_view = max( 5, (int) get_option( 'prautoblogger_ideas_per_page', PRAutoBlogger_Ideas_Browser_Query::PER_PAGE_DEFAULT ) );
		$total_pages   = (int) ceil( $total / $per_page_view );
		$type_counts   = PRAutoBlogger_Ideas_Browser_Query::get_type_counts();

		include PRAUTOBLOGGER_PLUGIN_DIR . 'templates/admin/ideas-browser.php';
	}

	/**
	 * AJAX: trigger article generation from a specific idea.
	 *
	 * Loads the analysis result, stores it as a transient, sets a per-idea
	 * status transient, and schedules a one-shot cron event so the actual
	 * generation runs in a separate PHP process (Hostinger 120s limit).
	 *
	 * Side effects: transient writes, cron scheduling.
	 */
	public function on_ajax_generate_from_idea(): void {
		check_ajax_referer( 'prautoblogger_idea_gen', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
			return;
		}

		$idea_id = absint( $_POST['idea_id'] ?? 0 );
		if ( $idea_id < 1 ) {
			wp_send_json_error( array( 'message' => 'Invalid idea ID.' ) );
			return;
		}

		$status_prefix = PRAutoBlogger_Ideas_Browser_Query::STATUS_PREFIX;
		$status_ttl    = PRAutoBlogger_Ideas_Browser_Query::STATUS_TTL;

		// Build Article_Idea from the analysis row.
		$idea_data = PRAutoBlogger_Ideas_Browser_Query::load_idea_data( $idea_id );
		if ( null === $idea_data ) {
			wp_send_json_error( array( 'message' => 'Idea not found.' ) );
			return;
		}

		// Store idea for the cron handler and set initial "running" status.
		set_transient( $status_prefix . 'data_' . $idea_id, $idea_data, $status_ttl );
		set_transient(
			$status_prefix . $idea_id,
			array(
				'status'  => 'running',
				'stage'   => __( 'Starting generation…', 'prautoblogger' ),
				'started' => time(),
			),
			$status_ttl
		);

		// Schedule background cron — passes idea_id as argument.
		$hook = 'prautoblogger_generate_from_idea';
		if ( ! wp_next_scheduled( $hook, array( $idea_id ) ) ) {
			wp_schedule_single_event( time(), $hook, array( $idea_id ) );
		}
		spawn_cron();
		wp_remote_post(
			site_url( 'wp-cron.php?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
			)
		);

		wp_send_json_success( array( 'message' => 'Generation started.' ) );
	}

	/**
	 * AJAX: return per-idea generation status for frontend polling.
	 *
	 * Side effects: none (reads transient only).
	 */
	public function on_ajax_idea_gen_status(): void {
		check_ajax_referer( 'prautoblogger_idea_gen', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
			return;
		}

		$idea_id = absint( $_POST['idea_id'] ?? 0 );
		$status  = get_transient( PRAutoBlogger_Ideas_Browser_Query::STATUS_PREFIX . $idea_id );
		if ( ! is_array( $status ) ) {
			wp_send_json_success( array( 'status' => 'idle' ) );
			return;
		}

		wp_send_json_success( $status );
	}

	/**
	 * Cron handler: generate a single article from a stored idea.
	 *
	 * Runs in a background PHP process. Acquires the generation lock,
	 * runs Article_Worker, and writes the result to the per-idea status transient.
	 *
	 * Side effects: LLM API calls, DB writes, post creation, cost logging.
	 *
	 * @param int $idea_id Analysis result row ID.
	 */
	public static function on_cron_generate_from_idea( int $idea_id ): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@ignore_user_abort( true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 300 );

		$prefix = PRAutoBlogger_Ideas_Browser_Query::STATUS_PREFIX;
		$ttl    = PRAutoBlogger_Ideas_Browser_Query::STATUS_TTL;
		$key    = $prefix . $idea_id;

		$idea_data = get_transient( $prefix . 'data_' . $idea_id );
		if ( ! is_array( $idea_data ) ) {
			set_transient(
				$key,
				array(
					'status' => 'error',
					'message' => 'Idea data expired.',
				),
				$ttl
			);
			return;
		}

		if ( ! PRAutoBlogger_Generation_Lock::acquire() ) {
			set_transient(
				$key,
				array(
					'status' => 'error',
					'message' => 'Another generation is running.',
				),
				$ttl
			);
			return;
		}

		try {
			$idea         = new PRAutoBlogger_Article_Idea( $idea_data );
			$cost_tracker = new PRAutoBlogger_Cost_Tracker();
			$cost_tracker->set_run_id( wp_generate_uuid4() );

			PRAutoBlogger_Ideas_Browser_Query::update_idea_stage( $idea_id, __( 'Generating article draft…', 'prautoblogger' ) );
			$worker = new PRAutoBlogger_Article_Worker( $cost_tracker );
			$result = $worker->generate( $idea );

			// Amortize any research costs for this single-article run.
			PRAutoBlogger_Post_Assembler::amortize_research_costs( $cost_tracker->get_run_id() );

			// Find the generated post ID for the "View" link.
			$post_id = PRAutoBlogger_Ideas_Browser_Query::find_post_by_run_id( $cost_tracker->get_run_id() );

			set_transient(
				$key,
				array(
					'status'    => 'complete',
					'generated' => $result['generated'],
					'published' => $result['published'],
					'cost'      => $result['cost'],
					'post_id'   => $post_id,
				),
				$ttl
			);
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Idea generation %s for #%d: %s', get_class( $e ), $idea_id, $e->getMessage() ),
				'pipeline'
			);
			set_transient(
				$key,
				array(
					'status' => 'error',
					'message' => $e->getMessage(),
				),
				$ttl
			);
		}

		PRAutoBlogger_Generation_Lock::release();
		delete_transient( $prefix . 'data_' . $idea_id );
	}
}
