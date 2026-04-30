<?php
/**
 * Settings management class
 *
 * Handles plugin settings including data retention, custom crawlers, and uninstall options.
 *
 * @package VigIA
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Settings class
 */
class VigIA_Settings {

    /**
     * Option name for plugin settings
     */
    const OPTION_NAME = 'vigia_settings';

    /**
     * Option name for custom crawlers
     */
    const CUSTOM_CRAWLERS_OPTION = 'vigia_custom_crawlers';

    /**
     * Default settings
     *
     * @var array
     */
    private static $defaults = array(
        'retention_days'         => 0,
        'delete_on_uninstall'    => false,
        'crawlers_box_collapsed' => false,
    );

    /**
     * Available retention periods
     *
     * @return array
     */
    public static function get_retention_options() {
        return array(
            0   => __( 'Unlimited (no auto-delete)', 'vigia' ),
            7   => __( '7 days', 'vigia' ),
            15  => __( '15 days', 'vigia' ),
            30  => __( '1 month', 'vigia' ),
            90  => __( '3 months', 'vigia' ),
            180 => __( '6 months', 'vigia' ),
            365 => __( '1 year', 'vigia' ),
        );
    }

    /**
     * Get all settings
     *
     * @return array
     */
    public static function get_settings() {
        $settings = get_option( self::OPTION_NAME, array() );
        return wp_parse_args( $settings, self::$defaults );
    }

    /**
     * Get a specific setting
     *
     * @param string $key     Setting key.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public static function get( $key, $default = null ) {
        $settings = self::get_settings();

        if ( isset( $settings[ $key ] ) ) {
            return $settings[ $key ];
        }

        if ( null !== $default ) {
            return $default;
        }

        return isset( self::$defaults[ $key ] ) ? self::$defaults[ $key ] : null;
    }

    /**
     * Update a setting
     *
     * @param string $key   Setting key.
     * @param mixed  $value Setting value.
     * @return bool
     */
    public static function update( $key, $value ) {
        $settings         = self::get_settings();
        $settings[ $key ] = $value;
        return update_option( self::OPTION_NAME, $settings );
    }

    /**
     * Update multiple settings
     *
     * @param array $new_settings Settings to update.
     * @return bool
     */
    public static function update_settings( $new_settings ) {
        $settings = self::get_settings();
        $settings = wp_parse_args( $new_settings, $settings );
        return update_option( self::OPTION_NAME, $settings );
    }

    /**
     * Get custom crawlers
     *
     * @return array
     */
    public static function get_custom_crawlers() {
        $crawlers = get_option( self::CUSTOM_CRAWLERS_OPTION, array() );
        return is_array( $crawlers ) ? $crawlers : array();
    }

    /**
     * Add a custom crawler
     *
     * @param string $user_agent User agent string to detect.
     * @param string $name       Display name.
     * @param string $company    Company/AI name.
     * @param string $category   Category slug.
     * @return bool
     */
    public static function add_custom_crawler( $user_agent, $name, $company, $category ) {
        $crawlers = self::get_custom_crawlers();

        // Generate unique ID.
        $id = 'custom_' . md5( $user_agent . time() );

        $crawlers[ $id ] = array(
            'user_agent' => sanitize_text_field( $user_agent ),
            'name'       => sanitize_text_field( $name ),
            'company'    => sanitize_text_field( $company ),
            'category'   => sanitize_key( $category ),
            'added'      => current_time( 'mysql' ),
        );

        return update_option( self::CUSTOM_CRAWLERS_OPTION, $crawlers );
    }

    /**
     * Remove a custom crawler
     *
     * @param string $id Crawler ID.
     * @return bool
     */
    public static function remove_custom_crawler( $id ) {
        $crawlers = self::get_custom_crawlers();

        if ( isset( $crawlers[ $id ] ) ) {
            unset( $crawlers[ $id ] );
            return update_option( self::CUSTOM_CRAWLERS_OPTION, $crawlers );
        }

        return false;
    }

    /**
     * Check if should delete data on uninstall
     *
     * @return bool
     */
    public static function should_delete_on_uninstall() {
        return (bool) self::get( 'delete_on_uninstall', false );
    }

    /**
     * Get retention days
     *
     * @return int
     */
    public static function get_retention_days() {
        return absint( self::get( 'retention_days', 0 ) );
    }

    /**
     * Delete all plugin data
     *
     * @return bool True on success.
     */
    public static function delete_all_data() {
        return VigIA_Database::truncate_table();
    }

    /**
     * Delete all plugin options and data (for uninstall)
     */
    public static function delete_all_options() {
        delete_option( self::OPTION_NAME );
        delete_option( self::CUSTOM_CRAWLERS_OPTION );
        delete_option( 'vigia_db_version' );
        delete_option( 'vigia_activation_notice' );
        delete_option( 'vigia_blocked_crawlers' );
        delete_option( 'vigia_robots_rules' );
        delete_option( 'vigia_email_settings' );
        delete_option( 'vigia_llms_settings' );
    }

    /**
     * Run scheduled data cleanup
     */
    public static function run_cleanup() {
        $retention_days = self::get_retention_days();

        // Only run if retention is set (not unlimited).
        if ( $retention_days > 0 ) {
            VigIA_Database::cleanup_old_data( $retention_days );
        }
    }
}
