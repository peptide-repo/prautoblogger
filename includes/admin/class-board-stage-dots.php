<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Enriches board cards with lightweight run_stages dot-rail data (M5).
 *
 * Extracted from PRAutoBlogger_Board_Data_Provider to stay under the
 * 300-line cap. Performs ONE batched run_stages query for all cards
 * in a section, never one query per card.
 *
 * Each card gains `run_stages_summary`: an ordered array of
 * { stage, status } where status is one of:
 *   'done' | 'active' | 'failed' | 'pending'
 * Used by the board.js stage dot-rail. The inspector's full I/O is
 * fetched separately on row-click via Board_Inspector_Handler.
 *
 * Falls back gracefully when the run_stages table is unavailable or a
 * card has no run_id (generating cards from the transient path).
 *
 * Triggered by: PRAutoBlogger_Board_Data_Provider::get_board_snapshot().
 * Dependencies: PRAutoBlogger_Run_Stage_State (table probe), WordPress $wpdb.
 *
 * @see admin/class-board-data-provider.php -- Calls enrich().
 * @see ARCHITECTURE.md                     -- §Board (M5 Mission Brief).
 */
class PRAutoBlogger_Board_Stage_Dots {

	/**
	 * Enrich a set of cards with run_stages dot-rail summaries.
	 *
	 * @param array<int, array<string, mixed>> $cards Cards to enrich.
	 * @return array<int, array<string, mixed>> Cards with run_stages_summary set.
	 */
	public static function enrich( array $cards ): array {
		// Initialise empty summary on every card.
		foreach ( $cards as $i => $card ) {
			$cards[ $i ]['run_stages_summary'] = array();
		}

		if ( ! PRAutoBlogger_Run_Stage_State::is_available() ) {
			return $cards;
		}

		$run_ids = array();
		foreach ( $cards as $card ) {
			if ( ! empty( $card['run_id'] ) ) {
				$run_ids[] = (string) $card['run_id'];
			}
		}
		if ( empty( $run_ids ) ) {
			return $cards;
		}

		global $wpdb;
		$table        = PRAutoBlogger_Run_Stage_State::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $run_ids ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $placeholders is a fixed %s list.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT run_id, stage, status FROM {$table} WHERE run_id IN ({$placeholders}) ORDER BY id ASC",
				$run_ids
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return $cards;
		}

		// Index by run_id.
		$by_run = array();
		foreach ( $rows as $row ) {
			$rid = (string) ( $row['run_id'] ?? '' );
			if ( '' === $rid ) {
				continue;
			}
			$by_run[ $rid ][] = array(
				'stage'  => (string) ( $row['stage'] ?? '' ),
				'status' => (string) ( $row['status'] ?? 'pending' ),
			);
		}

		foreach ( $cards as $i => $card ) {
			$rid = (string) ( $card['run_id'] ?? '' );
			if ( '' !== $rid && isset( $by_run[ $rid ] ) ) {
				$cards[ $i ]['run_stages_summary'] = $by_run[ $rid ];
			}
		}

		return $cards;
	}
}
