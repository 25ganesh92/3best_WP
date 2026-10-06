<?php
/**
 * Uninstall routine.
 *
 * Runs only when the plugin is deleted through WordPress, never on deactivation.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * Data removal is opt-in and off by default.
 *
 * The leads table holds real customer enquiries and the only record of the SMS
 * consent that was given. Deleting a plugin must not silently destroy that, so
 * nothing is dropped unless an operator has deliberately set:
 *
 *     define( 'THREEBEST_REMOVE_DATA_ON_UNINSTALL', true );
 *
 * in wp-config.php, or flipped the matching setting. Otherwise the tables are
 * left in place and can be picked up again by reinstalling.
 */

$threebest_remove = false;

if ( defined( 'THREEBEST_REMOVE_DATA_ON_UNINSTALL' ) && THREEBEST_REMOVE_DATA_ON_UNINSTALL ) {
	$threebest_remove = true;
}

$threebest_settings = get_option( 'threebest_settings', array() );

if ( is_array( $threebest_settings ) && ! empty( $threebest_settings['remove_data_on_uninstall'] ) ) {
	$threebest_remove = true;
}

if ( ! $threebest_remove ) {
	return;
}

global $wpdb;

$threebest_tables = array(
	$wpdb->prefix . 'threebest_leads',
	$wpdb->prefix . 'threebest_otp_verifications',
	$wpdb->prefix . 'threebest_service_locations',
);

foreach ( $threebest_tables as $threebest_table ) {
	// Identifier is built from $wpdb->prefix and a literal, never from input.
	$wpdb->query( "DROP TABLE IF EXISTS `{$threebest_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
}

delete_option( 'threebest_settings' );
delete_option( 'threebest_schema_version' );
delete_option( 'threebest_core_version' );
delete_option( 'threebest_activated_at' );
