<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Empty-completion guard for OpenRouter chat responses (v0.18.1).
 *
 * What: Treats a chat completion whose visible content is empty/whitespace
 *       as a FAILURE, never a success — per the LLM-call discipline:
 *       schema-validate every output, retry/fall back, never silently
 *       pass. The incident this prevents (2026-06-11, prod run acf24029,
 *       draft post 921): a true reasoning model at a high reasoning effort
 *       spent the entire max_tokens completion budget on thinking,
 *       finished with finish_reason='length', and emitted zero visible
 *       content — which flowed writer → editor (reject: "draft text is
 *       missing") → an empty draft post in the Review Queue, with the
 *       cost booked as 'success'.
 *
 *       Behavior on every parsed response:
 *       - finish_reason='length' is warning-logged (model, stage, prompt/
 *         completion/reasoning tokens) whether or not content survived.
 *       - Empty content + (finish_reason='length' OR reasoning active on
 *         the request) → ONE retry with reasoning disabled for that call
 *         (per-call `reasoning` override), then fail the stage.
 *       - Every failed/retried attempt lands in generation_log with its
 *         REAL response_status ('error') and token burn, and — inside a
 *         v0.18.0 run — a run_decisions row records the verdict.
 * Who triggers it: PRAutoBlogger_OpenRouter_Provider::send_chat_completion()
 *       on every successfully parsed response.
 * Dependencies: Logger, Cost_Tracker (failure rows), Run_Context (run
 *       attribution), Audit_Writer (run_decisions verdicts).
 *
 * @see class-open-router-provider.php        — Integration point; supplies the retry callable.
 * @see class-open-router-response-parser.php — Produces the parsed array assessed here.
 * @see class-open-router-request-builder.php — Reasoning token cap (first line of defense).
 * @see core/class-audit-writer.php           — run_decisions verdict rows.
 */
class PRAutoBlogger_OpenRouter_Completion_Guard {

	/** Verdict recorded when an empty attempt is retried with reasoning disabled. */
	public const VERDICT_RETRY = 'retry_reasoning_disabled';

	/** Verdict recorded when a call finally fails for empty content. */
	public const VERDICT_FAIL = 'failed_empty_completion';

	/**
	 * Assess a parsed completion: pass it through, retry once, or fail loudly.
	 *
	 * @param array<string, mixed> $parsed           Parsed response from Response_Parser::parse_success().
	 * @param array<string, mixed> $options          Original call options. Reads the caller-metadata keys
	 *                                               'stage' / 'prompt_key' (audit attribution) and
	 *                                               'empty_retry' (marks the retry attempt — never retried twice).
	 * @param bool                 $reasoning_active Whether the dispatched request had reasoning enabled.
	 * @param callable             $retry            Re-dispatches the call with the options it is given.
	 *                                               Invoked at most once, with reasoning disabled.
	 *
	 * @return array<string, mixed> A completion whose visible content is non-empty.
	 *
	 * @throws \RuntimeException When content is empty and the retry budget is spent or a retry
	 *                           cannot plausibly help.
	 */
	public function finalize( array $parsed, array $options, bool $reasoning_active, callable $retry ): array {
		$stage         = (string) ( $options['stage'] ?? 'unknown' );
		$finish_reason = (string) ( $parsed['finish_reason'] ?? 'unknown' );

		if ( 'length' === $finish_reason ) {
			PRAutoBlogger_Logger::instance()->warning(
				sprintf(
					'Completion hit its max_tokens ceiling (finish_reason=length): model=%s, stage=%s, prompt_tokens=%d, completion_tokens=%d, reasoning_tokens=%d.',
					(string) $parsed['model'],
					$stage,
					(int) $parsed['prompt_tokens'],
					(int) $parsed['completion_tokens'],
					(int) $parsed['reasoning_tokens']
				),
				'openrouter'
			);
		}

		if ( '' !== trim( (string) ( $parsed['content'] ?? '' ) ) ) {
			return $parsed; // Healthy completion — visible content present.
		}

		$already_retried = ! empty( $options['empty_retry'] );
		// A reasoning-disabled retry can plausibly help when the budget was
		// exhausted (length) or when reasoning was eating the budget.
		$retry_can_help = 'length' === $finish_reason || $reasoning_active;

		if ( $retry_can_help && ! $already_retried ) {
			$note = sprintf(
				'Empty completion (finish_reason=%s, completion_tokens=%d, reasoning_tokens=%d) — retrying once with reasoning disabled.',
				$finish_reason,
				(int) $parsed['completion_tokens'],
				(int) $parsed['reasoning_tokens']
			);
			$this->record_attempt( $parsed, $options, $stage, self::VERDICT_RETRY, $note );
			PRAutoBlogger_Logger::instance()->warning(
				sprintf( 'Stage %s: %s (model %s)', $stage, $note, (string) $parsed['model'] ),
				'openrouter'
			);

			$retry_options                = $options;
			$retry_options['reasoning']   = array( 'enabled' => false );
			$retry_options['empty_retry'] = true;
			return $retry( $retry_options );
		}

		$note = sprintf(
			'Empty completion (finish_reason=%s, completion_tokens=%d, reasoning_tokens=%d)%s — failing the call.',
			$finish_reason,
			(int) $parsed['completion_tokens'],
			(int) $parsed['reasoning_tokens'],
			$already_retried ? ' after a reasoning-disabled retry' : ''
		);
		$this->record_attempt( $parsed, $options, $stage, self::VERDICT_FAIL, $note );

		throw new \RuntimeException(
			sprintf(
				/* translators: 1: pipeline stage, 2: model identifier, 3: finish_reason value, 4: retry note. */
				__( 'LLM call for stage "%1$s" returned empty content (model %2$s, finish_reason=%3$s)%4$s.', 'prautoblogger' ),
				$stage,
				(string) $parsed['model'],
				$finish_reason,
				$already_retried ? __( ' after a reasoning-disabled retry', 'prautoblogger' ) : ''
			)
		);
	}

	/**
	 * Book a failed/retried attempt: a generation_log row with the REAL
	 * response_status and token burn (the empty call still cost money),
	 * plus a run_decisions verdict for new-style runs. Both writes are
	 * self-healing no-ops on a half-migrated schema; neither can throw.
	 *
	 * @param array<string, mixed> $parsed  Parsed response of the failed attempt.
	 * @param array<string, mixed> $options Call options ('prompt_key' attribution).
	 * @param string               $stage   Stage label for the log row.
	 * @param string               $verdict VERDICT_RETRY or VERDICT_FAIL.
	 * @param string               $note    Human-readable failure note.
	 * @return void
	 */
	private function record_attempt( array $parsed, array $options, string $stage, string $verdict, string $note ): void {
		$run_id = PRAutoBlogger_Run_Context::current_run_id();

		$tracker = new PRAutoBlogger_Cost_Tracker();
		if ( null !== $run_id && '' !== $run_id ) {
			// Attribute the row to the surrounding run. Idempotent: the
			// run context, ledger row, and prompt pins already exist.
			$tracker->set_run_id( $run_id );
		}
		$tracker->log_api_call(
			null,
			$stage,
			'OpenRouter',
			(string) $parsed['model'],
			(int) $parsed['prompt_tokens'],
			(int) $parsed['completion_tokens'],
			'error',
			$note,
			null,
			isset( $options['prompt_key'] ) ? (string) $options['prompt_key'] : null
		);

		// run_decisions verdict — no-op outside a run or pre-1.2.0 schema.
		PRAutoBlogger_Audit_Writer::record_decision( (string) ( $run_id ?? '' ), $stage, $verdict, $note );
	}
}
