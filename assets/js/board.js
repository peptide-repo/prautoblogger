/**
 * PRAutoBlogger — Mission Brief Board (M5, v0.27.0)
 *
 * Replaces the four-column kanban with a vertical run-list + right-rail
 * inspector layout. Responsibilities:
 *
 *   1. AJAX polling (prautoblogger_board_status) — same backoff logic as M4.
 *      Updates the four status sections in-place without flicker.
 *   2. Row-click inspector — fetches per-run stage I/O via
 *      prautoblogger_board_inspector and renders the right rail.
 *   3. Stage I/O expand — toggles per-stage prompt/response text inline
 *      inside the inspector.
 *
 * Security: All server data rendered via textContent (never innerHTML for
 *   prompt/response text). escHtml() guards any dynamic HTML concatenation.
 *
 * Localised config: `prabBoard` object (ajaxUrl, nonce, pollInterval,
 *   action, inspectorAction, strings) injected by Board_Page::on_enqueue_assets().
 *
 * @see includes/admin/class-board-page.php              -- AJAX handler + localize.
 * @see includes/ajax/class-board-inspector-handler.php  -- Inspector AJAX.
 * @see assets/css/board.css                             -- Styles.
 * @see templates/admin/board-page.php                   -- Server-rendered HTML.
 */
