<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Database-level atomic mutex for content generation.
 *
 * What: Provides acquire/release/check semantics for a single-writer generation lock.
 * Who calls it: PRAutoBlogger_Executor (daily and manual generation), status polling fallback.
 * Dependencies: WordPress $wpdb for direct SQL.
 *
 * Uses INSERT IGNORE against the wp_options UNIQUE index on option_name to
 * guarantee only one PHP process can hold the lock at a time. Expired locks
 * (>1 hour) are cleaned up automatically on acquire to prevent permanent deadlock.
 *
 * @see class-executor.php — Acquires/releases the lock around pipeline runs.
 * @see class-ajax-handlers.php — Releases stale locks in the status fallback path.
 */
class PRAutoBlogger_Generation_Lock {

	/** @var string Option name used as the lock key in wp_options. */
	private const LOCK_NAME = 'prautoblogger_generation_lock';

	/**
	 * Acquire the generation mutex.
	 *
	 * Cleans up expired locks (>1 hour) first, then attempts an atomic INSERT
	 * IGNORE. The UNIQUE constraint on option_name ensures only one caller wins.
	 *
	 * @return bool True if lock acquired, false if already held.
	 */
	public static function acquire(): bool {
		global $wpdb;

		// Clean up expired locks to prevent permanent deadlock.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
				self::LOCK_NAME,
				time() - HOUR_IN_SECONDS
			)
		);

		// Atomic insert — UNIQUE constraint guarantees only one process succeeds.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				self::LOCK_NAME,
				(string) time(),
				'no'
			)
		);

		return $result > 0;
	}

	/**
	 * Check if the generation lock is currently held.
	 *
	 * @return bool True if the lock exists (generation in progress or stuck).
	 */
	public static function is_locked(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::LOCK_NAME
			)
		);

		return null !== $row;
	}

	/**
	 * Get the Unix timestamp when the lock was acquired, or null if not held.
	 *
	 * Returns null when the lock is not set. The timestamp is the value written
	 * by acquire() — used by the status poller to detect infrastructure-timeout
	 * runs (lock held longer than STATUS_TTL → background PHP process died).
	 *
	 * @return int|null Unix timestamp, or null when the lock is absent.
	 */
	public static function get_acquired_at(): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::LOCK_NAME
			)
		);

		return null !== $row ? (int) $row : null;
	}

	/**
	 * Release the generation mutex.
	 *
	 * Safe to call even if the lock is not held (no-ops gracefully).
	 *
	 * Side effects: deletes the lock row from wp_options.
	 */
	public static function release(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s",
				self::LOCK_NAME
			)
		);
	}
}
