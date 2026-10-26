( function () {
	'use strict';

	if ( typeof turfClicks === 'undefined' ) {
		return;
	}

	// www.example.com and example.com are the same site - without this, a
	// bare-domain link on a www site (or vice versa) counts as outbound.
	function normalizeHost( host ) {
		return host.toLowerCase().replace( /^www\./, '' );
	}

	// Absolute http(s) URL of a link, or '' for anything else (mailto:, tel:,
	// javascript:, a malformed href).
	function linkUrl( link ) {
		try {
			var url = new URL( link.getAttribute( 'href' ) || '', window.location.href );

			return /^https?:$/.test( url.protocol ) ? url.href : '';
		} catch ( err ) {
			return '';
		}
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
			// A tagged link (or a tagged element inside one) also records where
			// it leads, so e.g. a ticket button shows which booking page it sent
			// the visitor to.
			var taggedLink = tagged.closest( 'a[href]' );
			var target     = taggedLink ? linkUrl( taggedLink ) : '';

			send( tagged.getAttribute( 'data-turf-click' ), target ? { target: target } : null );
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

			if ( url.hostname && normalizeHost( url.hostname ) !== normalizeHost( window.location.hostname ) ) {
				// Full destination URL (not just the hostname) so the report can
				// show exactly which external page was clicked; the source page
				// the visitor was on is already sent as `context` above.
				send( 'outbound-link', { target: url.href } );
			}
		} catch ( err ) {
			// Malformed URL - nothing to track.
		}
	} );
}() );
