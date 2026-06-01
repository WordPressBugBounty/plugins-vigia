<?php
/**
 * LLMS Generator class
 *
 * Generates llms.txt and llms-full.txt files for AI consumption.
 *
 * @package VigIA
 * @since 1.2.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * LLMS Generator class
 */
class VigIA_LLMS_Generator {

    /**
     * Option name for LLMS settings
     */
    const OPTION_NAME = 'vigia_llms_settings';

    /**
     * Cron hook name for auto-regeneration
     */
    const CRON_HOOK = 'vigia_llms_regenerate';

    /**
     * Default settings structure
     *
     * @var array
     */
    private static $defaults = array(
        'site_name'           => '',
        'site_description'    => '',
        'post_types'          => array(),
        'taxonomy_filters'    => array(),
        'manual_includes'     => array(),
        'manual_excludes'     => array(),
        'exclude_patterns'    => '',
        'exclude_noindex'     => true,
        'generate_full'       => false,
        'full_mode'           => 'full',
        'auto_regenerate'     => 'manual',
        'robots_llms'         => false,
        'robots_llms_full'    => false,
        'last_generated'      => 0,
    );

    /**
     * Supported SEO plugins
     *
     * @var array
     */
    private static $seo_plugins = array(
        'yoast'        => array(
            'name'     => 'Yoast SEO',
            'file'     => 'wordpress-seo/wp-seo.php',
            'meta_key' => '_yoast_wpseo_meta-robots-noindex',
            'noindex'  => '1',
        ),
        'rankmath'     => array(
            'name'     => 'Rank Math',
            'file'     => 'seo-by-rank-math/rank-math.php',
            'meta_key' => 'rank_math_robots',
            'noindex'  => 'noindex',
            'is_array' => true,
        ),
        'aioseo'       => array(
            'name'     => 'All in One SEO',
            'file'     => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
            'meta_key' => '_aioseo_noindex',
            'noindex'  => '1',
        ),
        'seopress'     => array(
            'name'     => 'SEOPress',
            'file'     => 'wp-seopress/seopress.php',
            'meta_key' => '_seopress_robots_index',
            'noindex'  => 'yes',
        ),
        'seoframework' => array(
            'name'     => 'The SEO Framework',
            'file'     => 'autodescription/autodescription.php',
            'meta_key' => '_genesis_noindex',
            'noindex'  => '1',
        ),
    );

