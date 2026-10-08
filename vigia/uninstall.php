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
    delete_option( 'vigia_version' );
    delete_option( 'vigia_mcp_read_only' );
    delete_option( 'vigia_mcp_enabled' );
    delete_option( 'vigia_mcp_adapter_notice' );

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

    // And the copies WordPress serves, kept in this site's uploads folder since
    // 2.7.0 (VigIA_LLMS_Generator::stored_path()). The plugin's classes are not
    // loaded here, so the path is put together again.
    $vigia_uploads = wp_upload_dir( null, false );

    if ( empty( $vigia_uploads['error'] ) && ! empty( $vigia_uploads['basedir'] ) ) {
        $vigia_storage = trailingslashit( $vigia_uploads['basedir'] ) . 'vigia/';

        foreach ( array( 'llms.txt', 'llms-full.txt' ) as $vigia_stored_name ) {
            if ( file_exists( $vigia_storage . $vigia_stored_name ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- One of two literal names inside this plugin's own folder in uploads.
                unlink( $vigia_storage . $vigia_stored_name );
            }
        }

        // The folder itself, once nothing is left in it.
        if ( is_dir( $vigia_storage ) && 2 === count( (array) scandir( $vigia_storage ) ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty folder this plugin created in uploads; WP_Filesystem is not loaded during uninstall.
            rmdir( $vigia_storage );
        }
    }
}