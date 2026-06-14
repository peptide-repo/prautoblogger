/**
 * PRAutoBlogger Board -- "New Article" generate button handler (v0.21.0, M4).
 *
 * Loaded on the board page alongside board.js. Depends on the prabBoard config
 * object and jQuery, both already present when board.js loads.
 *
 * @see assets/js/board.js          -- Board poller (loads first).
 * @see includes/admin/class-board-page.php -- Enqueues + localizes prabBoard.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.prabBoard || {};

	/**
	 * Handle click on the "New Article" button. Fires the existing generate_now
	 * AJAX action and triggers board backoff reset so the new card appears fast.
	 */
	function onBoardGenerateNow() {
		var $btn = $( '#prab-board-generate-now' );
		if ( ! $btn.length || $btn.prop( 'disabled' ) ) {
			return;
		}
		$btn.prop( 'disabled', true ).text( cfg.strings && cfg.strings.generatingBtn || 'Generating\u2026' );
		var $status = $( '#prab-board-gen-status' );
		$status.text( '' ).hide();

		$.ajax( {
			url: cfg.ajaxUrl,
			method: 'POST',
			data: { action: 'prautoblogger_generate_now', nonce: cfg.generateNonce },
			success: function ( response ) {
				if ( response && response.success ) {
					$status.text( cfg.strings && cfg.strings.genStarted || 'Generation started.' ).show();
					// Notify board poller to reset backoff (run is now active).
					$( document ).trigger( 'prab:generation-started' );
				} else {
					$status.text( cfg.strings && cfg.strings.genError || 'Generation failed.' ).show();
					$btn.prop( 'disabled', false ).text( cfg.strings && cfg.strings.newArticle || 'New Article' );
				}
			},
			error: function () {
				$status.text( cfg.strings && cfg.strings.genError || 'Generation failed.' ).show();
				$btn.prop( 'disabled', false ).text( cfg.strings && cfg.strings.newArticle || 'New Article' );
			}
		} );
	}

	$( function () {
		$( document ).on( 'click', '#prab-board-generate-now', onBoardGenerateNow );
	} );

}( jQuery ) );
