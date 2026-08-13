( function () {
	'use strict';

	if ( typeof turfOverviewRefresh === 'undefined' ) {
		return;
	}

	var container = document.getElementById( 'turf-overview-totals' );

	if ( ! container ) {
		return;
	}

	var days       = container.getAttribute( 'data-days' );
	var date       = container.getAttribute( 'data-date' ) || '';
	var rangeStart = container.getAttribute( 'data-range-start' ) || '';
	var rangeEnd   = container.getAttribute( 'data-range-end' ) || '';

	// A custom range whose end date is in the past never changes - polling it
	// every interval.ms would just be wasted requests. Compares plain Y-m-d
	// strings against "today" in the browser's own timezone; being off by a
	// day at a timezone edge only means one extra/one fewer poll, not a
	// correctness issue.
	if ( rangeEnd && rangeEnd < new Date().toISOString().slice( 0, 10 ) ) {
		return;
	}

	function refresh() {
		var body = new URLSearchParams();
		body.set( 'action', 'turf_overview_stats' );
		body.set( 'nonce', turfOverviewRefresh.nonce );
		body.set( 'days', days );

		if ( date ) {
			body.set( 'date', date );
		}

		if ( rangeStart && rangeEnd ) {
			body.set( 'range_start', rangeStart );
			body.set( 'range_end', rangeEnd );
		}

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
