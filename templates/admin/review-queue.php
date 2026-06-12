<?php
/**
 * Review queue template — lists generated drafts awaiting editorial approval.
 *
 * Variables available from PRAutoBlogger_Review_Queue::render_page():
 *   $query (WP_Query) — the pending draft posts.
 *
 * @see admin/class-review-queue.php — Renders this template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bulk_done = isset( $_GET['prautoblogger_bulk_done'] ) ? absint( $_GET['prautoblogger_bulk_done'] ) : 0;
$bulk_type = isset( $_GET['prautoblogger_bulk_type'] ) ? sanitize_text_field( wp_unslash( $_GET['prautoblogger_bulk_type'] ) ) : '';
?>
<div class="wrap prautoblogger-review-queue">
	<h1><?php esc_html_e( 'PRAutoBlogger — Review Queue', 'prautoblogger' ); ?></h1>

	<?php if ( ! empty( $halted_runs ) ) : ?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Runs halted by the per-run cost ceiling', 'prautoblogger' ); ?></strong>
				<?php esc_html_e( '— these runs were stopped before exceeding their budget and need human review. Raise the ceiling in Settings or discard the run.', 'prautoblogger' ); ?>
			</p>
			<table class="widefat striped" style="margin-bottom: 8px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Run ID', 'prautoblogger' ); ?></th>
						<th><?php esc_html_e( 'Halted at', 'prautoblogger' ); ?></th>
						<th><?php esc_html_e( 'Spent + held (USD)', 'prautoblogger' ); ?></th>
						<th><?php esc_html_e( 'Ceiling (USD)', 'prautoblogger' ); ?></th>
						<th><?php esc_html_e( 'Blocked overage (USD)', 'prautoblogger' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $halted_runs as $halted_run ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $halted_run['run_id'] ); ?></code></td>
							<td><?php echo esc_html( (string) ( $halted_run['finished_at'] ?? $halted_run['updated_at'] ) ); ?></td>
							<td><?php echo esc_html( number_format( (float) $halted_run['settled_usd'] + (float) $halted_run['reserved_usd'], 4 ) ); ?></td>
							<td><?php echo esc_html( number_format( (float) $halted_run['ceiling_usd'], 2 ) ); ?></td>
							<td><?php echo esc_html( number_format( (float) $halted_run['overage_usd'], 4 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<?php if ( $bulk_done > 0 && '' !== $bulk_type ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				if ( 'approve' === $bulk_type ) {
					printf(
						/* translators: %d: number of posts */
						esc_html( _n( '%d post published.', '%d posts published.', $bulk_done, 'prautoblogger' ) ),
						$bulk_done
					);
				} else {
					printf(
						/* translators: %d: number of posts */
						esc_html( _n( '%d post rejected.', '%d posts rejected.', $bulk_done, 'prautoblogger' ) ),
						$bulk_done
					);
				}
				?>
			</p>
		</div>
	<?php endif; ?>

	<div id="prautoblogger-queue-status" class="prautoblogger-status-message hidden"></div>

	<?php if ( ! $query->have_posts() ) : ?>
		<div class="prautoblogger-empty-queue">
			<p><?php esc_html_e( 'No generated drafts awaiting review. New posts will appear here after the next generation run.', 'prautoblogger' ); ?></p>
		</div>
	<?php else : ?>
		<form method="post" id="prautoblogger-review-form">
			<?php wp_nonce_field( 'prautoblogger_review_queue_bulk', 'prautoblogger_review_nonce' ); ?>

			<div class="prautoblogger-queue-toolbar">
				<label>
					<input type="checkbox" id="prautoblogger-select-all" />
					<?php esc_html_e( 'Select All', 'prautoblogger' ); ?>
				</label>
				<button type="submit" name="prautoblogger_bulk_action" value="approve" class="button button-primary">
					<?php esc_html_e( 'Approve Selected', 'prautoblogger' ); ?>
				</button>
				<button type="submit" name="prautoblogger_bulk_action" value="reject" class="button">
					<?php esc_html_e( 'Reject Selected', 'prautoblogger' ); ?>
				</button>
				<span class="prautoblogger-queue-count">
					<?php
					printf(
						/* translators: %d: number of drafts */
						esc_html( _n( '%d draft', '%d drafts', $query->found_posts, 'prautoblogger' ) ),
						$query->found_posts
					);
					?>
				</span>
			</div>

			<?php
			// v0.20.0: stale-stage warning chips (one batched query; CPO
			// product AC -- the stale flag is visible at publish time).
			$rq_post_runs = array();
			foreach ( $query->posts as $rq_post ) {
				$rq_run_id = (string) get_post_meta( $rq_post->ID, '_prautoblogger_run_id', true );
				if ( '' !== $rq_run_id ) {
					$rq_post_runs[ $rq_post->ID ] = $rq_run_id;
				}
			}
			$rq_stale_runs = array_flip( PRAutoBlogger_Run_Stage_State::runs_with_stale_stages( array_values( $rq_post_runs ) ) );
			?>
			<table class="widefat striped prautoblogger-queue-table">
				<thead>
					<tr>
						<th class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Select', 'prautoblogger' ); ?></span></th>
						<th><?php esc_html_e( 'Title', 'prautoblogger' ); ?></th>
						<th><?php esc_html_e( 'Topic', 'prautoblogger' ); ?></th>
						<th><?php esc_html_e( 'Type', 'prautoblogger' ); ?></th>
						<th><?php esc_html_e( 'Editor Verdict', 'prautoblogger' ); ?></th>
						<th><?php esc_html_e( 'Quality', 'prautoblogger' ); ?></th>
						<th><?php esc_html_e( 'Generated', 'prautoblogger' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'prautoblogger' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					while ( $query->have_posts() ) :
						$query->the_post();
						?>
						<?php
						$post_id       = get_the_ID();
						$verdict       = get_post_meta( $post_id, '_prautoblogger_editor_verdict', true );
						$topic         = get_post_meta( $post_id, '_prautoblogger_topic', true );
						$article_type  = get_post_meta( $post_id, '_prautoblogger_article_type', true );
						$quality_score = get_post_meta( $post_id, '_prautoblogger_quality_score', true );
						$generated_at  = get_post_meta( $post_id, '_prautoblogger_generated_at', true );
						$editor_notes  = get_post_meta( $post_id, '_prautoblogger_editor_notes', true );
						$verdict_class = 'prautoblogger-verdict-' . sanitize_html_class( $verdict ?: 'pending' );
						?>
						<tr data-post-id="<?php echo esc_attr( (string) $post_id ); ?>">
							<td class="check-column">
								<input type="checkbox" name="prautoblogger_post_ids[]" value="<?php echo esc_attr( (string) $post_id ); ?>" />
							</td>
							<td>
								<strong><?php the_title(); ?></strong>
								<?php if ( isset( $rq_post_runs[ $post_id ], $rq_stale_runs[ $rq_post_runs[ $post_id ] ] ) ) : ?>
									<a class="prab-review-stale-chip" href="<?php echo esc_url( PRAutoBlogger_Dossier_Page::url_for_post( (int) $post_id ) ); ?>">
										<?php esc_html_e( 'Stale stages -- review dossier', 'prautoblogger' ); ?>
									</a>
								<?php endif; ?>
								<?php if ( $editor_notes ) : ?>
									<div class="prautoblogger-editor-notes"><?php echo esc_html( $editor_notes ); ?></div>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $topic ?: '—' ); ?></td>
							<td><?php echo esc_html( ucfirst( $article_type ?: 'article' ) ); ?></td>
							<td><span class="<?php echo esc_attr( $verdict_class ); ?>"><?php echo esc_html( ucfirst( $verdict ?: 'pending' ) ); ?></span></td>
							<td><?php echo '' !== $quality_score ? esc_html( $quality_score . '/10' ) : '—'; ?></td>
							<td><?php echo $generated_at ? esc_html( wp_date( 'M j, g:i A', strtotime( $generated_at ) ) ) : '—'; ?></td>
							<td class="prautoblogger-queue-actions">
								<button type="button" class="button button-small button-primary prautoblogger-approve-btn" data-post-id="<?php echo esc_attr( (string) $post_id ); ?>">
									<?php esc_html_e( 'Approve', 'prautoblogger' ); ?>
								</button>
								<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>" class="button button-small">
									<?php esc_html_e( 'Edit', 'prautoblogger' ); ?>
								</a>
								<a href="<?php echo esc_url( get_preview_post_link( $post_id ) ); ?>" class="button button-small" target="_blank">
									<?php esc_html_e( 'Preview', 'prautoblogger' ); ?>
								</a>
								<button type="button" class="button button-small prautoblogger-reject-btn" data-post-id="<?php echo esc_attr( (string) $post_id ); ?>">
									<?php esc_html_e( 'Reject', 'prautoblogger' ); ?>
								</button>
							</td>
						</tr>
					<?php endwhile; ?>
					<?php wp_reset_postdata(); ?>
				</tbody>
			</table>
		</form>

		<?php
		// Pagination.
		$total_pages = $query->max_num_pages;
		if ( $total_pages > 1 ) :
			$current_page = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
			echo '<div class="prautoblogger-pagination">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $current_page,
						'total'     => $total_pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
			echo '</div>';
		endif;
		?>
	<?php endif; ?>
</div>
