<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Renders the interactive model-picker field for OpenRouter models.
 *
 * What: a button showing the current model's name + pricing. Clicking it
 *       opens a JS-powered popup (model-picker.js) with a searchable list.
 *       Includes an estimated cost preview below based on historical usage.
 *       For image-generation steps the static cost-per-image price is shown
 *       in the trigger button; the per-generation cost-preview panel is
 *       intentionally omitted (image cost is a fixed $/image, not token-
 *       based, so the historical-token preview formula does not apply).
 * Who calls it: PRAutoBlogger_Admin_Page::render_field() delegates here
 *               for fields with type 'model_select', and
 *               PRAutoBlogger_Pipeline_Settings_Renderer::render_model_picker()
 *               for the Pipeline Settings page.
 * Dependencies: PRAutoBlogger_Model_Registry_Interface (cached registry lookup),
 *               PRAutoBlogger_Cost_Tracker (historical token averages).
 *
 * @see admin/class-admin-page.php          — Calls render() from the main field switch.
 * @see admin/class-pipeline-settings-renderer.php — Calls render_model_picker().
 * @see assets/js/model-picker.js           — JS popup that fetches + displays models.
 * @see services/interface-model-registry.php — Registry interface for find_model().
 * @see core/class-cost-tracker.php         — get_avg_tokens_for_stages() for cost preview.
 */
class PRAutoBlogger_OpenRouter_Model_Field {

	/**
	 * Render the model picker trigger button and hidden input.
	 *
	 * @param string               $id    Field HTML id/name attribute.
	 * @param string               $value Current model id (e.g. 'google/gemini-2.5-flash-lite').
	 * @param array<string, mixed> $args  Field definition from settings fields.
	 */
	public static function render( string $id, string $value, array $args ): void {
		$capability    = $args['capability'] ?? 'text→text';
		$display_name  = '' !== $value ? $value : __( '— Select a model —', 'prautoblogger' );
		$display_price = '';
		if ( 'image_generation' === $capability ) {
			// Resolve from the static image model list defined in settings.
			$image_models = PRAutoBlogger_Settings_Fields_Extended::get_image_models();
			foreach ( $image_models as $im ) {
				if ( ( $im['id'] ?? '' ) === $value ) {
					$display_name  = $im['name'] ?? $value;
					$display_price = '$' . number_format( (float) ( $im['cost_per_image'] ?? 0 ), 4 ) . '/image';
					break;
				}
			}
		} else {
			// Resolve from the OpenRouter model registry cache.
			$registry = prautoblogger()->get_executor()->get_model_registry();
			$model    = $registry->find_model( $value );
			if ( null !== $model ) {
				$display_name  = $model['name'] ?? $value;
				$in            = (float) ( $model['input_price_per_m'] ?? 0 );
				$out           = (float) ( $model['output_price_per_m'] ?? 0 );
				$display_price = self::format_price( $in ) . ' / ' . self::format_price( $out );
			}
		}

		// Hidden input carries the actual value for the form submission.
		// The name attribute equals $id so that PRAutoBlogger_Pipeline_Settings_Save_Handler
		// can read $_POST[$option_name] directly — model-picker.js writes the selected
		// model id to this input by DOM id, and the browser posts it under this name.
		printf(
			'<input type="hidden" id="%s" name="%s" value="%s" />',
			esc_attr( $id ),
			esc_attr( $id ),
			esc_attr( $value )
		);

		// Trigger button — opens the model picker popup via JS.
		printf(
			'<button type="button" class="ab-mp-trigger" data-field-id="%s" data-capability="%s">'
			. '<span class="ab-mp-display-name">%s</span>'
			. '<span class="ab-mp-display-price">%s</span>'
			. '<span class="ab-mp-display-arrow">&#9662;</span>'
			. '</button>',
			esc_attr( $id ),
			esc_attr( $capability ),
			esc_html( $display_name ),
			esc_html( $display_price )
		);

		// Per-generation cost-preview panel — text→text models only.
		// Image steps show a static $/image price in the trigger button above;
		// the token-based historical cost formula does not apply to image generation.
		if ( 'image_generation' !== $capability && '' !== $value ) {
			$cost_preview = self::get_cost_preview( $id, $value );
			if ( $cost_preview ) {
				printf(
					'<div class="ab-mp-cost-preview">%s</div>',
					wp_kses_post( $cost_preview )
				);
			}
		}
	}

	/**
	 * Calculate and render the estimated cost preview for a model + setting combo.
	 *
	 * @param string $field_id Setting ID (e.g. 'prautoblogger_writing_model').
	 * @param string $model_id Model ID (e.g. 'anthropic/claude-3.5-haiku').
	 *
	 * @return string HTML snippet for the cost preview, or empty if no history.
	 */
	private static function get_cost_preview( string $field_id, string $model_id ): string {
		$stages = self::get_stages_for_setting( $field_id );
		if ( empty( $stages ) ) {
			return '';
		}

		$registry = prautoblogger()->get_executor()->get_model_registry();
		$model    = $registry->find_model( $model_id );
		if ( null === $model ) {
			return '';
		}

		$tracker = new PRAutoBlogger_Cost_Tracker();
		$tokens  = $tracker->get_avg_tokens_for_stages( $stages, 30 );

		if ( 0 === $tokens['sample_size'] ) {
			return '<small>' . esc_html__( 'Estimated cost: — (insufficient history)', 'prautoblogger' ) . '</small>';
		}

		$in_price   = (float) ( $model['input_price_per_m'] ?? 0 );
		$out_price  = (float) ( $model['output_price_per_m'] ?? 0 );
		$avg_tokens_in  = $tokens['avg_prompt_tokens'];
		$avg_tokens_out = $tokens['avg_completion_tokens'];

		// Cost per generation = (avg_in_tokens × in_price + avg_out_tokens × out_price) / 1_000_000
		$cost_per_gen = ( ( $avg_tokens_in * $in_price ) + ( $avg_tokens_out * $out_price ) ) / 1_000_000;

		return sprintf(
			'<small>%s %s</small>',
			esc_html__( 'Estimated cost per generation:', 'prautoblogger' ),
			esc_html( '$' . number_format( $cost_per_gen, 4 ) )
		);
	}

	/**
	 * Map a text→text setting ID to its constituent pipeline stages for the
	 * cost-preview calculation. Image-generation settings are not listed here
	 * because the cost preview is intentionally skipped for image steps
	 * (static $/image price is shown in the picker trigger instead).
	 *
	 * @param string $field_id Setting ID.
	 *
	 * @return string[] Stage names, e.g. ['outline', 'draft', 'polish'] for writing model.
	 */
	private static function get_stages_for_setting( string $field_id ): array {
		$map = array(
			'prautoblogger_analysis_model' => array( 'analysis' ),
			'prautoblogger_writing_model'  => array( 'outline', 'draft', 'polish' ),
			'prautoblogger_editor_model'   => array( 'review' ),
			'prautoblogger_research_model' => array( 'research', 'llm_research' ),
			// prautoblogger_image_model is intentionally absent: image cost is a
			// fixed $/image (shown in the trigger button), not a token-based preview.
		);
		return $map[ $field_id ] ?? array();
	}

	/**
	 * Format a price-per-million-tokens value for display.
	 *
	 * @param float $price Price per 1M tokens.
	 * @return string Formatted price string like "$0.25" or "Free".
	 */
	private static function format_price( float $price ): string {
		if ( $price <= 0 ) {
			return __( 'Free', 'prautoblogger' );
		}
		if ( $price < 0.01 ) {
			return '$' . number_format( $price, 4 );
		}
		return '$' . number_format( $price, 2 );
	}
}
