<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Policy gates for edit + single-step re-run (v0.20.0, M3).
 *
 * What: Single source of truth for WHO may re-run WHAT:
 *       - Published posts are frozen (CPO guardrail 5): publish/future/
 *         private statuses lock the run; edits go through the WP editor.
 *       - Runs that are actively executing (status pending/running or
 *         the global generation lock held) cannot be re-run — "in
 *         progress" in the CPO sense means in the WORKFLOW (unpublished),
 *         not mid-execution.
 *       - Editable stages (input fork + replay) are the item-scoped
 *         writer chat stages of the CURRENT pipeline vocabulary:
 *         outline/draft/polish (single-pass uses draft only). review's
 *         input is fully derived from content (re-run it from here
 *         instead); analysis/research are run-level idea-selection
 *         stages; image stages are not chat-replayable (Phase 2b).
 *       - The canonical per-item chain orders downstream computation
 *         for stale-marking and rebuild sets.
 * Who triggers it: Dossier_Actions (UI affordances + request validation),
 *       Rerun_Executor (re-validation under the lock before mutating).
 * Dependencies: get_post(), Run_State, Generation_Lock, Stage_Input_Store.
 *
 * @see admin/class-dossier-actions.php — AJAX validation.
 * @see core/class-rerun-executor.php   — Cron-side re-validation.
 * @see ARCHITECTURE.md #24             — Edit + re-run design.
 */
class PRAutoBlogger_Rerun_Eligibility {

	/** Post statuses that freeze a run (CPO guardrail 5). */
	public const FROZEN_STATUSES = array( 'publish', 'future', 'private' );

	/** Stages whose input may be edited and replayed (chat writer stages). */
	public const EDITABLE_STAGES = array( 'outline', 'draft', 'polish' );

	/** Canonical per-item stage chain (current pipeline vocabulary). */
	public const ITEM_STAGE_CHAIN = array( 'outline', 'draft', 'polish', 'review', 'publish' );

	/**
	 * Whether a stage's input may be edited + replayed.
	 *
	 * @param string $stage Stage name.
	 * @return bool
	 */
	public static function is_editable_stage( string $stage ): bool {
		return in_array( $stage, self::EDITABLE_STAGES, true );
	}

	/**
	 * Whether re-run-from-here may target this stage (any chain stage).
	 *
	 * @param string $stage Stage name.
	 * @return bool
	 */
	public static function is_chain_stage( string $stage ): bool {
		return in_array( $stage, self::ITEM_STAGE_CHAIN, true );
	}

	/**
	 * Stages strictly AFTER a stage in the canonical chain — the set that
	 * goes stale when the stage is re-run. Stages absent from an item
	 * (e.g. outline/polish on single-pass runs) simply match no rows.
	 *
	 * @param string $stage Stage name.
	 * @return array<string> Downstream stage names ([] for non-chain stages).
	 */
	public static function downstream_of( string $stage ): array {
		$idx = array_search( $stage, self::ITEM_STAGE_CHAIN, true );
		if ( false === $idx ) {
			return array();
		}
		return array_slice( self::ITEM_STAGE_CHAIN, $idx + 1 );
	}

	/**
	 * The demotion set for re-run-from-here: the stage itself plus all
	 * downstream chain stages.
	 *
	 * @param string $stage Stage name.
	 * @return array<string>
	 */
	public static function rebuild_set( string $stage ): array {
		$idx = array_search( $stage, self::ITEM_STAGE_CHAIN, true );
		if ( false === $idx ) {
			return array();
		}
		return array_slice( self::ITEM_STAGE_CHAIN, $idx );
	}

	/**
	 * Whether a post is frozen for pipeline re-runs (guardrail 5).
	 *
	 * @param WP_Post|null $post The post, or null when none exists yet.
	 * @return bool
	 */
	public static function post_frozen( ?WP_Post $post ): bool {
		return ( $post instanceof WP_Post )
			&& in_array( (string) $post->post_status, self::FROZEN_STATUSES, true );
	}

