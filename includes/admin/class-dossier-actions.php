<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * AJAX endpoints for the dossier's edit + re-run actions (v0.20.0, M3).
 *
 * What: Four manage_options + nonce gated endpoints. The mutation
 *       endpoints follow the chained-cron contract strictly: save_input
 *       only INSERTs an immutable fork version; the two rerun endpoints
 *       only validate + queue (Rerun_Job_Support::queue) — execution and
 *       ALL run/stage state mutations happen in the cron handler after
 *       re-validation under the generation lock. Run identity (run_id /
 *       item_key) is always derived server-side from the post's meta —
 *       never trusted from the client; the client only names the stage
 *       row it acted on, which is validated against an existing row.
 *       stage_status is the read-only poll the dossier JS uses to show
 *       queued -> pickup -> result.
 * Who triggers it: assets/js/dossier-edit.js via admin-ajax.php;
 *       registered in class-prautoblogger.php.
 * Dependencies: Rerun_Eligibility, Stage_Input_Store, Stage_Replay
 *       (decode), Rerun_Job_Support/Executor, Run_Stage_State.
 *
 * @see assets/js/dossier-edit.js     — The frontend caller.
 * @see core/class-rerun-executor.php — The cron handlers jobs land in.
 * @see ARCHITECTURE.md #24           — Edit + re-run design.
 */
class PRAutoBlogger_Dossier_Actions {

	/** Nonce action shared by all four endpoints. */
	public const NONCE_ACTION = 'prautoblogger_dossier_actions';

