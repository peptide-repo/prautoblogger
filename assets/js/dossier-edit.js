/**
 * PRAutoBlogger Article Dossier — edit + re-run actions (M3, v0.20.0).
 *
 * Chained-cron UX contract (CPO hard constraint): clicking a re-run
 * button QUEUES a background job — the UI says "queued", starts the
 * stage-status poll, and renders pickup/result as the rows change.
 * Nothing here implies synchronous execution.
 *
 * Vanilla JS, no build step. Config injected via wp_localize_script
 * ('prabDossierEdit': ajaxUrl, nonce, postId, pollInterval, strings).
 *
 * @see admin/class-dossier-actions.php — The endpoints this calls.
 * @see admin/class-dossier-page.php    — Enqueue + localization.
 */
( function () {
	'use strict';

	var cfg = window.prabDossierEdit || null;

	function post( action, fields ) {
		var data = new window.FormData();
		data.append( 'action', action );
		data.append( 'nonce', cfg.nonce );
		data.append( 'post_id', cfg.postId );
		Object.keys( fields || {} ).forEach( function ( key ) {
			data.append( key, fields[ key ] );
		} );
		return window.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
			.then( function ( res ) { return res.json(); } );
	}

	function feedback( panel, message, isError ) {
		var el = panel.querySelector( '[data-feedback]' );
		if ( el ) {
			el.textContent = message || '';
			el.classList.toggle( 'prab-edit-feedback--error', !! isError );
		}
	}

	function sectionFor( el ) {
		var node = el;
		while ( node && ! node.classList.contains( 'prab-dossier-section--stage' ) ) {
			node = node.parentElement;
		}
		return node;
	}

	// ── Stage-status polling (queued → pickup → result) ────────────────
	var pollTimer = null;
	var sawActivity = false;

	function applyStageState( state ) {
		var selector = '.prab-dossier-section--stage[data-stage="' + state.stage + '"][data-agent-role="' + state.agent_role + '"]';
		var section  = document.querySelector( selector );
		if ( ! section ) {
			return;
		}
		var pill = section.querySelector( '.prab-stage-status-pill' );
		if ( pill ) {
			pill.className   = 'prab-stage-status-pill prab-stage-status-pill--' + state.status;
			pill.textContent = state.status.charAt( 0 ).toUpperCase() + state.status.slice( 1 );
		}
		var staleChip = section.querySelector( '[data-chip="stale"]' );
		if ( staleChip ) {
			staleChip.hidden = ! state.stale;
		}
		section.classList.toggle( 'prab-stage--stale', !! state.stale );
		var humanChip = section.querySelector( '[data-chip="human"]' );
		if ( humanChip ) {
			humanChip.hidden = ! state.human_modified;
		}
	}

	function poll() {
		post( 'prautoblogger_dossier_stage_status', {} ).then( function ( res ) {
			if ( ! res || ! res.success || ! res.data ) {
				return;
			}
			var anyRunning = false;
			var anyStale   = false;
			var anyHuman   = false;
			( res.data.stages || [] ).forEach( function ( state ) {
				applyStageState( state );
				if ( 'running' === state.status || 'pending' === state.status ) {
					anyRunning = true;
				}
				anyStale = anyStale || !! state.stale;
				anyHuman = anyHuman || !! state.human_modified;
			} );
			var headerHuman = document.querySelector( '[data-header-chip="human"]' );
			if ( headerHuman ) {
				headerHuman.hidden = ! anyHuman;
			}
			var headerStale = document.querySelector( '[data-header-chip="stale"]' );
			if ( headerStale ) {
				headerStale.hidden = ! anyStale;
			}

			var terminal = -1 === [ 'running', 'pending' ].indexOf( res.data.run_status );
			if ( anyRunning || ! terminal ) {
				sawActivity = true;
				return;
			}
			// Run settled after activity we watched: re-render the dossier
			// fresh (server-rendered outputs, receipts, fork info).
			if ( sawActivity ) {
				window.clearInterval( pollTimer );
				pollTimer = null;
				window.location.reload();
			}
		} ).catch( function () { /* transient poll errors are ignored */ } );
	}

	function startPolling() {
		sawActivity = true;
		if ( ! pollTimer ) {
			pollTimer = window.setInterval( poll, cfg.pollInterval * 1000 );
		}
	}

	// ── Wiring ──────────────────────────────────────────────────────────
	document.addEventListener( 'DOMContentLoaded', function () {
		if ( ! cfg ) {
			return;
		}

		// Edit panel toggles.
		document.querySelectorAll( '.prab-edit-toggle' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var panel = document.getElementById( btn.getAttribute( 'aria-controls' ) );
				if ( ! panel ) {
					return;
				}
				var open = btn.getAttribute( 'aria-expanded' ) === 'true';
				panel.hidden = open;
				btn.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
			} );
		} );

		// Save fork.
		document.querySelectorAll( '.prab-edit-save' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var panel    = btn.closest( '.prab-edit-panel' );
				var messages = [];
				panel.querySelectorAll( '.prab-edit-textarea' ).forEach( function ( area ) {
					messages.push( { role: area.getAttribute( 'data-role' ), content: area.value } );
				} );
				btn.disabled = true;
				feedback( panel, cfg.strings.saving, false );
				post( 'prautoblogger_dossier_save_input', {
					stage: panel.getAttribute( 'data-stage' ),
					agent_role: panel.getAttribute( 'data-agent-role' ),
					messages: JSON.stringify( messages )
				} ).then( function ( res ) {
					btn.disabled = false;
					if ( res && res.success ) {
						feedback( panel, res.data.message, false );
						var rerunBtn = panel.querySelector( '.prab-edit-rerun' );
						if ( rerunBtn ) {
							rerunBtn.disabled = false;
						}
						var info = panel.querySelector( '[data-forkinfo]' );
						if ( info && res.data.version ) {
							info.textContent = 'v' + res.data.version;
						}
					} else {
						feedback( panel, ( res && res.data && res.data.message ) || cfg.strings.error, true );
					}
				} ).catch( function () {
					btn.disabled = false;
					feedback( panel, cfg.strings.error, true );
				} );
			} );
		} );

		// Replay latest fork (queued).
		document.querySelectorAll( '.prab-edit-rerun' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var panel = btn.closest( '.prab-edit-panel' );
				if ( ! window.confirm( cfg.strings.confirmReplay ) ) {
					return;
				}
				btn.disabled = true;
				post( 'prautoblogger_dossier_rerun_stage', {
					stage: panel.getAttribute( 'data-stage' ),
					agent_role: panel.getAttribute( 'data-agent-role' )
				} ).then( function ( res ) {
					if ( res && res.success ) {
						feedback( panel, res.data.message, false );
						startPolling();
					} else {
						btn.disabled = false;
						feedback( panel, ( res && res.data && res.data.message ) || cfg.strings.error, true );
					}
				} ).catch( function () {
					btn.disabled = false;
					feedback( panel, cfg.strings.error, true );
				} );
			} );
		} );

		// Re-run from here (queued rebuild).
		document.querySelectorAll( '.prab-rerun-from' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var section = sectionFor( btn );
				if ( ! window.confirm( cfg.strings.confirmRerunFrom ) ) {
					return;
				}
				btn.disabled = true;
				btn.textContent = cfg.strings.queued;
				post( 'prautoblogger_dossier_rerun_from', {
					stage: btn.getAttribute( 'data-stage' ),
					agent_role: section ? section.getAttribute( 'data-agent-role' ) : ''
				} ).then( function ( res ) {
					if ( res && res.success ) {
						startPolling();
					} else {
						btn.disabled = false;
						btn.textContent = cfg.strings.rerunFromLabel;
						window.alert( ( res && res.data && res.data.message ) || cfg.strings.error );
					}
				} ).catch( function () {
					btn.disabled = false;
					btn.textContent = cfg.strings.rerunFromLabel;
					window.alert( cfg.strings.error );
				} );
			} );
		} );

		// A stage already running (e.g. revisit mid-job): poll from load.
		if ( document.querySelector( '.prab-stage-status-pill--running' ) ) {
			startPolling();
		}
	} );
}() );
