/**
 * PRAutoBlogger Article Dossier -- raw-trace toggle (M2).
 *
 * Toggles the hidden raw-trace <div> per stage when the "Show raw trace"
 * button is clicked. Vanilla JS, no build step, no external deps.
 *
 * Accessible: uses aria-expanded + aria-controls; respects the hidden attribute.
 *
 * @see admin/class-dossier-page.php -- Enqueues this file.
 * @see templates/admin/dossier-page.php -- Renders the toggle buttons.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var toggles = document.querySelectorAll( '.prab-trace-toggle' );

		toggles.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var targetId = btn.getAttribute( 'aria-controls' );
				if ( ! targetId ) {
					return;
				}
				var panel = document.getElementById( targetId );
				if ( ! panel ) {
					return;
				}
				var isOpen = btn.getAttribute( 'aria-expanded' ) === 'true';
				if ( isOpen ) {
					panel.hidden = true;
					btn.setAttribute( 'aria-expanded', 'false' );
					btn.textContent = btn.textContent.replace( /hide/i, 'Show' );
				} else {
					panel.hidden = false;
					btn.setAttribute( 'aria-expanded', 'true' );
					btn.textContent = btn.textContent.replace( /show/i, 'Hide' );
				}
			} );
		} );
	} );
}() );
