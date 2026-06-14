<?php
declare(strict_types=1);

/**
 * WP-CLI commands for PRAutoBlogger.
 *
 * Registers custom wp-cli commands exposed to the plugin.
 */
class PRAutoBlogger_WP_CLI_Commands {

	/**
	 * Register WP-CLI commands.
	 *
	 * Called on plugins_loaded hook.
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		// Command: wp prautoblogger opik:eval
		\WP_CLI::add_command(
			'prautoblogger generate',
			array( self::class, 'generate_command' ),
			array(
				'shortdesc' => 'Queue a new article generation run on chained-cron checkpoints',
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

	/**
	 * Queue a new article generation run on chained-cron checkpoints.
	 *
	 * Routes through PRAutoBlogger_Generation_Checkpoint_Runner::kick_off()
	 * (same path as the board "New Article" button). Does NOT execute
	 * synchronously -- check the board or Activity Log for progress.
	 * This retires the `do_action("prautoblogger_manual_generation")` workaround.
	 *
	 * ## OPTIONS
	 *
	 * ## EXAMPLES
	 *
	 *     wp prautoblogger generate
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args (unused).
	 * @return void
	 */
	public static function generate_command( array $args, array $assoc_args ): void {
		PRAutoBlogger_Generation_Checkpoint_Runner::kick_off();
		\WP_CLI::success( 'Generation queued on chained-cron checkpoints. Check the board or Activity Log for progress.' );
	}
}
