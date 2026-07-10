( function () {
	'use strict';

	if ( typeof turfOnlineNow === 'undefined' ) {
		return;
	}

	var el       = document.getElementById( 'turf-online-now-value' );
	var pagesEl  = document.getElementById( 'turf-online-now-pages' );

	if ( ! el && ! pagesEl ) {
		return;
	}

	function refresh() {
		var body = new URLSearchParams();
		body.set( 'action', 'turf_online_now' );
		body.set( 'nonce', turfOnlineNow.nonce );

		fetch( turfOnlineNow.ajaxUrl, { method: 'POST', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( ! data.success ) {
					return;
				}

				if ( el ) {
					el.textContent = data.data.count.toLocaleString( turfOnlineNow.locale || undefined );
				}

				if ( pagesEl && 'string' === typeof data.data.pages_html ) {
					pagesEl.innerHTML = data.data.pages_html;
				}
			} )
			.catch( function () {} );
	}

	setInterval( refresh, turfOnlineNow.interval );
}() );