( function ( $ ) {
	'use strict';

	var cfg           = window.prabBoard || {};
	var baseInterval  = cfg.pollInterval  || 5000;
	var maxBackoff    = 4;
	var backoffFactor = 1;
	var pollTimer     = null;
	var $board        = null;
	var $errorBanner  = null;
	var selectedRunId = null;
	var inspFetching  = false;

	/** Escape HTML for safe DOM insertion via innerHTML. */
	function escHtml( str ) {
		return $( '<div>' ).text( String( str ) ).html();
	}

	/** Format cost to 4 decimal places with a leading $. */
	function formatCost( cost ) {
		var n = parseFloat( cost ) || 0;
		return '$' + n.toFixed( 4 );
	}

	/** Elapsed seconds → human label. */
	function formatElapsed( startedAt ) {
		if ( ! startedAt ) { return ''; }
		var diff = Math.max( 0, Math.floor( Date.now() / 1000 ) - parseInt( startedAt, 10 ) );
		if ( diff < 60 ) { return diff + 's'; }
		if ( diff < 3600 ) { return Math.floor( diff / 60 ) + 'm'; }
		return Math.floor( diff / 3600 ) + 'h';
	}

	/**
	 * Map a section key to chip CSS modifier + label.
	 */
	function sectionMeta( key ) {
		var map = {
			generating: { mod: 'generating', label: cfg.strings.generating || 'Generating' },
			in_review:  { mod: 'in_review',  label: cfg.strings.inReview   || 'In review' },
			published:  { mod: 'published',   label: cfg.strings.published  || 'Published' },
			failed:     { mod: 'failed',      label: cfg.strings.failed     || 'Failed'   },
		};
		return map[ key ] || { mod: key, label: key };
	}

	/**
	 * Build one run-row HTML string from card data.
	 *
	 * @param {Object} card       Card data from server.
	 * @param {string} sectionKey Section identifier.
	 * @returns {string} HTML string.
	 */
	function buildRowHtml( card, sectionKey ) {
		var meta      = sectionMeta( sectionKey );
		var isFailed  = ( 'failed' === sectionKey );
		var rowClass  = 'prab-run-row' + ( isFailed ? ' prab-run-row--failed' : '' );
		var title     = card.title || cfg.strings.empty || '';
		var cost      = card.cost_total ? formatCost( card.cost_total ) : '';
		var elapsed   = formatElapsed( card.started_at || card.created_at || 0 );
		var runId     = card.run_id  || '';
		var postId    = card.post_id || 0;

		var html = '<div class="' + escHtml( rowClass ) + '"';
		html    += ' role="listitem" tabindex="0"';
		html    += ' data-run-id="'  + escHtml( runId  ) + '"';
		html    += ' data-post-id="' + escHtml( postId ) + '"';
		html    += ' aria-label="'   + escHtml( title  ) + '">';

		// Chip column.
		html += '<div class="prab-row-chip-wrap">';
		html += '<span class="prab-chip prab-chip--' + escHtml( meta.mod ) + '">';
		html += escHtml( meta.label );
		html += '</span>';
		if ( card.human_modified ) {
			html += '<span class="prab-chip prab-chip--human" title="' + escHtml( cfg.strings.humanModified || 'Human-modified' ) + '">H</span>';
		}
		html += '</div>';

		// Main column.
		html += '<div class="prab-row-main">';
		html += '<div class="prab-row-title"></div>'; // textContent-set below
		if ( card.stage_current ) {
			html += '<div class="prab-row-stage-label"></div>'; // textContent-set below
		}
		if ( card.error_message ) {
			html += '<div class="prab-row-error"></div>';
		}
		html += '</div>';

		// Meta column.
		html += '<div class="prab-row-meta">';
		if ( cost ) {
			html += '<span class="prab-row-cost"></span>'; // textContent-set below
		}
		if ( elapsed ) {
			html += '<span class="prab-row-elapsed"></span>';
		}
		html += '</div>';

		html += '</div>';

		// Use a detached jQuery element to set text safely (avoids any XSS
		// even if card.title contained markup).
		var $row = $( html );
		$row.find( '.prab-row-title' ).text( title );
		if ( card.stage_current ) {
			$row.find( '.prab-row-stage-label' ).text( card.stage_current );
		}
		if ( card.error_message ) {
			var errShort = String( card.error_message ).split( ' ' ).slice( 0, 12 ).join( ' ' );
			if ( String( card.error_message ).split( ' ' ).length > 12 ) { errShort += '…'; }
			$row.find( '.prab-row-error' ).text( errShort );
		}
		if ( cost )    { $row.find( '.prab-row-cost' ).text( cost ); }
		if ( elapsed ) { $row.find( '.prab-row-elapsed' ).text( elapsed ); }

		return $( '<div>' ).append( $row ).html();
	}

	/**
	 * Re-render one status section from server snapshot.
	 *
	 * @param {string} secKey  Section identifier.
	 * @param {Array}  cards   Card objects for this section.
	 * @returns {void}
	 */
	function renderSection( secKey, cards ) {
		var $sec    = $board.find( '[data-section="' + secKey + '"]' );
		var $rows   = $sec.find( '[data-section-rows]' );
		var $count  = $sec.find( '.prab-section-count' );

		$count.text( cards.length );

		if ( ! cards.length ) {
			$rows.html( '<p class="prab-section-empty">' + escHtml( cfg.strings.empty || '' ) + '</p>' );
			return;
		}

		var html = '';
		for ( var i = 0; i < cards.length; i++ ) {
			html += buildRowHtml( cards[ i ], secKey );
		}
		$rows.html( html );

		// Re-highlight selected row if it reappears after poll.
		if ( selectedRunId ) {
			$rows.find( '[data-run-id="' + selectedRunId + '"]' ).addClass( 'is-selected' );
		}

		// Re-bind row-click for freshly rendered rows.
		bindRowClicks( $rows );
	}

	/**
	 * Apply snapshot to all four sections.
	 *
	 * @param {Object} snapshot Board snapshot from AJAX.
	 * @returns {void}
	 */
	function applySnapshot( snapshot ) {
		renderSection( 'generating', snapshot.generating  || [] );
		renderSection( 'in_review',  snapshot.in_review   || [] );
		renderSection( 'published',  snapshot.published   || [] );
		renderSection( 'failed',     snapshot.failed      || [] );
	}

	// ── Inspector rail ────────────────────────────────────────────────────

	/**
	 * Render the inspector rail with a loaded run's data.
	 *
	 * @param {Object} data  Response data from Board_Inspector_Handler.
	 * @returns {void}
	 */
	function renderInspector( data ) {
		var run    = data.run    || {};
		var stages = data.stages || [];
		var total  = data.cost_total || 0;

		var $inner = $( '#prab-inspector-inner' );
		$inner.empty();

		// Title + chip.
		var titleText = run.post_title || run.run_id || '';
		var $title    = $( '<div class="prab-inspector-title"></div>' ).text( titleText );
		$inner.append( $title );

		var statusMod = run.status || 'generating';
		var statusMap = {
			running:  'generating',
			complete: 'published',
			error:    'failed',
			draft:    'in_review',
		};
		var chipMod = statusMap[ statusMod ] || statusMod;
		var chipLabel = sectionMeta( chipMod ).label;
		var $chipRow = $( '<div class="prab-inspector-chip-row"></div>' );
		$chipRow.append( $( '<span class="prab-chip prab-chip--' + escHtml( chipMod ) + '"></span>' ).text( chipLabel ) );
		$inner.append( $chipRow );

		// Stage breakdown.
		var $stageList = $( '<ul class="prab-inspector-stages"></ul>' );
		for ( var i = 0; i < stages.length; i++ ) {
			$stageList.append( buildStageItem( stages[ i ] ) );
		}
		$inner.append( $stageList );

		// Total cost receipt.
		var $receipt = $( '<div class="prab-inspector-receipt"></div>' );
		var $receiptRow = $( '<div class="prab-receipt-row"></div>' );
		$receiptRow.append( $( '<span class="prab-receipt-label"></span>' ).text( cfg.strings.totalCost || 'Total cost' ) );
		$receiptRow.append( $( '<span class="prab-receipt-value"></span>' ).text( formatCost( total ) ) );
		$receipt.append( $receiptRow );
		$inner.append( $receipt );

		// Dossier link.
		if ( run.dossier_url ) {
			var $actions = $( '<div class="prab-inspector-actions"></div>' );
			var $btn     = $( '<a class="prab-inspector-dossier-btn"></a>' );
			$btn.attr( 'href', run.dossier_url );
			$btn.text( cfg.strings.openDossier || 'Open dossier →' );
			$actions.append( $btn );
			$inner.append( $actions );
		}
	}

	/**
	 * Build a single inspector stage list-item jQuery element.
	 *
	 * @param {Object} stage Stage object from inspector AJAX response.
	 * @returns {jQuery}
	 */
	function buildStageItem( stage ) {
		var status   = stage.response_status === 'error' ? 'failed' : 'done';
		var dotClass = 'prab-stage-dot prab-stage-dot--' + status;
		var cost     = stage.estimated_cost ? formatCost( stage.estimated_cost ) : '';

		var $li = $( '<li class="prab-inspector-stage"></li>' );

		// Status dot.
		$li.append( $( '<span class="' + escHtml( dotClass ) + '"></span>' ) );

		// Stage info (label + model).
		var $info = $( '<div class="prab-stage-info"></div>' );
		$info.append( $( '<div class="prab-stage-name"></div>' ).text( stage.label || stage.stage ) );
		if ( stage.model ) {
			$info.append( $( '<div class="prab-stage-model"></div>' ).text( stage.model ) );
		}
		if ( stage.error_message ) {
			$info.append( $( '<div class="prab-stage-error-msg"></div>' ).text( stage.error_message ) );
		}

		// I/O toggle (only when there is input or output to show).
		if ( stage.input_user || stage.output || stage.output_pruned ) {
			var $toggle = $( '<button class="prab-stage-io-toggle">I/O</button>' );
			$toggle.on( 'click', ( function ( s ) {
				return function () {
					toggleStageIO( $( this ).closest( 'li' ), s );
				};
			}( stage ) ) );
			$info.append( $toggle );
		}

		$li.append( $info );

		// Cost.
		if ( cost ) {
			$li.append( $( '<span class="prab-stage-cost"></span>' ).text( cost ) );
		}

		return $li;
	}

	/**
	 * Toggle stage I/O panel inside an inspector stage item.
	 *
	 * Text is set via textContent (jQuery .text()) — structurally safe.
	 *
	 * @param {jQuery} $li    Stage list item element.
	 * @param {Object} stage  Stage data with input_system/input_user/output.
	 * @returns {void}
	 */
	function toggleStageIO( $li, stage ) {
		var $existing = $li.find( '.prab-stage-io' );
		if ( $existing.length ) {
			$existing.remove();
			return;
		}

		var $io = $( '<div class="prab-stage-io"></div>' );

		if ( stage.input_user ) {
			$io.append( $( '<div class="prab-stage-io-label"></div>' ).text( cfg.strings.input || 'Input' ) );
			$io.append( $( '<pre class="prab-stage-io-text"></pre>' ).text( stage.input_user ) );
		}

		if ( stage.output ) {
			$io.append( $( '<div class="prab-stage-io-label"></div>' ).text( cfg.strings.output || 'Output' ) );
			$io.append( $( '<pre class="prab-stage-io-text"></pre>' ).text( stage.output ) );
		} else if ( stage.output_pruned ) {
			$io.append( $( '<div class="prab-stage-io-label"></div>' ).text( cfg.strings.output || 'Output' ) );
			$io.append( $( '<p class="prab-stage-io-text"></p>' ).text( cfg.strings.pruned || '[pruned]' ) );
		} else if ( stage.input_user ) {
			// Has input but no output — log-only stage or still running.
			$io.append( $( '<div class="prab-stage-io-label"></div>' ).text( cfg.strings.output || 'Output' ) );
			$io.append( $( '<p class="prab-stage-io-text"></p>' ).text( cfg.strings.noOutput || '[no output recorded]' ) );
		}

		// Insert after the stage-info div.
		$li.append( $io );
	}

	/**
	 * Load inspector data for a run_id via AJAX.
	 *
	 * @param {string} runId  Pipeline run UUID.
	 * @returns {void}
	 */
	function loadInspector( runId ) {
		if ( ! runId || inspFetching ) { return; }
		inspFetching = true;

		var $inner = $( '#prab-inspector-inner' );
		$inner.html( '<p class="prab-inspector-loading">' + escHtml( cfg.strings.inspectorLoad || 'Loading…' ) + '</p>' );

		$.ajax( {
			url:    cfg.ajaxUrl,
			method: 'POST',
			data: {
				action: cfg.inspectorAction,
				nonce:  cfg.nonce,
				run_id: runId
			},
			success: function ( response ) {
				inspFetching = false;
				if ( response && response.success && response.data ) {
					renderInspector( response.data );
				} else {
					$inner.html( '<p class="prab-inspector-error">' + escHtml( cfg.strings.inspectorError || 'Could not load run details.' ) + '</p>' );
				}
			},
			error: function () {
				inspFetching = false;
				$inner.html( '<p class="prab-inspector-error">' + escHtml( cfg.strings.inspectorError || 'Could not load run details.' ) + '</p>' );
			}
		} );
	}

	// ── Row click binding ─────────────────────────────────────────────────

	/**
	 * Bind click + keyboard-activate on run rows within a container.
	 *
	 * @param {jQuery} $container Container to search within.
	 * @returns {void}
	 */
	function bindRowClicks( $container ) {
		$container.find( '.prab-run-row' ).on( 'click keydown', function ( e ) {
			if ( e.type === 'keydown' && e.which !== 13 && e.which !== 32 ) { return; }
			e.preventDefault();

			var $row  = $( this );
			var runId = $row.data( 'run-id' );
			if ( ! runId ) { return; }

			// Toggle off if already selected.
			if ( selectedRunId === String( runId ) ) {
				selectedRunId = null;
				$row.removeClass( 'is-selected' );
				$( '#prab-inspector-inner' ).html(
					'<p class="prab-inspector-placeholder">' + escHtml( cfg.strings.inspectorEmpty || 'Select an article to preview its pipeline.' ) + '</p>'
				);
				return;
			}

			// Deselect all rows, select this one.
			$board.find( '.prab-run-row' ).removeClass( 'is-selected' );
			$row.addClass( 'is-selected' );
			selectedRunId = String( runId );
			loadInspector( selectedRunId );
		} );
	}

	// ── Polling ───────────────────────────────────────────────────────────

	/** Compute next poll delay. */
	function nextDelay( hasActiveRuns ) {
		if ( hasActiveRuns ) {
			backoffFactor = 1;
			return baseInterval;
		}
		backoffFactor = Math.min( backoffFactor + 1, maxBackoff );
		return baseInterval * backoffFactor;
	}

	/** Schedule the next poll. */
	function schedulePoll( delay ) {
		clearTimeout( pollTimer );
		pollTimer = setTimeout( doPoll, delay );
	}

	/** Execute one poll cycle. */
	function doPoll() {
		$.ajax( {
			url:    cfg.ajaxUrl,
			method: 'POST',
			data: {
				action: cfg.action,
				nonce:  cfg.nonce
			},
			success: function ( response ) {
				if ( $errorBanner ) { $errorBanner.removeClass( 'is-visible' ); }
				if ( response && response.success && response.data ) {
					applySnapshot( response.data );
					schedulePoll( nextDelay( !! response.data.has_active_runs ) );
				} else {
					schedulePoll( nextDelay( false ) );
				}
			},
			error: function () {
				if ( $errorBanner ) { $errorBanner.addClass( 'is-visible' ); }
				schedulePoll( nextDelay( false ) );
			}
		} );
	}

	// ── Init ──────────────────────────────────────────────────────────────

	$( function () {
		$board = $( '#prab-board' );
		if ( ! $board.length ) { return; }

		// Error banner.
		$errorBanner = $( '<div class="prab-board-error" role="alert"></div>' );
		$errorBanner.text( cfg.strings.pollError || '' );
		$board.before( $errorBanner );

		// Bind clicks on server-rendered rows.
		bindRowClicks( $board );

		// Start poller.
		var hasActive = ( $board.find( '[data-section="generating"] .prab-run-row' ).length > 0 );
		schedulePoll( nextDelay( hasActive ) );
	} );

}( jQuery ) );
