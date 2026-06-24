<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Authority pipeline field-array data for PRAutoBlogger_Pipeline_Settings_Option_Fields.
 *
 * What: Declarative field arrays for the three P2b.5 Authority-pipeline contexts:
 *       curate, seo, and authority. Extracted from Option_Fields_Data to keep
 *       that class under the 300-line rule. Contains no logic — only data.
 * Who calls it: PRAutoBlogger_Pipeline_Settings_Option_Fields_Data::fields_for() (curate/seo/authority).
 * Dependencies: WP i18n helpers (__) only.
 *
 * @see admin/class-pipeline-settings-option-fields-data.php — Core context router.
 * @see CONVENTIONS.md §Authority pipeline options            — naming + parse pattern.
 */
class PRAutoBlogger_Pipeline_Settings_Option_Fields_Data_Authority {

	/**
	 * Route authority-tier context identifiers to their field definitions.
	 *
	 * @param string $context One of: curate|seo|authority.
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields_for( string $context ): array {
		switch ( $context ) {
			case 'curate':
				return self::curate_fields();
			case 'seo':
				return self::seo_fields();
			case 'authority':
				return self::authority_fields();
			default:
				return array();
		}
	}

	/**
	 * Curate step context fields (P2b.5).
	 * The model picker and prompt editors in the step panel handle the main
	 * configuration. This textarea exposes optional override instructions
	 * that are appended to the curate stage system prompt.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function curate_fields(): array {
		return array(
			array(
				'id'          => 'prautoblogger_curate_instructions',
				'label'       => __( 'Curate Instructions', 'prautoblogger' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'Optional custom instructions appended to the research judge (curate) system prompt. Authority tier only.', 'prautoblogger' ),
			),
		);
	}

	/**
	 * SEO step context fields (P2b.5).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function seo_fields(): array {
		return array(
			array(
				'id'          => 'prautoblogger_seo_instructions',
				'label'       => __( 'SEO Instructions', 'prautoblogger' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'Custom instructions for the SEO stage LLM call.', 'prautoblogger' ),
			),
		);
	}

	/**
	 * Authority Settings context fields (P2b.5).
	 * Controls the master switch, citation gate, and per-category tier map.
	 * The tier-map textarea is a special read/write surface: the renderer
	 * converts prautoblogger_category_tiers (serialised array) back to
	 * 'slug: tier' lines; the save handler calls parse_and_save_category_tiers()
	 * to convert the textarea back to the array — see Save_Handler::handle_step_settings_save().
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function authority_fields(): array {
		return array(
			array(
				'id'          => 'prautoblogger_authority_pipeline_enabled',
				'label'       => __( 'Authority Pipeline', 'prautoblogger' ),
				'type'        => 'toggle',
				'default'     => '0',
				'description' => __( 'When ON, categories mapped to Authority tier use the full 6-stage pipeline (research to curate to draft to editorial to SEO to publish). Leave OFF until you have reviewed the configuration.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_citation_score_threshold',
				'label'       => __( 'Citation Score Gate', 'prautoblogger' ),
				'type'        => 'number',
				'default'     => 0,
				'min'         => 0,
				'max'         => 100,
				'description' => __( 'Minimum source quality score (0-100) for auto-publish. Set 0 to skip the gate while calibrating. Calibrate after ~10 Authority runs.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_category_tiers_input',
				'label'       => __( 'Category Tier Map', 'prautoblogger' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'One line per category slug. Format: slug: authority or slug: economy. New, YMYL, and unclassified categories default to Authority.', 'prautoblogger' ),
			),
		);
	}
}
