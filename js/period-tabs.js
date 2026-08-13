( function () {
	'use strict';

	// Progressive enhancement only: the custom-range form is already visible
	// via PHP whenever ?period=custom is actually in the URL, so this script
	// is purely a shortcut that skips the extra page load when a user clicks
	// the "Custom" tab from a different period.
	document.addEventListener( 'DOMContentLoaded', function () {
		var toggle = document.querySelector( '.turf-custom-tab-toggle' );
		var form   = document.getElementById( 'turf-custom-range-form' );

		if ( ! toggle || ! form ) {
			return;
		}

		toggle.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			form.style.display = 'flex';

			var start = document.getElementById( 'turf-range-start' );
			if ( start ) {
				start.focus();
			}
		} );
	} );
}() );
