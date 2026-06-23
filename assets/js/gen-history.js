/**
 * Generation History page — Stage I/O drill-down (M4).
 *
 * Handles the "Stage I/O" toggle button on each run row:
 *   1. On first click: POST to prautoblogger_gen_run_io, receive stage
 *      data, render the per-stage accordion (input_system, input_user,
 *      output).
 *   2. On subsequent clicks: toggle the row's visibility (no re-fetch).
 *
 * SECURITY: Renders all text via textContent — NEVER innerHTML for
 * prompt/response content. The nonce is passed via wp_localize_script
 * as prabGenHistory.nonce (not a data-* attribute).
 *
 * @see ajax/class-gen-run-io-handler.php -- AJAX endpoint.
 * @see admin/class-gen-history-page.php  -- Localises prabGenHistory config.
 */
( function () {
	'use strict';

	/** @type {{ ajaxUrl: string, action: string, nonce: string, strings: Object }} */
	var cfg = window.prabGenHistory || {};

	/**
	 * Render a single stage's I/O block.
	 *
	 * @param {Object} stage Stage data from AJAX response.
	 * @returns {HTMLElement}
	 */
	function renderStage( stage ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'prab-io-stage';

		// Header.
		var header = document.createElement( 'div' );
		header.className = 'prab-io-stage__header';

		var label = document.createElement( 'span' );
		label.className = 'prab-io-stage__label';
		label.textContent = stage.label || stage.stage;
		header.appendChild( label );

		var meta = document.createElement( 'span' );
		meta.className = 'prab-io-stage__meta';
		var costStr = stage.estimated_cost ? '$' + stage.estimated_cost.toFixed( 6 ) : '';
		var tokStr = stage.prompt_tokens
			? stage.prompt_tokens + ' in / ' + stage.completion_tokens + ' out tokens'
			: '';
		var metaParts = [ stage.model, tokStr, costStr ].filter( Boolean );
		meta.textContent = metaParts.join( ' · ' );
		header.appendChild( meta );

		var statusChip = document.createElement( 'span' );
		statusChip.className = 'prab-chip prab-chip--' + ( stage.response_status || '' );
		statusChip.textContent = stage.response_status || '';
		header.appendChild( statusChip );

		wrap.appendChild( header );

		// Body: system input, user input, output.
		var body = document.createElement( 'div' );
		body.className = 'prab-io-stage__body';

		body.appendChild( renderSection(
			/* translators: label for system prompt section */
			cfg.strings && cfg.strings.systemLabel || 'System Prompt (Input)',
			stage.input_system,
			! stage.input_system ? ( cfg.strings.noInput || '[No input stored]' ) : null
		) );

		body.appendChild( renderSection(
			/* translators: label for user instruction section */
			cfg.strings && cfg.strings.userLabel || 'Assembled Instruction (Input)',
			stage.input_user,
			! stage.input_user ? ( cfg.strings.noInput || '[No input stored]' ) : null
		) );

		var outputMissing = null;
		if ( stage.output_pruned ) {
			outputMissing = cfg.strings.pruned || '[Output pruned by retention policy]';
		} else if ( stage.output === null ) {
			outputMissing = cfg.strings.noOutput || '[No output stored for this stage]';
		}
		body.appendChild( renderSection(
			cfg.strings && cfg.strings.outputLabel || 'Model Response (Output)',
			stage.output,
			outputMissing
		) );

		if ( stage.error_message ) {
			body.appendChild( renderSection( 'Error', stage.error_message, null ) );
		}

		wrap.appendChild( body );
		return wrap;
	}

	/**
	 * Render a labelled text section.
	 *
	 * @param {string}      title       Section heading.
	 * @param {string|null} text        Content text (null when missing).
	 * @param {string|null} missingMsg  Message to show when text is null.
	 * @returns {HTMLElement}
	 */
	function renderSection( title, text, missingMsg ) {
		var section = document.createElement( 'div' );
		section.className = 'prab-io-section';

		var heading = document.createElement( 'div' );
		heading.className = 'prab-io-section__title';
		heading.textContent = title;
		section.appendChild( heading );

		var content = document.createElement( 'pre' );
		if ( text ) {
			content.className = 'prab-io-section__text';
			// textContent — never innerHTML — for security.
			content.textContent = text;
		} else {
			content.className = 'prab-io-section__text prab-io-section__text--missing';
			content.textContent = missingMsg || '';
		}
		section.appendChild( content );
		return section;
	}

	/**
	 * Fetch and render stage I/O for a run.
	 *
	 * @param {string}      runId  Pipeline run UUID.
	 * @param {HTMLElement} panel  The panel container element to populate.
	 */
	function fetchRunIO( runId, panel ) {
		var fd = new FormData();
		fd.append( 'action', cfg.action || 'prautoblogger_gen_run_io' );
		fd.append( 'nonce', cfg.nonce || '' );
		fd.append( 'run_id', runId );

		fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( resp ) {
				panel.innerHTML = '';
				if ( ! resp.success ) {
					var err = document.createElement( 'p' );
					err.textContent = ( resp.data && resp.data.message ) || cfg.strings.error;
					panel.appendChild( err );
					return;
				}
				var stages = resp.data.stages || [];
				if ( 0 === stages.length ) {
					var none = document.createElement( 'p' );
					none.textContent = cfg.strings.noStages || 'No stage entries found.';
					panel.appendChild( none );
					return;
				}
				stages.forEach( function ( s ) {
					panel.appendChild( renderStage( s ) );
				} );
				// If dossier URL available, append a link.
				var run = resp.data.run || {};
				if ( run.dossier_url ) {
					var link = document.createElement( 'p' );
					link.style.marginTop = '12px';
					var a = document.createElement( 'a' );
					a.href = run.dossier_url;
					a.textContent = 'Open full Article Dossier →';
					link.appendChild( a );
					panel.appendChild( link );
				}
			} )
			.catch( function () {
				panel.innerHTML = '';
				var err = document.createElement( 'p' );
				err.textContent = cfg.strings.error || 'Request failed.';
				panel.appendChild( err );
			} );
	}

	/** Wire "Stage I/O" toggle buttons on DOMContentLoaded. */
	document.addEventListener( 'DOMContentLoaded', function () {
		var buttons = document.querySelectorAll( '.prab-gen-history__io-toggle' );
		buttons.forEach( function ( btn ) {
			var runId = btn.dataset.runId;
			var ioRow = document.getElementById( 'prab-io-' + runId );
			if ( ! ioRow ) {
				return;
			}
			var panel = ioRow.querySelector( '.prab-gen-history__io-panel' );
			var loaded = false;

			btn.addEventListener( 'click', function () {
				if ( ioRow.hidden ) {
					ioRow.hidden = false;
					if ( ! loaded ) {
						loaded = true;
						fetchRunIO( runId, panel );
					}
				} else {
					ioRow.hidden = true;
				}
			} );
		} );
	} );
} )();
