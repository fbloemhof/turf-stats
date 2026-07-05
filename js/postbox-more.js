( function () {
	'use strict';

	if ( typeof turfPostboxMore === 'undefined' ) {
		return;
	}

	var DEFAULT_VISIBLE = 5;

	// A list can override how many rows show before the toggle by putting
	// data-turf-visible="N" on its table (or, for the bar-row breakdowns, on
	// the .inside). Everything else defaults to DEFAULT_VISIBLE.
	function visibleCount( source ) {
		var attr = source && source.getAttribute( 'data-turf-visible' );
		var n    = attr ? parseInt( attr, 10 ) : NaN;

		return ( ! isNaN( n ) && n > 0 ) ? n : DEFAULT_VISIBLE;
	}

	function collapse( items, link, visible ) {
		items.slice( visible ).forEach( function ( item ) {
			item.style.display = 'none';
		} );
		link.textContent = turfPostboxMore.moreLabel.replace( '%d', items.length - visible );
	}

	function expand( items, link ) {
		items.forEach( function ( item ) {
			item.style.display = '';
		} );
		link.textContent = turfPostboxMore.lessLabel;
	}

	function setup( items, insertAfter, visible ) {
		if ( items.length <= visible ) {
			return;
		}

		var link       = document.createElement( 'button' );
		link.type      = 'button';
		link.className = 'bk-stats-more-link';
		var expanded   = false;

		collapse( items, link, visible );

		link.addEventListener( 'click', function () {
			expanded = ! expanded;

			if ( expanded ) {
				expand( items, link );
			} else {
				collapse( items, link, visible );
			}
		} );

		insertAfter.insertAdjacentElement( 'afterend', link );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.postbox .inside' ).forEach( function ( inside ) {
			var barRows = Array.prototype.slice.call( inside.querySelectorAll( ':scope > .bk-stats-bar-row' ) );
			if ( barRows.length ) {
				setup( barRows, barRows[ barRows.length - 1 ], visibleCount( inside ) );
			}

			// Excludes .bk-stats-heatmap - its rows are a fixed 7-day grid,
			// not a ranked list, so there is nothing meaningful to collapse.
			inside.querySelectorAll( ':scope > table:not(.bk-stats-heatmap) > tbody' ).forEach( function ( tbody ) {
				var rows  = Array.prototype.slice.call( tbody.children );
				var table = tbody.closest( 'table' );
				if ( rows.length ) {
					setup( rows, table, visibleCount( table ) );
				}
			} );
		} );
	} );
}() );
