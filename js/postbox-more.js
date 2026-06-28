( function () {
	'use strict';

	if ( typeof turfPostboxMore === 'undefined' ) {
		return;
	}

	var VISIBLE = 5;

	function collapse( items, link ) {
		items.slice( VISIBLE ).forEach( function ( item ) {
			item.style.display = 'none';
		} );
		link.textContent = turfPostboxMore.moreLabel.replace( '%d', items.length - VISIBLE );
	}

	function expand( items, link ) {
		items.slice( VISIBLE ).forEach( function ( item ) {
			item.style.display = '';
		} );
		link.textContent = turfPostboxMore.lessLabel;
	}

	function setup( items, insertAfter ) {
		if ( items.length <= VISIBLE ) {
			return;
		}

		var link    = document.createElement( 'button' );
		link.type   = 'button';
		link.className = 'bk-stats-more-link';
		var expanded = false;

		collapse( items, link );

		link.addEventListener( 'click', function () {
			expanded = ! expanded;

			if ( expanded ) {
				expand( items, link );
			} else {
				collapse( items, link );
			}
		} );

		insertAfter.insertAdjacentElement( 'afterend', link );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.postbox .inside' ).forEach( function ( inside ) {
			var barRows = Array.prototype.slice.call( inside.querySelectorAll( ':scope > .bk-stats-bar-row' ) );
			if ( barRows.length ) {
				setup( barRows, barRows[ barRows.length - 1 ] );
			}

			// Excludes .bk-stats-heatmap - its rows are a fixed 7-day grid,
			// not a ranked list, so there is nothing meaningful to collapse.
			inside.querySelectorAll( ':scope > table:not(.bk-stats-heatmap) > tbody' ).forEach( function ( tbody ) {
				var rows = Array.prototype.slice.call( tbody.children );
				if ( rows.length ) {
					setup( rows, tbody.closest( 'table' ) );
				}
			} );
		} );
	} );
}() );
