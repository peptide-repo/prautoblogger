<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Handles plugin activation: creates database tables, sets default options,
 * and schedules the initial cron job.
 *
 * Triggered by: WordPress `register_activation_hook` in prautoblogger.php.
 * Dependencies: WordPress $wpdb, dbDelta().
 *
 * @see class-deactivator.php — Reverses transient/cron setup on deactivation.
 * @see ARCHITECTURE.md       — Database schema definitions.
 */
class PRAutoBlogger_Activator {

	/**
	 * Run activation tasks.
	 *
	 * Creates custom database tables if they don't exist, sets default option
	 * values (without overwriting existing ones), and schedules the daily
	 * generation cron event.
	 *
	 * Side effects: writes to wp_options, creates database tables, schedules WP-Cron event.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		self::set_default_options();
		self::schedule_cron();
		self::backpopulate_run_id_meta_v081();
		PRAutoBlogger_Migrate_Prompt_Seed_V0180::run();

		// Store the DB version so we can run migrations on future updates.
		update_option( 'prautoblogger_db_version', PRAUTOBLOGGER_DB_VERSION, false );
	}

	/**
	 * Delegate to the schema installers — see those classes.
	 *
	 * `Schema_Installer` owns the v1.1.0 tables; `Pipeline_Schema_Installer`
	 * owns the v0.18.0 / db-1.2.0 Pipeline v2 substrate tables. Both are
	 * dbDelta-idempotent and safe to re-run on any version mismatch.
	 */
	private static function create_tables(): void {
		PRAutoBlogger_Schema_Installer::install();
		PRAutoBlogger_Pipeline_Schema_Installer::install();
	}

	/**
	 * Set default option values without overwriting existing ones.
	 *
	 * Uses add_option() which is a no-op if the option already exists.
	 *
	 * Side effects: writes to wp_options.
	 *
	 * @return void
	 */
	private static function set_default_options(): void {
		$defaults = array(
			'prautoblogger_analysis_model'       => PRAUTOBLOGGER_DEFAULT_ANALYSIS_MODEL,
			'prautoblogger_writing_model'        => PRAUTOBLOGGER_DEFAULT_WRITING_MODEL,
			'prautoblogger_editor_model'         => PRAUTOBLOGGER_DEFAULT_EDITOR_MODEL,
			'prautoblogger_daily_article_target' => 1,
			'prautoblogger_writing_pipeline'     => 'multi_step',
			'prautoblogger_niche_description'    => '',
			'prautoblogger_target_subreddits'    => '[]',
			'prautoblogger_monthly_budget_usd'   => 50.00,
			'prautoblogger_per_run_cost_ceiling_usd' => PRAUTOBLOGGER_DEFAULT_RUN_CEILING_USD,
			'prautoblogger_request_json_retention_days' => PRAUTOBLOGGER_DEFAULT_REQUEST_JSON_RETENTION_DAYS,
			'prautoblogger_tone'                 => 'informational',
			'prautoblogger_min_word_count'       => 800,
			'prautoblogger_max_word_count'       => 2000,
			'prautoblogger_topic_exclusions'     => '[]',
			'prautoblogger_enabled_sources'      => '["reddit"]',
			'prautoblogger_article_font_family'  => 'default',
			'prautoblogger_article_font_size'    => 0,
			'prautoblogger_table_borders'        => '1',
			'prautoblogger_schedule_time'        => '03:00',
			'prautoblogger_log_level'            => 'info',
			'prautoblogger_image_nsfw_retry'       => '1',
			// M4 (v0.21.0): board column card limit + ideas page size -- settings-backed.
			'prautoblogger_board_column_limit'     => PRAUTOBLOGGER_DEFAULT_BOARD_COLUMN_LIMIT,
			'prautoblogger_ideas_per_page'         => PRAUTOBLOGGER_DEFAULT_IDEAS_PER_PAGE,
		);

		foreach ( $defaults as $key => $value ) {
			add_option( $key, $value );
		}
	}

