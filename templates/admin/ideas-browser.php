<?php
/**
 * Ideas browser template — lists analysis results (article ideas) from the pipeline.
 *
 * Variables available from PRAutoBlogger_Ideas_Browser::render_page():
 *   $rows        (array[])  — analysis result rows from the database.
 *   $total       (int)      — total row count (before pagination).
 *   $total_pages (int)      — total number of pages.
 *   $type_filter (string)   — active type filter (empty = all).
 *   $type_counts (array)    — map of analysis_type => count for filter badges.
 *   $paged       (int)      — current page number.
 *
 * @see admin/class-ideas-browser.php — Renders this template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$base_url = admin_url( 'admin.php?page=prautoblogger-ideas' );
?>
<style>
	.ab-spin { animation: ab-spin 1s linear infinite; display: inline-block; }
	@keyframes ab-spin { 100% { transform: rotate(360deg); } }
</style>
<div class="wrap prautoblogger-ideas-browser">
	<h1><?php esc_html_e( 'PRAutoBlogger — Ideas', 'prautoblogger' ); ?></h1>
	<p class="description">
		<?php
		printf(
			/* translators: %d: total idea count */
			esc_html__( '%d ideas collected from analysis runs. Newest first.', 'prautoblogger' ),
			$total
		);
		?>
	</p>

	<?php if ( ! empty( $type_counts ) ) : ?>
		<div class="prautoblogger-ideas-filters" style="margin: 12px 0;">
			<a href="<?php echo esc_url( $base_url ); ?>"
			   class="button <?php echo '' === $type_filter ? 'button-primary' : ''; ?>"
			   style="margin-right: 4px;">
				<?php printf( '%s (%d)', esc_html__( 'All', 'prautoblogger' ), $total ); ?>
			</a>
			<?php foreach ( $type_counts as $type_name => $count ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'type', $type_name, $base_url ) ); ?>"
				   class="button <?php echo $type_filter === $type_name ? 'button-primary' : ''; ?>"
				   style="margin-right: 4px;">
					<?php echo esc_html( ucfirst( $type_name ) ) . ' (' . (int) $count . ')'; ?>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( empty( $rows ) ) : ?>
		<div class="prautoblogger-empty-queue">
			<p><?php esc_html_e( 'No ideas found. Ideas will appear here after the next generation run.', 'prautoblogger' ); ?></p>
		</div>
	<?php else : ?>
		<table class="widefat striped prautoblogger-ideas-table">
			<thead>
				<tr>
					<th style="width:28%"><?php esc_html_e( 'Title / Topic', 'prautoblogger' ); ?></th>
					<th style="width:7%"><?php esc_html_e( 'Type', 'prautoblogger' ); ?></th>
					<th style="width:6%"><?php esc_html_e( 'Score', 'prautoblogger' ); ?></th>
					<th style="width:5%"><?php esc_html_e( 'Freq', 'prautoblogger' ); ?></th>
					<th style="width:30%"><?php esc_html_e( 'Key Points / Keywords', 'prautoblogger' ); ?></th>
					<th style="width:10%"><?php esc_html_e( 'Analyzed', 'prautoblogger' ); ?></th>
					<th style="width:14%"><?php esc_html_e( 'Generate', 'prautoblogger' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$meta       = json_decode( $row['metadata_json'] ?? '{}', true ) ?: array();
					$suggested  = $meta['suggested_title'] ?? '';
					$key_points = $meta['key_points'] ?? array();
					$keywords   = $meta['target_keywords'] ?? array();
					$score_pct  = round( (float) $row['relevance_score'] * 100 );
					$type_label = ucfirst( $row['analysis_type'] ?? 'unknown' );
					$type_class = 'prab-type-' . sanitize_html_class( $row['analysis_type'] );
					$idea_id    = (int) $row['id'];

					// Check if this idea is already mid-generation.
					$idea_status = get_transient( 'prab_idea_gen_' . $idea_id );
					$gen_state   = is_array( $idea_status ) ? ( $idea_status['status'] ?? 'idle' ) : 'idle';
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( '' !== $suggested ? $suggested : $row['topic'] ); ?></strong>
							<?php if ( '' !== $suggested && $suggested !== $row['topic'] ) : ?>
								<div style="color:#666; font-size:12px; margin-top:2px;">
									<?php echo esc_html( $row['topic'] ); ?>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $row['summary'] ) ) : ?>
								<div style="color:#555; font-size:12px; margin-top:4px; line-height:1.4;">
									<?php echo esc_html( $row['summary'] ); ?>
								</div>
							<?php endif; ?>
						</td>
						<td>
							<span class="<?php echo esc_attr( $type_class ); ?>" style="padding:2px 8px; border-radius:3px; font-size:12px; background:#f0f0f1;">
								<?php echo esc_html( $type_label ); ?>
							</span>
						</td>
						<td>
							<?php $bar_color = $score_pct >= 70 ? '#00a32a' : ( $score_pct >= 40 ? '#dba617' : '#d63638' ); ?>
							<div style="display:flex; align-items:center; gap:6px;">
								<div style="width:40px; height:6px; background:#ddd; border-radius:3px; overflow:hidden;">
									<div style="width:<?php echo (int) $score_pct; ?>%; height:100%; background:<?php echo esc_attr( $bar_color ); ?>;"></div>
								</div>
								<span style="font-size:12px;"><?php echo (int) $score_pct; ?>%</span>
							</div>
						</td>
						<td style="text-align:center;"><?php echo (int) $row['frequency']; ?></td>
						<td>
							<?php if ( ! empty( $key_points ) ) : ?>
								<ul style="margin:0; padding-left:16px; font-size:12px; line-height:1.5;">
									<?php foreach ( array_slice( $key_points, 0, 4 ) as $point ) : ?>
										<li><?php echo esc_html( $point ); ?></li>
									<?php endforeach; ?>
									<?php if ( count( $key_points ) > 4 ) : ?>
										<li style="color:#666;">+<?php echo count( $key_points ) - 4; ?> more</li>
									<?php endif; ?>
								</ul>
							<?php endif; ?>
							<?php if ( ! empty( $keywords ) ) : ?>
								<div style="margin-top:4px;">
									<?php foreach ( array_slice( $keywords, 0, 5 ) as $kw ) : ?>
										<span style="display:inline-block; padding:1px 6px; margin:1px 2px; background:#e7f5fe; border-radius:3px; font-size:11px; color:#0073aa;">
											<?php echo esc_html( $kw ); ?>
										</span>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</td>
						<td style="font-size:12px; color:#666;">
							<?php echo esc_html( wp_date( 'M j, g:i A', strtotime( $row['analyzed_at'] ) ) ); ?>
						</td>
						<td class="prab-idea-gen-cell"
							data-idea-id="<?php echo $idea_id; ?>"
							data-status="<?php echo esc_attr( $gen_state ); ?>">
							<?php if ( 'complete' === $gen_state && ! empty( $idea_status['post_id'] ) ) : ?>
								<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $idea_status['post_id'] . '&action=edit' ) ); ?>">Edit</a>
								| <a href="<?php echo esc_url( get_permalink( $idea_status['post_id'] ) ); ?>" target="_blank">View</a>
							<?php elseif ( 'running' === $gen_state ) : ?>
								<span class="dashicons dashicons-update ab-spin"></span>
								<span class="prab-idea-stage"><?php echo esc_html( $idea_status['stage'] ?? 'Generating…' ); ?></span>
							<?php else : ?>
								<button type="button"
										class="button button-small prab-gen-idea-btn"
										data-idea-id="<?php echo $idea_id; ?>">
									<?php esc_html_e( 'Generate', 'prautoblogger' ); ?>
								</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="prautoblogger-pagination" style="margin-top: 12px;">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $total_pages,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						)
					)
				);
				?>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
