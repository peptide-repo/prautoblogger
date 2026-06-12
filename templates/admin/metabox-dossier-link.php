<?php
/**
 * Post metabox template (slimmed, M2): "View generation dossier" link.
 *
 * The full generation record is available on the Article Dossier page.
 * This metabox replaces the previous inline metadata display to avoid
 * duplicating data that lives in the dossier.
 *
 * @see admin/class-post-metabox.php          -- Renders this template.
 * @see admin/class-dossier-page.php           -- Dossier target page.
 *
 * @var WP_Post $post The current post.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$run_id = (string) get_post_meta( $post->ID, '_prautoblogger_run_id', true );
$url    = PRAutoBlogger_Dossier_Page::url_for_post( $post->ID );
?>
<div class="prab-metabox-dossier">
	<?php if ( '' !== $run_id ) : ?>
		<p>
			<a href="<?php echo esc_url( $url ); ?>" class="button button-secondary">
				<?php esc_html_e( 'View generation dossier', 'prautoblogger' ); ?> &rarr;
			</a>
		</p>
		<p class="description prab-run-id-label">
			<?php
			printf(
				/* translators: %s: run UUID */
				esc_html__( 'Run: %s', 'prautoblogger' ),
				esc_html( $run_id )
			);
			?>
		</p>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'No generation record — article was not created by PRAutoBlogger.', 'prautoblogger' ); ?></p>
	<?php endif; ?>
</div>
