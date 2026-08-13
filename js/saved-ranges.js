( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var container = document.getElementById( 'turf-saved-ranges' );

		if ( ! container || typeof turfSavedRanges === 'undefined' ) {
			return;
		}

		var baseUrl = container.getAttribute( 'data-base-url' );
		var nonce   = container.getAttribute( 'data-nonce' );

		function post( action, extra ) {
			var params = Object.assign( { action: action, nonce: nonce }, extra );
			var body   = new URLSearchParams( params );

			return fetch( turfSavedRanges.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} ).then( function ( res ) {
				return res.json();
			} );
		}

		function jumpTo( start, end ) {
			var sep = baseUrl.indexOf( '?' ) > -1 ? '&' : '?';
			window.location.href = baseUrl + sep + 'period=custom&range_start=' + encodeURIComponent( start ) + '&range_end=' + encodeURIComponent( end );
		}

		var select = document.getElementById( 'turf-saved-ranges-select' );
		if ( select ) {
			select.addEventListener( 'change', function () {
				var option = select.options[ select.selectedIndex ];
				if ( ! option || ! option.value ) {
					return;
				}
				jumpTo( option.getAttribute( 'data-start' ), option.getAttribute( 'data-end' ) );
			} );
		}

		// Reloading after a successful save/delete (rather than re-rendering the
		// <select> and manage-list from the AJAX response in place) trades one
		// extra request for reusing the same PHP template that renders this UI
		// on a normal page load - no risk of the two ever drifting apart.
		var saveButton = document.getElementById( 'turf-save-range' );
		if ( saveButton ) {
			saveButton.addEventListener( 'click', function () {
				var name = window.prompt( turfSavedRanges.namePrompt );
				if ( null === name ) {
					return;
				}
				name = name.trim();
				if ( '' === name ) {
					return;
				}

				post( 'turf_save_range', {
					name:  name,
					start: container.getAttribute( 'data-current-start' ),
					end:   container.getAttribute( 'data-current-end' ),
				} ).then( function ( response ) {
					if ( response && response.success ) {
						window.location.reload();
					}
				} );
			} );
		}

		container.querySelectorAll( '.turf-saved-range-delete' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				post( 'turf_delete_saved_range', { id: button.getAttribute( 'data-id' ) } ).then( function ( response ) {
					if ( response && response.success ) {
						window.location.reload();
					}
				} );
			} );
		} );
	} );
}() );
