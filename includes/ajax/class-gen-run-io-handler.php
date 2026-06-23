<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * AJAX handler: per-run stage input/output drill-down (M4).
 *
 * What: Returns every stage's full INPUT (assembled instruction sent to
 *   the LLM, extracted from generation_log.request_json) and full OUTPUT
 *   (model response, from run_stages.meta_json) for a given run_id. Also
 *   returns per-stage model, tokens, cost, and response status. Stage
 *   ordering follows generation_log insertion order (ASC by id), which
 *   matches pipeline execution order.
 *
 *   INPUT COVERAGE: request_json stores the full chat body (model,
 *   messages[], temperature, …) minus the Authorization header — the
 *   Request_Recorder captures the body built by build_body() BEFORE the
 *   header is added. Specifically:
 *     - input_system: the 'system' role message content (prompt template).
 *     - input_user: the last 'user' role message content (rendered
 *       instruction with token substitutions). Both may be null for
 *       image/non-chat stages where request_json is absent or has a
 *       different shape.
 *
 *   OUTPUT COVERAGE: run_stages.meta_json.output holds the model's raw
 *   text response (written by Run_Stage_State::done() / Run_Stage_Writes).
 *   When meta_json is NULL (pruned after retention_days) or has no
 *   'output' key, output_pruned=true is returned so the UI can explain
 *   the gap honestly rather than showing a blank field.
 *   Stages that exist only in generation_log (image_a, image_b,
 *   llm_research, image_prompt_rewrite — "log-only" stages) may have
 *   no run_stages row at all; their output is null without pruned=true.
 *
 * SECURITY:
 *   - manage_options capability required.
 *   - Nonce: prautoblogger_gen_run_io, verified with check_ajax_referer().
 *   - run_id sanitised with sanitize_text_field().
 *   - All string values returned as plain text; the JS renders via
 *     textContent (not innerHTML) so XSS via prompt/response data is
 *     structurally impossible even if escaping were absent (it is not).
 *   - No writes — read-only.
 *
 * Triggered by: gen-history.js on "Stage I/O" toggle click.
 * Dependencies: PRAutoBlogger_Gen_History_Query (data layer).
 *
 * @see admin/class-gen-history-query.php -- Data source.
 * @see admin/class-gen-history-page.php  -- Page that localises the nonce.
 * @see assets/js/gen-history.js          -- Client trigger.
 * @see ARCHITECTURE.md                    -- generation_log + run_stages schema.
 */
class PRAutoBlogger_Gen_Run_IO_Handler {

	/** AJAX action identifier. */
	public const ACTION = 'prautoblogger_gen_run_io';

	/**
	 * Register wp-ajax hook.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Handle the AJAX drill-down request.
	 *
	 * POST fields:
	 *   nonce   string  wp_nonce for 'prautoblogger_gen_run_io'.
	 *   run_id  string  Pipeline run UUID.
	 *
	 * Success response: {
	 *   run: { run_id, status, settled_usd, started_at, finished_at,
	 *           post_id, post_title, dossier_url },
	 *   stages: [ { stage, label, model, agent_role, prompt_tokens,
	 *               completion_tokens, estimated_cost, response_status,
	 *               error_message, created_at,
	 *               input_system, input_user,
	 *               output, output_pruned } ]
	 * }
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ), 403 );
		}

		check_ajax_referer( PRAutoBlogger_Gen_History_Page::IO_NONCE_ACTION, 'nonce' );

		$run_id = isset( $_POST['run_id'] )
			? sanitize_text_field( wp_unslash( $_POST['run_id'] ) )
			: '';

		if ( '' === $run_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing run_id.', 'prautoblogger' ) ), 400 );
		}

		$query = new PRAutoBlogger_Gen_History_Query();

		// Run metadata.
		$run_meta = $query->get_run_meta( $run_id );
		if ( null === $run_meta ) {
			wp_send_json_error( array( 'message' => __( 'Run not found.', 'prautoblogger' ) ), 404 );
		}

		// Build the dossier URL when there is a linked post.
		$post_id    = (int) ( $run_meta['post_id'] ?? 0 );
		$dossier_url = $post_id > 0
			? PRAutoBlogger_Dossier_Page::url_for_post( $post_id )
			: '';

		$run_payload = array(
			'run_id'      => esc_html( (string) $run_meta['run_id'] ),
			'status'      => esc_html( (string) $run_meta['status'] ),
			'settled_usd' => round( (float) ( $run_meta['settled_usd'] ?? 0 ), 6 ),
			'started_at'  => esc_html( (string) ( $run_meta['started_at'] ?? '' ) ),
			'finished_at' => esc_html( (string) ( $run_meta['finished_at'] ?? '' ) ),
			'post_id'     => $post_id,
			'post_title'  => esc_html( (string) ( $run_meta['post_title'] ?? '' ) ),
			'dossier_url' => esc_url( $dossier_url ),
		);

		// Per-stage I/O.
		$raw_stages = $query->get_run_io( $run_id );
		$stages     = array();

		foreach ( $raw_stages as $stage ) {
			$stages[] = array(
				// Label derived from Stage_Display_Map for UI coherence.
				'stage'             => esc_html( $stage['stage'] ),
				'label'             => esc_html( PRAutoBlogger_Stage_Display_Map::label( $stage['stage'] ) ),
				'model'             => esc_html( $stage['model'] ),
				'agent_role'        => esc_html( $stage['agent_role'] ),
				'prompt_tokens'     => (int) $stage['prompt_tokens'],
				'completion_tokens' => (int) $stage['completion_tokens'],
				'estimated_cost'    => round( (float) $stage['estimated_cost'], 6 ),
				'response_status'   => esc_html( $stage['response_status'] ),
				'error_message'     => esc_html( $stage['error_message'] ),
				'created_at'        => esc_html( $stage['created_at'] ),
				// Input: null when stage has no request_json (e.g. image stages).
				// JS renders via textContent — no innerHTML escaping risk.
				'input_system'      => null !== $stage['input_system']
					? esc_html( $stage['input_system'] ) : null,
				'input_user'        => null !== $stage['input_user']
					? esc_html( $stage['input_user'] ) : null,
				// Output: null when no run_stages row (log-only stages).
				// output_pruned=true when meta_json exists but 'output' is absent (pruned).
				'output'            => null !== $stage['output']
					? esc_html( $stage['output'] ) : null,
				'output_pruned'     => (bool) $stage['output_pruned'],
			);
		}

		wp_send_json_success(
			array(
				'run'    => $run_payload,
				'stages' => $stages,
			)
		);
	}
}
