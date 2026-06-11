<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Per-PHP-process holder for the active pipeline run id.
 *
 * What: A process-scoped static slot recording which run this PHP request
 *       is executing, set whenever Cost_Tracker::set_run_id() is called
 *       (daily cron, manual generation, queued-article jobs, ideas
 *       browser). It lets components that are constructed without a run
 *       reference — the OpenRouter provider (cost-governor guard) and
 *       standalone Cost_Tracker instances (e.g. the image prompt
 *       rewriter's) — resolve the surrounding run for reservation and
 *       prompt-version stamping without threading a parameter through
 *       every constructor. Mirrors the Opik_Trace_Context precedent.
 * Who triggers it: PRAutoBlogger_Cost_Tracker::set_run_id() (set);
 *       Cost_Governor + Cost_Tracker (read).
 * Dependencies: none.
 *
 * @see core/class-cost-tracker.php  — Sets the context on set_run_id().
 * @see core/class-cost-governor.php — Reads it to guard LLM calls.
 */
class PRAutoBlogger_Run_Context {

	/** @var string|null Active run id for this PHP process, or null. */
	private static ?string $run_id = null;

	/**
	 * Record the active run id for this process.
	 *
	 * @param string $run_id Pipeline run UUID.
	 * @return void
	 */
	public static function set_run_id( string $run_id ): void {
		if ( '' !== $run_id ) {
			self::$run_id = $run_id;
		}
	}

	/**
	 * The active run id for this process, or null outside any run.
	 *
	 * @return string|null
	 */
	public static function current_run_id(): ?string {
		return self::$run_id;
	}

	/**
	 * Clear the context (tests / end of run).
	 *
	 * @return void
	 */
	public static function clear(): void {
		self::$run_id = null;
	}
}
