/**
 * Pipeline Settings page — step rail and prompt editor interactions.
 *
 * Responsibilities:
 *   - Confirm before resetting a prompt to default (redundant safety; the
 *     PHP onclick already handles it, but JS enhances UX).
 *   - Wire the model-picker hidden input so the save_model form picks up
 *     the newly selected model id from the popup.
 *
 * @see admin/class-pipeline-settings-page.php — Enqueues this script.
 * @see assets/js/model-picker.js              — Handles the picker popup.
 */
/* global jQuery */
( function ( $ ) {
	'use strict';

	$( function () {

		// When the model picker JS selects a model, update the hidden
		// 'model_id' input in the same .pab-model-form so the save form
		// carries the chosen value on submit.
		$( document ).on( 'prautoblogger:model_selected', function ( e, data ) {
			var $form = $( '.pab-model-form' );
			var $hidden = $form.find( 'input[name="model_id"]' );
			if ( 0 === $hidden.length ) {
				$hidden = $( '<input type="hidden" name="model_id" />' );
				$form.append( $hidden );
			}
			$hidden.val( data.model_id || data.id || '' );
		} );

	} );

}( jQuery ) );
