<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Assembles the view model for the Article Dossier page.
 *
 * Orchestrates 5 queries (all indexed by run_id or post_id):
 *   1. wp_prautoblogger_runs         -- run ledger row
 *   2. wp_prautoblogger_run_stages   -- all stage rows for the run
 *   3. wp_prautoblogger_generation_log -- per-stage cost + raw trace
 *   4. wp_prautoblogger_run_decisions  -- rationale per stage
 *   5. post meta (WP object cache)     -- run_id + verdict + keywords
 *
 * Legacy posts (no run row) return ['has_run' => false] -- never notices.
 * Amortized research rows (pv=null, role='') render gracefully.
 *
 * Triggered by: PRAutoBlogger_Dossier_Page::render_page().
 * Dependencies: PRAutoBlogger_Dossier_Gen_Log_Query, PRAutoBlogger_Stage_Display_Map,
 *               PRAutoBlogger_Run_Stage_State.
 *
 * @see admin/class-dossier-gen-log-query.php -- Raw generation_log queries.
 * @see core/class-run-stage-state.php        -- Stage I/O access.
 * @see ARCHITECTURE.md                        -- Substrate schema.
 */
class PRAutoBlogger_Dossier_Data_Assembler {

	/** @var PRAutoBlogger_Dossier_Gen_Log_Query */
	private PRAutoBlogger_Dossier_Gen_Log_Query $log_query;

	/**
	 * @param PRAutoBlogger_Dossier_Gen_Log_Query|null $log_query Injectable for tests.
	 */
	public function __construct( ?PRAutoBlogger_Dossier_Gen_Log_Query $log_query = null ) {
		$this->log_query = $log_query ?? new PRAutoBlogger_Dossier_Gen_Log_Query();
	}

	/**
	 * Assemble the full dossier view model for a post.
	 *
	 * @param int $post_id WordPress post ID (0 = bogus/not found).
	 * @return array<string, mixed> View model consumed by dossier-page.php.
	 */
	public function assemble( int $post_id ): array {
		$base = array(
			'post_id'       => $post_id,
			'has_run'       => false,
			'post_title'    => '',
			'post_status'   => '',
			'verdict'       => '',
			'tier'          => '',
			'run_id'        => '',
			'run'           => null,
			'stages'        => array(),
			'decisions'     => array(),
			'gen_log_index' => array(),
		);

		if ( $post_id <= 0 ) {
			return $base;
		}

		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) ) {
			return $base;
		}

		$base['post_title']  = $post->post_title;
		$base['post_status'] = $post->post_status;
		$base['verdict']     = (string) get_post_meta( $post_id, '_prautoblogger_editor_verdict', true );
		$base['tier']        = (string) get_post_meta( $post_id, '_prautoblogger_article_type', true );

		$run_id = (string) get_post_meta( $post_id, '_prautoblogger_run_id', true );
		if ( '' === $run_id ) {
			return $base;
		}
		$base['run_id'] = $run_id;

		$run = $this->get_run_row( $run_id );
		if ( null === $run ) {
			return $base;
		}

		$base['has_run']       = true;
		$base['run']           = $run;
		$base['stages']        = $this->get_stage_rows( $run_id );
		$base['decisions']     = $this->get_decision_rows( $run_id );
		$base['gen_log_index'] = $this->log_query->get_by_run( $run_id );

		return $base;
	}

	/**
	 * Fetch the run ledger row.
	 *
	 * @param string $run_id Pipeline run UUID.
	 * @return array<string, mixed>|null
	 */
	private function get_run_row( string $run_id ): ?array {
		global $wpdb;
		if ( null === $wpdb ) {
			return null;
		}
		$table = $wpdb->prefix . 'prautoblogger_runs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %s", $run_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Fetch all run_stages rows for a run, indexed by stage name.
	 *
	 * @param string $run_id Pipeline run UUID.
	 * @return array<string, array<string, mixed>> Stage rows keyed by stage name.
	 */
	private function get_stage_rows( string $run_id ): array {
		if ( ! PRAutoBlogger_Run_Stage_State::is_available() ) {
			return array();
		}
		global $wpdb;
		if ( null === $wpdb ) {
			return array();
		}
		$table = PRAutoBlogger_Run_Stage_State::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %s ORDER BY id ASC", $run_id ),
			ARRAY_A
		);

		$index = array();
		foreach ( ( $rows ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$stage    = (string) ( $row['stage'] ?? '' );
			$role_key = $stage . ':' . ( $row['agent_role'] ?? '' );
			$row['display_output'] = $this->extract_output( $row );
			$index[ $role_key ]    = $row;
		}
		return $index;
	}

	/**
	 * Extract the text output from a run_stages meta_json blob.
	 * Returns null when meta_json is NULL (pruned) or absent.
	 *
	 * @param array<string, mixed> $row Stage row.
	 * @return string|null
	 */
	private function extract_output( array $row ): ?string {
		if ( empty( $row['meta_json'] ) ) {
			return null;
		}
		$meta = json_decode( (string) $row['meta_json'], true );
		return ( is_array( $meta ) && isset( $meta['output'] ) ) ? (string) $meta['output'] : null;
	}

	/**
	 * Fetch all run_decisions rows, indexed by stage name.
	 *
	 * @param string $run_id Pipeline run UUID.
	 * @return array<string, array<string, mixed>> Decision rows keyed by stage.
	 */
	private function get_decision_rows( string $run_id ): array {
		global $wpdb;
		if ( null === $wpdb ) {
			return array();
		}
		$table = $wpdb->prefix . 'prautoblogger_run_decisions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %s ORDER BY id ASC", $run_id ),
			ARRAY_A
		);

		$index = array();
		foreach ( ( $rows ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$stage          = (string) ( $row['stage'] ?? 'unknown' );
			$index[ $stage ] = $row;
		}
		return $index;
	}
}
