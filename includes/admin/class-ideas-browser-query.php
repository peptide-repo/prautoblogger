<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Query helpers for PRAutoBlogger_Ideas_Browser.
 *
 * Extracted to keep class-ideas-browser.php under 300 lines (CONVENTIONS §1).
 * All methods are static; class is never instantiated.
 */
class PRAutoBlogger_Ideas_Browser_Query {

	/** Fall-through if setting is absent. */
	public const PER_PAGE_DEFAULT = 30;

	/** Transient key prefix for per-idea generation status. */
	public const STATUS_PREFIX = 'prab_idea_gen_';

	/** How long to keep per-idea status (seconds). */
	public const STATUS_TTL = 600;

	/** Query analysis results with optional filtering and pagination. */
	public static function query_ideas( int $paged, string $type ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'prautoblogger_analysis_results';
		$where  = array();
		$params = array();

		if ( '' !== $type ) {
			$where[]  = 'analysis_type = %s';
			$params[] = $type;
		}

		$where_sql = empty( $where ) ? '' : 'WHERE ' . implode( ' AND ', $where );
		$per_page  = max( 5, (int) get_option( 'prautoblogger_ideas_per_page', self::PER_PAGE_DEFAULT ) );
		$offset    = ( $paged - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$total = (int) $wpdb->get_var(  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			empty( $params )  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			? "SELECT COUNT(*) FROM {$table} {$where_sql}"  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", ...$params )  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$order_sql = 'ORDER BY analyzed_at DESC, relevance_score DESC';
		$limit_sql = sprintf( 'LIMIT %d OFFSET %d', $per_page, $offset );
		$full_sql  = "SELECT * FROM {$table} {$where_sql} {$order_sql} {$limit_sql}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = empty( $params )  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		? $wpdb->get_results( $full_sql, ARRAY_A )  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		: $wpdb->get_results( $wpdb->prepare( $full_sql, ...$params ), ARRAY_A );  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'  => $rows ?? array(),
			'total' => $total,
		);
	}

	/** Get counts per analysis_type for the filter badges. */
	public static function get_type_counts(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_analysis_results';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT analysis_type, COUNT(*) AS cnt FROM {$table} GROUP BY analysis_type ORDER BY cnt DESC",
			ARRAY_A
		);

		$counts = array();
		foreach ( $rows ?? array() as $row ) {
			$counts[ $row['analysis_type'] ] = (int) $row['cnt'];
		}
		return $counts;
	}

	/**
	 * Load an analysis result row and map it to Article_Idea constructor data.
	 *
	 * @param int $idea_id Analysis result row ID.
	 * @return array<string, mixed>|null Article_Idea-compatible array, or null.
	 */
	public static function load_idea_data( int $idea_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_analysis_results';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row(  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $idea_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}

		$meta = json_decode( $row['metadata_json'] ?? '{}', true ) ?? array();
		return array(
			'topic'           => $row['topic'],
			'article_type'    => $row['analysis_type'],
			'suggested_title' => $meta['suggested_title'] ?? $row['topic'],
			'summary'         => $row['summary'] ?? '',
			'score'           => (float) $row['relevance_score'],
			'analysis_id'     => (int) $row['id'],
			'source_ids'      => json_decode( $row['source_ids_json'] ?? '[]', true ) ?? array(),
			'key_points'      => $meta['key_points'] ?? array(),
			'target_keywords' => $meta['target_keywords'] ?? array(),
		);
	}

	/** Find the most recent post created by a specific run_id. */
	public static function find_post_by_run_id( string $run_id ): ?int {
		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_generation_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$post_id = $wpdb->get_var(
			$wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT DISTINCT post_id FROM {$table} WHERE run_id = %s AND post_id IS NOT NULL LIMIT 1",
				$run_id
			)
		);
		return null !== $post_id ? (int) $post_id : null;
	}

	/** Update the per-idea status transient with a stage message. */
	public static function update_idea_stage( int $idea_id, string $stage ): void {
		$key     = self::STATUS_PREFIX . $idea_id;
		$current = get_transient( $key );
		if ( is_array( $current ) ) {
			$current['stage'] = $stage;
			set_transient( $key, $current, self::STATUS_TTL );
		}
	}
}
