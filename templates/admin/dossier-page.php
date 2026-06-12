<?php
/**
 * Article Dossier template (M2 — read-only).
 *
 * Design contract: Proposal C "Editorial Record" -- warm editorial,
 * typographic hierarchy, verdict pills, receipt-style cost sidebar,
 * per-stage I/O with expandable raw-trace toggle. Vanilla JS/CSS only.
 *
 * Variables injected by PRAutoBlogger_Dossier_Page::render_page():
 *   $post_id (int)     -- WordPress post ID
 *   $dossier (array)   -- View model from PRAutoBlogger_Dossier_Data_Assembler
 *   $assembler (obj)   -- (not directly used here)
 *
 * @see admin/class-dossier-page.php          -- Renders this template.
 * @see admin/class-dossier-data-assembler.php -- Builds $dossier.
 * @see assets/css/dossier.css                 -- Styling.
 * @see assets/js/dossier.js                   -- Raw-trace toggle.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$has_run   = ! empty( $dossier['has_run'] );
$run       = $dossier['run'] ?? null;
$stages    = $dossier['stages'] ?? array();
$decisions = $dossier['decisions'] ?? array();
$log_index = $dossier['gen_log_index'] ?? array();
$verdict   = $dossier['verdict'] ?? '';
$tier      = $dossier['tier'] ?? '';
$run_id    = $dossier['run_id'] ?? '';

$log_query = new PRAutoBlogger_Dossier_Gen_Log_Query();
$stage_totals = $log_query->aggregate_per_stage( $log_index );

$total_cost = 0.0;
foreach ( $stage_totals as $st ) {
	$total_cost += $st['cost'];
}

$verdict_labels = array(
	'approved'  => __( 'Approved', 'prautoblogger' ),
	'revised'   => __( 'Revised', 'prautoblogger' ),
	'rejected'  => __( 'Rejected', 'prautoblogger' ),
	'halted'    => __( 'Halted', 'prautoblogger' ),
	'pending'   => __( 'Pending', 'prautoblogger' ),
);

$back_url = admin_url( 'admin.php?page=prautoblogger-board' );
?>
<div class="wrap prab-dossier-wrap">

	<?php // ── Back link ──────────────────────────────────────────────────────── ?>
	<p class="prab-dossier-back">
		<a href="<?php echo esc_url( $back_url ); ?>">
			&larr; <?php esc_html_e( 'Back to Board', 'prautoblogger' ); ?>
		</a>
	</p>

	<?php if ( ! $has_run ) : ?>
		<?php // ── Graceful empty state (legacy post or invalid id) ───────────────── ?>
	<div id="prab-dossier" class="prab-dossier prab-dossier--empty">
		<div class="prab-dossier-empty-notice">
			<h2><?php esc_html_e( 'No generation record', 'prautoblogger' ); ?></h2>
			<?php if ( $post_id > 0 && ! empty( $dossier['post_title'] ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: article title */
						esc_html__( 'The article "%s" was published before the generation substrate was introduced (v0.18.0). No dossier data is available.', 'prautoblogger' ),
						esc_html( $dossier['post_title'] )
					);
					?>
				</p>
			<?php elseif ( $post_id <= 0 ) : ?>
				<p><?php esc_html_e( 'No article was specified. Use a board card or post link to reach a dossier.', 'prautoblogger' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'No run record was found for this article.', 'prautoblogger' ); ?></p>
			<?php endif; ?>
		</div>
	</div>
		<?php return; ?>
	<?php endif; ?>

	<?php // ── Full dossier ────────────────────────────────────────────────────── ?>
	<div id="prab-dossier" class="prab-dossier">

		<?php // ── Article header ──────────────────────────────────────────────── ?>
		<div class="prab-dossier-header">
			<div class="prab-dossier-header-main">
				<h1 class="prab-dossier-title"><?php echo esc_html( $dossier['post_title'] ); ?></h1>
				<div class="prab-dossier-header-meta">
					<?php if ( '' !== $verdict ) : ?>
						<span class="prab-verdict-pill prab-verdict-pill--<?php echo esc_attr( $verdict ); ?>">
							<?php echo esc_html( $verdict_labels[ $verdict ] ?? ucfirst( $verdict ) ); ?>
						</span>
					<?php endif; ?>
					<?php if ( '' !== $tier ) : ?>
						<span class="prab-dossier-tier"><?php echo esc_html( ucfirst( $tier ) ); ?></span>
					<?php endif; ?>
					<span class="prab-dossier-status prab-dossier-status--<?php echo esc_attr( $dossier['post_status'] ?? '' ); ?>">
						<?php echo esc_html( ucfirst( $dossier['post_status'] ?? '' ) ); ?>
					</span>
				</div>
			</div>
			<?php if ( $post_id > 0 ) : ?>
				<div class="prab-dossier-header-actions">
					<a href="<?php echo esc_url( get_edit_post_link( $post_id, 'raw' ) ?? '' ); ?>" class="button">
						<?php esc_html_e( 'Edit Article', 'prautoblogger' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>

		<div class="prab-dossier-body">

			<?php // ── Main column: stage sections ─────────────────────────────── ?>
			<div class="prab-dossier-main">

			<?php
			$stage_order = array( 'analysis', 'llm_research', 'research', 'curate', 'outline', 'draft', 'polish', 'review', 'editorial', 'seo', 'publish', 'image_a', 'image_b' );
			$rendered_stages = array();

			// Render stages in canonical order first, then any extra rows.
			foreach ( $stage_order as $stage_name ) {
				foreach ( $stages as $role_key => $stage_row ) {
					if ( ( $stage_row['stage'] ?? '' ) !== $stage_name ) {
						continue;
					}
					$rendered_stages[] = $role_key;
					$log_rows = $log_index[ $stage_name ] ?? array();
					require PRAUTOBLOGGER_PLUGIN_DIR . 'templates/admin/dossier-stage-section.php';
				}
			}
			// Remaining unordered stages.
			foreach ( $stages as $role_key => $stage_row ) {
				if ( in_array( $role_key, $rendered_stages, true ) ) {
					continue;
				}
				$stage_name = (string) ( $stage_row['stage'] ?? 'unknown' );
				$log_rows   = $log_index[ $stage_name ] ?? array();
				require PRAUTOBLOGGER_PLUGIN_DIR . 'templates/admin/dossier-stage-section.php';
			}

			if ( empty( $stages ) ) :
				?>
				<div class="prab-dossier-no-stages">
					<p><?php esc_html_e( 'Stage data is not yet available for this run.', 'prautoblogger' ); ?></p>
				</div>
			<?php endif; ?>

			<?php // ── Editorial decisions section ─────────────────────────────── ?>
			<?php if ( ! empty( $decisions ) ) : ?>
				<div class="prab-dossier-section prab-dossier-section--decisions">
					<h2 class="prab-section-title"><?php esc_html_e( 'Editorial Decisions', 'prautoblogger' ); ?></h2>
					<?php foreach ( $decisions as $d_stage => $d ) : ?>
						<div class="prab-decision-row">
							<span class="prab-decision-stage"><?php echo esc_html( PRAutoBlogger_Stage_Display_Map::label( $d_stage ) ); ?></span>
							<span class="prab-verdict-pill prab-verdict-pill--<?php echo esc_attr( (string) ( $d['verdict'] ?? '' ) ); ?>">
								<?php echo esc_html( ucfirst( (string) ( $d['verdict'] ?? '' ) ) ); ?>
							</span>
							<?php if ( ! empty( $d['rationale'] ) ) : ?>
								<p class="prab-decision-rationale"><?php echo esc_html( (string) $d['rationale'] ); ?></p>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			</div><!-- .prab-dossier-main -->

			<?php // ── Sidebar: metadata + cost receipt ───────────────────────── ?>
			<div class="prab-dossier-sidebar">

				<div class="prab-sidebar-card">
					<h3 class="prab-sidebar-heading"><?php esc_html_e( 'Run Info', 'prautoblogger' ); ?></h3>
					<?php if ( null !== $run ) : ?>
						<dl class="prab-sidebar-dl">
							<dt><?php esc_html_e( 'Status', 'prautoblogger' ); ?></dt>
							<dd><span class="prab-run-status prab-run-status--<?php echo esc_attr( (string) ( $run['status'] ?? '' ) ); ?>"><?php echo esc_html( ucfirst( (string) ( $run['status'] ?? '' ) ) ); ?></span></dd>
							<dt><?php esc_html_e( 'Started', 'prautoblogger' ); ?></dt>
							<dd><?php echo esc_html( (string) ( $run['started_at'] ?? '—' ) ); ?></dd>
							<?php if ( ! empty( $run['finished_at'] ) ) : ?>
								<dt><?php esc_html_e( 'Finished', 'prautoblogger' ); ?></dt>
								<dd><?php echo esc_html( (string) $run['finished_at'] ); ?></dd>
							<?php endif; ?>
							<dt><?php esc_html_e( 'Run ID', 'prautoblogger' ); ?></dt>
							<dd class="prab-run-id"><?php echo esc_html( $run_id ); ?></dd>
						</dl>
					<?php endif; ?>
				</div>

				<div class="prab-sidebar-card prab-cost-receipt">
					<h3 class="prab-sidebar-heading"><?php esc_html_e( 'Cost Receipt', 'prautoblogger' ); ?></h3>
					<table class="prab-receipt-table">
						<tbody>
							<?php foreach ( $stage_totals as $st_name => $st ) : ?>
								<?php
								if ( $st['cost'] <= 0.0 && 0 === $st['calls'] ) :
									continue;
endif;
								?>
								<tr>
									<td class="prab-receipt-stage"><?php echo esc_html( PRAutoBlogger_Stage_Display_Map::label( $st_name ) ); ?></td>
									<td class="prab-receipt-cost">$<?php echo esc_html( number_format( $st['cost'], 5 ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
						<tfoot>
							<tr class="prab-receipt-total">
								<td><?php esc_html_e( 'Total', 'prautoblogger' ); ?></td>
								<td>$<?php echo esc_html( number_format( $total_cost, 5 ) ); ?></td>
							</tr>
						</tfoot>
					</table>
				</div>

			</div><!-- .prab-dossier-sidebar -->

		</div><!-- .prab-dossier-body -->

	</div><!-- #prab-dossier -->

</div><!-- .prab-dossier-wrap -->
