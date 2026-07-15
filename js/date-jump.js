/**
 * Turf Stats - "Go to date" picker.
 *
 * Replaces the native <input type="date"> (which can only ever display ISO
 * Y-m-d) with a text field showing the site's WP date format, backed by a
 * hidden ISO field that the form actually submits. jQuery UI's datepicker is
 * already enqueued in wp-admin; we just wire it up.
 */
( function( $ ) {
	'use strict';

	$( function() {
		var cfg = window.turfDateJump || {};
		var $display = $( '#turf-date' );
		var $iso = $( '#turf-date-iso' );

		if ( ! $display.length || ! $iso.length ) {
			return;
		}

		var format = cfg.dateFormat || 'dd/mm/yy';

		$display.datepicker( {
			dateFormat: format,
			maxDate: 0,
			changeMonth: true,
			changeYear: true,
			showButtonPanel: true,
			// Parse the current ISO value (yyyy-mm-dd) into a Date so the
			// calendar opens on the selected day instead of today.
			defaultDate: $iso.val() ? $.datepicker.parseDate( 'yy-mm-dd', $iso.val() ) : null,
			onSelect: function( text, inst ) {
				var picked = $( this ).datepicker( 'getDate' );
				if ( picked ) {
					$iso.val( $.datepicker.formatDate( 'yy-mm-dd', picked ) );
				}
			}
		} );

		// Seed the visible field from the hidden ISO value on load, so an
		// already-selected date renders in the WP format instead of ISO.
		if ( $iso.val() ) {
			var d = $.datepicker.parseDate( 'yy-mm-dd', $iso.val() );
			if ( d ) {
				$display.val( $.datepicker.formatDate( format, d ) );
			}
		}

		// If the user clears the text field, drop the hidden ISO value too so
		// an empty submission falls back to "no date filter".
		$display.on( 'change', function() {
			if ( ! $( this ).val() ) {
				$iso.val( '' );
			}
		} );
	} );
} )( jQuery );
