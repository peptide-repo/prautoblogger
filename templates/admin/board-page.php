<?php
/**
 * Kanban board template — primary landing screen for PRAutoBlogger.
 *
 * Renders the four-column board: Generating | In Review | Published | Failed.
 * Cards are populated on first load by a synchronous PHP data call, then kept
 * live by the board.js poller (AJAX, settings-backed interval).
 *
 * @see admin/class-board-page.php       — Renders this template.
 * @see admin/class-board-data-provider.php — Supplies card data.
 * @see assets/js/board.js               — Polling + DOM updates.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Eager load on first render so the board is not blank on arrival.
$provider = new PRAutoBlogger_Board_Data_Provider();
$snapshot = $provider->get_board_snapshot();

/**
 * Render a single board card as HTML.
 *
 * @param array<string, mixed> $card       Card data from Board_Data_Provider.
 * @param string               $column_key Column identifier (generating|in_review|published|failed).
 * @return void
 */
function prab_render_board_card( array $card, string $column_key ): void {
	$title     = ! empty( $card['title'] ) ? esc_html( $card['title'] ) : esc_html__( 'Untitled', 'prautoblogger' );
	$cost      = isset( $card['cost_total'] ) ? '$' . esc_html( number_format( (float) $card['cost_total'], 4 ) ) : '';
	$click_url = '';
	$link_text = '';

	if ( 'review' === ( $card['click_action'] ?? '' ) ) {
		$click_url = esc_url( $card['review_url'] ?? '' );
		$link_text = esc_html__( 'Review Queue', 'prautoblogger' );
	} elseif ( 'edit' === ( $card['click_action'] ?? '' ) ) {
		$click_url = esc_url( $card['edit_url'] ?? '' );
		$link_text = esc_html__( 'Edit Post', 'prautoblogger' );
	} elseif ( 'logs' === ( $card['click_action'] ?? '' ) ) {
		$click_url = esc_url( $card['log_url'] ?? '' );
		$link_text = esc_html__( 'View Log', 'prautoblogger' );
	}

	$is_generating = ( 'generating' === $column_key );
	$card_class    = 'prab-board-card';
	if ( $is_generating ) {
		$card_class .= ' prab-board-card--generating';
	}
	?>
	<div class="<?php echo esc_attr( $card_class ); ?>"
		 data-run-id="<?php echo esc_attr( (string) ( $card['run_id'] ?? '' ) ); ?>"
		 data-post-id="<?php echo esc_attr( (string) ( $card['post_id'] ?? 0 ) ); ?>">

		<?php if ( $is_generating ) : ?>
			<div class="prab-card-spinner" aria-label="<?php esc_attr_e( 'Generating in progress', 'prautoblogger' ); ?>"></div>
		<?php endif; ?>

		<div class="prab-card-title"><?php echo $title; // Already escaped above. ?></div>

		<?php if ( ! empty( $cost ) ) : ?>
			<div class="prab-card-meta">
				<span class="prab-card-cost"><?php echo $cost; // Already escaped above. ?></span>
			</div>
		<?php endif; ?>

		<?php if ( $is_generating && ! empty( $card['stage_current'] ) ) : ?>
			<div class="prab-card-stage">
				<?php
				printf(
					/* translators: %s: pipeline stage name */
					esc_html__( 'Stage: %s', 'prautoblogger' ),
					esc_html( ucfirst( (string) $card['stage_current'] ) )
				);
				?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $card['error_message'] ) ) : ?>
			<div class="prab-card-error">
				<?php echo esc_html( wp_trim_words( (string) $card['error_message'], 12, '…' ) ); ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $click_url ) ) : ?>
			<div class="prab-card-actions">
				<a href="<?php echo $click_url; // Already escaped above. ?>" class="prab-card-link">
					<?php echo $link_text; // Already escaped above. ?>
				</a>
			</div>
		<?php endif; ?>
	</div>
	<?php
}
?>
<div class="wrap prab-board-wrap">
	<h1 class="prab-board-heading">
		<?php esc_html_e( 'PRAutoBlogger', 'prautoblogger' ); ?>
		<span class="prab-board-heading-sub"><?php esc_html_e( 'Article Board', 'prautoblogger' ); ?></span>
	</h1>

	<div id="prab-board" class="prab-board" aria-live="polite" aria-label="<?php esc_attr_e( 'Article generation board', 'prautoblogger' ); ?>">

		<?php
		$columns = array(
			'generating' => array(
				'label'  => __( 'Generating', 'prautoblogger' ),
				'pill'   => 'prab-pill--generating',
				'cards'  => $snapshot['generating'],
			),
			'in_review'  => array(
				'label'  => __( 'In Review', 'prautoblogger' ),
				'pill'   => 'prab-pill--review',
				'cards'  => $snapshot['in_review'],
			),
			'published'  => array(
				'label'  => __( 'Published', 'prautoblogger' ),
				'pill'   => 'prab-pill--published',
				'cards'  => $snapshot['published'],
			),
			'failed'     => array(
				'label'  => __( 'Failed', 'prautoblogger' ),
				'pill'   => 'prab-pill--failed',
				'cards'  => $snapshot['failed'],
			),
		);
		foreach ( $columns as $col_key => $col ) :
			$count = count( $col['cards'] );
			?>
			<div class="prab-board-column" data-column="<?php echo esc_attr( $col_key ); ?>">
				<div class="prab-col-header">
					<span class="prab-col-title"><?php echo esc_html( $col['label'] ); ?></span>
					<span class="prab-col-count prab-pill <?php echo esc_attr( $col['pill'] ); ?>"><?php echo esc_html( (string) $count ); ?></span>
				</div>
				<div class="prab-col-cards" data-column-cards="<?php echo esc_attr( $col_key ); ?>">
					<?php if ( empty( $col['cards'] ) ) : ?>
						<p class="prab-col-empty"><?php esc_html_e( 'Nothing here yet.', 'prautoblogger' ); ?></p>
					<?php else : ?>
						<?php foreach ( $col['cards'] as $card ) : ?>
							<?php prab_render_board_card( $card, $col_key ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>

	</div><!-- #prab-board -->

	<p class="prab-board-footer">
		<?php
		$interval_secs = max( 3, (int) get_option( 'prautoblogger_board_poll_interval', 5 ) );
		printf(
			/* translators: %d: poll interval in seconds */
			esc_html__( 'Board refreshes every %d seconds while an article is generating. Configure in Settings → Schedule & Budget.', 'prautoblogger' ),
			$interval_secs
		);
		?>
	</p>
</div><!-- .prab-board-wrap -->
