<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Executes one stage replay from a saved input fork (v0.20.0, M3).
 *
 * What: Decodes an immutable stage_inputs fork body back into the
 *       (messages, model, options) triple and sends it through the
 *       normal provider seam — so the cost governor's reserve-before-
 *       call (on the SAME run ledger row), the retry/backoff loop, the
 *       empty-completion guard, and the B1 request recorder all apply
 *       to replays exactly as to pipeline calls (CPO guardrail 4). The
 *       fresh output lands in the stage's run_stages snapshot (the same
 *       place downstream rebuilds read from) and a generation_log row
 *       is written with the replayed request body.
 *
 *       options_from_body() is the exact inverse of Request_Builder::
 *       build_body() + apply_reasoning_budget(): the v0.18.1 reasoning
 *       headroom is subtracted back out (the builder re-adds it), and
 *       reasoning is pinned explicitly — enabled:false when the original
 *       body had none — so a later change to the global reasoning
 *       setting can never alter the replay's shape.
 * Who triggers it: PRAutoBlogger_Rerun_Executor::on_replay_job() only
 *       (after eligibility re-validation, lock, reopen, restart and
 *       stale-marking).
 * Dependencies: OpenRouter_Provider, Cost_Tracker, Run_Stage_State,
 *               Audit_Writer, Stage_Display_Map.
 *
 * @see core/class-rerun-executor.php                 — Orchestration around this.
 * @see providers/class-open-router-request-builder.php — The forward transform.
 * @see ARCHITECTURE.md #24                           — Edit + re-run design.
 */
class PRAutoBlogger_Stage_Replay {

	/**
	 * Reconstruct provider options from a stored request body.
	 *
	 * Inverse of build_body()/apply_reasoning_budget(): copies
	 * temperature/response_format; recovers the caller's max_tokens by
	 * subtracting the reasoning cap the builder added as headroom; pins
	 * reasoning explicitly (the stored body is the contract — global
	 * setting drift must not leak in). Adds the caller-metadata keys
	 * (stage, prompt_key) used by the guard's logging — never sent
	 * upstream.
	 *
	 * @param array<string, mixed> $body  Decoded request body.
	 * @param string               $stage Stage being replayed.
	 * @return array<string, mixed> Options for send_chat_completion().
	 */
	public static function options_from_body( array $body, string $stage ): array {
		$options = array();
		if ( isset( $body['temperature'] ) ) {
			$options['temperature'] = $body['temperature'];
		}
		if ( isset( $body['response_format'] ) ) {
			$options['response_format'] = $body['response_format'];
		}

		$reasoning_active = isset( $body['reasoning'] ) && false !== ( $body['reasoning']['enabled'] ?? true );
		if ( $reasoning_active ) {
			$options['reasoning'] = $body['reasoning'];
			if ( isset( $body['max_tokens'] ) ) {
				$cap = isset( $body['reasoning']['max_tokens'] ) ? (int) $body['reasoning']['max_tokens'] : 0;
				// The builder raised max_tokens by the cap (completion
				// headroom); hand back the caller-level value so the
				// builder's re-application is not compounded.
				$options['max_tokens'] = max( 1, (int) $body['max_tokens'] - $cap );
			}
		} else {
			// Pin reasoning OFF (same per-call override shape the
			// empty-completion retry uses) so a globally-enabled
			// reasoning setting cannot reshape the replay.
			$options['reasoning'] = array( 'enabled' => false );
			if ( isset( $body['max_tokens'] ) ) {
				$options['max_tokens'] = (int) $body['max_tokens'];
			}
		}

		$options['stage']      = $stage;
		$options['prompt_key'] = PRAutoBlogger_Stage_Display_Map::default_prompt_key( $stage );

		return $options;
	}

	/**
	 * Decode + validate a fork body. Returns null when the JSON is not a
	 * replayable chat body (model string + non-empty messages list of
	 * role/content pairs).
	 *
	 * @param string $request_json Stored fork body.
	 * @return array<string, mixed>|null Decoded body, or null when invalid.
	 */
	public static function decode_body( string $request_json ): ?array {
		$body = json_decode( $request_json, true );
		if ( ! is_array( $body ) || empty( $body['model'] ) || ! is_string( $body['model'] ) ) {
			return null;
		}
		if ( empty( $body['messages'] ) || ! is_array( $body['messages'] ) ) {
			return null;
		}
		foreach ( $body['messages'] as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'], $message['content'] )
				|| ! is_string( $message['role'] ) || ! is_string( $message['content'] ) ) {
				return null;
			}
		}
		return $body;
	}

	/**
	 * Execute the replay call and persist its results.
	 *
	 * The caller (Rerun_Executor) has already: re-validated eligibility,
	 * taken the generation lock, reopened the run, demoted the stage row
	 * to running and stale-marked downstream. This method performs the
	 * governed LLM call and the success-path writes.
	 *
	 * Side effects: one OpenRouter call (governor-reserved), run_stages
	 * done-write, one generation_log row (with the replayed request via
	 * the B1 recorder), run_decisions human_modified flags.
	 *
	 * @param string               $run_id     Run UUID.
	 * @param string               $stage      Stage name.
	 * @param string               $agent_role Stage row agent role.
	 * @param string               $item_key   Stage row item key.
	 * @param array<string, mixed> $body       Decoded fork body (from decode_body()).
	 * @return array{content: string, cost: float, model: string} Replay result.
	 *
	 * @throws \RuntimeException                    On provider failure or empty output.
	 * @throws PRAutoBlogger_Cost_Ceiling_Exception When the governor blocks the call.
	 */
	public static function run( string $run_id, string $stage, string $agent_role, string $item_key, array $body ): array {
		$tracker = new PRAutoBlogger_Cost_Tracker();
		// Joins this process to the run: Run_Context (governor guard),
		// ledger row, prompt pins — replay spend reserves against the
		// SAME per-run ceiling as the original calls (guardrail 4).
		$tracker->set_run_id( $run_id );

		$llm      = new PRAutoBlogger_OpenRouter_Provider();
		$options  = self::options_from_body( $body, $stage );
		$response = $llm->send_chat_completion( $body['messages'], (string) $body['model'], $options );

		// Same belt as the writer path: an empty replay output must fail
		// the stage, never overwrite a good snapshot with nothing.
		if ( '' === trim( (string) $response['content'] ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: 1: stage name, 2: model identifier. */
					__( 'Replay of stage "%1$s" produced empty content (model %2$s) — failing the replay instead of storing an empty output.', 'prautoblogger' ),
					$stage,
					(string) $response['model']
				)
			);
		}

		$cost = $llm->estimate_cost(
			(string) $response['model'],
			(int) $response['prompt_tokens'],
			(int) $response['completion_tokens']
		);

		PRAutoBlogger_Run_Stage_State::done( $run_id, $stage, $agent_role, $item_key, (string) $response['content'], $cost );

		$tracker->log_api_call(
			null,
			$stage,
			$llm->get_provider_name(),
			(string) $response['model'],
			(int) $response['prompt_tokens'],
			(int) $response['completion_tokens'],
			'success',
			'',
			'' !== $agent_role ? $agent_role : null,
			null
		);

		// Guardrail 2: decision rows for the replayed stage carry the
		// human_modified flag (no-op when the stage has none).
		PRAutoBlogger_Audit_Writer::flag_decisions_human_modified( $run_id, array( $stage ) );

		return array(
			'content' => (string) $response['content'],
			'cost'    => $cost,
			'model'   => (string) $response['model'],
		);
	}
}
