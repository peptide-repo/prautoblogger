<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Versioned prompt registry — immutable versions, one active row per key.
 *
 * What: Read/write API over `wp_prautoblogger_prompts`. Rendering resolves
 *       the ACTIVE body for a key and fills `{{ token }}` placeholders;
 *       when the table is missing or has no row for the key (half-migrated
 *       site, failed seed), it falls back to the canonical defaults the
 *       seed itself uses — byte-identical output, no fatals (self-healing).
 *       Versions are IMMUTABLE: there is no update path for a stored body;
 *       changes create a new (prompt_key, version) row and flip `active`
 *       (write side lives in PRAutoBlogger_Prompt_Registry_Writer).
 *       Runs pin the active version of every key at start (stored on the
 *       runs row) so generation_log rows can be stamped with the exact
 *       prompt_version they rendered with. No admin UI in Phase 1 — the
 *       list/activate/create API is what the Phase-2 Prompts screen needs.
 * Who triggers it: Content_Prompts / Analysis_Prompts / Chief_Editor /
 *       LLM_Research_Provider (render), Cost_Tracker (pins_for_run),
 *       Activator (seed_v1), Cost_Tracker::set_run_id (pin_for_run).
 * Dependencies: WordPress $wpdb, Prompt_Defaults(+Editorial), wp_json_encode.
 *
 * @see core/class-prompt-defaults.php           — Canonical v1 bodies (seed + fallback).
 * @see core/class-prompt-defaults-editorial.php — Editor/research/image bodies.
 * @see core/class-run-state.php                 — Owns the runs row the pins live on.
 * @see ARCHITECTURE.md #21                      — Design rationale.
 */
class PRAutoBlogger_Prompt_Registry {

	/** @var array<string, ?array<string, mixed>> Per-request active-row cache. */
	private static array $active_cache = array();

	/** @var array<string, array<string, int>> Per-request run-pins cache. */
	private static array $pins_cache = array();

	/** @var bool|null Per-request "table exists" probe result. */
	private static ?bool $table_ok = null;

	/** Fully-qualified prompts table name (shared with Prompt_Registry_Writer). */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'prautoblogger_prompts';
	}

	/**
	 * Whether the prompts table exists and is queryable. Cached per request;
	 * never throws — any failure reads as "not available" and every consumer
	 * falls back to the in-code defaults.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( null !== self::$table_ok ) {
			return self::$table_ok;
		}
		global $wpdb;
		if ( null === $wpdb ) {
			return false; // Not cached: $wpdb may appear later in boot.
		}
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found          = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		self::$table_ok = ( $found === $table );
		return self::$table_ok;
	}

	/**
	 * All registry definitions (content + analysis + editor + research + image).
	 *
	 * @return array<string, array{body: string, model_option: ?string, params: array<string, mixed>}>
	 */
	public static function defs(): array {
		return array_merge(
			PRAutoBlogger_Prompt_Defaults::defs(),
			PRAutoBlogger_Prompt_Defaults_Editorial::defs()
		);
	}

	/**
	 * Canonical in-code default body for a key (the same text seeded as v1).
	 *
	 * @param string $key Registry key.
	 * @return string|null Body template, or null for unknown keys.
	 */
	public static function default_body( string $key ): ?string {
		$defs = self::defs();
		return isset( $defs[ $key ] ) ? $defs[ $key ]['body'] : null;
	}

	/**
	 * Active row for a key, or null when none / table unavailable.
	 *
	 * @param string $key Registry key.
	 * @return array<string, mixed>|null Row with prompt_key, version, body, model, params_json, author, created_at, active.
	 */
	public static function get_active( string $key ): ?array {
		if ( array_key_exists( $key, self::$active_cache ) ) {
			return self::$active_cache[ $key ];
		}
		$row = null;
		if ( self::is_available() ) {
			global $wpdb;
			$table = self::table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE prompt_key = %s AND active = 1 ORDER BY version DESC LIMIT 1",
					$key
				),
				ARRAY_A
			);
			$row = is_array( $row ) ? $row : null;
		}
		self::$active_cache[ $key ] = $row;
		return $row;
	}

	/**
	 * Render a prompt: active body for the key (fallback: canonical default),
	 * with `{{ token }}` placeholders substituted.
	 *
	 * @param string                $key    Registry key (e.g. 'content.single_pass').
	 * @param array<string, string> $tokens Token name => replacement value.
	 * @return string Rendered prompt ('' only if the key is unknown AND absent).
	 */
	public static function render( string $key, array $tokens = array() ): string {
		$active = self::get_active( $key );
		$body   = null !== $active ? (string) $active['body'] : (string) self::default_body( $key );
		return self::fill( $body, $tokens );
	}

	/**
	 * Substitute `{{ token }}` placeholders in a template body.
	 *
	 * @param string                $body   Template body.
	 * @param array<string, string> $tokens Token name => replacement value.
	 * @return string
	 */
	public static function fill( string $body, array $tokens ): string {
		foreach ( $tokens as $name => $value ) {
			$body = str_replace( '{{ ' . $name . ' }}', (string) $value, $body );
		}
		return $body;
	}

	/**
	 * Map of prompt_key => active version for every key present in the table.
	 *
	 * @return array<string, int>
	 */
	public static function active_versions(): array {
		if ( ! self::is_available() ) {
			return array();
		}
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT prompt_key, version FROM {$table} WHERE active = 1", ARRAY_A );
		$map  = array();
		foreach ( ( is_array( $rows ) ? $rows : array() ) as $row ) {
			$map[ (string) $row['prompt_key'] ] = (int) $row['version'];
		}
		return $map;
	}

	/**
	 * Pin the current active versions onto a run's row (once, at run start).
	 * No-ops when the runs row already carries pins (resume keeps the
	 * versions the run started with) or when tables are unavailable.
	 *
	 * @param string $run_id Pipeline run UUID.
	 * @return void
	 */
	public static function pin_for_run( string $run_id ): void {
		if ( '' === $run_id || ! self::is_available() ) {
			return;
		}
		$pins = self::active_versions();
		if ( empty( $pins ) ) {
			return;
		}
		PRAutoBlogger_Run_State::set_pins_if_absent( $run_id, $pins );
		unset( self::$pins_cache[ $run_id ] );
	}

	/**
	 * The pinned prompt versions for a run (cached per request). Empty when
	 * the run has no pins (pre-1.2.0 rows, standalone calls, missing table).
	 *
	 * @param string|null $run_id Pipeline run UUID, or null.
	 * @return array<string, int>
	 */
	public static function pins_for_run( ?string $run_id ): array {
		if ( null === $run_id || '' === $run_id ) {
			return array();
		}
		if ( isset( self::$pins_cache[ $run_id ] ) ) {
			return self::$pins_cache[ $run_id ];
		}
		$pins                          = PRAutoBlogger_Run_State::get_pins( $run_id );
		self::$pins_cache[ $run_id ] = $pins;
		return $pins;
	}

	/** Reset per-request caches (tests). */
	public static function flush_cache(): void {
		self::$active_cache = array();
		self::$pins_cache   = array();
		self::$table_ok     = null;
	}
}
