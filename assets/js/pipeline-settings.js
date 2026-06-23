/**
 * Pipeline Settings page — M3: Template/Preview toggle, version history accordion,
 * and inline diff panel.
 *
 * M3 Responsibilities:
 *   1. Template / Preview toggle per prompt panel:
 *      - "Template" tab: shows the editable textarea (default).
 *      - "Preview assembled instructions" tab: fetches via AJAX and shows the
 *        fully-rendered prompt text. Read-only; no save path exists in JS or PHP.
 *        The two states are mutually exclusive; pressing "Template" never sends
 *        any network request.
 *   2. Version history accordion: toggle show/hide per prompt key.
 *   3. Inline diff panel: on "Diff" button click, fetch the computed diff from
 *        the server and render added/removed/context lines with colour coding.
 *        "Close diff" hides the panel.
 *
 * Security notes:
 *   - Preview and diff bodies are returned pre-escaped (esc_html'd) by PHP.
 *     They are inserted via .text() / textContent, never innerHTML, so no
 *     XSS vector exists even if the server escaping were to fail.
 *   - Nonces come from the prabPipeline JS object (localized in enqueue_assets).
 *
 * Note: model selection is handled entirely by model-picker.js; no bridge needed.
 *
 * @see admin/class-pipeline-settings-page.php       -- Localizes prabPipeline.
 * @see ajax/class-pipeline-preview-handler.php      -- Preview AJAX endpoint.
 * @see ajax/class-pipeline-history-handler.php      -- History/diff AJAX endpoint.
 * @see assets/js/model-picker.js                    -- Model picker (separate).
 */