	/**
	 * AJAX: save an edited stage input as a new immutable fork version.
	 *
	 * The fork body is the stage's base request (latest fork, else the
	 * recorded request_json) with ONLY the message contents substituted —
	 * roles, message count, model and call options are not editable, so
	 * a fork is always a well-formed replayable chat body. Prompt text
	 * is stored raw (it is LLM input, never rendered unescaped).
	 *
	 * Side effects: one stage_inputs INSERT.
	 *
	 * @return void Sends JSON.
	 */
	public function on_save_input(): void {
		$request = $this->authorize_and_resolve();

		$check = PRAutoBlogger_Rerun_Eligibility::check( $request['run_id'], $request['post_id'] );
		if ( ! PRAutoBlogger_Rerun_Eligibility::is_editable_stage( $request['stage'] ) || ! $check['ok'] ) {
			$reason = $check['ok'] ? __( 'This stage cannot be replayed with an edited input.', 'prautoblogger' ) : $check['reason'];
			wp_send_json_error( array( 'message' => $reason ), 400 );
			return;
		}

		$base_body = $this->resolve_base_body( $request );
		if ( null === $base_body ) {
			wp_send_json_error( array( 'message' => __( 'No persisted input exists for this stage.', 'prautoblogger' ) ), 400 );
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw prompt text for the LLM; structure-validated below, stored raw, escaped at render.
		$raw      = isset( $_POST['messages'] ) ? wp_unslash( (string) $_POST['messages'] ) : '';
		$messages = json_decode( $raw, true );
		$merged   = $this->merge_messages( $base_body, is_array( $messages ) ? $messages : null );
		if ( null === $merged ) {
			wp_send_json_error( array( 'message' => __( 'Edited messages must match the original structure (same count and roles, non-empty contents).', 'prautoblogger' ) ), 400 );
			return;
		}

		$user    = wp_get_current_user();
		$version = PRAutoBlogger_Stage_Input_Store::save_fork(
			$request['run_id'],
			$request['stage'],
			$request['agent_role'],
			$request['item_key'],
			(string) wp_json_encode( $merged ),
			(string) $user->user_login
		);
		if ( null === $version ) {
			wp_send_json_error( array( 'message' => __( 'The edited input could not be saved.', 'prautoblogger' ) ), 500 );
			return;
		}

		wp_send_json_success(
			array(
				'version' => $version,
				'message' => sprintf(
					/* translators: %d: fork version number. */
					__( 'Saved as version %d. The original input is preserved unchanged; re-running this stage will use your edited version and mark all downstream stages stale.', 'prautoblogger' ),
					$version
				),
			)
		);
	}

	/**
	 * AJAX: queue a single-stage replay of the latest edited fork.
	 *
	 * Validates, then ONLY schedules — chained-cron semantics. The UI
	 * copy never implies synchronous execution.
	 *
	 * @return void Sends JSON.
	 */
	public function on_rerun_stage(): void {
		$request = $this->authorize_and_resolve();

		$check = PRAutoBlogger_Rerun_Eligibility::check_replay(
			$request['run_id'],
			$request['post_id'],
			$request['stage'],
			$request['agent_role'],
			$request['item_key']
		);
		if ( ! $check['ok'] ) {
			wp_send_json_error( array( 'message' => $check['reason'] ), 400 );
			return;
		}

		PRAutoBlogger_Rerun_Job_Support::queue(
			PRAutoBlogger_Rerun_Executor::REPLAY_ACTION,
			array( $request['run_id'], $request['post_id'], $request['stage'], $request['agent_role'], $request['item_key'] ),
			sprintf(
				/* translators: %s: stage display label. */
				__( 'Queued: re-running %s with the edited input…', 'prautoblogger' ),
				PRAutoBlogger_Stage_Display_Map::label( $request['stage'] )
			)
		);

		wp_send_json_success(
			array(
				'queued'  => true,
				'message' => __( 'Re-run queued. The pipeline will pick it up shortly — progress appears here and on the board.', 'prautoblogger' ),
			)
		);
	}

	/**
	 * AJAX: queue re-run-from-here (rebuild this stage + downstream).
	 *
	 * @return void Sends JSON.
	 */
	public function on_rerun_from(): void {
		$request = $this->authorize_and_resolve();

		$check = PRAutoBlogger_Rerun_Eligibility::check_rebuild(
			$request['run_id'],
			$request['post_id'],
			$request['stage'],
			$request['item_key']
		);
		if ( ! $check['ok'] ) {
			wp_send_json_error( array( 'message' => $check['reason'] ), 400 );
			return;
		}

		PRAutoBlogger_Rerun_Job_Support::queue(
			PRAutoBlogger_Rerun_Executor::REBUILD_ACTION,
			array( $request['run_id'], $request['post_id'], $request['stage'], $request['item_key'] ),
			sprintf(
				/* translators: %s: stage display label. */
				__( 'Queued: re-running the pipeline from %s…', 'prautoblogger' ),
				PRAutoBlogger_Stage_Display_Map::label( $request['stage'] )
			)
		);

		wp_send_json_success(
			array(
				'queued'  => true,
				'message' => __( 'Re-run from here queued. Stages will rebuild in sequence — progress appears here and on the board.', 'prautoblogger' ),
			)
		);
	}

	/**
	 * AJAX: read-only stage-status poll for the dossier.
	 *
	 * @return void Sends JSON: run status, per-stage state, live message.
	 */
	public function on_stage_status(): void {
		$request = $this->authorize_and_resolve( false );

		$stages = array();
		foreach ( PRAutoBlogger_Run_Stage_State::stages_for_item( $request['run_id'], $request['item_key'] ) as $row ) {
			$stages[] = array(
				'stage'          => (string) ( $row['stage'] ?? '' ),
				'agent_role'     => (string) ( $row['agent_role'] ?? '' ),
				'status'         => (string) ( $row['status'] ?? '' ),
				'stale'          => ! empty( $row['stale'] ),
				'human_modified' => ! empty( $row['human_modified'] ),
				'attempt'        => (int) ( $row['attempt'] ?? 1 ),
				'finished_at'    => (string) ( $row['finished_at'] ?? '' ),
			);
		}

		$live = get_transient( PRAutoBlogger_Generation_Status_Poller::STATUS_TRANSIENT );

		wp_send_json_success(
			array(
				'run_status' => (string) ( PRAutoBlogger_Run_State::get_status( $request['run_id'] ) ?? '' ),
				'stages'     => $stages,
				'live'       => is_array( $live ) ? $live : null,
			)
		);
	}

	/**
	 * Shared gate: nonce + capability, then derive run identity from the
	 * POSTed post_id server-side. Sends a JSON error (and exits) on any
	 * failure.
	 *
	 * @param bool $require_stage Whether the request must name a stage row.
	 * @return array{post_id: int, run_id: string, item_key: string, stage: string, agent_role: string}
	 */
	private function authorize_and_resolve( bool $require_stage = true ): array {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ), 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$run_id  = $post_id > 0 ? (string) get_post_meta( $post_id, '_prautoblogger_run_id', true ) : '';
		if ( 0 === $post_id || '' === $run_id ) {
			wp_send_json_error( array( 'message' => __( 'No generation run is recorded for this article.', 'prautoblogger' ) ), 400 );
		}
		$idea_hash = (string) get_post_meta( $post_id, '_prautoblogger_idea_hash', true );
		$item_key  = '' !== $idea_hash ? 'idea:' . $idea_hash : '';

		$stage      = isset( $_POST['stage'] ) ? sanitize_key( (string) wp_unslash( $_POST['stage'] ) ) : '';
		$agent_role = isset( $_POST['agent_role'] ) ? sanitize_key( (string) wp_unslash( $_POST['agent_role'] ) ) : '';
		if ( $require_stage && '' === $stage ) {
			wp_send_json_error( array( 'message' => __( 'No stage specified.', 'prautoblogger' ) ), 400 );
		}

		return array(
			'post_id'    => $post_id,
			'run_id'     => $run_id,
			'item_key'   => $item_key,
			'stage'      => $stage,
			'agent_role' => $agent_role,
		);
	}

