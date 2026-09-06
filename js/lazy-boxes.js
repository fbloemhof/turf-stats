( function () {
	'use strict';

	if ( typeof turfLazyBoxes === 'undefined' ) {
		return;
	}

	// Reuses the same data-* attributes turf_overview_refresh_enqueue()
	// already puts on #turf-overview-totals for the stat-tile refresh poll -
	// one source of truth for "which period is this page showing" rather
	// than localizing it a second time.
	var totals = document.getElementById( 'turf-overview-totals' );

	if ( ! totals ) {
		return;
	}

	var days       = totals.getAttribute( 'data-days' ) || '7';
	var date       = totals.getAttribute( 'data-date' ) || '';
	var rangeStart = totals.getAttribute( 'data-range-start' ) || '';
	var rangeEnd   = totals.getAttribute( 'data-range-end' ) || '';

	function isVisible( el ) {
		// Cheap, mechanism-agnostic "is this hidden" check (Screen Options
		// hides a box via display:none, however it gets applied) - offsetParent
		// is null for any display:none ancestor, so a box the user has chosen
		// to hide never fires a wasted request.
		return null !== el.offsetParent;
	}

	function loadBox( placeholder ) {
		var boxId = placeholder.getAttribute( 'data-turf-lazy-box' );
		var box   = placeholder.closest( '.postbox' );

		var body = new URLSearchParams();
		body.set( 'action', 'turf_load_box' );
		body.set( 'nonce', turfLazyBoxes.nonce );
		body.set( 'box_id', boxId );
		body.set( 'days', days );

		if ( date ) {
			body.set( 'date', date );
		}

		if ( rangeStart && rangeEnd ) {
			body.set( 'range_start', rangeStart );
			body.set( 'range_end', rangeEnd );
		}

		fetch( turfLazyBoxes.ajaxUrl, { method: 'POST', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( ! data.success || ! data.data.html ) {
					// No data for this period - matches the old server-side
					// "empty callback -> box never registered" behaviour, just
					// applied client-side now that emptiness isn't known until
					// the query actually runs.
					if ( box ) {
						box.remove();
					}
					return;
				}

				placeholder.outerHTML = data.data.html;

				if ( box && window.turfInitPostboxMore ) {
					window.turfInitPostboxMore( box );
				}
			} )
			.catch( function () {
				// Leave the spinner as-is on a network error rather than
				// removing the box - a retry (reload) might still succeed.
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-turf-lazy-box]' ).forEach( function ( placeholder ) {
			if ( isVisible( placeholder ) ) {
				loadBox( placeholder );
			}
		} );
	} );
}() );
