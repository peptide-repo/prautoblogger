<?php
/**
 * Partial: log-only stage section (M3/F3, v0.20.0).
 *
 * Renders stages that exist ONLY in generation_log — image_a/image_b,
 * image_prompt_rewrite, llm_research, opik_eval_judge, ... — which the
 * M2 dossier silently dropped because the image pipeline (and the
 * research providers) never write run_stages rows (QA M2 F3). These
 * sections show the call record (model, tokens, cost, status, raw
 * trace) from their REAL data source; image stages additionally render
 * the post's actual pipeline attachments ($dossier['images']).
 *
 * Variables injected by dossier-page.php:
 *   $stage_name (string) -- stage key
 *   $log_rows (array)    -- generation_log rows for this stage
 *   $dossier (array)     -- page view model (images)
 *
 * Security: identical escaping discipline to dossier-stage-section.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$lo_label    = PRAutoBlogger_Stage_Display_Map::label( $stage_name );
$lo_is_image = in_array( $stage_name, array( 'image_a', 'image_b' ), true );
$lo_images   = $lo_is_image && ! empty( $dossier['images'] ) ? (array) $dossier['images'] : array();
$lo_cost     = 0.0;
$lo_status   = '';
$lo_model    = '—';
foreach ( $log_rows as $lo_row ) {
	$lo_cost  += (float) ( $lo_row['estimated_cost'] ?? 0 );
	$lo_status = (string) ( $lo_row['response_status'] ?? '' );
	if ( ! empty( $lo_row['model'] ) ) {
		$lo_model = (string) $lo_row['model'];
	}
}
$lo_section_id = 'prab-logstage-' . esc_attr( $stage_name );
?>
<div class="prab-dossier-section prab-dossier-section--stage prab-dossier-section--log-only"
	 data-stage="<?php echo esc_attr( $stage_name ); ?>"
	 id="<?php echo $lo_section_id; // Already escaped. ?>">

	<div class="prab-stage-header">
		<h2 class="prab-section-title"><?php echo esc_html( $lo_label ); ?></h2>
		<div class="prab-stage-header-meta">
			<?php if ( '' !== $lo_status ) : ?>
				<span class="prab-trace-status prab-trace-status--<?php echo esc_attr( $lo_status ); ?>">
					<?php echo esc_html( $lo_status ); ?>
				</span>
			<?php endif; ?>
			<?php if ( $lo_cost > 0 ) : ?>
				<span class="prab-stage-cost">$<?php echo esc_html( number_format( $lo_cost, 5 ) ); ?></span>
			<?php endif; ?>
			<span class="prab-stage-model"><?php echo esc_html( $lo_model ); ?></span>
		</div>
	</div>

	<?php if ( ! empty( $lo_images ) ) : ?>
		<div class="prab-image-grid">
			<?php foreach ( $lo_images as $lo_image ) : ?>
				<figure class="prab-image-card<?php echo ! empty( $lo_image['is_featured'] ) ? ' prab-image-card--chosen' : ''; ?>">
					<img src="<?php echo esc_url( (string) $lo_image['url'] ); ?>" alt="<?php echo esc_attr( (string) $lo_image['role'] ); ?>" loading="lazy" />
					<figcaption>
						<?php echo esc_html( ucfirst( (string) $lo_image['role'] ) ); ?>
						<?php if ( ! empty( $lo_image['is_featured'] ) ) : ?>
							<span class="prab-chip prab-chip--chosen"><?php esc_html_e( 'Chosen (featured)', 'prautoblogger' ); ?></span>
						<?php endif; ?>
					</figcaption>
				</figure>
			<?php endforeach; ?>
		</div>
	<?php elseif ( $lo_is_image ) : ?>
		<p class="prab-output-absent"><?php esc_html_e( 'No pipeline attachments recorded for this article (images attach on publish).', 'prautoblogger' ); ?></p>
	<?php endif; ?>

	<div class="prab-stage-trace">
		<button type="button" class="prab-trace-toggle button-link"
				aria-expanded="false"
				aria-controls="<?php echo $lo_section_id; // Already escaped. ?>-trace">
			<?php esc_html_e( 'Show raw trace', 'prautoblogger' ); ?>
		</button>
		<div id="<?php echo $lo_section_id; // Already escaped. ?>-trace" class="prab-trace-detail" hidden>
			<?php foreach ( $log_rows as $log_row ) : ?>
				<div class="prab-trace-entry">
					<div class="prab-trace-meta">
						<span><?php echo esc_html( (string) ( $log_row['model'] ?? '—' ) ); ?></span>
						<span>
						<?php
						printf(
							/* translators: 1: prompt tokens, 2: completion tokens. */
							esc_html__( '%1$d prompt + %2$d completion tokens', 'prautoblogger' ),
							(int) ( $log_row['prompt_tokens'] ?? 0 ),
							(int) ( $log_row['completion_tokens'] ?? 0 )
						);
						?>
						</span>
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

</div><!-- .prab-dossier-section--log-only -->
