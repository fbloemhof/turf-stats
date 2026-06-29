( function () {
	'use strict';

	if ( typeof turfClicks === 'undefined' ) {
		return;
	}

	function send( key, extra ) {
		var body = new URLSearchParams();
		body.set( 'action', 'turf_track_click' );
		body.set( 'key', key );
		body.set( 'context', window.location.pathname );

		if ( extra ) {
			Object.keys( extra ).forEach( function ( name ) {
				body.set( name, extra[ name ] );
			} );
		}

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
		var tagged = e.target.closest( '[data-turf-click]' );

		if ( tagged ) {
			send( tagged.getAttribute( 'data-turf-click' ) );
			return;
		}

		// Automatic outbound-link tracking: any <a href="..."> pointing at a
		// different hostname gets tracked under a fixed key, no
		// data-turf-click attribute needed anywhere in the theme or post
		// content - explicit attributes (handled above) still take
		// priority over this.
		var link = e.target.closest( 'a[href]' );

		if ( ! link ) {
			return;
		}

		var href = link.getAttribute( 'href' ) || '';

		if ( ! /^https?:\/\//i.test( href ) ) {
			return;
		}

		try {
			var url = new URL( href, window.location.href );

			if ( url.hostname && url.hostname !== window.location.hostname ) {
				send( 'outbound-link', { target: url.hostname } );
			}
		} catch ( err ) {
			// Malformed URL - nothing to track.
		}
	} );
}() );