	/**
	 * Full re-run eligibility check for a (run, post) pair.
	 *
	 * Returns ok=false with an operator-readable reason for: missing/
	 * frozen post, missing run row, run actively executing, or the
	 * global generation lock being held. Called from the AJAX layer for
	 * UI affordances AND re-validated by the cron handler under the lock
	 * (state can change between click and pickup).
	 *
	 * @param string $run_id  Run UUID.
	 * @param int    $post_id Post ID (0 = run has no post yet).
	 * @param bool   $require_idle_lock Whether a held generation lock blocks (true for actions).
	 * @return array{ok: bool, reason: string} Verdict + reason ('' when ok).
	 */
	public static function check( string $run_id, int $post_id, bool $require_idle_lock = true ): array {
		if ( '' === $run_id ) {
			return self::no( __( 'This article has no recorded generation run.', 'prautoblogger' ) );
		}

		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( self::post_frozen( $post ) ) {
			return self::no( __( 'This article is published — its run is frozen. Edit the article in the WordPress editor instead.', 'prautoblogger' ) );
		}

		$run = PRAutoBlogger_Run_State::get_run( $run_id );
		if ( null === $run ) {
			return self::no( __( 'No run ledger row exists for this article (pre-substrate run).', 'prautoblogger' ) );
		}
		if ( in_array( (string) $run['status'], array( 'pending', 'running' ), true ) ) {
			return self::no( __( 'This run is currently executing. Wait for it to finish (or for the reaper to mark it failed).', 'prautoblogger' ) );
		}

		if ( $require_idle_lock && null !== PRAutoBlogger_Generation_Lock::get_acquired_at() ) {
			return self::no( __( 'Another generation is in progress (lock held). Try again when it completes.', 'prautoblogger' ) );
		}

		return array(
			'ok'     => true,
			'reason' => '',
		);
	}

	/**
	 * Replay-specific check: base eligibility + editable stage + a saved
	 * input fork whose body has not been retention-pruned.
	 *
	 * @param string $run_id     Run UUID.
	 * @param int    $post_id    Post ID.
	 * @param string $stage      Stage name.
	 * @param string $agent_role Stage row agent role.
	 * @param string $item_key   Stage row item key.
	 * @param bool   $require_idle_lock False when the caller already owns the lock (cron handler).
	 * @return array{ok: bool, reason: string}
	 */
	public static function check_replay( string $run_id, int $post_id, string $stage, string $agent_role, string $item_key, bool $require_idle_lock = true ): array {
		if ( ! self::is_editable_stage( $stage ) ) {
			return self::no( __( 'This stage cannot be replayed with an edited input.', 'prautoblogger' ) );
		}
		$base = self::check( $run_id, $post_id, $require_idle_lock );
		if ( ! $base['ok'] ) {
			return $base;
		}
		$fork = PRAutoBlogger_Stage_Input_Store::latest_fork( $run_id, $stage, $agent_role, $item_key );
		if ( null === $fork ) {
			return self::no( __( 'Save an edited input for this stage first — re-run executes your latest saved version.', 'prautoblogger' ) );
		}
		if ( empty( $fork['request_json'] ) ) {
			return self::no( __( 'The saved input for this stage was pruned by the retention cron and can no longer be replayed.', 'prautoblogger' ) );
		}
		return $base;
	}

	/**
	 * Rebuild-specific check: base eligibility + chain stage + idea seed
	 * available (runs started before v0.20.0 have no seed).
	 *
	 * @param string $run_id   Run UUID.
	 * @param int    $post_id  Post ID.
	 * @param string $stage    Target stage.
	 * @param string $item_key Item key.
	 * @param bool   $require_idle_lock False when the caller already owns the lock (cron handler).
	 * @return array{ok: bool, reason: string}
	 */
	public static function check_rebuild( string $run_id, int $post_id, string $stage, string $item_key, bool $require_idle_lock = true ): array {
		if ( ! self::is_chain_stage( $stage ) ) {
			return self::no( __( 'Re-run from here is only available on article pipeline stages.', 'prautoblogger' ) );
		}
		$base = self::check( $run_id, $post_id, $require_idle_lock );
		if ( ! $base['ok'] ) {
			return $base;
		}
		if ( null === PRAutoBlogger_Stage_Input_Store::get_seed( $run_id, $item_key ) ) {
			return self::no( __( 'No idea seed is stored for this run (recorded from v0.20.0 onward) — the pipeline cannot rebuild it safely.', 'prautoblogger' ) );
		}
		return $base;
	}

	/**
	 * Shorthand for a negative verdict.
	 *
	 * @param string $reason Operator-readable reason.
	 * @return array{ok: bool, reason: string}
	 */
	private static function no( string $reason ): array {
		return array(
			'ok'     => false,
			'reason' => $reason,
		);
	}
}
