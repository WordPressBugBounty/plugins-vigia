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
    // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The 'meta_key' entries below are array keys in a static map of SEO plugins, not query arguments. The only real use is a get_post_meta() call, which is not a slow query.
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
    // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key

    /**
     * Initialize cron schedules
     */
    public static function init() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'cron_regenerate' ) );
        add_filter( 'cron_schedules', array( __CLASS__, 'add_monthly_schedule' ) );

        // Advertise the llms.txt covering this site, the discovery mechanism the
        // spec settled on. It hangs off the llms feature and not off the Markdown
        // endpoints on purpose: the Markdown alternate is per page and exists only
        // where a .md does, while describedby covers every URL under the file's
        // path. Hanging it here means whoever actually serves llms.txt advertises
        // it, so with the Visibility sibling installed it is never emitted twice
        // (we stand down when it serves one) and never missing (we still emit it
        // when the sibling is installed with its llms module off, which is how it
        // ships).
        if ( ! is_admin() ) {
            add_action( 'wp_head', array( __CLASS__, 'link_tag' ), 5 );
            add_action( 'template_redirect', array( __CLASS__, 'link_header' ), 1 );
        }
    }

    /**
     * Is VigIA the one serving an llms.txt at the site root?
     *
     * Deliberately cheap: this runs on every front-end request, and get_settings()
     * is an uncached direct query. A stat on a path we already know is all it
     * takes, and it is only reached when we are not ceding.
     *
     * @return bool
     */
    public static function serves_llms() {
        if ( self::is_ceded_to_visibility() ) {
            return false;
        }

        return file_exists( ABSPATH . 'llms.txt' );
    }

    /**
     * `<link rel="describedby">` pointing at llms.txt, in the head of every
     * front-end view. Site-wide on purpose: an llms.txt describes every page under
     * its path, so an agent landing on any URL can find the index. Skipped on
     * 404s, which describe nothing.
     */
    public static function link_tag() {
        if ( is_404() || ! self::serves_llms() ) {
            return;
        }

        printf(
            '<link rel="describedby" href="%s" />' . "\n",
            esc_url( home_url( '/llms.txt' ) )
        );
    }

    /**
     * The same relation as a `Link:` header, for clients that never parse the HTML
     * head. The spec calls out the header form precisely because it also covers
     * non-HTML resources.
     */
    public static function link_header() {
        if ( is_404() || headers_sent() || ! self::serves_llms() ) {
            return;
        }

        header( 'Link: <' . esc_url_raw( home_url( '/llms.txt' ) ) . '>; rel="describedby"', false );
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

        // Defaults for empty values. Decoded, because core stores both already
        // escaped and these two end up in a plain text file. See site_name().
        if ( empty( $settings['site_name'] ) ) {
            $settings['site_name'] = self::site_name();
        }
        if ( empty( $settings['site_description'] ) ) {
            $settings['site_description'] = self::site_description();
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
        // Cede to the Visibility sibling when it serves llms.txt: stop writing our
        // physical file (it would shadow Visibility's virtual output) and remove
        // any of ours already on disk. See VigIA_Sibling_Visibility.
        if ( self::is_ceded_to_visibility() ) {
            self::cleanup_for_cession();
            return new WP_Error(
                'ceded_to_visibility',
                __( 'Visibility now manages llms.txt on this site. To use VigIA\'s generator instead, disable llms.txt in Visibility first.', 'vigia' )
            );
        }

        $settings = self::normalize_settings( $settings );

        // Both files are written to the site root and served by the web server to
        // everybody, but they are built by whoever pressed the button in wp-admin.
        // Build them as a logged-out visitor so what goes in is what a logged-out
        // visitor may read. See VigIA_Content_Access::begin_anonymous_context().
        // And in the site's language, not the admin's. Both files are one copy
        // served to everybody, but the section headings are post type labels and
        // the posts page summary is a translated string, so whoever pressed the
        // button would otherwise decide what language they come out in: an admin
        // reading wp-admin in English writes `## Posts` into a Spanish site's
        // llms.txt. switch_to_locale() returns false when the locale is already
        // the current one, which is the cron case.
        $switched = switch_to_locale( get_locale() );

        VigIA_Content_Access::begin_anonymous_context();

        try {
            return self::build_and_write( $settings );
        } finally {
            VigIA_Content_Access::end_anonymous_context();

            if ( $switched ) {
                restore_previous_locale();
            }
        }
    }

    /**
     * Build both files and write them out. Always called inside the anonymous
     * context set up by generate().
     *
     * @param array $settings Normalized settings.
     * @return array|WP_Error
     */
    private static function build_and_write( $settings ) {
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
     * Regenerate a single llms file from the saved settings, without touching
     * the other one. Backs the command palette's quick actions.
     *
     * @param string $which 'full' for llms-full.txt, anything else for llms.txt.
     * @return array|WP_Error Result with url/size/count, or a WP_Error.
     */
    public static function regenerate_file( $which ) {
        if ( self::is_ceded_to_visibility() ) {
            self::cleanup_for_cession();
            return new WP_Error(
                'ceded_to_visibility',
                __( 'Visibility now manages llms.txt on this site. To use VigIA\'s generator instead, disable llms.txt in Visibility first.', 'vigia' )
            );
        }

        $settings = self::normalize_settings( self::get_settings() );

        // Same reasoning as generate(), both for the anonymous context and for
        // the locale: built by an admin, read by everybody.
        $switched = switch_to_locale( get_locale() );

        VigIA_Content_Access::begin_anonymous_context();

        try {
            return self::rebuild_one( $settings, $which );
        } finally {
            VigIA_Content_Access::end_anonymous_context();

            if ( $switched ) {
                restore_previous_locale();
            }
        }
    }

    /**
     * Rebuild a single llms file. Always called inside the anonymous context set
     * up by regenerate_file().
     *
     * @param array  $settings Normalized settings.
     * @param string $which    'full' for llms-full.txt, anything else for llms.txt.
     * @return array|WP_Error
     */
    private static function rebuild_one( $settings, $which ) {
        $post_ids = self::get_final_post_ids( $settings );

        if ( empty( $post_ids ) ) {
            return new WP_Error(
                'no_content',
                __( 'No content selected. Configure llms.txt in VigIA > Extras > LLMs first.', 'vigia' )
            );
        }

        if ( 'full' === $which ) {
            if ( empty( $settings['generate_full'] ) ) {
                return new WP_Error(
                    'full_disabled',
                    __( 'llms-full.txt is not enabled. Turn it on in VigIA > Extras > LLMs first.', 'vigia' )
                );
            }
            $content = self::generate_llms_full_txt( $settings, $post_ids );
            $result  = self::write_file( 'llms-full.txt', $content );
            $url     = home_url( '/llms-full.txt' );
        } else {
            $content = self::generate_llms_txt( $settings, $post_ids );
            $result  = self::write_file( 'llms.txt', $content );
            $url     = home_url( '/llms.txt' );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return array(
            'url'   => $url,
            'size'  => strlen( $content ),
            'count' => count( $post_ids ),
        );
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
        // Visibility owns llms.txt now: don't regenerate, and drop our own
        // physical file so it stops shadowing Visibility's virtual output.
        if ( self::is_ceded_to_visibility() ) {
            self::cleanup_for_cession();
            return;
        }

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

        // The types these surfaces may serve at all: addressable on the front end
        // and not gated by an LMS or membership plugin. Reading the list from one
        // place keeps the checkboxes on offer and the entries actually published
        // from drifting apart. See VigIA_Content_Access::servable_post_types().
        $servable = VigIA_Content_Access::servable_post_types();

        foreach ( $post_types as $pt ) {
            if ( 'attachment' === $pt->name ) {
                continue;
            }

            if ( ! in_array( $pt->name, $servable, true ) ) {
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
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Small, manually-curated exclusion list in an admin-only post picker; never a large set.
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
            if ( ! $post ) {
                continue;
            }

            // Status, password, and whatever the LMS and membership plugins on
            // this site have to say about the entry. These files are written to
            // the site root and served by the web server without WordPress ever
            // running, so anything that gets in here is public to everyone, for
            // as long as the file lives. A per-post "include in llms.txt" flag is
            // not consent to publish content the visitor cannot read.
            if ( ! VigIA_Content_Access::is_public( $post )
                || VigIA_Content_Access::is_gated_type( $post->post_type ) ) {
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
        $name = $settings['site_name'] ?: self::site_name();
        $desc = $settings['site_description'] ?: '';

        $content = '# ' . self::one_line( self::decode_entities( $name ) ) . "\n\n";

        // Collapsed to one line: the summary is a single blockquote, and a stored
        // description containing a line break (sanitize_textarea_field keeps them)
        // used to prefix only its first line with `>`, cutting the quote short and
        // spilling the rest into the body as loose text.
        $desc = self::one_line( self::decode_entities( $desc ) );
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
                $title   = self::one_line( self::decode_entities( get_the_title( $post ) ) );
                $url     = self::entry_url( $post );
                $excerpt = self::get_clean_excerpt( $post );

                // The posts page has no body of its own: WordPress shows the blog
                // loop there and never renders its content, so get_clean_excerpt()
                // comes back empty and the entry would be a bare link, the only
                // one in the file with neither summary nor `.md`. Say what it is
                // instead. Same page the Markdown module refuses to serve, see
                // class-markdown-endpoints.php:627.
                if ( '' === $excerpt && self::is_posts_page( $post ) ) {
                    $excerpt = __( 'Index of the blog posts published on this site.', 'vigia' );
                }

                $content .= "- [{$title}]({$url})";
                if ( $excerpt ) {
                    $content .= ": {$excerpt}";
                }
                $content .= "\n";
            }
            $content .= "\n";
        }

        if ( $settings['generate_full'] ) {
            // "Optional" is a section name the spec defines, the convention for
            // secondary links an agent may skip, and it goes last. Never
            // translated: a localised heading stops being the name the convention
            // refers to. llms-full.txt sits here rather than in a section of its
            // own because it is not part of the spec at all, and it is written as
            // a list item because the spec defines an H2 section as a list of
            // links, not as prose.
            $content .= "## Optional\n\n";
            $content .= '- [llms-full.txt](' . home_url( '/llms-full.txt' ) . "): Full text of every page listed above, in a single file.\n";
        }

        return $content;
    }

    /**
     * Collapse all whitespace, line breaks included, to single spaces.
     *
     * @param string $text Raw text.
     * @return string
     */
    private static function one_line( $text ) {
        return trim( (string) preg_replace( '/\s+/', ' ', (string) $text ) );
    }

    /**
     * Decode HTML entities in a piece of text, then strip tags again.
     *
     * Everything this class writes is plain text read by a model, so an entity
     * is not a character it resolves: `Bull&#038;Bear` arrives with the code
     * inside. Titles need this more than anything else, because `get_the_title()`
     * runs the `the_title` filter and `convert_chars()` there turns a plain `&`
     * into `&#038;` (`wp-includes/formatting.php`), which is how a title typed
     * with an ampersand ends up encoded in a file nobody renders as HTML.
     *
     * Stripping afterwards is not redundant: an entity-encoded `&lt;script&gt;`
     * survives a strip untouched, and decoding alone would put a real tag back
     * into the document. Twin of class-markdown-endpoints.php:1777, kept here so
     * the generator does not depend on a module that can be switched off.
     *
     * @param string $text Raw text.
     * @return string
     */
    private static function decode_entities( $text ) {
        return self::remove_tag_shapes( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    }

    /**
     * Take out everything shaped like an HTML tag, leaving a lone `<` alone.
     *
     * Not wp_strip_all_tags(): strip_tags() drops everything from a `<` that
     * never finds its `>`, so a decoded `5<10` or `<5 minutes` swallows the rest
     * of the line without a word of warning, and the entity at least used to
     * survive as text.
     *
     * To a fixed point, and this is the part that bites: one pass can weld two
     * leftovers into a new tag. `<scr<b></b>ipt>` loses the `<b></b>` and becomes
     * a working `<script>`, and `<i<b></b>mg src=x on<b></b>error=1>` rebuilds a
     * live `<img onerror>` out of something strip_tags() emptied. The
     * quoted-attribute alternatives keep a `>` inside an attribute from closing
     * the match early. Measured on adversarial input of several MB (a million
     * unclosed `<`, 100.000 tags, an unterminated comment): under 4 ms, no
     * backtracking.
     *
     * Twin of VigIA_Markdown_Endpoints::remove_tag_shapes(), same name on
     * purpose. Both findings, and the reconstruction the first fix introduced,
     * come from the two cross-review rounds of 2.6.5.
     *
     * @param string $text Text that may carry decoded markup.
     * @return string
     */
    private static function remove_tag_shapes( $text ) {
        $text = (string) $text;

        for ( $pass = 0; $pass < 10; $pass++ ) {
            $before = $text;
            $text   = (string) preg_replace( '#<!--.*?-->#s', '', $text );
            $text   = (string) preg_replace( '#</?[a-z](?:[^<>"\']|"[^"]*"|\'[^\']*\')*>#i', '', $text );

            if ( $text === $before ) {
                break;
            }
        }

        return $text;
    }

    /**
     * The site name as the owner typed it.
     *
     * `get_bloginfo( 'name' )` returns it already escaped, because
     * `sanitize_option()` runs `esc_html()` over `blogname` before storing it.
     * A browser resolves that; a file of plain text does not, so the heading
     * would read `# Mus&eacute;e d&#039;Impressionnisme`. Core's own recipe, with
     * the second argument that is easy to miss: the default `ENT_NOQUOTES`
     * leaves `&#039;` and `&quot;` intact, which is the reported case.
     *
     * @return string
     */
    public static function site_name() {
        return vigia_get_site_name();
    }

    /**
     * The site tagline, decoded for the same reason as site_name().
     *
     * `blogdescription` goes through the same `esc_html()` in
     * `sanitize_option()`, so it comes back escaped too.
     *
     * @return string
     */
    public static function site_description() {
        return vigia_get_site_description();
    }

    /**
     * Is this the page assigned as the posts page in Settings > Reading?
     *
     * Same test as VigIA_Markdown_Endpoints::is_posts_page(), which refuses to
     * serve a `.md` for it (class-markdown-endpoints.php:627). It is an archive,
     * not a document: WordPress shows the blog loop there and never renders its
     * own content, which on most installs is empty.
     *
     * @param WP_Post $the_post Post object.
     * @return bool
     */
    private static function is_posts_page( $the_post ) {
        if ( 'page' !== get_option( 'show_on_front' ) ) {
            return false;
        }

        $posts_page_id = (int) get_option( 'page_for_posts' );

        return $posts_page_id > 0 && $posts_page_id === (int) $the_post->ID;
    }

    /**
     * Does this text already end in punctuation that joins the next block?
     *
     * Takes the block, never the line built so far: matching with `/u` over a
     * growing subject revalidates the whole string every time, which turns
     * blocks_to_line() quadratic. Measured on 20.000 blocks: 9.9 seconds against
     * the line, under 10 ms against the block. Found in the cross review of 2.6.5.
     *
     * @param string $text Text to test.
     * @return bool
     */
    private static function ends_in_punctuation( $text ) {
        $last = mb_substr( (string) $text, -1, 1, 'UTF-8' );

        return '' !== $last && 1 === preg_match( '/^[.!?:;,\x{2026}\x{00BB}\x{201D}\x{2019})\]"\']$/u', $last );
    }

    /**
     * Flatten HTML to one line of prose, keeping the block boundaries.
     *
     * `wp_strip_all_tags()` on its own welds the blocks together: a heading and
     * the paragraph under it become `Politica editorial de capitancapo
     * capitancapo es un proyecto`, two sentences with nothing between them. The
     * markup carried that boundary and the strip threw it away, so we turn each
     * closing block tag into the full stop it was standing in for, unless the
     * block already ends in punctuation of its own.
     *
     * A newline would not survive: these summaries are a single line in llms.txt
     * and in the `.md` frontmatter, so whatever separates two blocks has to be
     * something that reads correctly inside a sentence.
     *
     * @param string $html Raw or rendered HTML.
     * @return string
     */
    private static function blocks_to_line( $html ) {
        $html = (string) $html;

        // Every boundary the markup draws becomes a newline first, so the split
        // below sees the same thing whether the break was a closing tag or a
        // line break the author typed.
        $html = preg_replace( '#<(?:br|hr)\b[^>]*>#i', "\n", $html );
        $html = preg_replace(
            '#</(?:p|div|section|article|aside|header|footer|main|nav|h[1-6]|li|ul|ol|dl|dt|dd|blockquote|pre|figure|figcaption|table|thead|tbody|tfoot|tr|td|th|address|form|fieldset|details|summary)\s*>#i',
            "\n",
            (string) $html
        );

        $text = wp_strip_all_tags( (string) $html );

        // $previous carries the block just appended, so the punctuation test
        // reads one short string instead of the whole line built so far. Testing
        // $line is quadratic (the /u match revalidates the entire subject every
        // time): on 20.000 blocks it went from 9.9 seconds to under 10 ms.
        $line     = '';
        $previous = '';

        foreach ( preg_split( '/\R+/', $text ) as $block ) {
            $block = self::one_line( $block );
            if ( '' === $block ) {
                continue;
            }

            if ( '' === $line ) {
                $line     = $block;
                $previous = $block;
                continue;
            }

            // Sentence-ending punctuation, a closing quote or bracket, or a
            // comma: all of them already join the next block readably.
            $line    .= ( self::ends_in_punctuation( $previous ) ? ' ' : '. ' ) . $block;
            $previous = $block;
        }

        return $line;
    }

    /**
     * The URL to publish for an entry: its Markdown version when this site serves
     * one for it, otherwise the permalink.
     *
     * The spec expects the links in llms.txt to point at LLM-friendly content, and
     * that is exactly what the Markdown endpoints produce, so the index links to
     * them instead of sending an agent to a page of HTML chrome to strip.
     *
     * The test is per entry, never per module: the post type lists of the two
     * features are separate settings and need not agree, so a type listed in
     * llms.txt may have no Markdown at all, and linking a .md nobody serves would
     * turn the index into a list of 404s.
     *
     * When the Visibility sibling owns the Markdown endpoint our own module is
     * deferred and answers nothing, so the question goes to its API instead: its
     * lists are the ones that decide. `is_callable()` also covers a sibling older
     * than 2.3.0, where those methods are private, and we fall back to the
     * permalink rather than publishing a link nobody serves.
     *
     * @param WP_Post $the_post Post.
     * @return string
     */
    private static function entry_url( $the_post ) {
        // When the Visibility sibling owns the Markdown endpoint, its
        // linkable_url() decides: it applies its own lists, eligibility and the
        // round-trip check that keeps entries whose .md would 404 on their
        // permalink. is_callable() covers a sibling older than 2.3.0 (no such
        // method), where we fall back to the permalink.
        if ( class_exists( 'VigIA_Sibling_Visibility' ) && VigIA_Sibling_Visibility::should_defer( 'markdown' ) ) {
            if ( is_callable( array( 'Native_AEO_Pack_Frontend_Markdown', 'linkable_url' ) ) ) {
                $url = (string) Native_AEO_Pack_Frontend_Markdown::linkable_url( $the_post );
                if ( '' !== $url ) {
                    return $url;
                }
            }

            return get_permalink( $the_post );
        }

        // Our own endpoint. linkable_url() carries the whole contract (module
        // switches, eligibility, round trip); the class is loaded even with the
        // feature off, which is exactly why the checks live inside it.
        if ( class_exists( 'VigIA_Markdown_Endpoints' ) ) {
            $url = (string) VigIA_Markdown_Endpoints::linkable_url( $the_post );
            if ( '' !== $url ) {
                return $url;
            }
        }

        return get_permalink( $the_post );
    }

    /**
     * Generate llms-full.txt content
     *
     * @param array $settings Settings.
     * @param array $post_ids Post IDs.
     * @return string
     */
    private static function generate_llms_full_txt( $settings, $post_ids ) {
        $name = self::one_line( self::decode_entities( $settings['site_name'] ?: self::site_name() ) );
        $desc = $settings['site_description'] ?: '';

        $desc = self::one_line( self::decode_entities( $desc ) );

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

            $content .= '## ' . self::one_line( self::decode_entities( get_the_title( $post ) ) ) . "\n\n";
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
            // With no hand-written excerpt the summary is the opening of the body,
            // and this one is read straight from the database: `the_content` never
            // runs here, so a membership plugin that gates by filtering it has no
            // say. Entries the visitor cannot read get no summary at all. A manual
            // excerpt above is different: the author wrote it to be shown.
            if ( ! VigIA_Content_Access::is_public( $the_post ) ) {
                return '';
            }

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

            // Remove remaining shortcodes, then flatten the markup keeping the
            // boundaries it drew: blocks_to_line() is what stops a heading from
            // being welded to the paragraph under it.
            $excerpt = strip_shortcodes( $content );
            $excerpt = self::blocks_to_line( $excerpt );

            // Final cleanup: remove any shortcode-like patterns that might remain.
            $excerpt = preg_replace( '/\[[a-z][a-z0-9_-]*[^\]]*\]/is', '', $excerpt );
            $excerpt = preg_replace( '/\[\/[a-z][a-z0-9_-]*\]/is', '', $excerpt );
        }

        // A hand-written excerpt can carry markup too, and it reaches the same
        // single line, so it goes through the same flattening.
        $excerpt = self::one_line( self::decode_entities( self::blocks_to_line( $excerpt ) ) );

        // Multibyte-aware: strlen()/substr() count bytes, and cutting a UTF-8
        // sequence in half writes an invalid byte into a file a model parses.
        // WordPress polyfills both functions when mbstring is missing
        // (wp-includes/compat.php).
        if ( mb_strlen( $excerpt, 'UTF-8' ) > $length ) {
            $excerpt = mb_substr( $excerpt, 0, $length, 'UTF-8' );

            // Back off to the last whole word, unless there is none to back off
            // to: mb_strrpos() returns false there and cutting at 0 would leave
            // nothing but the ellipsis.
            $last_space = mb_strrpos( $excerpt, ' ', 0, 'UTF-8' );
            if ( false !== $last_space && $last_space > 0 ) {
                $excerpt = mb_substr( $excerpt, 0, $last_space, 'UTF-8' );
            }

            $excerpt = rtrim( $excerpt ) . '...';
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
        $content = $the_post->post_content;

        // Save current global post and set up new one for shortcode context. This
        // is also what lets the membership plugins that gate by filtering
        // `the_content` recognise which entry they are being asked about: without
        // it they see no post, conclude there is nothing to protect and hand over
        // the whole body.
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
            // Use fallback: extract text from what the filters returned, not from
            // the raw body. A membership plugin that replaced the body leaves no
            // shortcodes for this branch to catch, but one that left a teaser
            // does, and reading the raw body there would hand over the very text
            // it just withheld. With no filters at all the two are the same string.
            $content = self::extract_text_from_shortcodes( $content );
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

        // Entities last. The body carries `&amp;` and `&#038;` that a model reads
        // literally, and decode_entities() strips again afterwards so decoding
        // cannot put a real tag back into a document already stripped.
        $content = self::decode_entities( $content );

        return trim( $content );
    }

    /**
     * Write one of the root files, as plain UTF-8 with no byte order mark.
     *
     * @param string $filename Filename.
     * @param string $content  Content.
     * @return bool|WP_Error
     */
    private static function write_file( $filename, $content ) {
        global $wp_filesystem;

        // llms.txt and llms-full.txt live at ABSPATH, one set of files for a whole
        // network. Only the main site writes them, so a subsite never overwrites
        // what every other site in the network serves. See
        // VigIA_Robots_Manager::owns_root_files().
        if ( is_multisite() && ! is_main_site() ) {
            return new WP_Error(
                'network_root_file',
                __( 'Files at the site root belong to the network root and can only be managed from the main site.', 'vigia' )
            );
        }

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

        // No byte order mark. These files are read by agents and parsers, not by
        // browsers or text editors, and UTF-8 needs no mark to be recognised: the
        // three bytes are content, so the first heading stops being `# ` at
        // position zero and a strict Markdown parser reads it as a paragraph. We
        // used to prepend one on purpose; 2.6.5 stops, and strips one arriving in
        // the content so a title pasted from a BOM'd editor cannot put it back.
        $content = (string) preg_replace( '/^\xEF\xBB\xBF/', '', (string) $content );

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

        // Same ownership rule as write_file(): a subsite must not delete the
        // network root's files.
        if ( is_multisite() && ! is_main_site() ) {
            return false;
        }

        $path = ABSPATH . $filename;
        if ( ! file_exists( $path ) ) {
            return true;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- wp_delete_file() returns void, and the caller needs to know whether the file is gone. Path is one of two literals from the allowlist above, always at ABSPATH.
        return unlink( $path );
    }

    /**
     * Is VigIA ceding llms.txt to the Visibility sibling?
     *
     * @return bool
     */
    private static function is_ceded_to_visibility() {
        return class_exists( 'VigIA_Sibling_Visibility' )
            && VigIA_Sibling_Visibility::should_defer( 'llms' );
    }

    /**
     * Remove VigIA's own physical llms.txt / llms-full.txt when ceding to the
     * Visibility sibling, so a leftover file at the site root does not shadow
     * Visibility's virtual output (a physical file is served by the webserver
     * before WordPress runs).
     *
     * Safe by design — only removes a file when ALL hold:
     *   - VigIA has generated at least once (`last_generated` set), so we never
     *     touch a hand-made or foreign file we never created;
     *   - the root llms.txt is NOT Visibility's (its header carries the
     *     "# Generated by Visibility" marker). If Visibility is in physical mode it
     *     owns that file, and deleting it would start the very regeneration war we
     *     are avoiding.
     *
     * Idempotent: once cleaned it zeroes `last_generated`, so subsequent admin
     * loads short-circuit without touching the disk again.
     */
    public static function cleanup_for_cession() {
        $settings = self::get_settings();

        if ( empty( $settings['last_generated'] ) ) {
            return; // We never generated; nothing of ours to remove.
        }

        if ( self::root_file_belongs_to_visibility() ) {
            return; // Visibility manages the physical file; never delete it.
        }

        self::delete_files();

        $settings['last_generated'] = 0;
        self::save_settings( $settings );
    }

    /**
     * Does the physical llms.txt at the site root belong to the Visibility
     * sibling (i.e. carry its "# Generated by Visibility" header)?
     *
     * Reads the index file (llms.txt), which is a bounded list, and infers
     * ownership of both files from it (the same owner writes both). When the file
     * can't be read, errs on the side of NOT deleting.
     *
     * @return bool
     */
    private static function root_file_belongs_to_visibility() {
        if ( ! class_exists( 'VigIA_Sibling_Visibility' ) ) {
            return false;
        }

        $path = ABSPATH . 'llms.txt';
        if ( ! file_exists( $path ) ) {
            return false; // No index file to attribute.
        }

        global $wp_filesystem;
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! WP_Filesystem() ) {
            return true; // Can't read safely → don't risk deleting a sibling file.
        }

        $contents = $wp_filesystem->get_contents( $path );
        if ( false === $contents ) {
            return true; // Unreadable → leave it alone.
        }

        return false !== strpos( substr( $contents, 0, 128 ), VigIA_Sibling_Visibility::LLMS_MARKER );
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