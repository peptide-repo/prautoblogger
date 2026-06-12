<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Per-PHP-process stash for the most recent outgoing LLM request body.
 *
 * What: A process-scoped static slot recording the JSON request body the
 *       OpenRouter provider is about to dispatch, so Cost_Tracker::
 *       log_api_call() can persist it to `generation_log.request_json`
 *       (v0.20.0 / B1) without threading the body through every call
 *       site. Mirrors the Run_Context precedent (v0.18.0).
 *
 *       SECURITY GUARANTEE: only the request BODY is ever recorded.
 *       Authorization headers are built separately
 *       (Request_Builder::build_headers()) and can never reach this
 *       class; build_body() copies only whitelisted option keys.
 *
 *       Consume-once semantics: consume() clears the slot, and record()
 *       overwrites it at every dispatch — so a stale body from an
 *       unlogged failed call can never attach to a later, unrelated
 *       log row.
 * Who triggers it: OpenRouter_Provider::send_chat_completion() (record,
 *       pre-dispatch so error rows carry the request too);
 *       Cost_Tracker::log_api_call() (consume).
 * Dependencies: none.
 *
 * @see providers/class-open-router-provider.php — Records before dispatch.
 * @see core/class-cost-tracker.php              — Consumes into the log row.
 * @see core/class-run-context.php               — The pattern this mirrors.
 * @see ARCHITECTURE.md #24                      — Edit + re-run substrate (B1).
 */
class PRAutoBlogger_Request_Recorder {

	/** @var string|null JSON-encoded request body of the in-flight LLM call. */
	private static ?string $request_json = null;

	/**
	 * Record the outgoing request body for the next log row.
	 *
	 * Overwrites any previous stash (one in-flight chat call per PHP
	 * process). Bodies that fail JSON encoding are skipped silently —
	 * the log row's request_json stays NULL, never blocking the call.
	 *
	 * @param array<string, mixed> $body Request body from Request_Builder::build_body().
	 * @return void
	 */
	public static function record( array $body ): void {
		$encoded            = wp_json_encode( $body );
		self::$request_json = is_string( $encoded ) ? $encoded : null;
	}

	/**
	 * Return the stashed request body and clear the slot (consume-once).
	 *
	 * @return string|null JSON request body, or null when nothing is stashed.
	 */
	public static function consume(): ?string {
		$json               = self::$request_json;
		self::$request_json = null;
		return $json;
	}

	/**
	 * Clear the stash (tests / defensive teardown).
	 *
	 * @return void
	 */
	public static function clear(): void {
		self::$request_json = null;
	}
}
