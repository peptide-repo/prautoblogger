<?php
/**
 * Partial: Pipeline Settings — editable step-option fields.
 *
 * Renders a save form containing every option field for the current step
 * context (research / analysis / writer / editorial) or the global context.
 *
 * Variables expected in scope (provided by pipeline-settings-page.php):
 *   string  $nonce_action — nonce action string.
 *   string  $nonce_field  — nonce field name.
 *   string  $step_id      — active step id (for form action URL, e.g. 'writer').
 *   string  $base_url     — admin.php?page=prautoblogger-pipeline base URL.
 *   string  $context      — step context key passed as step_context POST field.
 *   array   $fields       — array of field defs with 'current' key (from renderer).
 *   string  $section_title — h3 title for this options block.
 *
 * @see admin/class-pipeline-settings-option-fields.php — Field definitions + sanitizer.
 * @see admin/class-pipeline-settings-renderer.php      — Provides $fields via build_option_field_values().
 * @see templates/admin/pipeline-settings-page.php      — Includes this partial.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="pab-section">
	<h3 class="pab-section-title"><?php echo esc_html( $section_title ); ?></h3>
	<form method="post"
		  action="<?php echo esc_url( add_query_arg( 'step', $step_id, $base_url ) ); ?>"
		  class="pab-step-options-form">
		<?php wp_nonce_field( $nonce_action, $nonce_field ); ?>
		<input type="hidden" name="pipeline_action" value="save_step_settings" />
		<input type="hidden" name="step_context" value="<?php echo esc_attr( $context ); ?>" />

		<table class="form-table pab-options-table">
			<tbody>
			<?php foreach ( $fields as $field ) : ?>
				<?php
				$fid     = (string) $field['id'];
				$ftype   = (string) ( $field['type'] ?? 'textarea' );
				$current = $field['current'] ?? ( $field['default'] ?? '' );
				?>
				<tr>
					<th scope="row">
						<label for="pab-opt-<?php echo esc_attr( $fid ); ?>">
							<?php echo esc_html( $field['label'] ?? $fid ); ?>
						</label>
					</th>
					<td>
						<?php if ( 'textarea' === $ftype ) : ?>
							<textarea id="pab-opt-<?php echo esc_attr( $fid ); ?>"
									  name="<?php echo esc_attr( $fid ); ?>"
									  rows="5"
									  class="large-text code"><?php echo esc_textarea( (string) $current ); ?></textarea>

						<?php elseif ( 'select' === $ftype ) : ?>
							<select id="pab-opt-<?php echo esc_attr( $fid ); ?>"
									name="<?php echo esc_attr( $fid ); ?>">
								<?php foreach ( (array) ( $field['options'] ?? array() ) as $val => $label ) : ?>
									<option value="<?php echo esc_attr( (string) $val ); ?>"
									<?php selected( (string) $current, (string) $val ); ?>>
										<?php echo esc_html( (string) $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>

						<?php elseif ( 'number' === $ftype ) : ?>
							<input type="number"
								   id="pab-opt-<?php echo esc_attr( $fid ); ?>"
								   name="<?php echo esc_attr( $fid ); ?>"
								   value="<?php echo esc_attr( (string) $current ); ?>"
								   class="small-text"
								   <?php echo isset( $field['min'] ) ? 'min="' . esc_attr( (string) $field['min'] ) . '"' : ''; ?>
								   <?php echo isset( $field['max'] ) ? 'max="' . esc_attr( (string) $field['max'] ) . '"' : ''; ?>
								   />

						<?php elseif ( 'toggle' === $ftype ) : ?>
							<label class="pab-toggle-wrap">
								<input type="checkbox"
									   id="pab-opt-<?php echo esc_attr( $fid ); ?>"
									   name="<?php echo esc_attr( $fid ); ?>"
									   value="1"
									   <?php checked( '1', (string) $current ); ?> />
								<span class="pab-toggle-label">
									<?php esc_html_e( 'Enabled', 'prautoblogger' ); ?>
								</span>
							</label>

						<?php elseif ( 'checkboxes' === $ftype ) : ?>
							<?php
							// current is stored as JSON array string.
							$checked_vals = array();
							if ( is_string( $current ) && '' !== $current ) {
								$decoded = json_decode( $current, true );
								if ( is_array( $decoded ) ) {
									$checked_vals = $decoded;
								}
							}
							?>
							<fieldset>
								<?php foreach ( (array) ( $field['choices'] ?? array() ) as $val => $label ) : ?>
									<label>
										<input type="checkbox"
											   name="<?php echo esc_attr( $fid ); ?>[]"
											   value="<?php echo esc_attr( (string) $val ); ?>"
											   <?php checked( in_array( (string) $val, array_map( 'strval', $checked_vals ), true ) ); ?> />
										<?php echo esc_html( (string) $label ); ?>
									</label><br />
								<?php endforeach; ?>
							</fieldset>
						<?php endif; ?>

						<?php if ( ! empty( $field['description'] ) ) : ?>
							<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p class="submit">
			<button type="submit" class="ab-btn ab-btn-secondary ab-btn-sm">
				<?php esc_html_e( 'Save Settings', 'prautoblogger' ); ?>
			</button>
		</p>
	</form>
</div>
