<?php
/**
 * Uninstall VigIA
 *
 * Cleans up plugin data when uninstalled.
 *
 * @package VigIA
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Check if user wants to delete data.
$vigia_settings = get_option( 'vigia_settings', array() );

if ( ! empty( $vigia_settings['delete_on_uninstall'] ) ) {
    global $wpdb;

    // Delete custom table.
    $vigia_table_name = $wpdb->prefix . 'vigia_visits';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $vigia_table_name ) );

    // Delete all options.
    delete_option( 'vigia_settings' );
    delete_option( 'vigia_custom_crawlers' );
    delete_option( 'vigia_db_version' );
    delete_option( 'vigia_activation_notice' );
    delete_option( 'vigia_blocked_crawlers' );
    delete_option( 'vigia_blocked_items' );
    delete_option( 'vigia_robots_rules' );
    delete_option( 'vigia_email_settings' );
    delete_option( 'vigia_llms_settings' );
    delete_option( 'vigia_markdown_settings' );
    delete_option( 'vigia_jsonld_settings' );
    delete_option( 'vigia_flush_rewrite' );
    delete_option( 'vigia_aiss_tip_dismissed' );
    delete_option( 'vigia_md_cache_salt' );

    // Cached markdown documents. One transient per entry served, so there can be
    // plenty of them; they expire on their own, but an uninstall that was asked
    // to delete the data should not leave them behind.
    $vigia_md_like    = $wpdb->esc_like( '_transient_vigia_md_' ) . '%';
    $vigia_md_timeout = $wpdb->esc_like( '_transient_timeout_vigia_md_' ) . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall; there is no API to delete transients by prefix.
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $vigia_md_like, $vigia_md_timeout ) );

    // Clear scheduled hooks.
    wp_clear_scheduled_hook( 'vigia_daily_cleanup' );
    wp_clear_scheduled_hook( 'vigia_send_email_alerts' );

    // Delete llms files if they exist.
    $vigia_llms_file      = ABSPATH . 'llms.txt';
    $vigia_llms_full_file = ABSPATH . 'llms-full.txt';

    if ( file_exists( $vigia_llms_file ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        unlink( $vigia_llms_file );
    }

    if ( file_exists( $vigia_llms_full_file ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        unlink( $vigia_llms_full_file );
    }
}