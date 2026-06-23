<?php
/**
 * Mission Brief board template (M5) -- primary landing screen.
 *
 * Renders a vertical run list grouped by status section plus a persistent
 * right-rail inspector. Replaces the four-column kanban layout (M1-M4).
 *
 * Section order: Generating | In Review | Published | Failed.
 * Within each section: newest first.
 * Stalled/failed rows have a red left-border accent.
 *
 * The inspector rail is populated by board.js on row-click via
 * PRAutoBlogger_Board_Inspector_Handler AJAX (no navigation).
 *
 * @see admin/class-board-page.php            -- Renders this template.
 * @see admin/class-board-data-provider.php   -- Supplies run data.
 * @see ajax/class-board-inspector-handler.php-- Inspector AJAX.
 * @see assets/js/board.js                    -- Polling + inspector JS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Eager load on first render so the list is not blank on arrival.
$provider = new PRAutoBlogger_Board_Data_Provider();
$snapshot = $provider->get_board_snapshot();

$sections = array(
	'generating' => array(
		'label' => __( 'Generating', 'prautoblogger' ),
		'chip'  => 'generating',
		'cards' => $snapshot['generating'],
	),
	'in_review'  => array(
		'label' => __( 'In review', 'prautoblogger' ),
		'chip'  => 'in_review',
		'cards' => $snapshot['in_review'],
	),
	'published'  => array(
		'label' => __( 'Published', 'prautoblogger' ),
		'chip'  => 'published',
		'cards' => $snapshot['published'],
	),
	'failed'     => array(
		'label' => __( 'Failed', 'prautoblogger' ),
		'chip'  => 'failed',
		'cards' => $snapshot['failed'],
	),
);

$ideas_url = esc_url( admin_url( 'admin.php?page=prautoblogger-ideas' ) );
?>
<div class="wrap prab-board-wrap">

	<div class="prab-board-topbar">
		<h1 class="prab-board-heading">
			<?php esc_html_e( 'PRAutoBlogger', 'prautoblogger' ); ?>
			<span class="prab-board-heading-sub"><?php esc_html_e( 'Mission Brief', 'prautoblogger' ); ?></span>
		</h1>
		<a href="<?php echo $ideas_url; ?>" class="prab-board-new-article-btn">
			<?php esc_html_e( 'New article', 'prautoblogger' ); ?>
		</a>
	</div>

	<div id="prab-board" class="prab-board prab-board--mission-brief" aria-label="<?php esc_attr_e( 'Article pipeline board', 'prautoblogger' ); ?>">

		<!-- Left: run list -->
		<div class="prab-run-list" role="list" aria-label="<?php esc_attr_e( 'Article runs', 'prautoblogger' ); ?>">

			<?php foreach ( $sections as $section_key => $section ) : ?>
				<?php $count = count( $section['cards'] ); ?>
				<div class="prab-section" data-section="<?php echo esc_attr( $section_key ); ?>">

					<div class="prab-section-header">
						<span class="prab-section-label"><?php echo esc_html( $section['label'] ); ?></span>
						<span class="prab-section-count prab-chip prab-chip--<?php echo esc_attr( $section['chip'] ); ?>">
							<?php echo esc_html( (string) $count ); ?>
						</span>
					</div>

					<div class="prab-section-rows" data-section-rows="<?php echo esc_attr( $section_key ); ?>">
						<?php if ( empty( $section['cards'] ) ) : ?>
							<p class="prab-section-empty"><?php esc_html_e( 'Nothing here yet.', 'prautoblogger' ); ?></p>
						<?php else : ?>
							<?php foreach ( $section['cards'] as $card ) : ?>
								<?php prab_render_board_row( $card, $section_key ); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>

				</div><!-- .prab-section -->
			<?php endforeach; ?>

		</div><!-- .prab-run-list -->

		<!-- Right: inspector rail -->
		<aside class="prab-inspector" id="prab-inspector" aria-label="<?php esc_attr_e( 'Run inspector', 'prautoblogger' ); ?>">
			<div class="prab-inspector-inner" id="prab-inspector-inner">
				<p class="prab-inspector-placeholder">
					<?php esc_html_e( 'Select an article to preview its pipeline.', 'prautoblogger' ); ?>
				</p>
			</div>
		</aside>

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
<?php

/**
 * Render a single run-list row.
 *
 * @param array<string, mixed> $card       Card data from Board_Data_Provider.
 * @param string               $section_key Section identifier (generating|in_review|published|failed).
 * @return void
 */