	/**
	 * Schedule the daily generation cron event if not already scheduled.
	 *
	 * The custom 'prautoblogger_six_hours' schedule is normally registered via
	 * the `cron_schedules` filter in class-prautoblogger.php. However, activation
	 * runs before `plugins_loaded`, so the filter may not be registered yet.
	 * We register it inline here to ensure it exists at scheduling time.
	 *
	 * Side effects: registers a WP-Cron event, may register a cron schedule.
	 *
	 * @return void
	 */
	private static function schedule_cron(): void {
		if ( ! wp_next_scheduled( 'prautoblogger_daily_generation' ) ) {
			$timestamp = self::next_daily_generation_timestamp();
			if ( $timestamp > 0 ) {
				wp_schedule_event( $timestamp, 'daily', 'prautoblogger_daily_generation' );
			}
		}

		// Ensure the six-hour schedule exists before referencing it.
		// Activation can fire before plugins_loaded, so the cron_schedules filter
		// in class-prautoblogger.php may not be hooked yet.
		$schedules = wp_get_schedules();
		if ( ! isset( $schedules['prautoblogger_six_hours'] ) ) {
			add_filter(
				'cron_schedules',
				static function ( array $scheds ): array {
					$scheds['prautoblogger_six_hours'] = array(
						'interval' => 6 * HOUR_IN_SECONDS,
						'display'  => __( 'Every Six Hours', 'prautoblogger' ),
					);
					return $scheds;
				}
			);
		}

		// Schedule a separate metrics collection job (runs every 6 hours).
		if ( ! wp_next_scheduled( 'prautoblogger_collect_metrics' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'prautoblogger_six_hours', 'prautoblogger_collect_metrics' );
		}

		// v0.8.1: daily reaper for orphan `llm_research` rows. Pinned to
		// 03:15 so it runs 15 min after the primary generation cron, giving
		// any live pipeline time to finish and call amortize_research_costs
		// cleanly before the reaper scans.
		if ( ! wp_next_scheduled( 'prautoblogger_reap_orphan_research_rows' ) ) {
			$tomorrow = strtotime( 'tomorrow 03:15' );
			if ( false !== $tomorrow ) {
				wp_schedule_event( $tomorrow, 'daily', 'prautoblogger_reap_orphan_research_rows' );
			}
		}

		// v0.15.0: daily Runware model catalog sync.
		if ( ! wp_next_scheduled( 'prautoblogger_sync_runware_models' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'prautoblogger_sync_runware_models' );
		}
	}

	/**
	 * Compute the next daily-generation timestamp in the site's configured
	 * timezone. Fixes the v<0.8.2 bug where `strtotime("tomorrow HH:MM")`
	 * evaluated in UTC (WP core sets `date_default_timezone_set('UTC')`),
	 * firing the cron N hours off from user intent where N is the site's
	 * UTC offset.
	 *
	 * @return int Unix timestamp, or 0 on any failure.
	 */
	public static function next_daily_generation_timestamp(): int {
		$time_str = (string) get_option( 'prautoblogger_schedule_time', '03:00' );
		$parts    = explode( ':', $time_str );
		$hour     = isset( $parts[0] ) ? absint( $parts[0] ) : 3;
		$minute   = isset( $parts[1] ) ? absint( $parts[1] ) : 0;

		try {
			$site_tz = function_exists( 'wp_timezone' )
				? wp_timezone()
				: new \DateTimeZone( 'UTC' );
			$local   = ( new \DateTimeImmutable( 'tomorrow', $site_tz ) )
				->setTime( $hour, $minute, 0 );
			return (int) $local->getTimestamp();
		} catch ( \Exception $e ) {
			// Defensive: never leave the site without any schedule because
			// of a bad tz string. Fall back to the pre-fix behaviour.
			$timestamp = strtotime( "tomorrow {$hour}:{$minute}" );
			return false === $timestamp ? 0 : (int) $timestamp;
		}
	}

