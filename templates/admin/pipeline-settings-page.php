<?php
/**
 * Pipeline Settings page template.
 *
 * Variables injected by PRAutoBlogger_Pipeline_Settings_Renderer::render():
 *   array   $view['steps']        — ordered step definitions from Step_Map.
 *   array   $view['active_step']  — the currently selected step definition.
 *   array   $view['save_result']  — {status, message} from the save handler.
 *   string  $view['nonce_field']  — nonce field name.
 *   string  $view['nonce_action'] — nonce action string.
 *   string  $view['page_slug']    — pipeline page slug.
 *   array   $view['step_data']    — assembled view data for the active step.
 *
 * Prompt panels are rendered via the pipeline-settings-prompt-panel.php partial.
 *
 * @see admin/class-pipeline-settings-renderer.php — Provides $view.
 * @see admin/class-pipeline-settings-page.php     — Includes this template.
 * @see templates/admin/pipeline-settings-prompt-panel.php — Prompt editor partial.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$steps        = $view['steps'];
$active_step  = $view['active_step'];
$save_result  = $view['save_result'];
$nonce_field  = $view['nonce_field'];
$nonce_action = $view['nonce_action'];
$page_slug    = $view['page_slug'];
$step_data    = $view['step_data'];

$base_url = admin_url( 'admin.php?page=' . rawurlencode( $page_slug ) );

$cost_reporter = new PRAutoBlogger_Cost_Reporter();
$monthly_spend = $cost_reporter->get_monthly_spend();
$budget        = (float) get_option( 'prautoblogger_monthly_budget_usd', 50.00 );
?>
<div class="wrap ab-wrap pab-pipeline-wrap">

	<div class="ab-header">
		<div class="ab-header-left">
			<span class="dashicons dashicons-networking ab-header-icon"></span>
			<div>
				<h1 class="ab-header-title"><?php esc_html_e( 'Pipeline Settings', 'prautoblogger' ); ?></h1>
				<span class="ab-header-version">v<?php echo esc_html( PRAUTOBLOGGER_VERSION ); ?></span>
			</div>
		</div>
		<div class="ab-header-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=prautoblogger-settings' ) ); ?>" class="ab-btn ab-btn-outline">
				<?php esc_html_e( '← All Settings', 'prautoblogger' ); ?>
			</a>
		</div>
	</div>

	<div class="ab-stats-bar">
		<div class="ab-stat">
			<span class="ab-stat-label"><?php esc_html_e( 'Monthly Spend', 'prautoblogger' ); ?></span>
			<span class="ab-stat-value">$<?php echo esc_html( number_format( $monthly_spend, 2 ) ); ?> <small>/ $<?php echo esc_html( number_format( $budget, 2 ) ); ?></small></span>
		</div>
		<div class="ab-stat">
			<span class="ab-stat-label"><?php esc_html_e( 'Prompts Table', 'prautoblogger' ); ?></span>
			<span class="ab-stat-value">
				<?php if ( PRAutoBlogger_Prompt_Registry::is_available() ) : ?>
					<span class="pab-badge pab-badge-ok"><?php esc_html_e( 'Available', 'prautoblogger' ); ?></span>
				<?php else : ?>
					<span class="pab-badge pab-badge-warn"><?php esc_html_e( 'Unavailable — using defaults', 'prautoblogger' ); ?></span>
				<?php endif; ?>
			</span>
		</div>
	</div>

	<?php if ( 'saved' === $save_result['status'] ) : ?>
		<div class="ab-save-notice notice notice-success is-dismissible">
			<span class="dashicons dashicons-saved"></span> <?php echo esc_html( $save_result['message'] ); ?>
		</div>
	<?php elseif ( 'error' === $save_result['status'] ) : ?>
		<div class="ab-save-notice notice notice-error is-dismissible">
			<span class="dashicons dashicons-warning"></span> <?php echo esc_html( $save_result['message'] ); ?>
		</div>
	<?php endif; ?>

	<?php
	$niche = (string) get_option( 'prautoblogger_niche_description', '' );
	$niche_preview = '' !== $niche ? mb_strimwidth( $niche, 0, 80, '…' ) : '';
	?>
	<div class="pab-global-context-note">
		<span class="dashicons dashicons-info-outline"></span>
		<?php if ( '' !== $niche_preview ) : ?>
			<?php
			printf(
				/* translators: %s = truncated niche description */
				esc_html__( 'Niche context (used by all stages): "%s"', 'prautoblogger' ),
				esc_html( $niche_preview )
			);
			?>
		<?php else : ?>
			<?php esc_html_e( 'Niche description not set — edit in Settings → Content.', 'prautoblogger' ); ?>
		<?php endif; ?>
	</div>

	<div class="pab-pipeline-layout">

		<nav class="pab-step-rail" aria-label="<?php esc_attr_e( 'Pipeline steps', 'prautoblogger' ); ?>">
			<?php foreach ( $steps as $i => $step ) : ?>
				<?php $is_active = $step['id'] === $active_step['id']; ?>
				<a href="<?php echo esc_url( add_query_arg( 'step', $step['id'], $base_url ) ); ?>"
				   class="pab-step-btn <?php echo $is_active ? 'pab-step-btn--active' : ''; ?>"
				   aria-current="<?php echo $is_active ? 'page' : 'false'; ?>">
					<span class="pab-step-num"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
					<span class="dashicons <?php echo esc_attr( $step['icon'] ); ?> pab-step-icon"></span>
					<span class="pab-step-label"><?php echo esc_html( $step['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>

		<div class="pab-step-panel">

			<div class="pab-panel-header">
				<h2><?php echo esc_html( $active_step['label'] ); ?></h2>
				<?php if ( ! empty( $active_step['description'] ) ) : ?>
					<p class="pab-panel-desc"><?php echo esc_html( $active_step['description'] ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $step_data['model_option'] ) ) : ?>
			<div class="pab-section">
				<h3 class="pab-section-title"><?php esc_html_e( 'Model', 'prautoblogger' ); ?></h3>
				<form method="post"
				      action="<?php echo esc_url( add_query_arg( 'step', $active_step['id'], $base_url ) ); ?>"
				      class="pab-model-form">
					<?php wp_nonce_field( $nonce_action, $nonce_field ); ?>
					<input type="hidden" name="pipeline_action" value="save_model" />
					<input type="hidden" name="model_option" value="<?php echo esc_attr( $step_data['model_option'] ); ?>" />
					<div class="pab-model-picker-wrap">
						<?php
						$renderer = new PRAutoBlogger_Pipeline_Settings_Renderer();
						$renderer->render_model_picker(
							$step_data['model_option'],
							$step_data['model_value'],
							array( 'capability' => $active_step['capability'] ?? 'text→text' )
						);
						?>
					</div>
					<p class="pab-field-note">
						<?php esc_html_e( 'Changing the model here also updates the corresponding field in Settings → AI Models.', 'prautoblogger' ); ?>
					</p>
					<button type="submit" class="ab-btn ab-btn-secondary ab-btn-sm">
						<?php esc_html_e( 'Save Model', 'prautoblogger' ); ?>
					</button>
				</form>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $step_data['params'] ) ) : ?>
			<div class="pab-section">
				<h3 class="pab-section-title"><?php esc_html_e( 'Parameters', 'prautoblogger' ); ?></h3>
				<table class="pab-params-table">
					<?php foreach ( $step_data['params'] as $param_name => $param_value ) : ?>
					<tr>
						<th><?php echo esc_html( $param_name ); ?></th>
						<td><code class="pab-mono"><?php echo is_array( $param_value ) ? esc_html( wp_json_encode( $param_value ) ) : esc_html( (string) $param_value ); ?></code></td>
					</tr>
					<?php endforeach; ?>
				</table>
				<p class="pab-field-note">
					<?php esc_html_e( 'Parameters are defined in the prompt registry defaults. Editing params is a Phase 2b feature.', 'prautoblogger' ); ?>
				</p>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $step_data['system'] ) ) :
				$panel        = $step_data['system'];
				$panel_title  = __( 'System Instructions', 'prautoblogger' );
				$step_id      = $active_step['id'];
				$is_writer    = ( 'writer' === $step_id );
				$writing_mode = (string) get_option( 'prautoblogger_writing_pipeline', 'multi_step' );
				include PRAUTOBLOGGER_PLUGIN_DIR . 'templates/admin/pipeline-settings-prompt-panel.php';
			endif; ?>

			<?php foreach ( $step_data['agent_panels'] as $key => $panel ) :
				$panel_title  = sprintf(
					/* translators: %s = registry key e.g. 'content.draft' */
					__( 'Prompt: %s', 'prautoblogger' ),
					$key
				);
				$step_id      = $active_step['id'];
				$is_writer    = ( 'writer' === $step_id );
				$writing_mode = (string) get_option( 'prautoblogger_writing_pipeline', 'multi_step' );
				include PRAUTOBLOGGER_PLUGIN_DIR . 'templates/admin/pipeline-settings-prompt-panel.php';
			endforeach; ?>

		</div>
	</div>
</div>
