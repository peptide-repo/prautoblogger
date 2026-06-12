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
 * v0.20.0 (M3): stage rows are filtered to THIS post's item (item_key
 * from the idea-hash meta; multi-article runs no longer collide) and
 * gen_log rows exclude entries linked to OTHER posts; per-stage edit/
 * re-run affordance data + run spend ride the view model
 * (Dossier_Rerun_Panel_Data); log-only stages (image_a/image_b/
 * llm_research/... -- generation_log rows with no run_stages row) and
 * the post's pipeline attachments are exposed so the image sections
 * render from their REAL data sources (QA M2 F3).
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
			'item_key'      => '',
			'eligibility'   => array(
				'ok'     => false,
				'reason' => '',
			),
			'spend'              => PRAutoBlogger_Dossier_Rerun_Panel_Data::spend( null ),
			'human_modified_any' => false,
			'stale_any'          => false,
			'log_only_stages'    => array(),
			'images'             => array(),
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

		$idea_hash         = (string) get_post_meta( $post_id, '_prautoblogger_idea_hash', true );
		$item_key          = '' !== $idea_hash ? 'idea:' . $idea_hash : '';
		$base['item_key']  = $item_key;

		$base['has_run']       = true;
		$base['run']           = $run;
		$base['stages']        = $this->get_stage_rows( $run_id, $item_key );
		$base['decisions']     = $this->get_decision_rows( $run_id );
		$base['gen_log_index'] = $this->filter_log_for_post( $this->log_query->get_by_run( $run_id ), $post_id );
		$base['eligibility']   = PRAutoBlogger_Rerun_Eligibility::check( $run_id, $post_id );
		$base['spend']         = PRAutoBlogger_Dossier_Rerun_Panel_Data::spend( $run );

		foreach ( $base['stages'] as $role_key => $stage_row ) {
			$stage_name = (string) ( $stage_row['stage'] ?? '' );
			$rerun      = PRAutoBlogger_Dossier_Rerun_Panel_Data::for_stage(
				$stage_row,
				$base['gen_log_index'][ $stage_name ] ?? array(),
				$run_id,
				$base['eligibility']
			);
			$base['stages'][ $role_key ]['rerun'] = $rerun;
			$base['human_modified_any']           = $base['human_modified_any'] || $rerun['human_modified'];
			$base['stale_any']                    = $base['stale_any'] || $rerun['stale'];
		}

		// QA M2 F3: stages that exist only in generation_log (image_a/
		// image_b, llm_research, image_prompt_rewrite, ...) render from
		// their real source instead of silently disappearing.
		$run_stage_names         = array();
		foreach ( $base['stages'] as $stage_row ) {
			$run_stage_names[ (string) ( $stage_row['stage'] ?? '' ) ] = true;
		}
		$base['log_only_stages'] = array_values( array_diff( array_keys( $base['gen_log_index'] ), array_keys( $run_stage_names ) ) );
		$base['images']          = PRAutoBlogger_Dossier_Image_Data::for_post( $post_id );

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
	 * Fetch run_stages rows for a run, indexed by stage name — scoped to
	 * one item (+ run-level rows) when an item key is known.
	 *
	 * @param string $run_id   Pipeline run UUID.
	 * @param string $item_key Item key ('' = no scoping, M2 behavior).
	 * @return array<string, array<string, mixed>> Stage rows keyed by stage name.
	 */
	private function get_stage_rows( string $run_id, string $item_key = '' ): array {
		if ( ! PRAutoBlogger_Run_Stage_State::is_available() ) {
			return array();
		}
		global $wpdb;
		if ( null === $wpdb ) {
			return array();
		}
		$table = PRAutoBlogger_Run_Stage_State::table_name();
		// v0.20.0: scope to THIS post's item + run-level rows. When the
		// idea-hash meta is absent (pre-v0.18 posts) fall back to the
		// unfiltered M2 behavior (single-item runs are unaffected).
		if ( '' !== $item_key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %s AND item_key IN (%s, '') ORDER BY id ASC", $run_id, $item_key ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %s ORDER BY id ASC", $run_id ),
				ARRAY_A
			);
		}

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
	 * Drop gen_log rows linked to OTHER posts (multi-article runs share
	 * one run_id). Rows with no post linkage (NULL/0 — run-level work
	 * and pre-link entries) are kept.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $log_index Rows keyed by stage.
	 * @param int                                             $post_id   This dossier's post.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function filter_log_for_post( array $log_index, int $post_id ): array {
		$filtered = array();
		foreach ( $log_index as $stage => $rows ) {
			foreach ( $rows as $row ) {
				$row_post = (int) ( $row['post_id'] ?? 0 );
				if ( 0 !== $row_post && $row_post !== $post_id ) {
					continue;
				}
				$filtered[ $stage ][] = $row;
			}
		}
		return $filtered;
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
