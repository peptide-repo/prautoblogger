<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Per-run cost governor — reserve-before-call against the run ledger.
 *
 * What: Net-new enforcement (the monthly Cost_Tracker cap only bounds the
 *       month; `get_current_run_cost()` was an unguarded accumulator).
 *       Before any governed call dispatches, its worst-case cost estimate
 *       is atomically added to the run's `reserved_usd` via a single
 *       conditional UPDATE — the same atomic-conditional-write discipline
 *       as PRAutoBlogger_Generation_Lock (#10) — so concurrent writers
 *       (curl_multi fan-outs, chained jobs) cannot slip past the ceiling
 *       between check and call. Batches reserve their SUMMED estimate
 *       before dispatch. After each response the reservation settles to
 *       the actual cost, so a cheap call never holds its worst case.
 *       Breach: the run is marked `halted` (sticky), the overage is
 *       recorded on the runs row, the Review Queue surfaces it, and a
 *       Cost_Ceiling_Exception aborts the un-dispatched call. Standalone
 *       calls outside any run (no run context) are not run-governed —
 *       exactly the historical behavior; the monthly cap and the
 *       Cloudflare AI Gateway path are untouched everywhere.
 * Who triggers it: PRAutoBlogger_OpenRouter_Provider::send_chat_completion
 *       (every text-LLM call path), PRAutoBlogger_Image_Pipeline (image
 *       curl_multi batch).
 * Dependencies: Run_Context (which run is this process in),
 *       Run_State (ledger row + halt), OpenRouter_Pricing (#18 chain),
 *       WordPress $wpdb, Logger.
 *
 * @see class-generation-lock.php            — The atomic-write pattern this mirrors.
 * @see core/class-run-state.php             — The ledger row reserved against.
 * @see providers/class-open-router-provider.php — Text-call integration point.
 * @see core/class-image-pipeline.php        — Batch reservation integration point.
 * @see ARCHITECTURE.md #21                  — Design rationale.
 */
class PRAutoBlogger_Cost_Governor {

	/**
	 * Rough chars-per-token divisor for prompt-size estimation. An internal
	 * estimator detail (NOT a behavior setting — the enforced ceiling is
	 * the prautoblogger_per_run_cost_ceiling_usd SETTING).
	 */
	private const CHARS_PER_TOKEN = 4;

	/**
	 * Worst-case completion tokens assumed when a caller sets no
	 * max_tokens. Every current call site sets one; this is belt-and-braces.
	 */
	private const DEFAULT_MAX_COMPLETION_TOKENS = 4096;

	/**
	 * Reserve the worst-case cost of one chat-completion call for the
	 * process's current run. Call before dispatch.
	 *
	 * Returns null (call proceeds ungoverned, exactly as pre-v0.18.0) when
	 * this process is not inside a run, the run row/ledger is unavailable
	 * (half-migrated schema), or the run's ceiling is 0/disabled.
	 *
	 * @param string                                            $model    Model identifier.
	 * @param array<int, array{role: string, content: string}>  $messages Chat messages (prompt-size estimate).
	 * @param array<string, mixed>                              $options  Call options (max_tokens).
	 * @return array{run_id: string, amount: float}|null Open reservation, or null when ungoverned.
	 * @throws PRAutoBlogger_Cost_Ceiling_Exception When the reservation would breach the ceiling
	 *                                              (the run is already halted when this throws).
	 */
	public static function open_chat_reservation( string $model, array $messages, array $options ): ?array {
		return self::open_amount_reservation(
			self::estimate_chat_cost( $model, $messages, $options ),
			'llm:' . $model
		);
	}

	/**
	 * Reserve a pre-computed worst-case amount (e.g. a curl_multi image
	 * batch's summed estimate) for the process's current run.
	 *
	 * @param float  $amount_usd Worst-case cost estimate in USD.
	 * @param string $context    Short label for logs ('llm:<model>', 'image_batch').
	 * @return array{run_id: string, amount: float}|null Open reservation, or null when ungoverned.
	 * @throws PRAutoBlogger_Cost_Ceiling_Exception When the reservation would breach the ceiling.
	 */
	public static function open_amount_reservation( float $amount_usd, string $context ): ?array {
		$run_id = PRAutoBlogger_Run_Context::current_run_id();
		if ( null === $run_id || '' === $run_id || $amount_usd <= 0 ) {
			return null;
		}
		if ( ! PRAutoBlogger_Run_State::is_available() ) {
			return null; // Half-migrated schema — degrade to historical behavior.
		}

		PRAutoBlogger_Run_State::ensure_run( $run_id );
		$run = PRAutoBlogger_Run_State::get_run( $run_id );
		if ( null === $run ) {
			return null;
		}
		if ( (float) $run['ceiling_usd'] <= 0 ) {
			return null; // Ceiling disabled for this run.
		}

		if ( self::reserve( $run_id, $amount_usd ) ) {
			return array(
				'run_id' => $run_id,
				'amount' => $amount_usd,
			);
		}

		self::on_breach( $run_id, $amount_usd, $context );
		return null; // Unreachable — on_breach always throws.
	}

	/**
	 * Settle an open reservation to the call's actual cost: the hold is
	 * released and the actual amount moves to settled_usd.
	 *
	 * @param array{run_id: string, amount: float}|null $reservation Reservation from open_*(), or null (no-op).
	 * @param float                                     $actual_usd  Actual cost of the call.
	 * @return void
	 */
	public static function settle( ?array $reservation, float $actual_usd ): void {
		if ( null === $reservation || ! PRAutoBlogger_Run_State::is_available() ) {
			return;
		}
		global $wpdb;
		$table = PRAutoBlogger_Run_State::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET reserved_usd = GREATEST(reserved_usd - %f, 0),
					settled_usd = settled_usd + %f,
					updated_at = %s
				WHERE run_id = %s",
				$reservation['amount'],
				max( $actual_usd, 0 ),
				current_time( 'mysql' ),
				$reservation['run_id']
			)
		);
	}

	/**
	 * Release an open reservation without settling cost (failed call).
	 *
	 * @param array{run_id: string, amount: float}|null $reservation Reservation from open_*(), or null (no-op).
	 * @return void
	 */
	public static function release( ?array $reservation ): void {
		self::settle( $reservation, 0.0 );
	}

	/**
	 * Worst-case cost estimate for a chat call: estimated prompt tokens
	 * (message chars / 4) plus the call's max_tokens completion budget,
	 * priced via the #18 pricing chain (hardcoded table -> cached model
	 * registry -> conservative fallback).
	 *
	 * @param string                                           $model    Model identifier.
	 * @param array<int, array{role: string, content: string}> $messages Chat messages.
	 * @param array<string, mixed>                             $options  Call options.
	 * @return float Estimated worst-case USD cost.
	 */
	public static function estimate_chat_cost( string $model, array $messages, array $options ): float {
		$chars = 0;
		foreach ( $messages as $message ) {
			$chars += strlen( (string) ( $message['content'] ?? '' ) );
		}
		$prompt_tokens     = (int) ceil( $chars / self::CHARS_PER_TOKEN );
		$completion_tokens = (int) ( $options['max_tokens'] ?? self::DEFAULT_MAX_COMPLETION_TOKENS );

		return ( new PRAutoBlogger_OpenRouter_Pricing() )->estimate_cost( $model, $prompt_tokens, $completion_tokens );
	}

	/**
	 * Atomic conditional reservation — the #10 discipline. One UPDATE
	 * whose WHERE clause enforces `settled + reserved + estimate <=
	 * ceiling` AND that the run is still open; affected-rows tells us who
	 * won. Concurrent reservers serialize on the row lock.
	 *
	 * @param string $run_id Run UUID.
	 * @param float  $amount Estimate to reserve.
	 * @return bool True when the reservation was written.
	 */
	private static function reserve( string $run_id, float $amount ): bool {
		global $wpdb;
		$table = PRAutoBlogger_Run_State::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET reserved_usd = reserved_usd + %f, updated_at = %s
				WHERE run_id = %s
					AND status IN ('pending','running')
					AND (reserved_usd + settled_usd + %f) <= ceiling_usd",
				$amount,
				current_time( 'mysql' ),
				$run_id,
				$amount
			)
		);
		return 1 === (int) $updated;
	}

	/**
	 * Handle a failed reservation: halt the run (sticky), record the
	 * overage, log, throw. The Review Queue lists halted runs; chained
	 * queue jobs abort via the run-status check.
	 *
	 * @param string $run_id  Run UUID.
	 * @param float  $amount  The estimate that failed to reserve.
	 * @param string $context Short label for logs.
	 * @return void
	 * @throws PRAutoBlogger_Cost_Ceiling_Exception Always.
	 */
	private static function on_breach( string $run_id, float $amount, string $context ): void {
		$run      = PRAutoBlogger_Run_State::get_run( $run_id );
		$ceiling  = null !== $run ? (float) $run['ceiling_usd'] : 0.0;
		$held     = null !== $run ? (float) $run['reserved_usd'] + (float) $run['settled_usd'] : 0.0;
		$overage  = max( ( $held + $amount ) - $ceiling, 0.0 );

		PRAutoBlogger_Run_State::mark_status( $run_id, 'halted' );
		PRAutoBlogger_Run_State::record_overage( $run_id, $overage );

		$message = sprintf(
			/* translators: 1: blocked amount USD, 2: call context, 3: committed USD, 4: ceiling USD, 5: run id. */
			__( 'Per-run cost ceiling reached: reserving $%1$.4f for %2$s would push the run to $%3$.4f against a $%4$.2f ceiling. Run %5$s halted and routed to the Review Queue.', 'prautoblogger' ),
			$amount,
			$context,
			$held + $amount,
			$ceiling,
			$run_id
		);

		PRAutoBlogger_Logger::instance()->error( $message, 'cost-governor' );

		throw new PRAutoBlogger_Cost_Ceiling_Exception( $message );
	}
}
