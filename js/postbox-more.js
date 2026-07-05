( function () {
	'use strict';

	if ( typeof turfPostboxMore === 'undefined' ) {
		return;
	}

	var DEFAULT_VISIBLE = 5;

	// A list can override how many rows show before the toggle by putting
	// data-turf-visible="N" on its table (or bar-list). Everything else
	// defaults to DEFAULT_VISIBLE. This must match the CSS in
	// turf_postboxes_enqueue() that does the actual before-paint hiding.
	function visibleCount( el ) {
		var attr = el.getAttribute( 'data-turf-visible' );
		var n    = attr ? parseInt( attr, 10 ) : NaN;

		return ( ! isNaN( n ) && n > 0 ) ? n : DEFAULT_VISIBLE;
	}

	// The rows are already collapsed by CSS (.turf-expanded absent) before the
	// page paints - there's nothing to hide here. This only adds the toggle
	// button and flips the class, so expanding/collapsing is the only layout
	// change, never a load-time one.
	function setup( container, itemCount, visible ) {
		if ( itemCount <= visible ) {
			return;
		}

		var link       = document.createElement( 'button' );
		link.type      = 'button';
		link.className = 'bk-stats-more-link';

		function sync() {
			link.textContent = container.classList.contains( 'turf-expanded' )
				? turfPostboxMore.lessLabel
				: turfPostboxMore.moreLabel.replace( '%d', itemCount - visible );
		}

		sync();

		link.addEventListener( 'click', function () {
			container.classList.toggle( 'turf-expanded' );
			sync();
		} );

		container.insertAdjacentElement( 'afterend', link );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.postbox .inside .bk-stats-bar-list' ).forEach( function ( list ) {
			var rows = list.querySelectorAll( ':scope > .bk-stats-bar-row' );
			setup( list, rows.length, visibleCount( list ) );
		} );

		// Excludes .bk-stats-heatmap - its rows are a fixed 7-day grid, not a
		// ranked list, so there is nothing meaningful to collapse.
		document.querySelectorAll( '.postbox .inside > table:not(.bk-stats-heatmap)' ).forEach( function ( table ) {
			var tbody = table.querySelector( ':scope > tbody' );
			if ( ! tbody ) {
				return;
			}
			setup( table, tbody.children.length, visibleCount( table ) );
		} );
	} );
}() );
