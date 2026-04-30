<?php
/**
 * Blocker class
 *
 * Handles PHP-based blocking of AI crawlers by User-Agent or IP address.
 *
 * @package VigIA
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Blocker class
 */
class VigIA_Blocker {

    /**
     * Option name for blocked items
     */
    const OPTION_NAME = 'vigia_blocked_items';

    /**
     * Legacy option name (for migration)
     */
    const LEGACY_OPTION = 'vigia_blocked_crawlers';

    /**
     * Initialize blocker - runs early on every request
     */
    public static function init() {
        // Migrate legacy data if needed.
        self::maybe_migrate_legacy();

        // Only block on frontend requests.
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || defined( 'REST_REQUEST' ) ) {
            return;
        }

        self::maybe_block_request();
    }

    /**
     * Migrate legacy blocked crawlers to new format
     */
    private static function maybe_migrate_legacy() {
        $legacy = get_option( self::LEGACY_OPTION, null );

        if ( null === $legacy || empty( $legacy ) ) {
            return;
        }

        $new_blocks = self::get_all_blocks();

        foreach ( $legacy as $item ) {
            $new_blocks[] = array(
                'id'         => 'ua_' . md5( $item['user_agent'] . time() . wp_rand() ),
                'type'       => 'useragent',
                'name'       => $item['name'],
                'pattern'    => $item['user_agent'],
                'blocked_at' => isset( $item['blocked_at'] ) ? $item['blocked_at'] : current_time( 'mysql' ),
            );
        }

        update_option( self::OPTION_NAME, $new_blocks );
        delete_option( self::LEGACY_OPTION );
    }

    /**
     * Check if current request should be blocked
     */
    private static function maybe_block_request() {
        $blocks = self::get_all_blocks();

        if ( empty( $blocks ) ) {
            return;
        }

        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        $client_ip  = self::get_client_ip();

        foreach ( $blocks as $block ) {
            if ( 'useragent' === $block['type'] && ! empty( $user_agent ) ) {
                if ( false !== stripos( $user_agent, $block['pattern'] ) ) {
                    self::block_request();
                }
            } elseif ( 'ip' === $block['type'] ) {
                if ( $client_ip === $block['pattern'] ) {
                    self::block_request();
                }
            }
        }
    }

    /**
     * Get client IP address
     *
     * @return string
     */
    private static function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        );

        foreach ( $ip_keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                if ( strpos( $ip, ',' ) !== false ) {
                    $ips = explode( ',', $ip );
                    $ip  = trim( $ips[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Block the current request
     */
    private static function block_request() {
        status_header( 403 );
        nocache_headers();

        header( 'Content-Type: text/plain; charset=utf-8' );
        echo 'Access denied';
        exit;
    }

    /**
     * Get all blocks
     *
     * @return array
     */
    public static function get_all_blocks() {
        $blocks = get_option( self::OPTION_NAME, array() );
        return is_array( $blocks ) ? $blocks : array();
    }

    /**
     * Get blocks by type
     *
     * @param string $type Block type (useragent or ip).
     * @return array
     */
    public static function get_blocked_by_type( $type ) {
        $blocks   = self::get_all_blocks();
        $filtered = array();

        foreach ( $blocks as $block ) {
            if ( $block['type'] === $type ) {
                $filtered[] = $block;
            }
        }

        return $filtered;
    }

    /**
     * Add User-Agent block
     *
     * @param string $name    Display name.
     * @param string $pattern User-Agent pattern to match.
     * @return bool
     */
    public static function add_useragent_block( $name, $pattern ) {
        if ( self::is_useragent_blocked( $pattern ) ) {
            return false;
        }

        $blocks   = self::get_all_blocks();
        $blocks[] = array(
            'id'         => 'ua_' . md5( $pattern . time() ),
            'type'       => 'useragent',
            'name'       => sanitize_text_field( $name ),
            'pattern'    => sanitize_text_field( $pattern ),
            'blocked_at' => current_time( 'mysql' ),
        );

        return update_option( self::OPTION_NAME, $blocks );
    }

    /**
     * Add IP block
     *
     * @param string $name Display name/note.
     * @param string $ip   IP address to block.
     * @return bool
     */
    public static function add_ip_block( $name, $ip ) {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        if ( self::is_ip_blocked( $ip ) ) {
            return false;
        }

        $blocks   = self::get_all_blocks();
        $blocks[] = array(
            'id'         => 'ip_' . md5( $ip . time() ),
            'type'       => 'ip',
            'name'       => sanitize_text_field( $name ? $name : $ip ),
            'pattern'    => sanitize_text_field( $ip ),
            'blocked_at' => current_time( 'mysql' ),
        );

        return update_option( self::OPTION_NAME, $blocks );
    }

    /**
     * Remove block by ID
     *
     * @param string $block_id Block ID.
     * @return bool
     */
    public static function remove_block( $block_id ) {
        $blocks  = self::get_all_blocks();
        $updated = array();

        foreach ( $blocks as $block ) {
            if ( $block['id'] !== $block_id ) {
                $updated[] = $block;
            }
        }

        return update_option( self::OPTION_NAME, $updated );
    }

    /**
     * Check if User-Agent pattern is blocked
     *
     * @param string $pattern User-Agent pattern.
     * @return bool
     */
    public static function is_useragent_blocked( $pattern ) {
        $blocks = self::get_blocked_by_type( 'useragent' );

        foreach ( $blocks as $block ) {
            if ( $block['pattern'] === $pattern ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP is blocked
     *
     * @param string $ip IP address.
     * @return bool
     */
    public static function is_ip_blocked( $ip ) {
        $blocks = self::get_blocked_by_type( 'ip' );

        foreach ( $blocks as $block ) {
            if ( $block['pattern'] === $ip ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Legacy: Get blocked crawlers (for backwards compatibility)
     *
     * @return array
     */
    public static function get_blocked_crawlers() {
        return self::get_blocked_by_type( 'useragent' );
    }

    /**
     * Legacy: Check if crawler name is blocked
     *
     * @param string $crawler_name Crawler name.
     * @return bool
     */
    public static function is_blocked( $crawler_name ) {
        $blocks = self::get_blocked_by_type( 'useragent' );

        foreach ( $blocks as $block ) {
            if ( $block['name'] === $crawler_name || $block['pattern'] === $crawler_name ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Legacy: Add blocked crawler
     *
     * @param string $crawler_name Crawler name.
     * @param string $user_agent   User-Agent pattern.
     * @return bool
     */
    public static function add_blocked_crawler( $crawler_name, $user_agent = '' ) {
        return self::add_useragent_block( $crawler_name, $user_agent ? $user_agent : $crawler_name );
    }

    /**
     * Legacy: Remove blocked crawler
     *
     * @param string $crawler_name Crawler name.
     * @return bool
     */
    public static function remove_blocked_crawler( $crawler_name ) {
        $blocks  = self::get_all_blocks();
        $updated = array();

        foreach ( $blocks as $block ) {
            if ( 'useragent' !== $block['type'] || ( $block['name'] !== $crawler_name && $block['pattern'] !== $crawler_name ) ) {
                $updated[] = $block;
            }
        }

        return update_option( self::OPTION_NAME, $updated );
    }

    /**
     * Clear all blocks
     *
     * @return bool
     */
    public static function clear_all() {
        return delete_option( self::OPTION_NAME );
    }
}