	/**
	 * v0.8.2 one-shot migration — reschedule `prautoblogger_daily_generation`
	 * in the site's timezone.
	 *
	 * Existing installs had their daily cron scheduled with the v<0.8.2
	 * UTC-interpretation bug. This clears the stale event and reschedules it
	 * using the timezone-aware helper, exactly once per site (gated by
	 * `prautoblogger_migrated_schedule_tz_v082`). Logs an INFO line with the
	 * before/after next-run times so the outcome is auditable.
	 *
	 * @return void
	 */
	public static function reschedule_daily_in_site_timezone_v082(): void {
		if ( get_option( 'prautoblogger_migrated_schedule_tz_v082' ) ) {
			return;
		}

		$old_timestamp = wp_next_scheduled( 'prautoblogger_daily_generation' );
		wp_clear_scheduled_hook( 'prautoblogger_daily_generation' );

		$new_timestamp = self::next_daily_generation_timestamp();
		if ( $new_timestamp > 0 ) {
			wp_schedule_event( $new_timestamp, 'daily', 'prautoblogger_daily_generation' );
		}

		if ( class_exists( 'PRAutoBlogger_Logger', false ) ) {
			PRAutoBlogger_Logger::instance()->info(
				sprintf(
					'Rescheduled daily generation in site timezone (was UTC). Old next_run: %s, new next_run: %s.',
					false !== $old_timestamp ? gmdate( 'Y-m-d H:i:s \\U\\T\\C', (int) $old_timestamp ) : 'unscheduled',
					$new_timestamp > 0 ? gmdate( 'Y-m-d H:i:s \\U\\T\\C', $new_timestamp ) : 'unscheduled'
				),
				'activator'
			);
		}

		update_option( 'prautoblogger_migrated_schedule_tz_v082', '1' );
	}

	/**
	 * One-shot v0.8.1 migration — back-populate `_prautoblogger_run_id`
	 * post_meta for existing posts from their gen-log rows.
	 *
	 * Lets the orphan-research-row reaper (v0.8.1+) attribute historic
	 * orphan rows to sibling posts via post_meta lookup, without having
	 * to walk the generation_log table. Gated by
	 * `prautoblogger_migrated_run_id_meta_v081` so it runs exactly once
	 * per site.
	 *
	 * Side effects: adds post_meta rows for existing PRAutoBlogger posts
	 *               that don't already have `_prautoblogger_run_id`.
	 *
	 * @return void
	 */
	private static function backpopulate_run_id_meta_v081(): void {
		if ( get_option( 'prautoblogger_migrated_run_id_meta_v081' ) ) {
			return;
		}

		global $wpdb;
		$gen_log = $wpdb->prefix . 'prautoblogger_generation_log';

		// For each (post_id, run_id) pair present in the gen-log, write
		// the run_id meta if it's not already set. Uses the first run_id
		// seen per post — in practice each post is linked to exactly one run.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pairs = $wpdb->get_results(  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT post_id, MIN(run_id) AS run_id FROM {$gen_log}
			WHERE post_id IS NOT NULL AND run_id IS NOT NULL AND run_id != ''
			GROUP BY post_id"
		);

		if ( is_array( $pairs ) ) {
			foreach ( $pairs as $row ) {
				$post_id = (int) ( $row->post_id ?? 0 );
				$run_id  = (string) ( $row->run_id ?? '' );
				if ( $post_id > 0 && '' !== $run_id && ! metadata_exists( 'post', $post_id, '_prautoblogger_run_id' ) ) {
					update_post_meta( $post_id, '_prautoblogger_run_id', $run_id );
				}
			}
		}

		update_option( 'prautoblogger_migrated_run_id_meta_v081', '1' );
	}

	/**
	 * v0.9.0 migration: flip legacy default image model to Runware schnell
	 * and re-derive provider. CEO decision 2026-04-21 — 65× cheaper than
	 * Gemini Nano Banana. Explicit user selections are preserved.
	 *
	 * Side effects: may update `prautoblogger_image_model`,
	 *               `prautoblogger_image_provider`, and sets the
	 *               `prautoblogger_migrated_default_image_v090` flag.
	 */
	public static function migrate_default_image_v090(): void {
		if ( get_option( 'prautoblogger_migrated_default_image_v090' ) ) {
			return;
		}
		$curr = (string) get_option( 'prautoblogger_image_model', '' );
		if ( in_array( $curr, array( '', 'google/gemini-2.5-flash-image' ), true ) ) {
			update_option( 'prautoblogger_image_model', 'runware:100@1' );
		}
		$final = (string) get_option( 'prautoblogger_image_model', 'runware:100@1' );
		$prov  = PRAutoBlogger_Image_Model_Registry::provider_for( $final );
		if ( '' !== $prov ) {
			update_option( 'prautoblogger_image_provider', $prov );
		}
		update_option( 'prautoblogger_migrated_default_image_v090', '1' );
	}
}
