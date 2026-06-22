<?php
declare(strict_types=1);

/**
 * WP-CLI commands for PRAutoBlogger.
 *
 * Registers custom wp-cli commands exposed to the plugin.
 */
class PRAutoBlogger_WP_CLI_Commands {

	/**
	 * Max generate-tick iterations for the --sync loop (covers daily_article_target = 1
	 * with ample headroom; prevents infinite loops if the queue never drains).
	 *
	 * @var int
	 */
	private const SYNC_MAX_TICKS = 20;

	/**
	 * Register WP-CLI commands.
	 *
	 * Called on plugins_loaded hook.
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command(
			'prautoblogger generate',
			array( self::class, 'generate_command' ),
			array(
				'shortdesc' => 'Queue (or synchronously run) a new article generation run',
				'synopsis'  => array(
					array(
						'type'        => 'flag',
						'name'        => 'sync',
						'description' => 'Run the full pipeline synchronously in this process (VPS orchestrator mode). Blocks until complete.',
						'optional'    => true,
					),
				),
			)
		);

		\WP_CLI::add_command(
			'prautoblogger opik:eval',
			array( self::class, 'opik_eval_command' ),
			array(
				'shortdesc' => 'Run Opik evals on the frozen dataset',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'limit',
						'description' => 'Max items to run (0 = all)',
						'optional'    => true,
						'default'     => '0',
					),
					array(
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Skip Opik API push; score locally only',
						'optional'    => true,
					),
				),
			)
		);
	}

	/**
	 * Queue or synchronously run a new article generation run.
	 *
	 * Without --sync: routes through kick_off() (same as the board "New Article"
	 * button). Returns immediately; all pipeline work happens in cron.
	 *
	 * With --sync: runs the pipeline synchronously in this SSH/CLI process.
	 *   1. Acquires the generation lock (skips if a live run is in progress).
	 *   2. Calls on_orchestrate_tick() to collect/score ideas in-process.
	 *   3. Loops calling on_generate_tick() until the queue is empty or the run
	 *      reaches a terminal state, up to SYNC_MAX_TICKS iterations.
	 *   4. Emits a JSON summary line on stdout and exits 0 (success) or 1 (error).
	 *
	 * The --sync path suppresses wp-cron reschedule inside on_generate_tick() so
	 * the external loop cannot race with a background cron tick.
	 *
	 * ## OPTIONS
	 *
	 * [--sync]
	 * : Run synchronously (VPS orchestrator mode).
	 *
	 * ## EXAMPLES
	 *
	 *     wp prautoblogger generate
	 *     wp prautoblogger generate --sync
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args: sync.
	 * @return void
	 */
	public static function generate_command( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['sync'] ) ) {
			self::run_sync();
			return;
		}

		PRAutoBlogger_Generation_Checkpoint_Runner::kick_off();
		\WP_CLI::success( 'Generation queued on chained-cron checkpoints. Check the board or Activity Log for progress.' );
	}

	/**
	 * Run the full pipeline synchronously in this process (--sync mode).
	 *
	 * Called only from generate_command(); never from wp-cron or AJAX paths.
	 *
	 * @return void
	 */
	private static function run_sync(): void {
		// Honour ignore_user_abort so a dropped SSH session does not kill us.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@ignore_user_abort( true );

		// Check for a live (non-expired) lock. Generation_Lock::acquire() already
		// cleans expired locks on its DELETE pass, so if acquire() returns false
		// there is a genuinely live concurrent run — skip cleanly.
		if ( ! PRAutoBlogger_Generation_Lock::is_locked() ) {
			// Lock is not held; proceed. acquire() is called inside on_orchestrate_tick().
		} else {
			$acquired_at = PRAutoBlogger_Generation_Lock::get_acquired_at();
			$age         = null !== $acquired_at ? ( time() - $acquired_at ) : HOUR_IN_SECONDS;
			if ( $age < HOUR_IN_SECONDS ) {
				\WP_CLI::log(
					json_encode(
						array(
							'status' => 'skipped',
							'reason' => 'run_in_progress',
							'lock_age_s' => $age,
						)
					)
				);
				exit( 0 );
			}
			// Expired lock — on_orchestrate_tick()'s acquire() will clean it.
		}

		// Enable sync-mode: on_generate_tick() will skip the wp-cron reschedule.
		PRAutoBlogger_Generation_Checkpoint_Runner::set_sync_mode( true );

		\WP_CLI::log( 'PRAB-SYNC: starting orchestrate tick' );
		PRAutoBlogger_Generation_Checkpoint_Runner::on_orchestrate_tick();

		// If the orchestrate tick failed to acquire the lock or found no ideas,
		// the RUN_ID option will be absent. Detect and exit cleanly.
		$run_id = (string) get_option( 'prautoblogger_checkpoint_run_id', '' );
		if ( '' === $run_id ) {
			$queue = get_option( 'prautoblogger_article_queue' );
			if ( ! is_array( $queue ) || empty( $queue['ideas'] ) ) {
				\WP_CLI::log(
					json_encode(
						array(
							'status' => 'done',
							'generated' => 0,
							'reason' => 'no_ideas_or_lock_failed',
						)
					)
				);
				exit( 0 );
			}
		}

		// Tick loop: drive generate ticks synchronously.
		$ticks = 0;
		while ( $ticks < self::SYNC_MAX_TICKS ) {
			$queue = get_option( 'prautoblogger_article_queue' );
			if ( ! is_array( $queue ) || empty( $queue['ideas'] ) ) {
				break; // Queue drained or finalized.
			}
			++$ticks;
			\WP_CLI::log( sprintf( 'PRAB-SYNC: generate tick %d (ideas remaining: %d)', $ticks, count( $queue['ideas'] ) ) );
			PRAutoBlogger_Generation_Checkpoint_Runner::on_generate_tick();
		}

		PRAutoBlogger_Generation_Checkpoint_Runner::set_sync_mode( false );

		if ( $ticks >= self::SYNC_MAX_TICKS ) {
			\WP_CLI::warning( 'PRAB-SYNC: hit max-tick guard (' . self::SYNC_MAX_TICKS . '). Queue may not be fully drained.' );
		}

		// Read final status transient for summary.
		$status = get_transient( 'prautoblogger_generation_status' );
		$result = is_array( $status ) ? $status : array();

		$summary = array(
			'status'     => 'done',
			'generated'  => (int) ( $result['generated'] ?? 0 ),
			'published'  => (int) ( $result['published'] ?? 0 ),
			'rejected'   => (int) ( $result['rejected'] ?? 0 ),
			'cost_usd'   => round( (float) ( $result['cost'] ?? 0.0 ), 4 ),
			'ticks'      => $ticks,
		);

		\WP_CLI::log( json_encode( $summary ) );

		if ( 'error' === ( $result['status'] ?? '' ) ) {
			\WP_CLI::error( 'PRAB-SYNC: generation completed with error: ' . ( $result['message'] ?? 'unknown' ), false );
			exit( 1 );
		}

		\WP_CLI::success( 'PRAB-SYNC: generation complete.' );
		exit( 0 );
	}

	/**
	 * Run eval on the frozen dataset.
	 *
	 * @param array $args Positional args (unused).
	 * @param array $assoc_args Associative args: limit, dry-run.
	 *
	 * @return void
	 */
	public static function opik_eval_command( array $args, array $assoc_args ): void {
		// Check if Opik is enabled.
		if ( empty( PRAUTOBLOGGER_OPIK_API_KEY ) || empty( PRAUTOBLOGGER_OPIK_WORKSPACE ) ) {
			if ( ! isset( $assoc_args['dry-run'] ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_echo -- CLI output
				echo "Warning: Opik is not configured. Running with --dry-run only.\n";
				$assoc_args['dry-run'] = true;
			}
		}

		$limit   = absint( $assoc_args['limit'] ?? 0 );
		$dry_run = isset( $assoc_args['dry-run'] );

		$runner = new PRAutoBlogger_Opik_Eval_Runner();
		$result = $runner->run( $limit, $dry_run );

		exit( $result['items_run'] > 0 ? 0 : 1 );
	}
}
