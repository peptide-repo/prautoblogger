<?php
/**
 * Partial: single stage section for the Article Dossier.
 *
 * Variables injected by dossier-page.php:
 *   $stage_row (array)  -- run_stages row incl. display_output + ['rerun'] panel data (M3)
 *   $stage_name (string) -- stage key
 *   $log_rows (array)   -- generation_log rows for this stage
 *   $dossier (array)    -- page view model (spend/eligibility/post_id)
 *
 * Security: all model output escaped via esc_html() or wp_kses_post().
 * request_json escaped via esc_html() -- treat as untrusted HTML.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$s_label      = PRAutoBlogger_Stage_Display_Map::label( $stage_name );
$s_status     = (string) ( $stage_row['status'] ?? '' );
$s_output     = $stage_row['display_output'] ?? null;
$s_cost       = (float) ( $stage_row['cost_usd'] ?? 0 );
$s_role       = (string) ( $stage_row['agent_role'] ?? '' );
$s_started    = (string) ( $stage_row['started_at'] ?? '' );
$s_finished   = (string) ( $stage_row['finished_at'] ?? '' );
$output_pruned = ( ! empty( $stage_row['status'] ) && 'done' === $stage_row['status'] && null === $s_output );

// Determine prompt version from gen_log (first row for this stage).
$first_log     = ! empty( $log_rows ) ? $log_rows[0] : null;
$s_model       = ( null !== $first_log && ! empty( $first_log['model'] ) ) ? $first_log['model'] : '—';
$s_pv          = ( null !== $first_log && ! empty( $first_log['prompt_version'] ) ) ? $first_log['prompt_version'] : '—';
$has_raw_trace = ! empty( $log_rows );
$section_id    = 'prab-stage-' . esc_attr( $stage_name ) . '-' . esc_attr( $s_role );
$rerun         = is_array( $stage_row['rerun'] ?? null ) ? $stage_row['rerun'] : array();
$is_stale      = ! empty( $rerun['stale'] );
$is_human      = ! empty( $rerun['human_modified'] );
$attempts      = (int) ( $rerun['attempt'] ?? 1 );
?>
<div class="prab-dossier-section prab-dossier-section--stage prab-stage-status--<?php echo esc_attr( $s_status ); ?><?php echo $is_stale ? ' prab-stage--stale' : ''; ?>"
	 data-stage="<?php echo esc_attr( $stage_name ); ?>"
	 data-agent-role="<?php echo esc_attr( $s_role ); ?>"
	 id="<?php echo $section_id; // Already escaped. ?>">

	<div class="prab-stage-header">
		<h2 class="prab-section-title">
			<?php echo esc_html( $s_label ); ?>
			<?php if ( '' !== $s_role ) : ?>
				<span class="prab-stage-role">(<?php echo esc_html( $s_role ); ?>)</span>
			<?php endif; ?>
		</h2>
		<div class="prab-stage-header-meta">
			<span class="prab-stage-status-pill prab-stage-status-pill--<?php echo esc_attr( $s_status ); ?>">
				<?php echo esc_html( ucfirst( $s_status ) ); ?>
			</span>
			<?php // M3 audit chips -- visible, never tooltip-buried (CPO guardrails 2+3). ?>
			<span class="prab-chip prab-chip--stale" data-chip="stale" <?php echo $is_stale ? '' : 'hidden'; ?>>
				<?php esc_html_e( 'Stale — upstream changed', 'prautoblogger' ); ?>
			</span>
			<span class="prab-chip prab-chip--human" data-chip="human" <?php echo $is_human ? '' : 'hidden'; ?>>
				<?php esc_html_e( 'Human-edited input', 'prautoblogger' ); ?>
			</span>
			<?php if ( $attempts > 1 ) : ?>
				<span class="prab-chip prab-chip--attempt">
					<?php
					printf(
						/* translators: %d: attempt count. */
						esc_html__( 'Attempt %d', 'prautoblogger' ),
						(int) $attempts
					);
					?>
				</span>
			<?php endif; ?>
			<?php if ( $s_cost > 0 ) : ?>
				<span class="prab-stage-cost">$<?php echo esc_html( number_format( $s_cost, 5 ) ); ?></span>
			<?php endif; ?>
			<span class="prab-stage-model"><?php echo esc_html( $s_model ); ?></span>
			<?php if ( '—' !== $s_pv ) : ?>
				<span class="prab-stage-pv">pv <?php echo esc_html( $s_pv ); ?></span>
			<?php endif; ?>
		</div>
	</div>

	<?php // ── Output ──────────────────────────────────────────────────────── ?>
	<div class="prab-stage-output">
		<?php if ( $output_pruned ) : ?>
			<p class="prab-output-pruned">
				<?php
				$days = (int) get_option( 'prautoblogger_request_json_retention_days', 14 );
				printf(
					/* translators: %d: retention days */
					esc_html__( 'Output retained for %d days — currently unavailable (pruned by retention cron).', 'prautoblogger' ),
					$days
				);
				?>
			</p>
		<?php elseif ( null !== $s_output ) : ?>
			<div class="prab-stage-output-rendered">
				<?php echo wp_kses_post( $s_output ); ?>
			</div>
		<?php else : ?>
			<p class="prab-output-absent"><?php esc_html_e( 'No output recorded for this stage.', 'prautoblogger' ); ?></p>
		<?php endif; ?>
	</div>

	<?php // ── Raw trace toggle ─────────────────────────────────────────────── ?>
	<?php if ( $has_raw_trace ) : ?>
		<div class="prab-stage-trace">
			<button type="button" class="prab-trace-toggle button-link"
					aria-expanded="false"
					aria-controls="<?php echo $section_id; ?>-trace">
				<?php esc_html_e( 'Show raw trace', 'prautoblogger' ); ?>
			</button>
			<div id="<?php echo $section_id; ?>-trace" class="prab-trace-detail" hidden>
				<?php foreach ( $log_rows as $log_row ) : ?>
					<div class="prab-trace-entry">
						<div class="prab-trace-meta">
							<span><?php echo esc_html( (string) ( $log_row['model'] ?? '—' ) ); ?></span>
							<span><?php printf( esc_html__( '%1$d prompt + %2$d completion tokens', 'prautoblogger' ), (int) ( $log_row['prompt_tokens'] ?? 0 ), (int) ( $log_row['completion_tokens'] ?? 0 ) ); ?></span>
							<span>$<?php echo esc_html( number_format( (float) ( $log_row['estimated_cost'] ?? 0 ), 6 ) ); ?></span>
							<span class="prab-trace-status prab-trace-status--<?php echo esc_attr( (string) ( $log_row['response_status'] ?? '' ) ); ?>">
								<?php echo esc_html( (string) ( $log_row['response_status'] ?? '' ) ); ?>
							</span>
						</div>
						<?php if ( ! empty( $log_row['request_json'] ) ) : ?>
							<details class="prab-trace-request">
								<summary><?php esc_html_e( 'Request payload', 'prautoblogger' ); ?></summary>
								<?php // SECURITY: esc_html() treats model output as untrusted HTML. ?>
								<pre class="prab-trace-pre"><?php echo esc_html( (string) $log_row['request_json'] ); ?></pre>
							</details>
						<?php endif; ?>
						<?php if ( ! empty( $log_row['error_message'] ) ) : ?>
							<p class="prab-trace-error"><?php echo esc_html( (string) $log_row['error_message'] ); ?></p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $s_started ) : ?>
		<div class="prab-stage-timestamps">
			<span class="prab-ts"><?php echo esc_html( $s_started ); ?></span>
			<?php if ( '' !== $s_finished ) : ?>
				<span class="prab-ts-sep">→</span>
				<span class="prab-ts"><?php echo esc_html( $s_finished ); ?></span>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php // ── M3: edit + re-run affordances (chained-cron; nothing is synchronous) ── ?>
	<div class="prab-stage-rerun-footer">
		<?php if ( ! empty( $rerun['editable'] ) ) : ?>
			<button type="button" class="button prab-edit-toggle"
					aria-expanded="false" aria-controls="<?php echo $section_id; // Already escaped. ?>-edit">
				<?php esc_html_e( 'Edit input', 'prautoblogger' ); ?>
			</button>
		<?php elseif ( ! empty( $rerun['edit_reason'] ) ) : ?>
			<span class="prab-edit-unavailable"><?php echo esc_html( (string) $rerun['edit_reason'] ); ?></span>
		<?php endif; ?>
		<?php if ( ! empty( $rerun['rerun_from'] ) ) : ?>
			<button type="button" class="button prab-rerun-from<?php echo $is_stale ? ' prab-rerun-from--stale' : ''; ?>"
					data-stage="<?php echo esc_attr( $stage_name ); ?>">
				<?php esc_html_e( 'Re-run from here', 'prautoblogger' ); ?>
			</button>
		<?php endif; ?>
	</div>
	<?php
	if ( ! empty( $rerun['editable'] ) ) {
		require PRAUTOBLOGGER_PLUGIN_DIR . 'templates/admin/dossier-edit-panel.php';
	}
	?>

</div><!-- .prab-dossier-section--stage -->
