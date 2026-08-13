( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var button = document.getElementById( 'turf-send-test-email-report' );
		var status = document.getElementById( 'turf-send-test-email-report-status' );
		var recipientField = document.getElementById( 'turf-email-report-recipient' );

		if ( ! button || typeof turfEmailReportAdmin === 'undefined' ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var recipient = recipientField ? recipientField.value : '';

			button.disabled = true;
			if ( status ) {
				status.textContent = turfEmailReportAdmin.i18n.sending;
			}

			var body = new URLSearchParams();
			body.set( 'action', 'turf_send_test_email_report' );
			body.set( 'nonce', turfEmailReportAdmin.nonce );
			body.set( 'recipient', recipient );

			fetch( turfEmailReportAdmin.ajaxUrl, { method: 'POST', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) {
					if ( status ) {
						status.textContent = ( data && data.success ) ? turfEmailReportAdmin.i18n.sent : turfEmailReportAdmin.i18n.failed;
					}
				} )
				.catch( function () {
					if ( status ) {
						status.textContent = turfEmailReportAdmin.i18n.failed;
					}
				} )
				.finally( function () {
					button.disabled = false;
				} );
		} );
	} );
}() );
