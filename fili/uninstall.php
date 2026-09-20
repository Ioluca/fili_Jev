<?php
// Remove what Fili created for itself. Links already approved and applied are the
// owner's content by now and stay where they are.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
global $wpdb;
foreach ( array( 'docs', 'terms', 'df', 'sentences', 'proposals', 'pairs' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fili_{$t}" ); // phpcs:ignore
}
foreach ( array( 'fili_settings', 'fili_api_key', 'fili_state', 'fili_spend', 'fili_lock' ) as $o ) {
	delete_option( $o );
}
