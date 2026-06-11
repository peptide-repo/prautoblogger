<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Frontend status-transient + summary-log helpers for pipeline runs.
 *
 * What: The generation-status transient plumbing (stage broadcast, queue
 *       progress, final result) and the pipeline summary log line,
 *       extracted verbatim from Pipeline_Runner / Article_Worker in
 *       v0.18.0 (300-line cap; the two classes carried duplicate
 *       broadcast helpers). Pure presentation/state — no business logic.
 * Who triggers it: Pipeline_Runner, Article_Worker.
 * Dependencies: WordPress transients, PRAutoBlogger_Logger.
 *
 * @see core/class-pipeline-runner.php — Queue progress + final status writes.
 * @see core/class-article-worker.php  — Per-stage broadcasts.
 * @see class-executor.php             — Poller that reads this transient.
 */
class PRAutoBlogger_Pipeline_Status {

	/** Transient key polled by the admin frontend. */
	private const KEY = 'prautoblogger_generation_status';

	/** Seconds the status transient stays readable. */
	private const TTL = 600;

	/**
	 * Update the live stage text (only while a run is broadcasting).
	 *
	 * @param string $stage Human-readable stage description.
	 * @return void
	 */
	public static function broadcast( string $stage ): void {
		$current = get_transient( self::KEY );
		if ( is_array( $current ) && 'running' === ( $current['status'] ?? '' ) ) {
			$current['stage']        = $stage;
			$current['last_updated'] = time();
			set_transient( self::KEY, $current, self::TTL );
		}
	}

	/**
	 * Update queue progress ("Generating article X of Y…").
	 *
	 * @param array $queue Queue array with 'results' + 'ideas'.
	 * @return void
	 */
	public static function update_queue_progress( array $queue ): void {
		$done  = $queue['results']['generated'] + $queue['results']['rejected'];
		$total = $done + count( $queue['ideas'] );

		$current = get_transient( self::KEY );
		set_transient(
			self::KEY,
			array(
				'status'       => 'running',
				/* translators: 1: current article number, 2: total articles. */
				'stage'        => sprintf( __( 'Generating article %1$d of %2$d…', 'prautoblogger' ), $done + 1, $total ),
				'started'      => is_array( $current ) ? ( $current['started'] ?? time() ) : time(),
				'last_updated' => time(),
			),
			self::TTL
		);
	}

	/**
	 * Write the final "complete" status.
	 *
	 * @param array $r Result counters (generated/published/rejected/cost).
	 * @return void
	 */
	public static function write_final( array $r ): void {
		set_transient(
			self::KEY,
			array(
				'status'    => 'complete',
				'generated' => $r['generated'],
				'published' => $r['published'],
				'rejected'  => $r['rejected'],
				'cost'      => $r['cost'],
			),
			self::TTL
		);
	}

	/**
	 * Log the pipeline summary line.
	 *
	 * @param array $r Result counters (generated/published/rejected/cost).
	 * @return void
	 */
	public static function log_summary( array $r ): void {
		PRAutoBlogger_Logger::instance()->info(
			sprintf(
				'Pipeline complete: %d generated, %d published, %d rejected. Cost: $%.4f',
				$r['generated'],
				$r['published'],
				$r['rejected'],
				$r['cost']
			),
			'pipeline'
		);
	}
}