function prab_render_board_row( array $card, string $section_key ): void {
	$title     = ! empty( $card['title'] ) ? esc_html( $card['title'] ) : esc_html__( 'Untitled', 'prautoblogger' );
	$run_id    = esc_attr( (string) ( $card['run_id'] ?? '' ) );
	$post_id   = esc_attr( (string) ( $card['post_id'] ?? 0 ) );
	$cost      = isset( $card['cost_total'] ) ? '$' . esc_html( number_format( (float) $card['cost_total'], 4 ) ) : '';
	$is_failed = ( 'failed' === $section_key );

	// Elapsed time.
	$elapsed    = '';
	$started_ts = (int) ( $card['started_at'] ?? $card['created_at'] ?? 0 );
	if ( $started_ts > 0 ) {
		$diff    = max( 0, time() - $started_ts );
		$elapsed = $diff < 60
			? sprintf(
				/* translators: %d: seconds */
				esc_html__( '%ds', 'prautoblogger' ),
				$diff
			)
			: sprintf(
				/* translators: %d: minutes */
				esc_html__( '%dm', 'prautoblogger' ),
				(int) floor( $diff / 60 )
			);
	}

	$row_class = 'prab-run-row';
	if ( $is_failed || 'failed' === ( $card['status'] ?? '' ) ) {
		$row_class .= ' prab-run-row--failed';
	}
	?>
	<div class="<?php echo esc_attr( $row_class ); ?>"
		 role="listitem"
		 data-run-id="<?php echo $run_id; ?>"
		 data-post-id="<?php echo $post_id; ?>"
		 tabindex="0"
		 aria-label="<?php echo $title; ?>">

		<div class="prab-row-chip-wrap">
			<span class="prab-chip prab-chip--<?php echo esc_attr( $section_key ); ?>">
				<?php
				$chip_labels = array(
					'generating' => __( 'Generating', 'prautoblogger' ),
					'in_review'  => __( 'In review', 'prautoblogger' ),
					'published'  => __( 'Published', 'prautoblogger' ),
					'failed'     => __( 'Failed', 'prautoblogger' ),
				);
				echo esc_html( $chip_labels[ $section_key ] ?? ucfirst( $section_key ) );
				?>
			</span>
			<?php if ( ! empty( $card['human_modified'] ) ) : ?>
				<span class="prab-chip prab-chip--human" title="<?php esc_attr_e( 'Human-modified', 'prautoblogger' ); ?>">H</span>
			<?php endif; ?>
		</div>

		<div class="prab-row-main">
			<div class="prab-row-title"><?php echo $title; ?></div>

			<?php if ( ! empty( $card['stage_current'] ) ) : ?>
				<div class="prab-row-stage-label">
					<?php echo esc_html( PRAutoBlogger_Stage_Display_Map::label( (string) $card['stage_current'] ) ); ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $card['error_message'] ) ) : ?>
				<div class="prab-row-error">
					<?php echo esc_html( wp_trim_words( (string) $card['error_message'], 12, '…' ) ); ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="prab-row-meta">
			<?php if ( '' !== $cost ) : ?>
				<span class="prab-row-cost"><?php echo $cost; ?></span>
			<?php endif; ?>
			<?php if ( '' !== $elapsed ) : ?>
				<span class="prab-row-elapsed"><?php echo $elapsed; ?></span>
			<?php endif; ?>
		</div>

	</div><!-- .prab-run-row -->
	<?php
}
