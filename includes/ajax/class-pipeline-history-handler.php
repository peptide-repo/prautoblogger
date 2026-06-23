<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * AJAX handler: fetch version history and compute diffs for a prompt key.
 *
 * What: Two actions on one class —
 *   prautoblogger_pipeline_history: returns all stored versions for a key
 *       (version number, author, created_at, active flag). Read-only; never
 *       mutates the registry.
 *   prautoblogger_pipeline_diff: returns a line-level unified diff between
 *       two specified versions (or between a version and the current active
 *       body). Diff is computed server-side (no external binary required)
 *       and returned as an array of {type, text} lines (added/removed/context)
 *       so the JS renderer can apply colour without parsing unified format.
 *
 * Security: manage_options cap + nonce on every request. All registry key
 *           inputs are validated against the Step_Map allowlist. Diff output
 *           is esc_html'd before encoding.
 *
 * Who triggers it: pipeline-settings.js on history accordion open and diff
 *                  button click.
 * Dependencies: PRAutoBlogger_Prompt_Registry_Writer (list_versions),
 *               PRAutoBlogger_Prompt_Registry (get_active, default_body),
 *               PRAutoBlogger_Pipeline_Settings_Step_Map (key allowlist).
 *
 * @see ajax/class-pipeline-preview-handler.php   -- Preview assembled text.
 * @see core/class-prompt-registry-writer.php     -- list_versions() source.
 * @see ARCHITECTURE.md #21                       -- Prompt registry design.
 */
class PRAutoBlogger_Pipeline_History_Handler {

	/** AJAX action: list all versions for a key. */
	public const ACTION_HISTORY = 'prautoblogger_pipeline_history';

	/** AJAX action: diff two versions. */
	public const ACTION_DIFF = 'prautoblogger_pipeline_diff';

