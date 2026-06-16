<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Image settings fields for PRAutoBlogger.
 *
 * Declarative field definitions for the Images section of the settings page.
 * Extracted from PRAutoBlogger_Settings_Fields_Extended for 300-line compliance.
 *
 * What: Returns the images-section field array consumed by the settings registry.
 * Who triggers: PRAutoBlogger_Settings_Fields_Extended::get_fields() via merge.
 * Dependencies: PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL, PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_TEMPLATE,
 *               PRAutoBlogger_Image_Prompt_Builder, PRAutoBlogger_Image_Model_Registry.
 *
 * @see admin/class-settings-fields-extended.php -- Merges these fields in get_fields().
 * @see admin/class-admin-page.php               -- Renders the fields.
 * @see CONVENTIONS.md                           -- "How To: Add a New Admin Setting".
 */
class PRAutoBlogger_Settings_Fields_Images {

	/**
	 * Get image generation settings fields.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_fields(): array {
		return array(

			// ── Images ─────────────────────────────────────────────────
			array(
				'id'          => 'prautoblogger_image_enabled',
				'label'       => __( 'Enable Image Generation', 'prautoblogger' ),
				'type'        => 'toggle',
				'section'     => 'prautoblogger_images',
				'default'     => '0',
				'description' => __( 'Generate images for each published article.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_image_b_enabled',
				'label'       => __( 'Generate Second Image (B)', 'prautoblogger' ),
				'type'        => 'toggle',
				'section'     => 'prautoblogger_images',
				'default'     => '1',
				'description' => __( 'Generate a second image from source data for A/B testing. Disabling saves one image generation + one LLM prompt rewrite per article.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_image_model',
				'label'       => __( 'Image Model', 'prautoblogger' ),
				'type'        => 'model_select',
				'section'     => 'prautoblogger_images',
				'default'     => PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL,
				'capability'  => 'image_generation',
				'description' => __( 'Pick an image model. The provider (Runware or OpenRouter) is derived from the model registry on save, so mismatched pairs are no longer possible.', 'prautoblogger' ),
				'badge'       => __( 'Quality', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_runware_api_key',
				'label'       => __( 'Runware API Key', 'prautoblogger' ),
				'type'        => 'password',
				'section'     => 'prautoblogger_images',
				'description' => __( 'Required when using a Runware FLUX model. Get your key at runware.ai/signup.', 'prautoblogger' ),
				'icon'        => '🔑',
			),
			array(
				'id'          => 'prautoblogger_image_style_template',
				'label'       => __( 'Style Template', 'prautoblogger' ),
				'type'        => 'textarea',
				'section'     => 'prautoblogger_images',
				'default'     => PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_TEMPLATE,
				'description' => __( 'The full image prompt template. Must contain exactly one {{ topic_summary }} token, which is filled with a 1-2 sentence topic/mechanism summary per article. The rest is brand-locked editorial style. Replaces the old Style Suffix (now deprecated). Changing mid-run causes visible style drift.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_image_prompt_instructions',
				'label'       => __( 'Image Prompt Instructions', 'prautoblogger' ),
				'type'        => 'textarea',
				'section'     => 'prautoblogger_images',
				'default'     => PRAutoBlogger_Image_Prompt_Builder::REWRITER_SYSTEM_PROMPT,
				'description' => __( 'System prompt given to the rewriter LLM that turns each article into a SCENE (a 1-2 sentence editorial topic/mechanism summary, substituted into the Style Template) plus a CAPTION (HTML text shown below the image). Changing this reshapes the look of all future images. Leave blank to use the default.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_image_nsfw_retry',
				'label'       => __( 'Retry NSFW-Blocked Images', 'prautoblogger' ),
				'type'        => 'toggle',
				'section'     => 'prautoblogger_images',
				'default'     => '1',
				'description' => __( 'When the provider rejects an image prompt as NSFW, retry once with a generic fallback scene built from the article title. Disable to fail fast if the filter gets trigger-happy.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_image_compose_enabled',
				'label'       => __( 'Compose Branded Variants', 'prautoblogger' ),
				'type'        => 'toggle',
				'section'     => 'prautoblogger_images',
				'default'     => '1',
				'description' => __( 'Render branded social variants (teal band, logo, baked caption) from each generated image via the local deterministic composer. Auto-degrades to plain resizing or pass-through if the server lacks an image library — publishing is never blocked.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_image_compose_variants',
				'label'       => __( 'Composed Variant Set', 'prautoblogger' ),
				'type'        => 'text',
				'section'     => 'prautoblogger_images',
				'default'     => 'og,square',
				'description' => __( 'Comma-separated variants to render for Image A. Supported: og (1200×630 social share), square (1080×1080 card). Unknown values are ignored.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_image_featured_mark_enabled',
				'label'       => __( 'Featured Image Corner Mark', 'prautoblogger' ),
				'type'        => 'toggle',
				'section'     => 'prautoblogger_images',
				'default'     => '1',
				'description' => __( 'Overlay a small semi-transparent Peptide Repo mark in the bottom-right corner of featured images, so scraped or re-shared images carry attribution.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_runware_model_catalog',
				'label'       => __( 'Runware Model Catalog', 'prautoblogger' ),
				'type'        => 'runware_catalog_sync',
				'section'     => 'prautoblogger_images',
				'description' => __( 'Live model catalog sync status and on-demand refresh.', 'prautoblogger' ),
			),
		);
	}
}
