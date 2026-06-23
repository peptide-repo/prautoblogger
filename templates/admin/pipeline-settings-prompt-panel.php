<?php
/**
 * Partial: Pipeline Settings — single prompt editor panel (M3).
 *
 * Variables passed by pipeline-settings-page.php:
 *   array  $panel         -- prompt panel data from Renderer::build_prompt_panel_data().
 *   string $nonce_action  -- nonce action string (save form).
 *   string $nonce_field   -- nonce field name (save form).
 *   string $step_id       -- active step id (for the form action URL).
 *   string $base_url      -- admin.php?page=prautoblogger-pipeline base URL.
 *   string $panel_title   -- human-readable title for this panel's h3.
 *   string $writing_mode  -- (optional) current writing pipeline mode for Writer step.
 *   bool   $is_writer     -- whether this is the Writer step.
 *   array  $view          -- full view array (carries preview/history nonces).
 *
 * @see templates/admin/pipeline-settings-page.php -- Includes this partial.
 * @see ajax/class-pipeline-preview-handler.php    -- Serves preview AJAX.
 * @see ajax/class-pipeline-history-handler.php    -- Serves history/diff AJAX.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$key      = $panel['key'];
$css_key  = str_replace( '.', '-', $key );
$form_key = str_replace( '.', '-', $key );

// Writer-step inactive detection.
$is_inactive = false;
if ( isset( $is_writer ) && $is_writer ) {
	$writing_mode     = isset( $writing_mode ) ? $writing_mode : (string) get_option( 'prautoblogger_writing_pipeline', 'multi_step' );
	$single_pass_keys = array( 'content.single_pass' );
	$multi_step_keys  = array( 'content.outline', 'content.draft', 'content.polish' );
	$is_inactive      = ( 'single_pass' === $writing_mode && in_array( $key, $multi_step_keys, true ) )
					 || ( 'multi_step' === $writing_mode && in_array( $key, $single_pass_keys, true ) );
}

$versions       = $panel['versions'] ?? array();
$has_history    = count( $versions ) > 1;
$history_id     = 'pab-history-' . $css_key;
$diff_id        = 'pab-diff-' . $css_key;
$preview_id     = 'pab-preview-' . $css_key;
$template_id    = 'pab-template-' . $css_key;
$toggle_hint_id = 'pab-toggle-hint-' . $css_key;
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

	<!-- M3: Template / Preview toggle -->
	<div class="pab-tp-toggle-row">
		<div class="pab-tp-toggle" role="group"
			 aria-label="<?php esc_attr_e( 'Template or preview mode', 'prautoblogger' ); ?>"
			 data-prompt-key="<?php echo esc_attr( $form_key ); ?>"
			 data-preview-id="<?php echo esc_attr( $preview_id ); ?>"
			 data-template-id="<?php echo esc_attr( $template_id ); ?>"
			 data-toggle-hint-id="<?php echo esc_attr( $toggle_hint_id ); ?>"
			 data-ajax-url="<?php echo esc_attr( admin_url( 'admin-ajax.php' ) ); ?>"
			 data-preview-action="<?php echo esc_attr( $view['preview_action'] ); ?>">
			<button type="button"
					class="pab-tp-btn pab-tp-btn--active"
					data-mode="template"
					aria-pressed="true">
				<?php esc_html_e( 'Template', 'prautoblogger' ); ?>
			</button>
			<button type="button"
					class="pab-tp-btn"
					data-mode="preview"
					aria-pressed="false">
				<?php esc_html_e( 'Preview assembled instructions', 'prautoblogger' ); ?>
			</button>
		</div>
		<span id="<?php echo esc_attr( $toggle_hint_id ); ?>" class="pab-tp-hint">
			<?php esc_html_e( 'Editing the template affects all future runs.', 'prautoblogger' ); ?>
		</span>
	</div>

	<!-- Template view (editable) -->
	<div id="<?php echo esc_attr( $template_id ); ?>" class="pab-tp-view pab-tp-view--template">
		<form method="post"
			  action="<?php echo esc_url( add_query_arg( 'step', $step_id, $base_url ) ); ?>"
			  class="pab-prompt-form">
			<?php wp_nonce_field( $nonce_action, $nonce_field ); ?>
			<input type="hidden" name="pipeline_action" value="save_prompt" />
			<input type="hidden" name="prompt_key" value="<?php echo esc_attr( $form_key ); ?>" />

			<div class="pab-prompt-editor-wrap">
				<div class="pab-prompt-editor-toolbar">
					<span class="pab-prompt-editor-title">
						<?php
						printf(
							/* translators: %s = prompt registry key */
							esc_html__( 'Template — %s', 'prautoblogger' ),
							esc_html( $key )
						);
						?>
					</span>
				</div>
				<textarea name="prompt_body"
						  id="pab-prompt-<?php echo esc_attr( $css_key ); ?>"
						  class="pab-prompt-editor"
						  rows="10"
						  spellcheck="false"><?php echo esc_textarea( $panel['body'] ); ?></textarea>
			</div>

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

	<!-- Preview view (read-only, M3) -->
	<div id="<?php echo esc_attr( $preview_id ); ?>"
		 class="pab-tp-view pab-tp-view--preview"
		 style="display:none;">
		<div class="pab-preview-block">
			<div class="pab-preview-header">
				<span class="pab-preview-label"><?php esc_html_e( 'Preview — assembled instructions', 'prautoblogger' ); ?></span>
				<span class="pab-preview-readonly-badge"><?php esc_html_e( 'Read-only', 'prautoblogger' ); ?></span>
				<span class="pab-preview-source-note js-preview-source"></span>
			</div>
			<pre class="pab-preview-body js-preview-body"><span class="pab-preview-loading"><?php esc_html_e( 'Loading preview…', 'prautoblogger' ); ?></span></pre>
			<div class="pab-preview-notice">
				<span class="dashicons dashicons-info-outline"></span>
				<span><?php echo wp_kses( __( '<strong>Preview only — not editable.</strong> This is the fully-rendered prompt the LLM will receive, with tokens filled from the last run. To change the template body, switch to the Template view.', 'prautoblogger' ), array( 'strong' => array() ) ); ?></span>
			</div>
		</div>
	</div>

	<!-- M3: Version history accordion -->
	<?php if ( count( $versions ) > 0 ) : ?>
	<div class="pab-history-accordion">
		<button type="button"
				class="pab-history-trigger"
				aria-expanded="false"
				aria-controls="<?php echo esc_attr( $history_id ); ?>">
			<span class="dashicons dashicons-arrow-down-alt2 pab-history-arrow"></span>
			<?php
			printf(
				/* translators: %s = prompt registry key */
				esc_html__( 'Version history — %s', 'prautoblogger' ),
				esc_html( $key )
			);
			?>
		</button>
		<div id="<?php echo esc_attr( $history_id ); ?>"
			 class="pab-history-body"
			 style="display:none;">
			<?php foreach ( $versions as $ver ) : ?>
				<?php
				$is_current = (bool) $ver['active'];
				$ver_num    = (int) $ver['version'];
				?>
				<div class="pab-version-row">
					<span class="pab-version-num">v<?php echo esc_html( (string) $ver_num ); ?></span>
					<span class="pab-version-author"><?php echo esc_html( (string) $ver['author'] ); ?></span>
					<span class="pab-version-date"><?php echo esc_html( (string) $ver['created_at'] ); ?></span>
					<?php if ( $is_current ) : ?>
						<span class="pab-badge pab-badge-ok pab-version-current"><?php esc_html_e( 'current', 'prautoblogger' ); ?></span>
					<?php endif; ?>
					<?php if ( $ver_num > 1 ) : ?>
						<button type="button"
								class="ab-btn ab-btn-outline ab-btn-sm pab-diff-btn"
								data-prompt-key="<?php echo esc_attr( $form_key ); ?>"
								data-version-a="<?php echo esc_attr( (string) ( $ver_num - 1 ) ); ?>"
								data-version-b="<?php echo esc_attr( (string) $ver_num ); ?>"
								data-diff-target="<?php echo esc_attr( $diff_id ); ?>"
								data-ajax-url="<?php echo esc_attr( admin_url( 'admin-ajax.php' ) ); ?>"
								data-diff-action="<?php echo esc_attr( $view['diff_action'] ); ?>">
							<?php esc_html_e( 'Diff', 'prautoblogger' ); ?>
						</button>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<!-- M3: Diff display area (hidden until a Diff button is clicked) -->
	<div id="<?php echo esc_attr( $diff_id ); ?>"
		 class="pab-diff-panel"
		 style="display:none;">
		<div class="pab-diff-header js-diff-header"></div>
		<div class="pab-diff-block js-diff-lines"></div>
		<button type="button"
				class="ab-btn ab-btn-outline ab-btn-sm pab-diff-close"
				data-diff-id="<?php echo esc_attr( $diff_id ); ?>">
			<?php esc_html_e( 'Close diff', 'prautoblogger' ); ?>
		</button>
	</div>
	<?php endif; ?>

</div>
