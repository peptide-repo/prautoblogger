<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * AJAX handler: per-run stage inspector for the Mission Brief board (M5).
 *
 * What: Returns the full per-stage I/O breakdown for a given run_id,
 *   structured for the board's right-rail inspector. Reuses
 *   PRAutoBlogger_Gen_History_Query::get_run_io() (the M4 data layer)
 *   and PRAutoBlogger_Gen_History_Query::get_run_meta() verbatim --
 *   no new DB queries.
 *
 * The inspector surfaces prompts and responses. SECURITY is enforced here:
 *   - Authorization headers are NEVER logged (provider build_body() is
 *     captured before header injection -- see ARCHITECTURE.md §Request_Recorder).
 *   - manage_options capability required.
 *   - Nonce: 'prautoblogger_board' (shared with board AJAX, same page).
 *   - All string values esc_html'd before JSON output; JS renders via
 *     textContent, never innerHTML (structurally prevents XSS).
 *   - Read-only -- no writes.
 *
 * Triggered by: board.js on run-row click (right-rail fetch).
 * Dependencies: PRAutoBlogger_Gen_History_Query, PRAutoBlogger_Board_Page,
 *               PRAutoBlogger_Stage_Display_Map, PRAutoBlogger_Dossier_Page.
 *
 * @see admin/class-board-page.php          -- Registers this handler; supplies nonce.
 * @see admin/class-gen-history-query.php   -- Data layer (get_run_io, get_run_meta).
 * @see core/class-stage-display-map.php   -- Stage label resolution.
 * @see assets/js/board.js                  -- Client trigger (row-click inspector fetch).
 * @see ARCHITECTURE.md                     -- §Board (M5 Mission Brief).
 */
class PRAutoBlogger_Board_Inspector_Handler {

	/** AJAX action identifier. */
	public const ACTION = 'prautoblogger_board_inspector';

	/**
	 * Register wp-ajax hook.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Handle the inspector AJAX request.
	 *
	 * POST fields:
	 *   nonce   string  wp_nonce for 'prautoblogger_board'.
	 *   run_id  string  Pipeline run UUID.
	 *
	 * Success response JSON:
	 * {
	 *   run: { run_id, status, settled_usd, started_at, post_id,
	 *          post_title, dossier_url },
	 *   stages: [
	 *     { stage, label, model, agent_role, prompt_tokens,
	 *       completion_tokens, estimated_cost, response_status,
	 *       error_message, input_system, input_user,
	 *       output, output_pruned }
	 *   ],
	 *   cost_total: float
	 * }
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ),
				403
			);
			return;
		}

		check_ajax_referer( PRAutoBlogger_Board_Page::NONCE_ACTION, 'nonce' );

		$run_id = isset( $_POST['run_id'] )
			? sanitize_text_field( wp_unslash( $_POST['run_id'] ) )
			: '';

		if ( '' === $run_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing run_id.', 'prautoblogger' ) ), 400 );
		}

		$query    = new PRAutoBlogger_Gen_History_Query();
		$run_meta = $query->get_run_meta( $run_id );

		if ( null === $run_meta ) {
			// For runs that exist only in gen_log (no runs table row), build minimal meta.
			$run_meta = array(
				'run_id'      => $run_id,
				'status'      => 'unknown',
				'settled_usd' => 0,
				'started_at'  => '',
				'finished_at' => '',
				'post_id'     => 0,
				'post_title'  => '',
			);
		}

		$post_id     = (int) ( $run_meta['post_id'] ?? 0 );
		$dossier_url = $post_id > 0
			? PRAutoBlogger_Dossier_Page::url_for_post( $post_id )
			: '';

		$run_payload = array(
			'run_id'      => esc_html( (string) ( $run_meta['run_id'] ?? '' ) ),
			'status'      => esc_html( (string) ( $run_meta['status'] ?? '' ) ),
			'settled_usd' => round( (float) ( $run_meta['settled_usd'] ?? 0 ), 6 ),
			'started_at'  => esc_html( (string) ( $run_meta['started_at'] ?? '' ) ),
			'post_id'     => $post_id,
			'post_title'  => esc_html( (string) ( $run_meta['post_title'] ?? '' ) ),
			'dossier_url' => esc_url( $dossier_url ),
		);

		$raw_stages = $query->get_run_io( $run_id );
		$stages     = array();
		$cost_total = 0.0;

		foreach ( $raw_stages as $stage ) {
			$cost_total += (float) ( $stage['estimated_cost'] ?? 0 );
			$stages[]    = array(
				'stage'             => esc_html( (string) ( $stage['stage'] ?? '' ) ),
				'label'             => esc_html( PRAutoBlogger_Stage_Display_Map::label( (string) ( $stage['stage'] ?? '' ) ) ),
				'model'             => esc_html( (string) ( $stage['model'] ?? '' ) ),
				'agent_role'        => esc_html( (string) ( $stage['agent_role'] ?? '' ) ),
				'prompt_tokens'     => (int) ( $stage['prompt_tokens'] ?? 0 ),
				'completion_tokens' => (int) ( $stage['completion_tokens'] ?? 0 ),
				'estimated_cost'    => round( (float) ( $stage['estimated_cost'] ?? 0 ), 6 ),
				'response_status'   => esc_html( (string) ( $stage['response_status'] ?? '' ) ),
				'error_message'     => esc_html( (string) ( $stage['error_message'] ?? '' ) ),
				// JS renders via textContent -- no innerHTML XSS risk.
				'input_system'      => null !== $stage['input_system']
					? esc_html( (string) $stage['input_system'] ) : null,
				'input_user'        => null !== $stage['input_user']
					? esc_html( (string) $stage['input_user'] ) : null,
				'output'            => null !== $stage['output']
					? esc_html( (string) $stage['output'] ) : null,
				'output_pruned'     => (bool) ( $stage['output_pruned'] ?? false ),
			);
		}

		wp_send_json_success(
			array(
				'run'        => $run_payload,
				'stages'     => $stages,
				'cost_total' => round( $cost_total, 6 ),
			)
		);
	}
}
