/**
 * Pipeline Settings page — step rail and prompt editor interactions.
 *
 * Responsibilities:
 *   - Confirm before resetting a prompt to default (redundant safety; the
 *     PHP onclick already handles it, but JS enhances UX).
 *
 * Note: model selection is handled entirely by model-picker.js, which writes
 * the chosen model id directly to the hidden input named after the option
 * (e.g. name="prautoblogger_research_model"). No event bridge is needed here —
 * the form submits that value on the standard POST to handle_model_save().
 *
 * @see admin/class-pipeline-settings-save-handler.php — Reads $_POST[$option_name].
 * @see assets/js/model-picker.js              — Writes to the hidden input by DOM id.
 */
/* global jQuery */
( function ( $ ) {
	'use strict';

	$( function () {
		// Future UX hooks (e.g. unsaved-changes warning) go here.
	} );

}( jQuery ) );
