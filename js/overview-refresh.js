( function () {
	'use strict';

	if ( typeof turfOverviewRefresh === 'undefined' ) {
		return;
	}

	var container = document.getElementById( 'turf-overview-totals' );

	if ( ! container ) {
		return;
	}

	var days = container.getAttribute( 'data-days' );

	function refresh() {
		var body = new URLSearchParams();
		body.set( 'action', 'turf_overview_stats' );
		body.set( 'nonce', turfOverviewRefresh.nonce );
		body.set( 'days', days );

		fetch( turfOverviewRefresh.ajaxUrl, { method: 'POST', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( ! data.success ) {
					return;
				}

				Object.keys( data.data.boxes ).forEach( function ( key ) {
					var box = document.getElementById( 'turf-stat-' + key );

					if ( box ) {
						box.innerHTML = data.data.boxes[ key ];
					}
				} );
			} )
			.catch( function () {} );
	}

	setInterval( refresh, turfOverviewRefresh.interval );
}() );