/* global jQuery, prabPipeline */
( function ( $ ) {
	'use strict';

	/**
	 * Safely read the localized config, falling back to empty strings so
	 * the page never fatals when the object is missing (e.g. on other pages).
	 *
	 * @type {{ ajaxUrl: string, previewAction: string, previewNonce: string,
	 *          historyAction: string, historyNonce: string,
	 *          diffAction: string, diffNonce: string }}
	 */
	var cfg = ( typeof prabPipeline !== 'undefined' ) ? prabPipeline : {};

	// ── 1. Template / Preview toggle ──────────────────────────────────────────

	$( document ).on( 'click', '.pab-tp-btn', function () {
		var $btn    = $( this );
		var $toggle = $btn.closest( '.pab-tp-toggle' );
		var mode    = $btn.data( 'mode' );

		// Update button states.
		$toggle.find( '.pab-tp-btn' ).each( function () {
			$( this ).toggleClass( 'pab-tp-btn--active', $( this ).data( 'mode' ) === mode );
			$( this ).attr( 'aria-pressed', $( this ).data( 'mode' ) === mode ? 'true' : 'false' );
		} );

		var templateId = $toggle.data( 'template-id' );
		var previewId  = $toggle.data( 'preview-id' );
		var hintId     = $toggle.data( 'toggle-hint-id' );
		var $template  = $( '#' + templateId );
		var $preview   = $( '#' + previewId );
		var $hint      = $( '#' + hintId );

		if ( 'template' === mode ) {
			$template.show();
			$preview.hide();
			if ( $hint.length ) {
				$hint.text( $hint.data( 'template-hint' ) || prabTrans( 'Editing the template affects all future runs.' ) );
			}
			return;
		}

		// Preview mode: show panel immediately, then fetch.
		$template.hide();
		$preview.show();
		if ( $hint.length ) {
			$hint.text( prabTrans( 'Preview only — not editable.' ) );
		}

		var promptKey = $toggle.data( 'prompt-key' );
		var $body     = $preview.find( '.js-preview-body' );
		var $source   = $preview.find( '.js-preview-source' );

		// Only fetch once per page load; use data-loaded flag.
		if ( $preview.data( 'loaded' ) ) {
			return;
		}

		$body.html( '<span class="pab-preview-loading">&#8230;</span>' );

		$.post(
			( cfg.ajaxUrl || '' ),
			{
				action:     cfg.previewAction || '',
				nonce:      cfg.previewNonce  || '',
				prompt_key: promptKey
			},
			function ( response ) {
				if ( response && response.success ) {
					var d = response.data;
					// Insert pre-escaped text via textContent (double-safe).
					$body[ 0 ].textContent = d.preview || '';

					if ( d.from_run ) {
						var sourceNote = d.run_post
							? d.run_date + ' · ' + d.run_post
							: d.run_date;
						$source.text( prabTrans( 'Tokens from last run · ' ) + sourceNote );
					} else {
						$source.text( prabTrans( 'Sample render — no run found yet' ) );
					}
					$preview.data( 'loaded', true );
				} else {
					$body[ 0 ].textContent = prabTrans( 'Could not load preview.' );
				}
			},
			'json'
		).fail( function () {
			$body[ 0 ].textContent = prabTrans( 'Network error loading preview.' );
		} );
	} );

	// ── 2. Version history accordion ──────────────────────────────────────────

	$( document ).on( 'click', '.pab-history-trigger', function () {
		var $trigger = $( this );
		var bodyId   = $trigger.attr( 'aria-controls' );
		var $body    = $( '#' + bodyId );
		var expanded = $trigger.attr( 'aria-expanded' ) === 'true';

		$trigger.attr( 'aria-expanded', expanded ? 'false' : 'true' );
		$trigger.find( '.pab-history-arrow' )
			.toggleClass( 'dashicons-arrow-down-alt2', expanded )
			.toggleClass( 'dashicons-arrow-up-alt2', ! expanded );
		$body.toggle( ! expanded );
	} );

	// ── 3. Diff button ────────────────────────────────────────────────────────

	$( document ).on( 'click', '.pab-diff-btn', function () {
		var $btn      = $( this );
		var promptKey = $btn.data( 'prompt-key' );
		var versionA  = $btn.data( 'version-a' );
		var versionB  = $btn.data( 'version-b' );
		var diffId    = $btn.data( 'diff-target' );
		var $panel    = $( '#' + diffId );
		var $header   = $panel.find( '.js-diff-header' );
		var $lines    = $panel.find( '.js-diff-lines' );

		$panel.show();
		$header.text( prabTrans( 'Loading diff…' ) );
		$lines.empty();

		$.post(
			( cfg.ajaxUrl || '' ),
			{
				action:     cfg.diffAction || '',
				nonce:      cfg.diffNonce  || '',
				prompt_key: promptKey,
				version_a:  versionA,
				version_b:  versionB
			},
			function ( response ) {
				if ( response && response.success ) {
					var d = response.data;
					$header.text( d.header || '' );
					$lines.empty();
					if ( d.lines && d.lines.length ) {
						$.each( d.lines, function ( i, line ) {
							var $line = $( '<div>' ).addClass( 'pab-diff-line pab-diff-line--' + line.type );
							// line.text is pre-escaped server-side; use textContent.
							$line[ 0 ].textContent = line.text;
							$lines.append( $line );
						} );
					} else {
						$lines.text( prabTrans( 'No differences found.' ) );
					}
				} else {
					$header.text( prabTrans( 'Failed to load diff.' ) );
				}
			},
			'json'
		).fail( function () {
			$header.text( prabTrans( 'Network error loading diff.' ) );
		} );
	} );

	// ── 4. Close diff ─────────────────────────────────────────────────────────

	$( document ).on( 'click', '.pab-diff-close', function () {
		var diffId = $( this ).data( 'diff-id' );
		$( '#' + diffId ).hide();
	} );

	// ── Helper: minimal i18n pass-through (strings are in PHP-rendered HTML) ──

	/**
	 * Thin translation shim. Since strings originate in PHP (already translated
	 * + escaped), this is just a pass-through so JS keeps a single string origin.
	 *
	 * @param {string} s
	 * @returns {string}
	 */
	function prabTrans( s ) {
		return s;
	}

}( jQuery ) );
