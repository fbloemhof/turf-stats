( function () {
	'use strict';

	if ( typeof turfViews === 'undefined' ) {
		return;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var referrerHost = '';

		try {
			if ( document.referrer ) {
				referrerHost = new URL( document.referrer ).hostname;
			}
		} catch ( e ) {
			referrerHost = '';
		}

		var urlParams = new URLSearchParams( window.location.search );

		var body = new URLSearchParams();
		body.set( 'action', 'turf_track_view' );
		body.set( 'post_id', turfViews.postId );
		body.set( 'object_type', turfViews.objectType );
		body.set( 'page_type', turfViews.pageType || '' );
		body.set( 'referrer', referrerHost );
		body.set( 'utm_source', urlParams.get( 'utm_source' ) || '' );
		body.set( 'utm_medium', urlParams.get( 'utm_medium' ) || '' );
		body.set( 'utm_campaign', urlParams.get( 'utm_campaign' ) || '' );
		body.set( 'nonce', turfViews.nonce );

		function updateLabel( text ) {
			var el = document.getElementById( 'post-views' );

			if ( el ) {
				el.textContent = text;
			}
		}

		// Tracks how far a visitor scrolls and how long they stay, attached to
		// the same event row the initial view created. Sent once, when the
		// visitor leaves - sendBeacon so it still goes out even as the tab closes.
		function trackEngagement( eventId ) {
			var maxScroll  = 0;
			var startTime  = Date.now();
			var sent       = false;

			function currentScrollPct() {
				var doc        = document.documentElement;
				var scrollable = doc.scrollHeight - doc.clientHeight;

				if ( scrollable <= 0 ) {
					return 100;
				}

				return Math.max( 0, Math.min( 100, Math.round( ( window.scrollY / scrollable ) * 100 ) ) );
			}

			window.addEventListener( 'scroll', function () {
				maxScroll = Math.max( maxScroll, currentScrollPct() );
			}, { passive: true } );

			function send() {
				if ( sent ) {
					return;
				}
				sent = true;

				var engagementBody = new URLSearchParams();
				engagementBody.set( 'action', 'turf_track_engagement' );
				engagementBody.set( 'event_id', eventId );
				engagementBody.set( 'scroll_depth', maxScroll );
				engagementBody.set( 'duration', Math.round( ( Date.now() - startTime ) / 1000 ) );

				if ( navigator.sendBeacon ) {
					var blob = new Blob( [ engagementBody.toString() ], { type: 'application/x-www-form-urlencoded' } );
					navigator.sendBeacon( turfViews.ajaxUrl, blob );
				} else {
					fetch( turfViews.ajaxUrl, { method: 'POST', body: engagementBody, keepalive: true } );
				}
			}

			document.addEventListener( 'visibilitychange', function () {
				if ( 'hidden' === document.visibilityState ) {
					send();
				}
			} );
			window.addEventListener( 'pagehide', send );
		}

		// The AJAX call always fires (it's what counts the view) - the
		// #post-views element is optional and only used to display the count
		// when the template has one (e.g. not on the front page).
		fetch( turfViews.ajaxUrl, { method: 'POST', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( data.success && data.data.views > 0 ) {
					updateLabel( ' • ' + data.data.views.toLocaleString( 'nl-NL' ) + ' keer bekeken' );
				} else {
					updateLabel( ' • ' );
				}

				if ( data.success && data.data.event_id ) {
					trackEngagement( data.data.event_id );
				}
			} )
			.catch( function () {
				updateLabel( ' • ' );
			} );
	} );
}() );
