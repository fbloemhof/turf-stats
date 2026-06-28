( function () {
	'use strict';

	if ( typeof turfOnlineNow === 'undefined' ) {
		return;
	}

	var el = document.getElementById( 'turf-online-now-value' );

	if ( ! el ) {
		return;
	}

	function refresh() {
		var body = new URLSearchParams();
		body.set( 'action', 'turf_online_now' );
		body.set( 'nonce', turfOnlineNow.nonce );

		fetch( turfOnlineNow.ajaxUrl, { method: 'POST', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( data.success ) {
					el.textContent = data.data.count.toLocaleString( 'nl-NL' );
				}
			} )
			.catch( function () {} );
	}

	setInterval( refresh, turfOnlineNow.interval );
}() );