    /**
     * Initialize cron schedules
     */
    public static function init() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'cron_regenerate' ) );
        add_filter( 'cron_schedules', array( __CLASS__, 'add_monthly_schedule' ) );
    }

    /**
     * Get settings directly from database (bypasses object cache)
     *
     * @return array
     */
    public static function get_settings() {
        global $wpdb;

        // Direct DB query to bypass object cache completely.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                self::OPTION_NAME
            )
        );

        $settings = array();
        if ( $row ) {
            // Decode without allowing object instantiation (guards against PHP object injection).
            $decoded  = is_serialized( $row ) ? unserialize( $row, array( 'allowed_classes' => false ) ) : $row;
            $settings = is_array( $decoded ) ? $decoded : array();
        }
        $settings = self::normalize_settings( $settings );

        // Defaults for empty values.
        if ( empty( $settings['site_name'] ) ) {
            $settings['site_name'] = get_bloginfo( 'name' );
        }
        if ( empty( $settings['site_description'] ) ) {
            $settings['site_description'] = get_bloginfo( 'description' );
        }

        return $settings;
    }

    /**
     * Normalize settings to correct types
     *
     * @param array $settings Raw settings.
     * @return array
     */
    private static function normalize_settings( $settings ) {
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        $normalized = self::$defaults;

        // Strings.
        foreach ( array( 'site_name', 'site_description', 'exclude_patterns', 'full_mode', 'auto_regenerate' ) as $key ) {
            if ( isset( $settings[ $key ] ) ) {
                $normalized[ $key ] = (string) $settings[ $key ];
            }
        }

        // Arrays.
        foreach ( array( 'post_types', 'taxonomy_filters', 'manual_includes', 'manual_excludes' ) as $key ) {
            if ( isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ) {
                $normalized[ $key ] = $settings[ $key ];
            }
        }

        // Booleans.
        foreach ( array( 'exclude_noindex', 'generate_full', 'robots_llms', 'robots_llms_full' ) as $key ) {
            if ( isset( $settings[ $key ] ) ) {
                $normalized[ $key ] = self::to_bool( $settings[ $key ] );
            }
        }

        // Integer.
        if ( isset( $settings['last_generated'] ) ) {
            $normalized['last_generated'] = (int) $settings['last_generated'];
        }

        // Validate enums.
        if ( ! in_array( $normalized['full_mode'], array( 'full', 'excerpt' ), true ) ) {
            $normalized['full_mode'] = 'full';
        }
        if ( ! in_array( $normalized['auto_regenerate'], array( 'manual', 'daily', 'weekly', 'monthly' ), true ) ) {
            $normalized['auto_regenerate'] = 'manual';
        }

        return $normalized;
    }

    /**
     * Convert to boolean
     *
     * @param mixed $value Value.
     * @return bool
     */
    private static function to_bool( $value ) {
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_string( $value ) ) {
            return in_array( strtolower( $value ), array( 'true', '1', 'yes', 'on' ), true );
        }
        return (bool) $value;
    }

    /**
     * Save settings (complete replacement, no merging)
     *
     * @param array $settings Complete settings.
     * @return bool
     */
    public static function save_settings( $settings ) {
        $settings = self::normalize_settings( $settings );

        // Update cron.
        self::schedule_regeneration( $settings['auto_regenerate'] );

        // Clear all caches.
        wp_cache_delete( self::OPTION_NAME, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_flush();

        // Save with autoload disabled.
        $saved = update_option( self::OPTION_NAME, $settings, false );

        // Clear again.
        wp_cache_delete( self::OPTION_NAME, 'options' );
        wp_cache_delete( 'alloptions', 'options' );

        // Update robots.txt.
        if ( class_exists( 'VigIA_Robots_Manager' ) ) {
            VigIA_Robots_Manager::update_llms_references(
                $settings['robots_llms'],
                $settings['robots_llms_full'] && $settings['generate_full']
            );
        }

        return $saved;
    }

    /**
     * Generate files (does NOT save settings)
     *
     * @param array $settings Settings.
     * @return array|WP_Error
     */
    public static function generate( $settings ) {
        $settings = self::normalize_settings( $settings );
        $post_ids = self::get_final_post_ids( $settings );

        if ( empty( $post_ids ) ) {
            return new WP_Error(
                'no_content',
                __( 'No content selected. Please select at least one post type or add content manually.', 'vigia' )
            );
        }

        // Generate llms.txt.
        $llms_content = self::generate_llms_txt( $settings, $post_ids );
        $llms_result  = self::write_file( 'llms.txt', $llms_content );

        if ( is_wp_error( $llms_result ) ) {
            return $llms_result;
        }

        $result = array(
            'llms_txt' => array(
                'url'   => home_url( '/llms.txt' ),
                'size'  => strlen( $llms_content ),
                'count' => count( $post_ids ),
            ),
            'llms_full_txt' => null,
        );

        // Generate llms-full.txt if enabled.
        if ( $settings['generate_full'] ) {
            $full_content = self::generate_llms_full_txt( $settings, $post_ids );
            $full_result  = self::write_file( 'llms-full.txt', $full_content );

            if ( is_wp_error( $full_result ) ) {
                return $full_result;
            }

            $result['llms_full_txt'] = array(
                'url'   => home_url( '/llms-full.txt' ),
                'size'  => strlen( $full_content ),
                'count' => count( $post_ids ),
            );
        } else {
            // Remove llms-full.txt if disabled.
            self::delete_file( 'llms-full.txt' );
        }

        return $result;
    }

    /**
     * Save and generate in one call
     *
     * @param array $settings Settings from form.
     * @return array|WP_Error
     */
    public static function save_and_generate( $settings ) {
        $settings['last_generated'] = time();

        // Normalize settings.
        $settings = self::normalize_settings( $settings );

        // Update cron schedule.
        self::schedule_regeneration( $settings['auto_regenerate'] );

        // Clear caches before saving.
        wp_cache_delete( self::OPTION_NAME, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_flush();

        // Save settings to database (WITHOUT updating robots.txt yet).
        update_option( self::OPTION_NAME, $settings, false );

        // Clear caches after saving.
        wp_cache_delete( self::OPTION_NAME, 'options' );
        wp_cache_delete( 'alloptions', 'options' );

        // Generate files FIRST (so they exist when we update robots.txt).
        $result = self::generate( $settings );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // NOW update robots.txt (files exist at this point).
        if ( class_exists( 'VigIA_Robots_Manager' ) ) {
            VigIA_Robots_Manager::update_llms_references(
                $settings['robots_llms'],
                $settings['robots_llms_full'] && $settings['generate_full']
            );
        }

        return $result;
    }

    /**
     * Schedule cron
     *
     * @param string $frequency Frequency.
     */
    public static function schedule_regeneration( $frequency ) {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }

        if ( 'manual' === $frequency ) {
            return;
        }

        $schedules = array(
            'daily'   => 'daily',
            'weekly'  => 'weekly',
            'monthly' => 'monthly',
        );

        if ( isset( $schedules[ $frequency ] ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, $schedules[ $frequency ], self::CRON_HOOK );
        }
    }

    /**
     * Add monthly schedule
     *
     * @param array $schedules Schedules.
     * @return array
     */
    public static function add_monthly_schedule( $schedules ) {
        if ( ! isset( $schedules['monthly'] ) ) {
            $schedules['monthly'] = array(
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __( 'Once Monthly', 'vigia' ),
            );
        }
        return $schedules;
    }

    /**
     * Cron callback
     */
    public static function cron_regenerate() {
        if ( ! self::llms_exists() ) {
            return;
        }

        $settings = self::get_settings();
        $settings['last_generated'] = time();
        self::save_settings( $settings );
        self::generate( $settings );
    }

    /**
     * Get public post types
     *
     * @return array
     */
    public static function get_public_post_types() {
        $post_types = get_post_types( array( 'public' => true ), 'objects' );
        $result     = array();

        foreach ( $post_types as $pt ) {
            if ( 'attachment' === $pt->name ) {
                continue;
            }

            $count = wp_count_posts( $pt->name );
            $result[ $pt->name ] = array(
                'name'  => $pt->name,
                'label' => $pt->labels->name,
                'count' => isset( $count->publish ) ? (int) $count->publish : 0,
            );
        }

        return $result;
    }

    /**
     * Get taxonomies for post type
     *
     * @param string $post_type Post type.
     * @return array
     */
    public static function get_post_type_taxonomies( $post_type ) {
        $taxonomies = get_object_taxonomies( $post_type, 'objects' );
        $result     = array();

        foreach ( $taxonomies as $tax ) {
            if ( ! $tax->public ) {
                continue;
            }

            $terms = get_terms( array(
                'taxonomy'   => $tax->name,
                'hide_empty' => true,
            ) );

            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }

            $term_list = array();
            foreach ( $terms as $term ) {
                $term_list[] = array(
                    'id'    => $term->term_id,
                    'name'  => $term->name,
                    'slug'  => $term->slug,
                    'count' => $term->count,
                );
            }

            $result[ $tax->name ] = array(
                'name'  => $tax->name,
                'label' => $tax->labels->name,
                'terms' => $term_list,
            );
        }

        return $result;
    }

    /**
     * Search posts
     *
     * @param string $search      Search term.
     * @param array  $exclude_ids Exclude IDs.
     * @param int    $limit       Limit.
     * @return array
     */
    public static function search_posts( $search = '', $exclude_ids = array(), $limit = 20 ) {
        $args = array(
            'post_type'      => get_post_types( array( 'public' => true ) ),
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'relevance',
            'order'          => 'DESC',
        );

        if ( $search ) {
            $args['s'] = $search;
        }
        if ( $exclude_ids ) {
            $args['post__not_in'] = array_map( 'absint', $exclude_ids );
        }

        $posts  = get_posts( $args );
        $result = array();

        foreach ( $posts as $post ) {
            $result[] = array(
                'id'    => $post->ID,
                'title' => get_the_title( $post ),
                'type'  => get_post_type_object( $post->post_type )->labels->singular_name,
                'url'   => get_permalink( $post ),
            );
        }

        return $result;
    }

    /**
     * Detect SEO plugin
     *
     * @return array|false
     */
    public static function detect_seo_plugin() {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach ( self::$seo_plugins as $slug => $plugin ) {
            if ( is_plugin_active( $plugin['file'] ) ) {
                return array(
                    'slug' => $slug,
                    'name' => $plugin['name'],
                );
            }
        }

        return false;
    }

    /**
     * Check if NoIndexer plugin is active
     *
     * @return bool
     */
    public static function is_noindexer_active() {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active( 'noindexer/noindexer.php' );
    }

    /**
     * Detect NoIndexer plugin for UI display
     *
     * @return array|false Plugin info array or false.
     */
    public static function detect_noindexer() {
        if ( self::is_noindexer_active() ) {
            return array(
                'slug' => 'noindexer',
                'name' => 'NoIndexer',
            );
        }

        return false;
    }

    /**
     * Check if post is noindex
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    public static function is_post_noindex( $post_id ) {
        // NoIndexer plugin (AyudaWP) - check in parallel to any SEO plugin.
        // Use static method if available (handles bulk rules + exclusions).
        // Fall back to direct meta check when class is not loaded (e.g. admin/AJAX context).
        if ( class_exists( 'Noindexer_Frontend' ) ) {
            if ( Noindexer_Frontend::is_noindex( $post_id ) ) {
                return true;
            }
        } elseif ( self::is_noindexer_active() ) {
            if ( get_post_meta( $post_id, '_noindexer_noindex', true ) ) {
                return true;
            }
        }

        $seo = self::detect_seo_plugin();
        if ( ! $seo ) {
            return false;
        }

        $config = self::$seo_plugins[ $seo['slug'] ];
        $meta   = get_post_meta( $post_id, $config['meta_key'], true );

        if ( empty( $meta ) ) {
            return false;
        }

        if ( ! empty( $config['is_array'] ) ) {
            if ( ! is_array( $meta ) ) {
                // Decode without allowing object instantiation (guards against PHP object injection).
                $meta = is_serialized( $meta ) ? unserialize( $meta, array( 'allowed_classes' => false ) ) : $meta;
            }
            return is_array( $meta ) && in_array( $config['noindex'], $meta, true );
        }

        return $meta === $config['noindex'];
    }

    /**
     * Check URL pattern match
     *
     * @param string $url      URL.
     * @param array  $patterns Patterns.
     * @return bool
     */
    public static function matches_exclude_pattern( $url, $patterns ) {
        if ( empty( $patterns ) ) {
            return false;
        }

        $path = wp_parse_url( $url, PHP_URL_PATH ) ?: '/';

        foreach ( $patterns as $pattern ) {
            $pattern = trim( $pattern );
            if ( empty( $pattern ) ) {
                continue;
            }

            $regex = str_replace( array( '*', '?' ), array( '.*', '.' ), preg_quote( $pattern, '/' ) );

            if ( preg_match( '/^' . $regex . '$/i', $path ) || preg_match( '/^' . $regex . '$/i', $url ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get final post IDs
     *
     * @param array $settings Settings.
     * @return array
     */
    public static function get_final_post_ids( $settings ) {
        $post_ids = array();

        // From post types.
        if ( ! empty( $settings['post_types'] ) && is_array( $settings['post_types'] ) ) {
            foreach ( $settings['post_types'] as $post_type ) {
                $args = array(
                    'post_type'      => sanitize_key( $post_type ),
                    'post_status'    => 'publish',
                    'has_password'   => false,
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                );

                // Get taxonomies that actually belong to this post type.
                $valid_taxonomies = get_object_taxonomies( $post_type );

                // Taxonomy filters - only apply if taxonomy belongs to this post type.
                if ( ! empty( $settings['taxonomy_filters'][ $post_type ] ) ) {
                    $tax_query = array( 'relation' => 'AND' );

                    foreach ( $settings['taxonomy_filters'][ $post_type ] as $tax => $terms ) {
                        // Skip if this taxonomy doesn't belong to this post type.
                        if ( ! in_array( $tax, $valid_taxonomies, true ) ) {
                            continue;
                        }

                        if ( ! empty( $terms ) && is_array( $terms ) ) {
                            $tax_query[] = array(
                                'taxonomy' => sanitize_key( $tax ),
                                'field'    => 'term_id',
                                'terms'    => array_map( 'absint', $terms ),
                            );
                        }
                    }

                    if ( count( $tax_query ) > 1 ) {
                        $args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                    }
                }

                $ids      = get_posts( $args );
                $post_ids = array_merge( $post_ids, $ids );
            }
        }

        // Manual includes.
        if ( ! empty( $settings['manual_includes'] ) && is_array( $settings['manual_includes'] ) ) {
            $post_ids = array_merge( $post_ids, array_map( 'absint', $settings['manual_includes'] ) );
        }

        $post_ids = array_unique( $post_ids );

        // Manual excludes.
        if ( ! empty( $settings['manual_excludes'] ) && is_array( $settings['manual_excludes'] ) ) {
            $post_ids = array_diff( $post_ids, array_map( 'absint', $settings['manual_excludes'] ) );
        }

        // Pattern excludes.
        $patterns = array();
        if ( ! empty( $settings['exclude_patterns'] ) ) {
            $patterns = array_filter( array_map( 'trim', explode( "\n", $settings['exclude_patterns'] ) ) );
        }

        // Filter.
        $filtered = array();
        foreach ( $post_ids as $id ) {
            $post = get_post( $id );
            if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
                continue;
            }

            if ( ! empty( $settings['exclude_noindex'] ) && self::is_post_noindex( $id ) ) {
                continue;
            }

            if ( self::matches_exclude_pattern( get_permalink( $id ), $patterns ) ) {
                continue;
            }

            $filtered[] = $id;
        }

        return $filtered;
    }

    /**
     * Generate llms.txt content
     *
     * @param array $settings Settings.
     * @param array $post_ids Post IDs.
     * @return string
     */
    private static function generate_llms_txt( $settings, $post_ids ) {
        $name = $settings['site_name'] ?: get_bloginfo( 'name' );
        $desc = $settings['site_description'] ?: '';

        $content = "# {$name}\n\n";
        if ( $desc ) {
            $content .= "> {$desc}\n\n";
        }

        // Group by type.
        $by_type = array();
        foreach ( $post_ids as $id ) {
            $post = get_post( $id );
            if ( ! $post ) {
                continue;
            }

            $type_obj = get_post_type_object( $post->post_type );
            $label    = $type_obj ? $type_obj->labels->name : ucfirst( $post->post_type );

            if ( ! isset( $by_type[ $label ] ) ) {
                $by_type[ $label ] = array();
            }
            $by_type[ $label ][] = $post;
        }

        foreach ( $by_type as $label => $posts ) {
            $content .= "## {$label}\n\n";

            foreach ( $posts as $post ) {
                $title   = get_the_title( $post );
                $url     = get_permalink( $post );
                $excerpt = self::get_clean_excerpt( $post );

                $content .= "- [{$title}]({$url})";
                if ( $excerpt ) {
                    $content .= ": {$excerpt}";
                }
                $content .= "\n";
            }
            $content .= "\n";
        }

        if ( $settings['generate_full'] ) {
            $content .= "## Full content\n\n";
            $content .= "For complete content, see [llms-full.txt](" . home_url( '/llms-full.txt' ) . ")\n";
        }

        return $content;
    }

    /**
     * Generate llms-full.txt content
     *
     * @param array $settings Settings.
     * @param array $post_ids Post IDs.
     * @return string
     */
    private static function generate_llms_full_txt( $settings, $post_ids ) {
        $name = $settings['site_name'] ?: get_bloginfo( 'name' );
        $desc = $settings['site_description'] ?: '';

        $content = "# {$name} - Full Content\n\n";
        if ( $desc ) {
            $content .= "> {$desc}\n\n";
        }
        $content .= "---\n\n";

        foreach ( $post_ids as $id ) {
            $post = get_post( $id );
            if ( ! $post ) {
                continue;
            }

            $content .= "## " . get_the_title( $post ) . "\n\n";
            $content .= "URL: " . get_permalink( $post ) . "\n\n";

            if ( 'excerpt' === $settings['full_mode'] ) {
                $content .= self::get_clean_excerpt( $post, 500 );
            } else {
                $content .= self::get_clean_content( $post );
            }

            $content .= "\n\n---\n\n";
        }

        return $content;
    }

    /**
     * Get clean excerpt
     *
     * Uses post excerpt if available, otherwise extracts from content
     * after processing shortcodes from page builders.
     *
     * @param WP_Post $the_post Post object.
     * @param int     $length   Length.
     * @return string
     */
    private static function get_clean_excerpt( $the_post, $length = 160 ) {
        if ( ! empty( $the_post->post_excerpt ) ) {
            $excerpt = $the_post->post_excerpt;
        } else {
            // Process shortcodes first for page builders.
            $content          = $the_post->post_content;
            $original_content = $content;

            // Save current global post and set up new one for shortcode context.
            // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            global $post;
            $original_post   = $post;
            $GLOBALS['post'] = $the_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            setup_postdata( $the_post );

            // Execute shortcodes.
            $content = do_shortcode( $content );

            // Restore original post.
            if ( isset( $original_post ) ) {
                $GLOBALS['post'] = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
                setup_postdata( $original_post );
            } else {
                wp_reset_postdata();
            }

            // Check if ANY shortcodes remain unprocessed.
            $has_unprocessed_shortcodes = preg_match( '/\[[a-z][a-z0-9_-]*[\s\]]/i', $content );

            if ( $has_unprocessed_shortcodes ) {
                // Use fallback: extract text from shortcodes manually.
                $content = self::extract_text_from_shortcodes( $original_content );
            }

            // Remove remaining shortcodes and strip tags.
            $excerpt = strip_shortcodes( $content );
            $excerpt = wp_strip_all_tags( $excerpt );

            // Final cleanup: remove any shortcode-like patterns that might remain.
            $excerpt = preg_replace( '/\[[a-z][a-z0-9_-]*[^\]]*\]/is', '', $excerpt );
            $excerpt = preg_replace( '/\[\/[a-z][a-z0-9_-]*\]/is', '', $excerpt );
        }

        $excerpt = preg_replace( '/\s+/', ' ', trim( $excerpt ) );

        if ( strlen( $excerpt ) > $length ) {
            $excerpt = substr( $excerpt, 0, $length );
            $excerpt = substr( $excerpt, 0, strrpos( $excerpt, ' ' ) ) . '...';
        }

        return $excerpt;
    }

    /**
     * Extract text from unprocessed page builder shortcodes
     *
     * When page builder shortcodes cannot be rendered (e.g., running in admin context),
     * this method extracts the text content from within the shortcode tags.
     * Supports Divi, Elementor, WPBakery, Beaver Builder, Avada/Fusion, and other common builders.
     *
     * Uses a multi-pass approach:
     * 1. First, extract content from known "text content" shortcodes
     * 2. Then, remove all structural/container shortcodes (keep nested content)
     * 3. Finally, clean up any remaining shortcode artifacts
     *
     * @param string $content Content with unprocessed shortcodes.
     * @return string Extracted text content.
     */
    private static function extract_text_from_shortcodes( $content ) {
        // =====================================================================
        // PASS 0: Pre-process - Handle complex Divi attributes.
        // Divi uses JSON-like attributes that can break regex parsing.
        // Remove these problematic attributes first.
        // =====================================================================

        // Remove global_colors_info and similar JSON attributes that break parsing.
        $content = preg_replace( '/\s+global_colors_info="[^"]*"/i', '', $content );
        $content = preg_replace( '/\s+_builder_version="[^"]*"/i', '', $content );
        $content = preg_replace( '/\s+custom_css_[a-z_]+="[^"]*"/i', '', $content );
        $content = preg_replace( '/\s+hover_enabled="[^"]*"/i', '', $content );
        $content = preg_replace( '/\s+sticky_enabled="[^"]*"/i', '', $content );

        // =====================================================================
        // PASS 0.5: Remove shortcodes from common plugins that don't provide useful text.
        // These are functional shortcodes (forms, tables, galleries, etc.) that
        // don't contribute meaningful content to llms.txt.
        // =====================================================================

        // Contact forms.
        $content = preg_replace( '/\[contact-form-7[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[wpforms[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[gravityform[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[formidable[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[ninja_form[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[fluentform[^\]]*\]/is', '', $content );

        // Tables.
        $content = preg_replace( '/\[tableon[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[posts_table[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[table[^\]]*\].*?\[\/table\]/is', '', $content );
        $content = preg_replace( '/\[tablepress[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[supsystic-tables[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[wpdatatable[^\]]*\]/is', '', $content );

        // Galleries and media.
        $content = preg_replace( '/\[gallery[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[envira-gallery[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[ngg[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[foogallery[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[video[^\]]*\].*?\[\/video\]/is', '', $content );
        $content = preg_replace( '/\[audio[^\]]*\].*?\[\/audio\]/is', '', $content );
        $content = preg_replace( '/\[playlist[^\]]*\]/is', '', $content );

        // Sliders and carousels.
        $content = preg_replace( '/\[rev_slider[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[smartslider3[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[metaslider[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[soliloquy[^\]]*\]/is', '', $content );

        // Maps.
        $content = preg_replace( '/\[wpgmza[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[google-map[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[maps-marker[^\]]*\]/is', '', $content );

        // Social and embeds.
        $content = preg_replace( '/\[embed[^\]]*\].*?\[\/embed\]/is', '', $content );
        $content = preg_replace( '/\[instagram-feed[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[twitter-feed[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[facebook-feed[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[youtube[^\]]*\]/is', '', $content );

        // WooCommerce (functional shortcodes, not content).
        $content = preg_replace( '/\[product[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[products[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[add_to_cart[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[woocommerce_[^\]]*\]/is', '', $content );

        // Other common plugins.
        $content = preg_replace( '/\[vc_[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[caption[^\]]*\].*?\[\/caption\]/is', '$1', $content );
        $content = preg_replace( '/\[su_[^\]]*\].*?\[\/su_[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[su_[^\]]*\]/is', '', $content );

        // Pattern for matching shortcode attributes (now simplified after cleanup).
        // Matches: attribute="value" or attribute='value' or just attribute.
        $attr_pattern = '[^\]]*';

        // =====================================================================
        // PASS 1: Extract content from known TEXT CONTAINER shortcodes.
        // These are shortcodes that typically contain actual visible text content.
        // Process these first to preserve their text before removing containers.
        // =====================================================================

        // Divi Builder: [et_pb_text]content[/et_pb_text].
        $content = preg_replace(
            '/\[et_pb_text' . $attr_pattern . '\](.*?)\[\/et_pb_text\]/is',
            "\n$1\n",
            $content
        );

        // Divi Builder: [et_pb_code]content[/et_pb_code] - may contain HTML/text.
        $content = preg_replace(
            '/\[et_pb_code' . $attr_pattern . '\](.*?)\[\/et_pb_code\]/is',
            "\n$1\n",
            $content
        );

        // Divi Builder: [et_pb_blurb] - extracts title and content.
        $content = preg_replace(
            '/\[et_pb_blurb' . $attr_pattern . '\](.*?)\[\/et_pb_blurb\]/is',
            "\n$1\n",
            $content
        );

        // Divi Builder: [et_pb_accordion_item] - FAQ items.
        $content = preg_replace(
            '/\[et_pb_accordion_item' . $attr_pattern . '\](.*?)\[\/et_pb_accordion_item\]/is',
            "\n$1\n",
            $content
        );

        // Divi Builder: [et_pb_tab] - Tab content.
        $content = preg_replace(
            '/\[et_pb_tab' . $attr_pattern . '\](.*?)\[\/et_pb_tab\]/is',
            "\n$1\n",
            $content
        );

        // Divi Builder: [et_pb_toggle] - Toggle content.
        $content = preg_replace(
            '/\[et_pb_toggle' . $attr_pattern . '\](.*?)\[\/et_pb_toggle\]/is',
            "\n$1\n",
            $content
        );

        // Divi Builder: [et_pb_slide] - Slider content.
        $content = preg_replace(
            '/\[et_pb_slide' . $attr_pattern . '\](.*?)\[\/et_pb_slide\]/is',
            "\n$1\n",
            $content
        );

        // Divi Builder: [et_pb_cta] - Call to action (has heading/button_text in attrs).
        $content = preg_replace(
            '/\[et_pb_cta' . $attr_pattern . '\](.*?)\[\/et_pb_cta\]/is',
            "\n$1\n",
            $content
        );

        // WPBakery: [vc_column_text]content[/vc_column_text].
        $content = preg_replace(
            '/\[vc_column_text' . $attr_pattern . '\](.*?)\[\/vc_column_text\]/is',
            "\n$1\n",
            $content
        );

        // WPBakery: [vc_raw_html]content[/vc_raw_html].
        $content = preg_replace(
            '/\[vc_raw_html' . $attr_pattern . '\](.*?)\[\/vc_raw_html\]/is',
            "\n$1\n",
            $content
        );

        // Avada/Fusion: [fusion_text]content[/fusion_text].
        $content = preg_replace(
            '/\[fusion_text' . $attr_pattern . '\](.*?)\[\/fusion_text\]/is',
            "\n$1\n",
            $content
        );

        // Avada/Fusion: [fusion_code]content[/fusion_code].
        $content = preg_replace(
            '/\[fusion_code' . $attr_pattern . '\](.*?)\[\/fusion_code\]/is',
            "\n$1\n",
            $content
        );

        // Themify: [themify_text]content[/themify_text].
        $content = preg_replace(
            '/\[themify_text' . $attr_pattern . '\](.*?)\[\/themify_text\]/is',
            "\n$1\n",
            $content
        );

        // Generic text containers used by various builders.
        $content = preg_replace(
            '/\[(text|content|column_text|raw_content)' . $attr_pattern . '\](.*?)\[\/\1\]/is',
            "\n$2\n",
            $content
        );

        // =====================================================================
        // PASS 2: Remove STRUCTURAL/CONTAINER shortcodes (keep their content).
        // These are layout shortcodes that wrap other shortcodes or content.
        // We remove the tags but keep what's between them.
        // =====================================================================

        // Divi Builder structural shortcodes (et_pb_*).
        // Remove opening tags with any attributes.
        $content = preg_replace(
            '/\[et_pb_[a-z0-9_]+' . $attr_pattern . '\]/is',
            '',
            $content
        );
        // Remove closing tags.
        $content = preg_replace(
            '/\[\/et_pb_[a-z0-9_]+\]/is',
            '',
            $content
        );

        // WPBakery/Visual Composer structural shortcodes (vc_*).
        $content = preg_replace(
            '/\[vc_[a-z0-9_]+' . $attr_pattern . '\]/is',
            '',
            $content
        );
        $content = preg_replace(
            '/\[\/vc_[a-z0-9_]+\]/is',
            '',
            $content
        );

        // Avada/Fusion Builder structural shortcodes (fusion_*).
        $content = preg_replace(
            '/\[fusion_[a-z0-9_]+' . $attr_pattern . '\]/is',
            '',
            $content
        );
        $content = preg_replace(
            '/\[\/fusion_[a-z0-9_]+\]/is',
            '',
            $content
        );

        // Themify Builder shortcodes.
        $content = preg_replace(
            '/\[themify_[a-z0-9_]+' . $attr_pattern . '\]/is',
            '',
            $content
        );
        $content = preg_replace(
            '/\[\/themify_[a-z0-9_]+\]/is',
            '',
            $content
        );

        // Beaver Builder shortcodes (fl_*).
        $content = preg_replace(
            '/\[fl_[a-z0-9_]+' . $attr_pattern . '\]/is',
            '',
            $content
        );
        $content = preg_replace(
            '/\[\/fl_[a-z0-9_]+\]/is',
            '',
            $content
        );

        // Elementor shortcodes and template references.
        $content = preg_replace(
            '/\[elementor[a-z0-9_-]*' . $attr_pattern . '\]/is',
            '',
            $content
        );
        $content = preg_replace(
            '/\[\/elementor[a-z0-9_-]*\]/is',
            '',
            $content
        );

        // Oxygen Builder.
        $content = preg_replace(
            '/\[oxygen[a-z0-9_-]*' . $attr_pattern . '\]/is',
            '',
            $content
        );
        $content = preg_replace(
            '/\[\/oxygen[a-z0-9_-]*\]/is',
            '',
            $content
        );

        // Brizy Builder.
        $content = preg_replace(
            '/\[brizy[a-z0-9_-]*' . $attr_pattern . '\]/is',
            '',
            $content
        );
        $content = preg_replace(
            '/\[\/brizy[a-z0-9_-]*\]/is',
            '',
            $content
        );

        // =====================================================================
        // PASS 3: Clean up any remaining shortcode artifacts.
        // =====================================================================

        // Remove any leftover shortcodes that look like page builder shortcodes.
        // Pattern matches [anything_with_underscores ...] or [/anything_with_underscores].
        $content = preg_replace(
            '/\[\/?[a-z]+_[a-z0-9_]+' . $attr_pattern . '\]/is',
            '',
            $content
        );

        // =====================================================================
        // PASS 4: Aggressive fallback - remove ANY remaining shortcode-like patterns.
        // This catches edge cases where complex attributes broke earlier patterns.
        // =====================================================================

        // Remove any remaining opening shortcode tags with attributes.
        // Pattern includes letters, numbers, underscores, and hyphens in shortcode names.
        $content = preg_replace( '/\[[a-z_][a-z0-9_-]*\s+[^\]]+\]/is', '', $content );

        // Remove any remaining self-closing or simple shortcodes (with hyphens support).
        $content = preg_replace( '/\[\/?[a-z_][a-z0-9_-]*\]/is', '', $content );

        // Final nuclear option: remove ANYTHING that looks like a shortcode.
        // Matches [word...] or [word ...] patterns that might have been missed.
        $content = preg_replace( '/\[[a-z][a-z0-9_-]*[^\]]*\]/is', '', $content );

        // Also remove closing tags that might be orphaned.
        $content = preg_replace( '/\[\/[a-z][a-z0-9_-]*\]/is', '', $content );

        // Clean up excessive whitespace from removed shortcodes.
        $content = preg_replace( '/\n{3,}/', "\n\n", $content );
        $content = preg_replace( '/[ \t]+/', ' ', $content );

        return trim( $content );
    }

    /**
     * Get clean content
     *
     * Processes post content, rendering shortcodes from page builders
     * (Divi, Elementor, WPBakery, etc.) and converting HTML to markdown-like text.
     *
     * @param WP_Post $the_post Post object.
     * @return string
     */
    private static function get_clean_content( $the_post ) {
        $content          = $the_post->post_content;
        $original_content = $content;

        // Save current global post and set up new one for shortcode context.
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        global $post;
        $original_post   = $post;
        $GLOBALS['post'] = $the_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        setup_postdata( $the_post );

        // First, execute all shortcodes explicitly.
        // This is crucial for page builders like Divi, Elementor, WPBakery, etc.
        $content = do_shortcode( $content );

        // Then apply the_content filters for any remaining processing.
        // Use a flag to prevent infinite loops if the_content calls do_shortcode again.
        remove_filter( 'the_content', 'do_shortcode', 11 );
        $content = apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        add_filter( 'the_content', 'do_shortcode', 11 );

        // Restore original post.
        if ( isset( $original_post ) ) {
            $GLOBALS['post'] = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            setup_postdata( $original_post );
        } else {
            wp_reset_postdata();
        }

        // Check if ANY shortcodes remain unprocessed (page builders, plugins, etc.).
        // This happens when running in admin/AJAX context where plugins don't register shortcodes.
        $has_unprocessed_shortcodes = preg_match( '/\[[a-z][a-z0-9_-]*[\s\]]/i', $content );

        if ( $has_unprocessed_shortcodes ) {
            // Use fallback: extract text from shortcodes manually.
            $content = self::extract_text_from_shortcodes( $original_content );
        }

        // ALWAYS run strip_shortcodes as final safety net.
        $content = strip_shortcodes( $content );

        // Remove common page builder artifacts and empty divs/sections.
        $content = preg_replace( '/<(div|section|article|aside|header|footer|nav|main)[^>]*>\s*<\/\1>/is', '', $content );

        // HTML to markdown-like.
        $content = preg_replace( '/<h1[^>]*>(.*?)<\/h1>/is', "# $1\n\n", $content );
        $content = preg_replace( '/<h2[^>]*>(.*?)<\/h2>/is', "## $1\n\n", $content );
        $content = preg_replace( '/<h3[^>]*>(.*?)<\/h3>/is', "### $1\n\n", $content );
        $content = preg_replace( '/<h[4-6][^>]*>(.*?)<\/h[4-6]>/is', "#### $1\n\n", $content );
        $content = preg_replace( '/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $content );
        $content = preg_replace( '/<li[^>]*>(.*?)<\/li>/is', "- $1\n", $content );
        $content = preg_replace( '/<\/?[ou]l[^>]*>/is', "\n", $content );
        $content = preg_replace( '/<a[^>]*>(.*?)<\/a>/is', '$1', $content );
        $content = preg_replace( '/<(strong|b)[^>]*>(.*?)<\/(strong|b)>/is', '**$2**', $content );
        $content = preg_replace( '/<(em|i)[^>]*>(.*?)<\/(em|i)>/is', '*$2*', $content );
        $content = wp_strip_all_tags( $content );
        $content = preg_replace( '/\n{3,}/', "\n\n", $content );
        $content = preg_replace( '/[ \t]+/', ' ', $content );

        // Final cleanup: remove any shortcode-like patterns that might remain after all processing.
        $content = preg_replace( '/\[[a-z][a-z0-9_-]*[^\]]*\]/is', '', $content );
        $content = preg_replace( '/\[\/[a-z][a-z0-9_-]*\]/is', '', $content );

        return trim( $content );
    }

    /**
     * Write file with UTF-8 BOM for proper encoding detection
     *
     * @param string $filename Filename.
     * @param string $content  Content.
     * @return bool|WP_Error
     */
    private static function write_file( $filename, $content ) {
        global $wp_filesystem;

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if ( ! WP_Filesystem() ) {
            return new WP_Error( 'filesystem_error', __( 'Could not initialize WordPress filesystem.', 'vigia' ) );
        }

        $path = ABSPATH . $filename;

        if ( $wp_filesystem->exists( $path ) && ! $wp_filesystem->is_writable( $path ) ) {
            /* translators: %s: filename (e.g., llms.txt or llms-full.txt) */
            return new WP_Error( 'file_not_writable', sprintf( __( 'Cannot write to %s.', 'vigia' ), $filename ) );
        }

        if ( ! $wp_filesystem->exists( $path ) && ! $wp_filesystem->is_writable( ABSPATH ) ) {
            return new WP_Error( 'dir_not_writable', __( 'Cannot write to site root.', 'vigia' ) );
        }

        // Add UTF-8 BOM for proper encoding detection by browsers and text editors.
        $utf8_bom = "\xEF\xBB\xBF";
        $content  = $utf8_bom . $content;

        if ( ! $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE ) ) {
            /* translators: %s: filename (e.g., llms.txt or llms-full.txt) */
            return new WP_Error( 'write_failed', sprintf( __( 'Failed to write %s.', 'vigia' ), $filename ) );
        }

        return true;
    }

    /**
     * Delete files
     *
     * @return bool
     */
    public static function delete_files() {
        return self::delete_file( 'llms.txt' ) && self::delete_file( 'llms-full.txt' );
    }

    /**
     * Delete single file
     *
     * @param string $filename Filename.
     * @return bool
     */
    public static function delete_file( $filename ) {
        if ( ! in_array( $filename, array( 'llms.txt', 'llms-full.txt' ), true ) ) {
            return false;
        }

        $path = ABSPATH . $filename;
        if ( ! file_exists( $path ) ) {
            return true;
        }

        return unlink( $path ); // phpcs:ignore
    }

    /**
     * Check llms.txt exists
     *
     * @return bool
     */
    public static function llms_exists() {
        return file_exists( ABSPATH . 'llms.txt' );
    }

    /**
     * Check llms-full.txt exists
     *
     * @return bool
     */
    public static function llms_full_exists() {
        return file_exists( ABSPATH . 'llms-full.txt' );
    }

    /**
     * Get file info
     *
     * @param string $filename Filename.
     * @return array|false
     */
    public static function get_file_info( $filename ) {
        $path = ABSPATH . $filename;
        if ( ! file_exists( $path ) ) {
            return false;
        }

        return array(
            'exists'   => true,
            'size'     => filesize( $path ),
            'modified' => filemtime( $path ),
            'url'      => home_url( '/' . $filename ),
        );
    }

    /**
     * Estimate content count
     *
     * @param array $post_types      Post types.
     * @param array $taxonomy_filters Filters.
     * @return int
     */
    public static function estimate_content_count( $post_types, $taxonomy_filters = array() ) {
        $total = 0;

        foreach ( $post_types as $pt ) {
            $args = array(
                'post_type'      => $pt,
                'post_status'    => 'publish',
                'has_password'   => false,
                'posts_per_page' => -1,
                'fields'         => 'ids',
            );

            if ( ! empty( $taxonomy_filters[ $pt ] ) ) {
                $tq = array( 'relation' => 'AND' );
                foreach ( $taxonomy_filters[ $pt ] as $tax => $terms ) {
                    if ( $terms ) {
                        $tq[] = array(
                            'taxonomy' => $tax,
                            'field'    => 'term_id',
                            'terms'    => array_map( 'absint', $terms ),
                        );
                    }
                }
                if ( count( $tq ) > 1 ) {
                    $args['tax_query'] = $tq; // phpcs:ignore
                }
            }

            $total += count( get_posts( $args ) );
        }

        return $total;
    }

    /**
     * Get formatted last generated
     *
     * @return string
     */
    public static function get_last_generated_formatted() {
        $settings = self::get_settings();

        if ( empty( $settings['last_generated'] ) ) {
            return __( 'Never', 'vigia' );
        }

        $ts   = $settings['last_generated'];
        $date = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
        $diff = human_time_diff( $ts, time() );

        /* translators: %s: human-readable time difference (e.g., "2 hours", "3 days") */
        return sprintf( '%s (%s)', $date, sprintf( __( '%s ago', 'vigia' ), $diff ) );
    }

    /**
     * Get next regeneration
     *
     * @return string
     */
    public static function get_next_regeneration() {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( ! $ts ) {
            return __( 'Not scheduled', 'vigia' );
        }
        return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
    }
}

add_action( 'init', array( 'VigIA_LLMS_Generator', 'init' ) );