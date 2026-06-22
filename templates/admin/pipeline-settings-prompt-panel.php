<?php
/**
 * Partial: Pipeline Settings — single prompt editor panel.
 *
 * Variables passed by pipeline-settings-page.php:
 *   array  $panel        — prompt panel data from Renderer::build_prompt_panel_data().
 *   string $nonce_action — nonce action string.
 *   string $nonce_field  — nonce field name.
 *   string $step_id      — active step id (for the form action URL).
 *   string $base_url     — admin.php?page=prautoblogger-pipeline base URL.
 *   string $panel_title  — human-readable title for this panel's h3.
 *   string $writing_mode — (optional) current writing pipeline mode for Writer step.
 *   bool   $is_writer    — whether this is the Writer step.
 *
 * @see templates/admin/pipeline-settings-page.php — Includes this partial.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$key        = $panel['key'];
$css_key    = str_replace( '.', '-', $key );
$form_key   = str_replace( '.', '-', $key );

// Writer-step inactive detection.
$is_inactive = false;
if ( isset( $is_writer ) && $is_writer ) {
	$writing_mode     = isset( $writing_mode ) ? $writing_mode : (string) get_option( 'prautoblogger_writing_pipeline', 'multi_step' );
	$single_pass_keys = array( 'content.single_pass' );
	$multi_step_keys  = array( 'content.outline', 'content.draft', 'content.polish' );
	$is_inactive      = ( 'single_pass' === $writing_mode && in_array( $key, $multi_step_keys, true ) )
	                 || ( 'multi_step' === $writing_mode && in_array( $key, $single_pass_keys, true ) );
}
?>
<div class="pab-section">
	<h3 class="pab-section-title">
		<?php echo esc_html( $panel_title ); ?>
		<span class="pab-version-badge">
			<?php
			if ( $panel['active_version'] > 0 ) {
				printf( esc_html__( 'v%d', 'prautoblogger' ), (int) $panel['active_version'] );
			} else {
				esc_html_e( 'default', 'prautoblogger' );
			}
			?>
		</span>
		<?php if ( $panel['is_default'] ) : ?>
			<span class="pab-badge pab-badge-ok"><?php esc_html_e( 'Using default', 'prautoblogger' ); ?></span>
		<?php endif; ?>
	</h3>

	<?php if ( $panel['version_count'] > 0 ) : ?>
		<p class="pab-version-meta">
			<?php
			printf(
				/* translators: 1: version count, 2: author, 3: date */
				esc_html__( '%1$d version(s) stored. Active: by %2$s on %3$s.', 'prautoblogger' ),
				(int) $panel['version_count'],
				esc_html( $panel['author'] ),
				esc_html( $panel['created_at'] )
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( $is_inactive ) : ?>
		<p class="pab-inactive-note">
			<span class="dashicons dashicons-hidden"></span>
			<?php
			printf(
				/* translators: %s = current writing mode */
				esc_html__( 'Inactive in current mode (%s). Edit to prepare for mode switch.', 'prautoblogger' ),
				esc_html( $writing_mode )
			);
			?>
		</p>
	<?php endif; ?>

	<form method="post"
	      action="<?php echo esc_url( add_query_arg( 'step', $step_id, $base_url ) ); ?>"
	      class="pab-prompt-form">
		<?php wp_nonce_field( $nonce_action, $nonce_field ); ?>
		<input type="hidden" name="pipeline_action" value="save_prompt" />
		<input type="hidden" name="prompt_key" value="<?php echo esc_attr( $form_key ); ?>" />

		<textarea name="prompt_body"
		          id="pab-prompt-<?php echo esc_attr( $css_key ); ?>"
		          class="pab-prompt-editor"
		          rows="10"
		          spellcheck="false"><?php echo esc_textarea( $panel['body'] ); ?></textarea>

		<div class="pab-prompt-actions">
			<button type="submit" class="ab-btn ab-btn-secondary ab-btn-sm">
				<?php esc_html_e( 'Save (creates new version)', 'prautoblogger' ); ?>
			</button>
			<?php if ( ! $panel['is_default'] ) : ?>
			<button type="submit"
			        name="pipeline_action"
			        value="reset_prompt"
			        class="ab-btn ab-btn-outline ab-btn-sm pab-btn-reset"
			        onclick="return confirm('<?php esc_attr_e( 'Reset to factory default? This creates a new version.', 'prautoblogger' ); ?>');">
				<?php esc_html_e( 'Reset to default', 'prautoblogger' ); ?>
			</button>
			<?php endif; ?>
		</div>
	</form>
</div>
