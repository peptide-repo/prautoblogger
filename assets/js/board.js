/**
 * PRAutoBlogger — Kanban Board Poller
 *
 * Live-polls the board status AJAX endpoint and updates the four columns
 * (Generating | In Review | Published | Failed) in-place without a full
 * page reload.
 *
 * Poll behaviour:
 *   - Polls at `prabBoard.pollInterval` ms when any article is generating.
 *   - Doubles the interval (backoff) when `has_active_runs` is false, capping
 *     at 4× the base interval — prevents needless AJAX on an idle board.
 *   - Resets to base interval as soon as an active run is detected again.
 *
 * Click-throughs (M1):
 *   - Generating → Activity Log
 *   - In Review  → Review Queue
 *   - Published  → Post edit screen
 *   - Failed     → Activity Log
 *   (M2 rewires all to Article Dossier)
 *
 * Localised config: `prabBoard` object (ajaxUrl, nonce, pollInterval, action,
 *   strings) injected by PRAutoBlogger_Board_Page::on_enqueue_assets().
 *
 * @see includes/admin/class-board-page.php      — AJAX handler + localise.
 * @see assets/css/board.css                     — Card and column styles.
 * @see templates/admin/board-page.php           — Initial server-rendered HTML.
 */
( function ( $ ) {
	'use strict';

	var cfg            = window.prabBoard || {};
	var baseInterval   = cfg.pollInterval  || 5000;
	var maxBackoff     = 4;
	var backoffFactor  = 1;
	var pollTimer      = null;
	var $board         = null;
	var $errorBanner   = null;

	/** Escape HTML for safe DOM insertion. */
	function escHtml( str ) {
		return $( '<div>' ).text( String( str ) ).html();
	}

	/** Format cost to 4 decimal places with a leading $. */
	function formatCost( cost ) {
		var n = parseFloat( cost ) || 0;
		return '$' + n.toFixed( 4 );
	}

	/**
	 * Build a card HTML string from a card data object.
	 *
	 * @param {Object} card       Card data from server.
	 * @param {string} columnKey  Column identifier.
	 * @returns {string} HTML string.
	 */
	function buildCardHtml( card, columnKey ) {
		var isGenerating = ( columnKey === 'generating' );
		var cls          = 'prab-board-card' + ( isGenerating ? ' prab-board-card--generating' : '' );
		var html         = '';

		html += '<div class="' + escHtml( cls ) + '"';
		html += ' data-run-id="'  + escHtml( card.run_id  || '' ) + '"';
		html += ' data-post-id="' + escHtml( card.post_id || 0  ) + '">';

		if ( isGenerating ) {
			html += '<div class="prab-card-spinner" aria-label="' + escHtml( cfg.strings.generating ) + '"></div>';
		}

		html += '<div class="prab-card-title">' + escHtml( card.title || cfg.strings.empty ) + '</div>';

		if ( card.cost_total ) {
			html += '<div class="prab-card-meta"><span class="prab-card-cost">';
			html += escHtml( formatCost( card.cost_total ) );
			html += '</span></div>';
		}

		if ( isGenerating && card.stage_current ) {
			html += '<div class="prab-card-stage">';
			/* translators: stage name */
			html += escHtml( cfg.strings.generating + ': ' + card.stage_current );
			html += '</div>';
		}

		if ( card.error_message ) {
			var errShort = String( card.error_message ).split( ' ' ).slice( 0, 12 ).join( ' ' );
			if ( card.error_message.split( ' ' ).length > 12 ) {
				errShort += '…';
			}
			html += '<div class="prab-card-error">' + escHtml( errShort ) + '</div>';
		}

		// Click-through link.
		var linkUrl  = '';
		var linkText = '';
		if ( card.click_action === 'review' && card.review_url ) {
			linkUrl  = card.review_url;
			linkText = cfg.strings.view;
		} else if ( card.click_action === 'edit' && card.edit_url ) {
			linkUrl  = card.edit_url;
			linkText = cfg.strings.edit;
		} else if ( card.click_action === 'logs' && card.log_url ) {
			linkUrl  = card.log_url;
			linkText = cfg.strings.viewLog;
		}

		if ( linkUrl ) {
			html += '<div class="prab-card-actions">';
			html += '<a href="' + escHtml( linkUrl ) + '" class="prab-card-link">';
			html += escHtml( linkText );
			html += '</a></div>';
		}

		html += '</div>';
		return html;
	}

	/**
	 * Re-render a single column from the server snapshot.
	 *
	 * Updates the pill count and replaces card HTML. Avoids full DOM teardown
	 * to prevent flicker on the running spinner.
	 *
	 * @param {string}   colKey Column identifier.
	 * @param {Array}    cards  Array of card objects.
	 * @returns {void}
	 */
	function renderColumn( colKey, cards ) {
		var $col      = $board.find( '[data-column="' + colKey + '"]' );
		var $cards    = $col.find( '[data-column-cards]' );
		var $pill     = $col.find( '.prab-col-count' );

		$pill.text( cards.length );

		if ( ! cards.length ) {
			$cards.html( '<p class="prab-col-empty">' + escHtml( cfg.strings.empty ) + '</p>' );
			return;
		}

		// Build new card HTML.
		var newHtml = '';
		for ( var i = 0; i < cards.length; i++ ) {
			newHtml += buildCardHtml( cards[ i ], colKey );
		}
		$cards.html( newHtml );
	}

	/**
	 * Apply a server snapshot to all four columns.
	 *
	 * @param {Object} snapshot  Board snapshot from AJAX.
	 * @returns {void}
	 */
	function applySnapshot( snapshot ) {
		renderColumn( 'generating', snapshot.generating  || [] );
		renderColumn( 'in_review',  snapshot.in_review   || [] );
		renderColumn( 'published',  snapshot.published   || [] );
		renderColumn( 'failed',     snapshot.failed      || [] );
	}

	/**
	 * Compute the next poll delay based on whether there are active runs.
	 *
	 * @param {boolean} hasActiveRuns
	 * @returns {number} Milliseconds until next poll.
	 */
	function nextDelay( hasActiveRuns ) {
		if ( hasActiveRuns ) {
			backoffFactor = 1;
			return baseInterval;
		}
		backoffFactor = Math.min( backoffFactor + 1, maxBackoff );
		return baseInterval * backoffFactor;
	}

	/**
	 * Schedule the next poll after a given delay.
	 *
	 * @param {number} delay  Milliseconds.
	 * @returns {void}
	 */
	function schedulePoll( delay ) {
		clearTimeout( pollTimer );
		pollTimer = setTimeout( doPoll, delay );
	}

	/**
	 * Execute one board poll: AJAX POST → apply snapshot → reschedule.
	 *
	 * @returns {void}
	 */
	function doPoll() {
		$.ajax( {
			url:    cfg.ajaxUrl,
			method: 'POST',
			data: {
				action: cfg.action,
				nonce:  cfg.nonce
			},
			success: function ( response ) {
				if ( $errorBanner ) {
					$errorBanner.removeClass( 'is-visible' );
				}
				if ( response && response.success && response.data ) {
					applySnapshot( response.data );
					schedulePoll( nextDelay( !! response.data.has_active_runs ) );
				} else {
					schedulePoll( nextDelay( false ) );
				}
			},
			error: function () {
				if ( $errorBanner ) {
					$errorBanner.addClass( 'is-visible' );
				}
				schedulePoll( nextDelay( false ) );
			}
		} );
	}

	/** Initialise the board poller on DOM ready. */
	$( function () {
		$board = $( '#prab-board' );
		if ( ! $board.length ) {
			return;
		}

		// Inject the error banner after the heading.
		$errorBanner = $( '<div class="prab-board-error" role="alert">' + escHtml( cfg.strings.pollError || '' ) + '</div>' );
		$board.before( $errorBanner );

		// Check if any generating card is present in the initial server render.
		var hasActive = ( $board.find( '[data-column="generating"] .prab-board-card' ).length > 0 );
		backoffFactor = hasActive ? 1 : 1; // Always start at base on first load.

		schedulePoll( nextDelay( hasActive ) );
	} );

}( jQuery ) );
