<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Write side of the versioned prompt registry: create / activate / seed.
 *
 * What: Companion to PRAutoBlogger_Prompt_Registry (split for the 300-line
 *       cap). Owns every mutation of `wp_prautoblogger_prompts` and
 *       enforces the immutability rule: a stored body is NEVER updated —
 *       a change is a new (prompt_key, version) row, and `activate()`
 *       flips the single active flag per key. `seed_v1()` writes the
 *       canonical v0.16.0 texts as version 1 (idempotent — keys that
 *       already have rows keep their history). This is the API surface
 *       the Phase-2 in-admin Prompts editor will call; Phase 1 ships no UI.
 * Who triggers it: PRAutoBlogger_Activator (seed migration on db 1.2.0),
 *       Phase-2 admin (create/activate), WP-CLI (future).
 * Dependencies: WordPress $wpdb, PRAutoBlogger_Prompt_Registry (table name,
 *       availability probe, defs, cache flush).
 *
 * @see core/class-prompt-registry.php — Read side (render, pins, fallback).
 * @see class-activator.php            — Calls seed_v1() once, flag-gated.
 */
class PRAutoBlogger_Prompt_Registry_Writer {

	/**
	 * All versions of a key, newest first (Phase-2 list/diff support).
	 *
	 * @param string $key Registry key.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_versions( string $key ): array {
		if ( ! PRAutoBlogger_Prompt_Registry::is_available() ) {
			return array();
		}
		global $wpdb;
		$table = PRAutoBlogger_Prompt_Registry::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE prompt_key = %s ORDER BY version DESC", $key ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Create a NEW immutable version of a key and (optionally) activate it.
	 * Never mutates an existing row's body — that is the whole point.
	 *
	 * @param string                    $key      Registry key.
	 * @param string                    $body     Template body.
	 * @param string|null               $model    Optional model hint.
	 * @param array<string, mixed>|null $params   Optional params snapshot.
	 * @param string                    $author   Who created it (seed tag, login, agent).
	 * @param bool                      $activate Flip active to this version (default true).
	 * @return int New version number, or 0 on failure / table unavailable.
	 */
	public static function create_version( string $key, string $body, ?string $model, ?array $params, string $author, bool $activate = true ): int {
		if ( ! PRAutoBlogger_Prompt_Registry::is_available() ) {
			return 0;
		}
		global $wpdb;
		$table = PRAutoBlogger_Prompt_Registry::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$max  = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT MAX(version) FROM {$table} WHERE prompt_key = %s", $key )
		);
		$next = $max + 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert(
			$table,
			array(
				'prompt_key'  => $key,
				'version'     => $next,
				'body'        => $body,
				'model'       => $model,
				'params_json' => null !== $params ? wp_json_encode( $params ) : null,
				'author'      => $author,
				'created_at'  => current_time( 'mysql' ),
				'active'      => 0,
			)
		);
		if ( false === $ok ) {
			return 0;
		}
		if ( $activate ) {
			self::activate( $key, $next );
		}
		return $next;
	}

	/**
	 * Make one version the single active version of its key.
	 *
	 * @param string $key     Registry key.
	 * @param int    $version Version to activate.
	 * @return bool True when the version is now active.
	 */
	public static function activate( string $key, int $version ): bool {
		if ( ! PRAutoBlogger_Prompt_Registry::is_available() ) {
			return false;
		}
		global $wpdb;
		$table = PRAutoBlogger_Prompt_Registry::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET active = 0 WHERE prompt_key = %s", $key ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET active = 1 WHERE prompt_key = %s AND version = %d", $key, $version )
		);
		PRAutoBlogger_Prompt_Registry::flush_cache();
		return 1 === (int) $updated;
	}

	/**
	 * Seed every registry key with its canonical v1 body. Idempotent: keys
	 * that already have rows are left untouched (their history is theirs).
	 *
	 * Side effects: up to one INSERT + two UPDATEs per missing key.
	 *
	 * @param string $author Audit tag for the seed rows (e.g. 'seed:v0.18.0').
	 * @return int Number of keys seeded.
	 */
	public static function seed_v1( string $author = 'seed:v0.18.0' ): int {
		if ( ! PRAutoBlogger_Prompt_Registry::is_available() ) {
			return 0;
		}
		$seeded = 0;
		foreach ( PRAutoBlogger_Prompt_Registry::defs() as $key => $def ) {
			if ( ! empty( self::list_versions( $key ) ) ) {
				continue;
			}
			$model   = null !== $def['model_option'] ? (string) get_option( $def['model_option'], '' ) : null;
			$version = self::create_version( $key, $def['body'], '' !== (string) $model ? $model : null, $def['params'], $author, true );
			if ( $version > 0 ) {
				++$seeded;
			}
		}
		PRAutoBlogger_Prompt_Registry::flush_cache();
		return $seeded;
	}
}
