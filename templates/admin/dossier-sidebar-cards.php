<?php
/**
 * Partial: M3 sidebar cards — run spend (guardrail 4 visibility) and
 * the per-stage model/prompt-version consolidation (QA M2 F2).
 *
 * Included by dossier-page.php; inherits its scope: $dossier, $run,
 * $log_index, $stages.
 *
 * Security: all db-sourced values esc_html(); numbers via number_format.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sc_spend = is_array( $dossier['spend'] ?? null ) ? $dossier['spend'] : array(
	'settled'  => 0.0,
	'reserved' => 0.0,
	'ceiling'  => 0.0,
	'warn'     => false,
);
$sc_held  = (float) $sc_spend['settled'] + (float) $sc_spend['reserved'];
?>
<div class="prab-sidebar-card prab-run-spend<?php echo ! empty( $sc_spend['warn'] ) ? ' prab-run-spend--warn' : ''; ?>">
	<h3 class="prab-sidebar-heading"><?php esc_html_e( 'Run Spend', 'prautoblogger' ); ?></h3>
	<dl class="prab-sidebar-dl">
		<dt><?php esc_html_e( 'Settled', 'prautoblogger' ); ?></dt>
		<dd>$<?php echo esc_html( number_format( (float) $sc_spend['settled'], 4 ) ); ?></dd>
		<?php if ( (float) $sc_spend['reserved'] > 0 ) : ?>
			<dt><?php esc_html_e( 'Reserved', 'prautoblogger' ); ?></dt>
			<dd>$<?php echo esc_html( number_format( (float) $sc_spend['reserved'], 4 ) ); ?></dd>
		<?php endif; ?>
		<dt><?php esc_html_e( 'Ceiling', 'prautoblogger' ); ?></dt>
		<dd>
			<?php if ( (float) $sc_spend['ceiling'] > 0 ) : ?>
				$<?php echo esc_html( number_format( (float) $sc_spend['ceiling'], 2 ) ); ?>
			<?php else : ?>
				<?php esc_html_e( 'none configured', 'prautoblogger' ); ?>
			<?php endif; ?>
		</dd>
	</dl>
	<?php if ( ! empty( $sc_spend['warn'] ) ) : ?>
		<p class="prab-spend-warning">
			<?php
			printf(
				/* translators: 1: spent USD, 2: ceiling USD. */
				esc_html__( 'This run has committed $%1$s of its $%2$s ceiling — further re-runs may halt.', 'prautoblogger' ),
				esc_html( number_format( $sc_held, 4 ) ),
				esc_html( number_format( (float) $sc_spend['ceiling'], 2 ) )
			);
			?>
		</p>
	<?php endif; ?>
</div>

<div class="prab-sidebar-card prab-models-card">
	<h3 class="prab-sidebar-heading"><?php esc_html_e( 'Models & Prompts', 'prautoblogger' ); ?></h3>
	<table class="prab-models-table">
		<tbody>
			<?php foreach ( $log_index as $sc_stage => $sc_rows ) : ?>
				<?php
				$sc_first = $sc_rows[0] ?? array();
				$sc_model = ! empty( $sc_first['model'] ) ? (string) $sc_first['model'] : '—';
				$sc_pv    = ! empty( $sc_first['prompt_version'] ) ? (string) $sc_first['prompt_version'] : '—';
				$sc_role  = ! empty( $sc_first['agent_role'] ) ? (string) $sc_first['agent_role'] : '—';
				?>
				<tr>
					<th scope="row"><?php echo esc_html( PRAutoBlogger_Stage_Display_Map::label( (string) $sc_stage ) ); ?></th>
					<td>
						<span class="prab-models-model"><?php echo esc_html( $sc_model ); ?></span>
						<span class="prab-models-meta">
							<?php
							printf(
								/* translators: 1: prompt version, 2: agent role. */
								esc_html__( 'pv %1$s · %2$s', 'prautoblogger' ),
								esc_html( $sc_pv ),
								esc_html( $sc_role )
							);
							?>
						</span>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
