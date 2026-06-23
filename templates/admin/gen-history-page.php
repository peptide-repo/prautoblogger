<?php
/**
 * Template: Generation History page (M4).
 *
 * Variables provided by PRAutoBlogger_Gen_History_Page::render_page():
 *   array  $rows       -- list of run rows from Gen_History_Query::get_page().
 *   array  $pagination -- { current, total, count, base }.
 *
 * SECURITY: All output escaped (esc_html / esc_url / number_format).
 *           Stage I/O loaded via AJAX (gen-history.js) and rendered with
 *           textContent — no innerHTML for prompt/response text.
 *
 * @see admin/class-gen-history-page.php  -- Registers page + passes vars.
 * @see assets/js/gen-history.js          -- Stage I/O drill-down JS.
 * @see assets/css/gen-history.css        -- Styles.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap prab-gen-history">
	<h1><?php esc_html_e( 'Generation History', 'prautoblogger' ); ?></h1>
	<p class="prab-gen-history__subtitle">
		<?php
		printf(
			/* translators: %d: total number of runs */
			esc_html__( '%d total runs — newest first. Click "Stage I/O" to inspect the full input and output of each pipeline step.', 'prautoblogger' ),
			(int) $pagination['count']
		);
		?>
	</p>

	<?php if ( empty( $rows ) ) : ?>
		<p><?php esc_html_e( 'No generation runs found.', 'prautoblogger' ); ?></p>
	<?php else : ?>

	<table class="wp-list-table widefat fixed striped prab-gen-history__table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Article', 'prautoblogger' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Date', 'prautoblogger' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'prautoblogger' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Model(s)', 'prautoblogger' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Cost', 'prautoblogger' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Duration', 'prautoblogger' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'prautoblogger' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $rows as $row ) : ?>
			<?php
			$run_id       = (string) ( $row['run_id'] ?? '' );
			$post_id      = (int) ( $row['post_id'] ?? 0 );
			$post_title   = '' !== (string) ( $row['post_title'] ?? '' )
				? (string) $row['post_title'] : '';
			$status       = (string) ( $row['status'] ?? '' );
			$started_at   = (string) ( $row['started_at'] ?? '' );
			$cost_total   = round( (float) ( $row['cost_total'] ?? 0 ), 4 );
			$models_raw   = (string) ( $row['models'] ?? '' );
			$duration_s   = isset( $row['duration_seconds'] ) && null !== $row['duration_seconds']
				? (int) $row['duration_seconds'] : null;
			$dossier_url  = $post_id > 0
				? PRAutoBlogger_Dossier_Page::url_for_post( $post_id ) : '';

			// Status chip CSS class.
			$status_class = 'prab-chip prab-chip--' . esc_attr( $status );
			?>
			<tr class="prab-gen-history__row" data-run-id="<?php echo esc_attr( $run_id ); ?>">
				<td>
					<?php if ( '' !== $post_title && $post_id > 0 ) : ?>
						<a href="<?php echo esc_url( $dossier_url ); ?>">
							<?php echo esc_html( $post_title ); ?>
						</a>
					<?php elseif ( '' !== $post_title ) : ?>
						<?php echo esc_html( $post_title ); ?>
					<?php else : ?>
						<span class="prab-gen-history__no-title">
							<?php
							printf(
								/* translators: %s: abbreviated run ID */
								esc_html__( 'Run %s', 'prautoblogger' ),
								esc_html( substr( $run_id, 0, 8 ) )
							);
							?>
						</span>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $started_at ); ?></td>
				<td><span class="<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status ); ?></span></td>
				<td class="prab-gen-history__models">
					<span title="<?php echo esc_attr( $models_raw ); ?>">
						<?php echo esc_html( $models_raw ); ?>
					</span>
				</td>
				<td>$<?php echo esc_html( number_format( $cost_total, 4 ) ); ?></td>
				<td>
					<?php
					if ( null !== $duration_s ) {
						$mins = (int) floor( $duration_s / 60 );
						$secs = $duration_s % 60;
						if ( $mins > 0 ) {
							printf(
								/* translators: 1: minutes, 2: seconds */
								esc_html__( '%1$dm %2$ds', 'prautoblogger' ),
								$mins,
								$secs
							);
						} else {
							printf(
								/* translators: %d: seconds */
								esc_html__( '%ds', 'prautoblogger' ),
								$secs
							);
						}
					} else {
						esc_html_e( '—', 'prautoblogger' );
					}
					?>
				</td>
				<td class="prab-gen-history__actions">
					<?php if ( '' !== $dossier_url ) : ?>
						<a href="<?php echo esc_url( $dossier_url ); ?>" class="button button-small">
							<?php esc_html_e( 'Dossier', 'prautoblogger' ); ?>
						</a>
					<?php endif; ?>
					<button type="button"
						class="button button-small prab-gen-history__io-toggle"
						data-run-id="<?php echo esc_attr( $run_id ); ?>">
						<?php esc_html_e( 'Stage I/O', 'prautoblogger' ); ?>
					</button>
				</td>
			</tr>
			<tr class="prab-gen-history__io-row" id="prab-io-<?php echo esc_attr( $run_id ); ?>" hidden>
				<td colspan="7">
					<div class="prab-gen-history__io-panel">
						<p class="prab-gen-history__io-loading">
							<?php esc_html_e( 'Loading…', 'prautoblogger' ); ?>
						</p>
					</div>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

		<?php if ( $pagination['total'] > 1 ) : ?>
	<div class="prab-gen-history__pagination">
			<?php for ( $p = 1; $p <= $pagination['total']; $p++ ) : ?>
				<?php if ( $p === $pagination['current'] ) : ?>
				<span class="prab-gen-history__page-current"><?php echo (int) $p; ?></span>
			<?php else : ?>
				<a href="<?php echo esc_url( add_query_arg( 'paged', $p, $pagination['base'] ) ); ?>">
					<?php echo (int) $p; ?>
				</a>
			<?php endif; ?>
		<?php endfor; ?>
	</div>
	<?php endif; ?>

	<?php endif; ?>
</div>