	/**
	 * Register wp-ajax hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'wp_ajax_' . self::ACTION_HISTORY, array( __CLASS__, 'handle_history' ) );
		add_action( 'wp_ajax_' . self::ACTION_DIFF, array( __CLASS__, 'handle_diff' ) );
	}

	/**
	 * Return all stored versions for a prompt key, newest first.
	 *
	 * POST fields:
	 *   nonce      string  wp_nonce for 'prautoblogger_pipeline_history'.
	 *   prompt_key string  Registry key slug (dots to hyphens).
	 *
	 * @return void
	 */
	public static function handle_history(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ), 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION_HISTORY ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'prautoblogger' ) ), 403 );
		}

		$slug       = isset( $_POST['prompt_key'] ) ? sanitize_key( wp_unslash( $_POST['prompt_key'] ) ) : '';
		$prompt_key = self::resolve_key_from_slug( $slug );

		if ( null === $prompt_key ) {
			wp_send_json_error( array( 'message' => __( 'Unknown prompt key.', 'prautoblogger' ) ), 400 );
		}

		$rows     = PRAutoBlogger_Prompt_Registry_Writer::list_versions( $prompt_key );
		$versions = array();
		foreach ( $rows as $row ) {
			$versions[] = array(
				'version'    => (int) $row['version'],
				'author'     => esc_html( (string) $row['author'] ),
				'created_at' => esc_html( (string) $row['created_at'] ),
				'active'     => (bool) $row['active'],
			);
		}

		wp_send_json_success( array( 'versions' => $versions ) );
	}

	/**
	 * Compute and return a line-level diff between two prompt versions.
	 *
	 * POST fields:
	 *   nonce      string  wp_nonce for 'prautoblogger_pipeline_diff'.
	 *   prompt_key string  Registry key slug (dots to hyphens).
	 *   version_a  int     Older version number (or 0 = factory default).
	 *   version_b  int     Newer version number (or 0 = current active).
	 *
	 * Returns {lines: [{type: 'added'|'removed'|'context', text: string}], header: string}.
	 *
	 * @return void
	 */
	public static function handle_diff(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ), 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION_DIFF ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'prautoblogger' ) ), 403 );
		}

		$slug       = isset( $_POST['prompt_key'] ) ? sanitize_key( wp_unslash( $_POST['prompt_key'] ) ) : '';
		$prompt_key = self::resolve_key_from_slug( $slug );
		if ( null === $prompt_key ) {
			wp_send_json_error( array( 'message' => __( 'Unknown prompt key.', 'prautoblogger' ) ), 400 );
		}

		$version_a = isset( $_POST['version_a'] ) ? (int) $_POST['version_a'] : 0;
		$version_b = isset( $_POST['version_b'] ) ? (int) $_POST['version_b'] : 0;

		$body_a = self::fetch_version_body( $prompt_key, $version_a );
		$body_b = self::fetch_version_body( $prompt_key, $version_b );

		if ( null === $body_a || null === $body_b ) {
			wp_send_json_error( array( 'message' => __( 'Version not found.', 'prautoblogger' ) ), 404 );
		}

		$label_a = 0 === $version_a ? __( 'default', 'prautoblogger' ) : sprintf( 'v%d', $version_a );
		$label_b = 0 === $version_b ? __( 'current', 'prautoblogger' ) : sprintf( 'v%d', $version_b );

		$lines = self::compute_diff( $body_a, $body_b );

		wp_send_json_success(
			array(
				'lines'  => $lines,
				'header' => esc_html(
					sprintf(
						/* translators: 1: prompt key, 2: old version label, 3: new version label */
						__( '%1$s — %2$s → %3$s', 'prautoblogger' ),
						$prompt_key,
						$label_a,
						$label_b
					)
				),
			)
		);
	}

	/**
	 * Fetch the body text for a specific version number.
	 * Version 0 means the factory default (in-code canonical body).
	 *
	 * @param string $key     Registry key.
	 * @param int    $version Version number, or 0 for factory default.
	 * @return string|null Body text, or null when not found.
	 */
	private static function fetch_version_body( string $key, int $version ): ?string {
		if ( 0 === $version ) {
			return PRAutoBlogger_Prompt_Registry::default_body( $key );
		}
		$rows = PRAutoBlogger_Prompt_Registry_Writer::list_versions( $key );
		foreach ( $rows as $row ) {
			if ( (int) $row['version'] === $version ) {
				return (string) $row['body'];
			}
		}
		return null;
	}

	/**
	 * Compute a line-level diff of two text bodies.
	 *
	 * Uses a simple LCS-based diff (no external binary). Returns an array of
	 * {type: 'added'|'removed'|'context', text: string} objects with
	 * text already esc_html'd. Context lines are capped at 3 surrounding
	 * changed lines to keep response size bounded.
	 *
	 * @param string $old Old text body.
	 * @param string $new New text body.
	 * @return array<int, array{type: string, text: string}>
	 */
	private static function compute_diff( string $old, string $new ): array {
		$old_lines = explode( "\n", $old );
		$new_lines = explode( "\n", $new );
		$m         = count( $old_lines );
		$n         = count( $new_lines );

		// LCS table — for reasonable-sized prompts (typically < 200 lines).
		$lcs = array_fill( 0, $m + 1, array_fill( 0, $n + 1, 0 ) );
		for ( $i = 1; $i <= $m; $i++ ) {
			for ( $j = 1; $j <= $n; $j++ ) {
				if ( $old_lines[ $i - 1 ] === $new_lines[ $j - 1 ] ) {
					$lcs[ $i ][ $j ] = $lcs[ $i - 1 ][ $j - 1 ] + 1;
				} else {
					$lcs[ $i ][ $j ] = max( $lcs[ $i - 1 ][ $j ], $lcs[ $i ][ $j - 1 ] );
				}
			}
		}

		// Backtrack to build edit ops.
		$ops = array();
		$i   = $m;
		$j   = $n;
		while ( $i > 0 || $j > 0 ) {
			if ( $i > 0 && $j > 0 && $old_lines[ $i - 1 ] === $new_lines[ $j - 1 ] ) {
				array_unshift( $ops, array( 'context', $old_lines[ $i - 1 ] ) );
				--$i;
				--$j;
			} elseif ( $j > 0 && ( 0 === $i || $lcs[ $i ][ $j - 1 ] >= $lcs[ $i - 1 ][ $j ] ) ) {
				array_unshift( $ops, array( 'added', $new_lines[ $j - 1 ] ) );
				--$j;
			} else {
				array_unshift( $ops, array( 'removed', $old_lines[ $i - 1 ] ) );
				--$i;
			}
		}

		// Collapse context: keep 3 lines before/after each changed block.
		$context_radius = 3;
		$changed        = array();
		foreach ( $ops as $idx => $op ) {
			if ( 'context' !== $op[0] ) {
				$changed[ $idx ] = true;
			}
		}

		$result  = array();
		$skipped = 0;
		foreach ( $ops as $idx => $op ) {
			$near_change = false;
			for ( $d = -$context_radius; $d <= $context_radius; $d++ ) {
				if ( isset( $changed[ $idx + $d ] ) ) {
					$near_change = true;
					break;
				}
			}
			if ( 'context' === $op[0] && ! $near_change ) {
				$skipped++;
				continue;
			}
			if ( $skipped > 0 ) {
				$result[] = array(
					'type' => 'omitted',
					'text' => esc_html(
						sprintf(
							/* translators: %d: number of skipped context lines */
							__( '… %d lines unchanged …', 'prautoblogger' ),
							$skipped
						)
					),
				);
				$skipped = 0;
			}
			$result[] = array(
				'type' => $op[0],
				'text' => esc_html( $op[1] ),
			);
		}
		if ( $skipped > 0 ) {
			$result[] = array(
				'type' => 'omitted',
				'text' => esc_html(
					sprintf(
						/* translators: %d: number of skipped context lines */
						__( '… %d lines unchanged …', 'prautoblogger' ),
						$skipped
					)
				),
			);
		}

		return $result;
	}

	/**
	 * Resolve a slug back to the canonical registry key via the step map allowlist.
	 *
	 * @param string $slug Slug from POST (after sanitize_key).
	 * @return string|null Canonical key, or null when not in the allowlist.
	 */
	private static function resolve_key_from_slug( string $slug ): ?string {
		foreach ( PRAutoBlogger_Pipeline_Settings_Step_Map::allowed_prompt_keys() as $key ) {
			if ( sanitize_key( str_replace( '.', '-', $key ) ) === $slug ) {
				return $key;
			}
		}
		return null;
	}
}
