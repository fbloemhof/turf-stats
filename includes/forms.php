<?php
/**
 * Tracks form submissions from Contact Form 7 and/or Gravity Forms as
 * conversion events - which form, on which page, so a submission rate can
 * be calculated against that page's views. The table itself is always
 * created (cheap, and matches every other feature's pattern); the actual
 * submission hooks only attach once `init` has fired and the relevant
 * plugin's classes are known to be loaded (plugins load in alphabetical
 * order, so "turf-stats" loads before both "contact-form-7" and
 * "gravityforms" - a top-level class_exists() check would always be false).
 */

define( 'TURF_FORMS_DB_VERSION', '1.0' );

function turf_forms_table() {
	global $wpdb;
	return $wpdb->prefix . 'turf_form_submissions';
}

function turf_forms_install() {
	if ( get_option( 'turf_forms_db_version' ) === TURF_FORMS_DB_VERSION ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table           = turf_forms_table();
	$charset_collate = $wpdb->get_charset_collate();

	dbDelta( "CREATE TABLE $table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		plugin VARCHAR(20) NOT NULL,
		form_id VARCHAR(50) NOT NULL,
		form_title VARCHAR(255) NOT NULL DEFAULT '',
		page_path VARCHAR(255) NOT NULL DEFAULT '',
		visitor_hash CHAR(32) NOT NULL,
		submitted_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY form_lookup (plugin, form_id, submitted_at)
	) $charset_collate;" );

	update_option( 'turf_forms_db_version', TURF_FORMS_DB_VERSION );
}
add_action( 'init', 'turf_forms_install' );

function turf_forms_register_hooks() {
	if ( class_exists( 'WPCF7_ContactForm' ) ) {
		add_action( 'wpcf7_mail_sent', 'turf_forms_track_cf7' );
	}

	if ( class_exists( 'GFForms' ) ) {
		add_action( 'gform_after_submission', 'turf_forms_track_gravityforms', 10, 2 );
	}
}
add_action( 'init', 'turf_forms_register_hooks' );

function turf_forms_log( $plugin, $form_id, $form_title ) {
	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	$path = '';
	if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['HTTP_REFERER'] ), PHP_URL_PATH );
		$path = substr( sanitize_text_field( $path ), 0, 255 );
	}

	global $wpdb;
	$wpdb->insert(
		turf_forms_table(),
		array(
			'plugin'       => $plugin,
			'form_id'      => substr( (string) $form_id, 0, 50 ),
			'form_title'   => substr( sanitize_text_field( $form_title ), 0, 255 ),
			'page_path'    => $path,
			'visitor_hash' => turf_visitor_hash( $user_agent ),
			'submitted_at' => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s' )
	);
}

function turf_forms_track_cf7( $contact_form ) {
	turf_forms_log( 'cf7', $contact_form->id(), $contact_form->title() );
}

function turf_forms_track_gravityforms( $entry, $form ) {
	turf_forms_log( 'gravityforms', $form['id'], $form['title'] );
}
