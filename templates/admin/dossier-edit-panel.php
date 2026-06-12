<?php
/**
 * Partial: per-stage edit + replay panel (M3, v0.20.0).
 *
 * Rendered only for editable stages (writer chat stages with a
 * persisted input). Included by dossier-stage-section.php; inherits its
 * scope: $stage_name, $s_role, $section_id, $rerun (panel data),
 * $dossier (spend/eligibility/post_id).
 *
 * UX contract (CPO guardrails): the copy states explicitly that saving
 * forks a NEW version (original preserved), that re-running marks
 * downstream stages stale, that execution is QUEUED (never implied
 * synchronous), and what the run has already spent against its ceiling.
 *
 * Security: message contents via esc_textarea(); all attributes
 * esc_attr(); spend numbers via number_format + esc_html.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$panel_prefill = is_array( $rerun['prefill'] ?? null ) ? $rerun['prefill'] : array(
	'model'    => '',
	'messages' => array(),
);
$panel_fork    = is_array( $rerun['fork'] ?? null ) ? $rerun['fork'] : null;
$panel_spend   = is_array( $dossier['spend'] ?? null ) ? $dossier['spend'] : array(
	'settled'  => 0.0,
	'reserved' => 0.0,
	'ceiling'  => 0.0,
	'warn'     => false,
);
$held_usd      = (float) $panel_spend['settled'] + (float) $panel_spend['reserved'];
?>
<div class="prab-edit-panel" id="<?php echo $section_id; // Already escaped. ?>-edit" hidden
	 data-stage="<?php echo esc_attr( $stage_name ); ?>"
	 data-agent-role="<?php echo esc_attr( $s_role ); ?>">

	<p class="prab-edit-copy">
		<?php esc_html_e( 'You are editing a copy of this stage\'s input. Saving creates a new version — the original is preserved unchanged. Re-running this stage executes your latest saved version as a queued background job and marks all downstream stages stale; nothing downstream re-runs until you trigger it.', 'prautoblogger' ); ?>
	</p>

	<p class="prab-edit-spend<?php echo ! empty( $panel_spend['warn'] ) ? ' prab-edit-spend--warn' : ''; ?>">
		<?php if ( (float) $panel_spend['ceiling'] > 0 ) : ?>
			<?php
			printf(
				/* translators: 1: spent USD, 2: ceiling USD. */
				esc_html__( 'Run spend so far: $%1$s of the $%2$s per-run ceiling. Re-runs are new spend on this run.', 'prautoblogger' ),
				esc_html( number_format( $held_usd, 4 ) ),
				esc_html( number_format( (float) $panel_spend['ceiling'], 2 ) )
			);
			if ( ! empty( $panel_spend['warn'] ) ) {
				echo ' ';
				esc_html_e( 'Warning: this run is approaching its ceiling — the re-run may halt. Raise the per-run ceiling setting first if needed.', 'prautoblogger' );
			}
			?>
		<?php else : ?>
			<?php esc_html_e( 'No per-run cost ceiling is configured for this run (monthly budget still applies).', 'prautoblogger' ); ?>
		<?php endif; ?>
	</p>

	<p class="prab-edit-model">
		<?php
		printf(
			/* translators: %s: model identifier. */
			esc_html__( 'Model (fixed for replay): %s', 'prautoblogger' ),
			esc_html( (string) $panel_prefill['model'] )
		);
		?>
	</p>

	<?php foreach ( $panel_prefill['messages'] as $i => $panel_message ) : ?>
		<div class="prab-edit-message">
			<label class="prab-edit-role" for="<?php echo $section_id; // Already escaped. ?>-msg-<?php echo (int) $i; ?>">
				<?php echo esc_html( ucfirst( (string) ( $panel_message['role'] ?? '' ) ) ); ?>
			</label>
			<textarea class="prab-edit-textarea large-text"
					id="<?php echo $section_id; // Already escaped. ?>-msg-<?php echo (int) $i; ?>"
					rows="<?php echo 'system' === ( $panel_message['role'] ?? '' ) ? 4 : 10; ?>"
					data-role="<?php echo esc_attr( (string) ( $panel_message['role'] ?? '' ) ); ?>"><?php echo esc_textarea( (string) ( $panel_message['content'] ?? '' ) ); ?></textarea>
		</div>
	<?php endforeach; ?>

	<div class="prab-edit-actions">
		<button type="button" class="button button-primary prab-edit-save">
			<?php esc_html_e( 'Save as new version', 'prautoblogger' ); ?>
		</button>
		<button type="button" class="button prab-edit-rerun" <?php disabled( null === $panel_fork ); ?>>
			<?php esc_html_e( 'Re-run this stage (queued)', 'prautoblogger' ); ?>
		</button>
		<span class="prab-edit-forkinfo" data-forkinfo>
			<?php
			if ( null !== $panel_fork ) {
				printf(
					/* translators: 1: version number, 2: author login, 3: datetime. */
					esc_html__( 'Latest saved version: v%1$d by %2$s at %3$s', 'prautoblogger' ),
					(int) $panel_fork['version'],
					esc_html( (string) $panel_fork['author'] ),
					esc_html( (string) $panel_fork['created_at'] )
				);
			} else {
				esc_html_e( 'No edited version saved yet.', 'prautoblogger' );
			}
			?>
		</span>
		<span class="prab-edit-feedback" aria-live="polite" data-feedback></span>
	</div>
</div>
