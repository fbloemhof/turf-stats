( function () {
	'use strict';

	if ( typeof turfClicks === 'undefined' ) {
		return;
	}

	function send( key ) {
		var body = new URLSearchParams();
		body.set( 'action', 'turf_track_click' );
		body.set( 'key', key );
		body.set( 'context', window.location.pathname );

		if ( navigator.sendBeacon ) {
			var blob = new Blob( [ body.toString() ], { type: 'application/x-www-form-urlencoded' } );
			navigator.sendBeacon( turfClicks.ajaxUrl, blob );
		} else {
			fetch( turfClicks.ajaxUrl, { method: 'POST', body: body, keepalive: true } );
		}
	}

	// Delegated listener so it also covers elements added later (e.g. after
	// an AJAX-driven view change) without needing to re-bind anything.
	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest( '[data-turf-click]' );

		if ( el ) {
			send( el.getAttribute( 'data-turf-click' ) );
		}
	} );
}() );
