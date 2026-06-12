<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Adds a metabox to PRAutoBlogger-generated posts.
 *
 * M2: Slimmed to a "View generation dossier" link. Full generation metadata
 * lives on the Article Dossier page (PRAutoBlogger_Dossier_Page).
 *
 * Triggered by: PRAutoBlogger::register_admin_hooks() on `add_meta_boxes`.
 * Dependencies: PRAutoBlogger_Dossier_Page (for URL helper).
 *
 * @see class-prautoblogger.php          -- Registers the hook.
 * @see admin/class-dossier-page.php     -- Dossier target; provides url_for_post().
 * @see core/class-publisher.php         -- Writes the run_id meta this class reads.
 */
class PRAutoBlogger_Post_Metabox {

	/**
	 * Register the metabox for PRAutoBlogger-generated posts only.
	 *
	 * @return void
	 */
	public function on_register_metabox(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->id ) {
			return;
		}

		global $post;
		if ( ! $post || '1' !== get_post_meta( $post->ID, '_prautoblogger_generated', true ) ) {
			return;
		}

		add_meta_box(
			'prautoblogger_generation_info',
			__( 'PRAutoBlogger', 'prautoblogger' ),
			array( $this, 'render_metabox' ),
			'post',
			'side',
			'default'
		);
	}

	/**
	 * Render the dossier-link metabox.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render_metabox( \WP_Post $post ): void {
		include PRAUTOBLOGGER_PLUGIN_DIR . 'templates/admin/metabox-dossier-link.php';
	}
}
