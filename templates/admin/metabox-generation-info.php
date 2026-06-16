<?php
/**
 * Post metabox template showing PRAutoBlogger generation metadata.
 *
 * @see admin/class-post-metabox.php — Renders this template.
 *
 * @var WP_Post $post The current post.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$meta = array(
	'topic'          => get_post_meta( $post->ID, '_prautoblogger_topic', true ),
	'type'           => get_post_meta( $post->ID, '_prautoblogger_article_type', true ),
	'model'          => get_post_meta( $post->ID, '_prautoblogger_model_used', true ),
	'pipeline'       => get_post_meta( $post->ID, '_prautoblogger_pipeline_mode', true ),
	'verdict'        => get_post_meta( $post->ID, '_prautoblogger_editor_verdict', true ),
	'editor_notes'   => get_post_meta( $post->ID, '_prautoblogger_editor_notes', true ),
	'quality_score'  => get_post_meta( $post->ID, '_prautoblogger_quality_score', true ),
	'seo_score'      => get_post_meta( $post->ID, '_prautoblogger_seo_score', true ),
	'generated_at'   => get_post_meta( $post->ID, '_prautoblogger_generated_at', true ),
	'keywords'       => get_post_meta( $post->ID, '_prautoblogger_target_keywords', true ),
);
?>
<div class="prautoblogger-metabox">
	<p>
		<strong><?php esc_html_e( 'Generated:', 'prautoblogger' ); ?></strong>
		<?php echo esc_html( $meta['generated_at'] ? $meta['generated_at'] : __( 'Unknown', 'prautoblogger' ) ); ?>
	</p>
	<p>
		<strong><?php esc_html_e( 'Topic:', 'prautoblogger' ); ?></strong>
		<?php echo esc_html( $meta['topic'] ? $meta['topic'] : '—' ); ?>
	</p>
	<p>
		<strong><?php esc_html_e( 'Type:', 'prautoblogger' ); ?></strong>
		<?php echo esc_html( ucfirst( $meta['type'] ? $meta['type'] : 'article' ) ); ?>
	</p>
	<p>
		<strong><?php esc_html_e( 'Model:', 'prautoblogger' ); ?></strong>
		<?php echo esc_html( $meta['model'] ? $meta['model'] : '—' ); ?>
	</p>
	<p>
		<strong><?php esc_html_e( 'Pipeline:', 'prautoblogger' ); ?></strong>
		<?php echo esc_html( 'multi_step' === $meta['pipeline'] ? __( 'Multi-step', 'prautoblogger' ) : __( 'Single-pass', 'prautoblogger' ) ); ?>
	</p>
	<p>
		<strong><?php esc_html_e( 'Editor Verdict:', 'prautoblogger' ); ?></strong>
		<span class="prautoblogger-verdict-<?php echo esc_attr( $meta['verdict'] ? $meta['verdict'] : 'unknown' ); ?>">
			<?php echo esc_html( ucfirst( $meta['verdict'] ? $meta['verdict'] : __( 'Unknown', 'prautoblogger' ) ) ); ?>
		</span>
	</p>
	<?php if ( $meta['quality_score'] ) : ?>
		<p>
			<strong><?php esc_html_e( 'Quality:', 'prautoblogger' ); ?></strong>
			<?php echo esc_html( number_format( (float) $meta['quality_score'] * 100, 0 ) ); ?>%
			&nbsp;|&nbsp;
			<strong><?php esc_html_e( 'SEO:', 'prautoblogger' ); ?></strong>
			<?php echo esc_html( number_format( (float) $meta['seo_score'] * 100, 0 ) ); ?>%
		</p>
	<?php endif; ?>
	<?php if ( $meta['editor_notes'] ) : ?>
		<p>
			<strong><?php esc_html_e( 'Editor Notes:', 'prautoblogger' ); ?></strong><br>
			<?php echo esc_html( $meta['editor_notes'] ); ?>
		</p>
	<?php endif; ?>
	<?php if ( $meta['keywords'] ) : ?>
		<p>
			<strong><?php esc_html_e( 'Keywords:', 'prautoblogger' ); ?></strong>
			<?php echo esc_html( implode( ', ', ( json_decode( $meta['keywords'], true ) ? json_decode( $meta['keywords'], true ) : array() ) ) ); ?>
		</p>
	<?php endif; ?>
</div>
