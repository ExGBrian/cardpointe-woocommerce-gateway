<?php
/**
 * Uninstall handler.
 *
 * Removes settings, cached data, user vault references and saved payment tokens
 * only when the "Remove data on uninstall" option was enabled. Orders are never touched
 * and no API calls are made.
 *
 * @package ParadoxSolutions\CardPointe
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes plugin data for the current site.
 */
function paradox_cardpointe_uninstall_site() {
	if ( 'yes' !== get_option( 'paradox_cardpointe_remove_data_on_uninstall', 'no' ) ) {
		return;
	}

	global $wpdb;

	$gateway_ids = array( 'paradox_cardpointe', 'paradox_cardpointe_echeck' );

	// Saved payment tokens (and their meta) created by these gateways.
	$token_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT token_id FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE gateway_id IN (%s, %s)",
			$gateway_ids[0],
			$gateway_ids[1]
		)
	);
	if ( ! empty( $token_ids ) ) {
		$placeholders = implode( ',', array_fill( 0, count( $token_ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_payment_tokenmeta WHERE payment_token_id IN ($placeholders)", $token_ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE token_id IN ($placeholders)", $token_ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// User meta holding vault references.
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\_paradox\_cardpointe\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// The Apple Pay certificate, when this plugin stored it (it holds a private key), and its folder.
	$card_settings = get_option( 'woocommerce_paradox_cardpointe_settings', array() );
	$cert_path     = is_array( $card_settings ) && ! empty( $card_settings['apple_pay_cert_path'] ) ? (string) $card_settings['apple_pay_cert_path'] : '';
	$uploads       = wp_upload_dir( null, false );
	$private_dir   = empty( $uploads['basedir'] ) ? '' : trailingslashit( $uploads['basedir'] ) . 'paradox-cardpointe-private';
	$in_private    = '' !== $private_dir && 0 === strpos( wp_normalize_path( $cert_path ), trailingslashit( wp_normalize_path( $private_dir ) ) );

	if ( '' !== $cert_path && file_exists( $cert_path ) && ( 'paradox-cardpointe-apple-pay.pem' === basename( $cert_path ) || $in_private ) ) {
		wp_delete_file( $cert_path );
	}
	if ( '' !== $private_dir && is_dir( $private_dir ) ) {
		$leftovers = glob( trailingslashit( $private_dir ) . 'apple-pay-*.pem' );
		$leftovers = is_array( $leftovers ) ? $leftovers : array();
		foreach ( array_merge( $leftovers, array( $private_dir . '/.htaccess', $private_dir . '/index.html' ) ) as $leftover ) {
			if ( file_exists( $leftover ) ) {
				wp_delete_file( $leftover );
			}
		}
		@rmdir( $private_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	// Options.
	foreach ( $gateway_ids as $id ) {
		delete_option( 'woocommerce_' . $id . '_settings' );
	}
	delete_option( 'paradox_cardpointe_version' );
	delete_option( 'paradox_cardpointe_remove_data_on_uninstall' );
	delete_option( 'paradox_cardpointe_privacy_retention' );
	delete_option( 'paradox_cardpointe_apple_pay_verified' );

	// Transients.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_paradox\_cardpointe\_%' OR option_name LIKE '\_transient\_timeout\_paradox\_cardpointe\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

if ( is_multisite() ) {
	$paradox_cardpointe_site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $paradox_cardpointe_site_ids as $paradox_cardpointe_site_id ) {
		switch_to_blog( $paradox_cardpointe_site_id );
		paradox_cardpointe_uninstall_site();
		restore_current_blog();
	}
} else {
	paradox_cardpointe_uninstall_site();
}