	/**
	 * The base body an edit forks from: latest saved fork, else the
	 * stage's recorded request_json.
	 *
	 * @param array<string, mixed> $request Resolved request context.
	 * @return array<string, mixed>|null Decoded body, or null when absent.
	 */
	private function resolve_base_body( array $request ): ?array {
		$fork = PRAutoBlogger_Stage_Input_Store::latest_fork( $request['run_id'], $request['stage'], $request['agent_role'], $request['item_key'] );
		if ( null !== $fork && ! empty( $fork['request_json'] ) ) {
			return PRAutoBlogger_Stage_Replay::decode_body( (string) $fork['request_json'] );
		}
		$log_rows = ( new PRAutoBlogger_Dossier_Gen_Log_Query() )->get_by_run( $request['run_id'] );
		$rows     = $log_rows[ $request['stage'] ] ?? array();
		foreach ( array_reverse( $rows ) as $row ) {
			if ( ! empty( $row['request_json'] ) ) {
				$body = PRAutoBlogger_Stage_Replay::decode_body( (string) $row['request_json'] );
				if ( null !== $body ) {
					return $body;
				}
			}
		}
		return null;
	}

	/**
	 * Substitute edited message contents into the base body. Structure
	 * is locked: same message count, same roles, all contents non-empty
	 * strings — so every fork stays a replayable chat body.
	 *
	 * @param array<string, mixed>     $base     Decoded base body.
	 * @param array<int, mixed>|null   $messages Edited messages payload.
	 * @return array<string, mixed>|null Merged body, or null when invalid.
	 */
	private function merge_messages( array $base, ?array $messages ): ?array {
		if ( null === $messages || count( $messages ) !== count( $base['messages'] ) ) {
			return null;
		}
		$merged = $base;
		foreach ( array_values( $messages ) as $i => $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'], $message['content'] )
				|| ! is_string( $message['role'] ) || ! is_string( $message['content'] ) ) {
				return null;
			}
			if ( ( $base['messages'][ $i ]['role'] ?? null ) !== $message['role'] ) {
				return null;
			}
			if ( '' === trim( $message['content'] ) ) {
				return null;
			}
			$merged['messages'][ $i ]['content'] = $message['content'];
		}
		return $merged;
	}
}
