<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Runware model catalog sync — orchestrates periodic fetching, caching, failure
 * cooldown, and fallback for the Runware model list.
 *
 * What: Syncs Runware's model catalog (daily cron + on-demand AJAX), caches
 *       normalized results in WP options (v2 schema key — see below), merges
 *       pricing, and enforces a per-failure cooldown to stop admin-page-load
 *       cascades when the API is broken or the key is misconfigured.
 * Who triggers it: Daily cron (prautoblogger_sync_runware_models), AJAX "Sync now"
 *                  button in PRAdmin, and lazy-load via PRAutoBlogger_Image_Model_Registry.
 * Dependencies: PRAutoBlogger_Runware_Catalog_Fetcher, PRAutoBlogger_Logger,
 *               PRAutoBlogger_Runware_Image_Pricing, PRAutoBlogger_Image_Model_Registry.
 *
 * Cache schema note: option key is prautoblogger_runware_model_cache_v2 (bumped
 * from v1) to cleanly invalidate cached rows that used the old taskType:models
 * response shape (taskType/requiresImage fields). The v1 option is left in place
 * and will be purged by the next WP options cleanup pass.
 *
 * Failure cooldown: prautoblogger_runware_catalog_last_failure_at records the
 * last failure timestamp. is_in_failure_cooldown() checks it against
 * prautoblogger_runware_catalog_failure_cooldown_seconds (default 3600s). This
 * caps errors from admin page loads to ~24/day (24h / 1h cooldown) rather than
 * 40+/day from every Settings/picker page load triggering a live sync.
 *
 * @see class-runware-catalog-fetcher.php      — Pagination + normalize (extracted for 300-line rule).
 * @see class-runware-image-pricing.php        — Authoritative pricing source & fallback models.
 * @see admin/class-image-model-registry.php   — Caller; merges Runware + OpenRouter lists.
 * @see class-prautoblogger.php                — Registers daily cron + AJAX hook.
 * @see class-activator.php / class-deactivator.php — Schedule / unschedule on activation/deactivation.
 */
class PRAutoBlogger_Runware_Model_Catalog {

	private const CACHE_TTL_SECONDS = 86400;

	/**
	 * Option key for the v2 normalized model cache (air-identifier schema).
	 * Bumped from v1 (prautoblogger_runware_model_cache) to force re-sync.
	 */
	private const CACHE_OPTION      = 'prautoblogger_runware_model_cache_v2';
	private const CACHE_TS_OPTION   = 'prautoblogger_runware_model_cache_updated_at';
	private const FAILURE_TS_OPTION = 'prautoblogger_runware_catalog_last_failure_at';
	private const COOLDOWN_SETTING  = 'prautoblogger_runware_catalog_failure_cooldown_seconds';
	private const COOLDOWN_DEFAULT  = 3600;

	/**
	 * Fetch, normalize, merge pricing, and cache the Runware model catalog.
	 * On success: writes cache + clears failure timestamp. Returns true.
	 * On failure: writes failure timestamp for cooldown. Returns false.
	 *
	 * Error handling: logs via PRAutoBlogger_Logger, never throws, always
	 *                 returns bool to allow fallback to cached/hardcoded list.
	 *
	 * @return bool True on successful fetch and cache update; false otherwise.
	 */
	public function sync(): bool {
		try {
			$api_key = $this->get_api_key();
			if ( '' === $api_key ) {
				PRAutoBlogger_Logger::instance()->warning(
					'Runware model catalog sync: API key not configured. Using cached/fallback models.',
					'runware-catalog'
				);
				return false;
			}

			$fetcher    = new PRAutoBlogger_Runware_Catalog_Fetcher();
			$raw_models = $fetcher->fetch_all_text_to_image( $api_key );

			if ( empty( $raw_models ) ) {
				PRAutoBlogger_Logger::instance()->warning(
					'Runware model catalog fetch returned empty list. Using cached/fallback models.',
					'runware-catalog'
				);
				$this->record_failure();
				return false;
			}

			$normalized   = $fetcher->normalize_models( $raw_models );
			$with_pricing = $this->merge_pricing( $normalized );

			if ( empty( $with_pricing ) ) {
				PRAutoBlogger_Logger::instance()->warning(
					'Runware model catalog normalization resulted in empty list. Using cached/fallback models.',
					'runware-catalog'
				);
				$this->record_failure();
				return false;
			}

			update_option( self::CACHE_OPTION, $with_pricing, false );
			update_option( self::CACHE_TS_OPTION, time(), false );
			delete_option( self::FAILURE_TS_OPTION );

			PRAutoBlogger_Logger::instance()->info(
				sprintf( 'Runware model catalog synced: %d models cached.', count( $with_pricing ) ),
				'runware-catalog'
			);
			return true;
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Runware model catalog sync failed: %s (%s)', $e->getMessage(), get_class( $e ) ),
				'runware-catalog'
			);
			$this->record_failure();
			return false;
		}
	}

	/**
	 * Return the normalized model list, using smart cache + fallback logic:
	 * fresh cache → cached; stale + not in cooldown → sync; stale cache fallback;
	 * no cache → hardcoded fallback (never empty).
	 *
	 * @return array<int, array<string, mixed>> Normalized model list.
	 */
	public function get_models(): array {
		if ( ! $this->is_stale() ) {
			$cached = get_option( self::CACHE_OPTION, array() );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		if ( ! $this->is_in_failure_cooldown() && $this->sync() ) {
			$cached = get_option( self::CACHE_OPTION, array() );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		$cached = get_option( self::CACHE_OPTION, array() );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			PRAutoBlogger_Logger::instance()->info(
				'Using stale Runware model cache (live sync failed or suppressed by cooldown).',
				'runware-catalog'
			);
			return $cached;
		}

		$fallback = PRAutoBlogger_Image_Model_Registry::get_runware_fallback_models();
		PRAutoBlogger_Logger::instance()->warning(
			sprintf(
				'No Runware model cache available; using hardcoded fallback (%d models).',
				count( $fallback )
			),
			'runware-catalog'
		);
		return $fallback;
	}

	/**
	 * Unix timestamp of the last successful sync, or null if never synced.
	 *
	 * @return int|null
	 */
	public function get_last_synced_at(): ?int {
		$ts = get_option( self::CACHE_TS_OPTION, null );
		if ( null === $ts ) {
			return null;
		}
		$ts = (int) $ts;
		return $ts > 0 ? $ts : null;
	}

	/**
	 * Unix timestamp of the last sync failure, or null if no failure on record.
	 *
	 * @return int|null
	 */
	public function get_last_failure_at(): ?int {
		$ts = get_option( self::FAILURE_TS_OPTION, null );
		if ( null === $ts ) {
			return null;
		}
		$ts = (int) $ts;
		return $ts > 0 ? $ts : null;
	}

	/**
	 * Whether the cache is stale (older than 24h) or absent.
	 *
	 * @return bool True if a refresh is warranted; false if still fresh.
	 */
	public function is_stale(): bool {
		$updated_at = $this->get_last_synced_at();
		if ( null === $updated_at ) {
			return true;
		}
		return ( time() - $updated_at ) > self::CACHE_TTL_SECONDS;
	}

	/**
	 * Whether a sync attempt should be suppressed due to a recent failure.
	 *
	 * The cooldown window is read from prautoblogger_runware_catalog_failure_cooldown_seconds
	 * (default 3600s). This prevents admin-page-load cascades (40+ errors/day)
	 * when the API endpoint or key is broken. A successful sync clears it.
	 *
	 * @return bool True if a sync should be suppressed; false if retry is permitted.
	 */
	public function is_in_failure_cooldown(): bool {
		$last_failure = $this->get_last_failure_at();
		if ( null === $last_failure ) {
			return false;
		}
		$cooldown = (int) get_option( self::COOLDOWN_SETTING, self::COOLDOWN_DEFAULT );
		if ( $cooldown <= 0 ) {
			$cooldown = self::COOLDOWN_DEFAULT;
		}
		return ( time() - $last_failure ) < $cooldown;
	}

	/**
	 * Merge pricing from PRAutoBlogger_Runware_Image_Pricing into normalized models.
	 * Models without a pricing entry get cost_per_image=null.
	 *
	 * @param array<int, array<string, mixed>> $normalized Normalized models.
	 * @return array<int, array<string, mixed>> Models with pricing merged.
	 */
	private function merge_pricing( array $normalized ): array {
		$pricing_table = PRAutoBlogger_Runware_Image_Pricing::get_model_costs();
		foreach ( $normalized as &$model ) {
			$model_id = $model['id'] ?? '';
			if ( isset( $pricing_table[ $model_id ] ) ) {
				$model['cost_per_image'] = (float) $pricing_table[ $model_id ];
			}
		}
		return $normalized;
	}

	/**
	 * Record the current time as the last sync failure timestamp.
	 *
	 * @return void
	 */
	private function record_failure(): void {
		update_option( self::FAILURE_TS_OPTION, time(), false );
	}

	/**
	 * Get the decrypted Runware API key from settings.
	 *
	 * @return string Plaintext key, or empty string if not configured.
	 */
	private function get_api_key(): string {
		$stored = (string) get_option( 'prautoblogger_runware_api_key', '' );
		if ( '' === $stored ) {
			return '';
		}
		if ( PRAutoBlogger_Encryption::is_encrypted( $stored ) ) {
			$decrypted = PRAutoBlogger_Encryption::decrypt( $stored );
			return '' === $decrypted ? '' : $decrypted;
		}
		return $stored;
	}
}
