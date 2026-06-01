<?php
/**
 * Database management class
 *
 * Handles table creation, data storage and retrieval for crawler visits.
 *
 * @package VigIA
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Database class
 */
class VigIA_Database {

    /**
     * Database version for migrations
     *
     * 1.1.0 — adds the content_type column populated at insert time so the
     *        recent activity filter can target it without LIKE scans. The
     *        detector classifies hits into post / page / product / CPT /
     *        category / tag / archive / feed / sitemap / api / file /
     *        home / admin / wp-system / not-found / other. The backfill
     *        cron processes rows that pre-date the column AND revisits
     *        rows previously bucketed as "other" so they end up in the
     *        right specific bucket.
     */
    const DB_VERSION = '1.1.0';

    /**
     * Get table name
     *
     * @return string
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'vigia_visits';
    }

    /**
     * Create database tables on activation
     *
     * Note: dbDelta requires raw SQL with table name, cannot use prepare() with %i.
     * Table name is safe as it only uses $wpdb->prefix + fixed string.
     */
    public static function create_tables() {
        global $wpdb;

        $table_name      = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta requires raw SQL, table name is safe (wpdb prefix + fixed string)
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            crawler_name varchar(100) NOT NULL,
            crawler_category varchar(50) NOT NULL DEFAULT 'unknown',
            user_agent text NOT NULL,
            request_url text NOT NULL,
            request_path varchar(500) NOT NULL,
            ip_address varchar(45) NOT NULL,
            http_status smallint(3) unsigned NOT NULL DEFAULT 200,
            content_type varchar(20) NOT NULL DEFAULT '',
            visit_date datetime NOT NULL,
            PRIMARY KEY (id),
            KEY crawler_name (crawler_name),
            KEY crawler_category (crawler_category),
            KEY visit_date (visit_date),
            KEY request_path (request_path(191)),
            KEY content_type (content_type)
        ) {$charset_collate};";
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'vigia_db_version', self::DB_VERSION );
    }

    /**
     * Ensure the schema matches DB_VERSION.
     *
     * Called on every admin pageload via VigIA::init(). When the stored DB
     * version is older than the constant, dbDelta runs again to add any
     * missing column (e.g. content_type added in 1.1.0). Idempotent — when
     * already up to date this only reads one option.
     */
    public static function maybe_upgrade_schema() {
        $stored = get_option( 'vigia_db_version', '0.0.0' );
        if ( version_compare( $stored, self::DB_VERSION, '>=' ) ) {
            return;
        }
        self::create_tables();

        // Process the first batch immediately so the content_type column is
        // populated for the most recent visits the moment the admin loads.
        // Without this, the activity filter feels broken for an hour until
        // the cron's first tick.
        self::backfill_content_types( 500 );

        // Sites upgrading from a pre-2.0.0 install via wp.org auto-update do
        // not go through the activation hook, so make sure the backfill cron
        // is scheduled the first time the admin loads a page after upgrading.
        if ( ! wp_next_scheduled( 'vigia_backfill_content_type' ) ) {
            wp_schedule_event( time() + 60, 'hourly', 'vigia_backfill_content_type' );
        }
    }

    /**
     * Insert a crawler visit record
     *
     * @param array $data Visit data.
     * @return int|false Insert ID or false on failure.
     */
    public static function insert_visit( $data ) {
        global $wpdb;

        $defaults = array(
            'crawler_name'     => '',
            'crawler_category' => 'unknown',
            'user_agent'       => '',
            'request_url'      => '',
            'request_path'     => '',
            'ip_address'       => '',
            'http_status'      => 200,
            'content_type'     => '',
            'visit_date'       => current_time( 'mysql' ),
        );

        $data = wp_parse_args( $data, $defaults );

        // Sanitize data
        $data['crawler_name']     = sanitize_text_field( $data['crawler_name'] );
        $data['crawler_category'] = sanitize_text_field( $data['crawler_category'] );
        $data['user_agent']       = sanitize_text_field( $data['user_agent'] );
        $data['request_url']      = esc_url_raw( $data['request_url'] );
        $data['request_path']     = sanitize_text_field( $data['request_path'] );
        $data['ip_address']       = sanitize_text_field( $data['ip_address'] );
        $data['http_status']      = absint( $data['http_status'] );

        // Compute content_type once at insert time so the activity filter can
        // index by it. URL-to-postid lookups are cheap when done once but
        // would be prohibitive if recalculated on every query. The http_status
        // is passed so a 404 hit is classified as not-found regardless of
        // what the path's slug would resolve to.
        if ( '' === $data['content_type'] ) {
            $data['content_type'] = self::detect_content_type( $data['request_path'], $data['http_status'] );
        }
        $data['content_type'] = sanitize_key( $data['content_type'] );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, not a core WP table
        $result = $wpdb->insert(
            self::get_table_name(),
            $data,
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get visit statistics for a date range
     *
     * @param string $start_date Start date (Y-m-d format).
     * @param string $end_date   End date (Y-m-d format).
     * @return array Statistics data.
     */
    public static function get_stats( $start_date, $end_date ) {
        global $wpdb;

        $table_name = self::get_table_name();

        // Total visits
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, real-time data
        $total = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE visit_date BETWEEN %s AND %s',
                $table_name,
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59'
            )
        );

        // Unique crawlers
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, real-time data
        $unique_crawlers = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(DISTINCT crawler_name) FROM %i WHERE visit_date BETWEEN %s AND %s',
                $table_name,
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59'
            )
        );

        // Unique pages crawled
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, real-time data
        $unique_pages = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(DISTINCT request_path) FROM %i WHERE visit_date BETWEEN %s AND %s',
                $table_name,
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59'
            )
        );

        return array(
            'total_visits'    => absint( $total ),
            'unique_crawlers' => absint( $unique_crawlers ),
            'unique_pages'    => absint( $unique_pages ),
        );
    }

    /**
     * Get visits grouped by crawler with pagination support
     *
     * @param string $start_date Start date.
     * @param string $end_date   End date.
     * @param int    $limit      Max results (default 20).
     * @param int    $offset     Offset for pagination (default 0).
     * @return array Crawler visit counts.
     */
    public static function get_visits_by_crawler( $start_date, $end_date, $limit = 20, $offset = 0 ) {
        global $wpdb;

        $table_name = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, real-time data
        $results = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT crawler_name, crawler_category, COUNT(*) as visit_count 
                FROM %i 
                WHERE visit_date BETWEEN %s AND %s 
                GROUP BY crawler_name, crawler_category 
                ORDER BY visit_count DESC 
                LIMIT %d OFFSET %d',
                $table_name,
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59',
                $limit,
                $offset
            ),
            ARRAY_A
        );

        return $results ? $results : array();
    }

    /**
     * Get total count of unique crawlers for pagination
     *
     * @param string $start_date Start date.
     * @param string $end_date   End date.
     * @return int Total unique crawlers count.
     */
    public static function get_crawlers_count( $start_date, $end_date ) {
        global $wpdb;

        $table_name = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, real-time data
        $count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(DISTINCT crawler_name) FROM %i WHERE visit_date BETWEEN %s AND %s',
                $table_name,
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59'
            )
        );

        return absint( $count );
    }

    /**
     * Get visits grouped by category
     *
     * @param string $start_date Start date.
     * @param string $end_date   End date.
     * @return array Category visit counts.
     */
    public static function get_visits_by_category( $start_date, $end_date ) {
        global $wpdb;

        $table_name = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, real-time data
        $results = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT crawler_category, COUNT(*) as visit_count 
                FROM %i 
                WHERE visit_date BETWEEN %s AND %s 
                GROUP BY crawler_category 
                ORDER BY visit_count DESC',
                $table_name,
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59'
            ),
            ARRAY_A
        );

        return $results ? $results : array();
    }

    /**
     * Get visits over time (daily)
     *
     * @param string $start_date Start date.
     * @param string $end_date   End date.
     * @return array Daily visit counts.
     */
    public static function get_visits_over_time( $start_date, $end_date ) {
        global $wpdb;

        $table_name = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, real-time data
        $results = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT DATE(visit_date) as date, COUNT(*) as visit_count 
                FROM %i 
                WHERE visit_date BETWEEN %s AND %s 
                GROUP BY DATE(visit_date) 
                ORDER BY date ASC',
                $table_name,
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59'
            ),
            ARRAY_A
        );

        return $results ? $results : array();
    }

    /**
     * Get most crawled pages with pagination support
     *
     * @param string $start_date Start date.
     * @param string $end_date   End date.
     * @param int    $limit      Max results (default 20).
     * @param int    $offset     Offset for pagination (default 0).
     * @return array Page visit counts.
     */
    public static function get_top_pages( $start_date, $end_date, $limit = 20, $offset = 0 ) {
        global $wpdb;

        $table_name = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, real-time data
        $results = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT request_path, COUNT(*) as visit_count, COUNT(DISTINCT crawler_name) as crawler_count 
                FROM %i 
                WHERE visit_date BETWEEN %s AND %s 
                GROUP BY request_path 
                ORDER BY visit_count DESC 
                LIMIT %d OFFSET %d',
                $table_name,
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59',
                $limit,
                $offset
            ),
            ARRAY_A
        );

        return $results ? $results : array();
    }

    /**
     * Get total count of unique pages for pagination
     *
     * @param string $start_date Start date.
     * @param string $end_date   End date.
     * @return int Total unique pages count.
     */
    public static function get_pages_count( $start_date, $end_date ) {
        global $wpdb;

        $table_name = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, real-time data
        $count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(DISTINCT request_path) FROM %i WHERE visit_date BETWEEN %s AND %s',
                $table_name,
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59'
            )
        );

        return absint( $count );
    }

    /**
     * Get recent visits
     *
     * Backwards-compatible wrapper kept for callers that just want the latest
     * N rows without any filtering. New code should call query_visits() and
     * pass an args array.
     *
     * @param int $limit Max results.
     * @return array Recent visits.
     */
    public static function get_recent_visits( $limit = 50 ) {
        global $wpdb;

        $table_name = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, real-time data
        $results = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT crawler_name, crawler_category, request_path, ip_address, http_status, content_type, visit_date
                FROM %i
                ORDER BY visit_date DESC
                LIMIT %d',
                $table_name,
                $limit
            ),
            ARRAY_A
        );

        if ( $results ) {
            foreach ( $results as $idx => $row ) {
                if ( ! empty( $row['content_type'] ) && 'other' !== $row['content_type'] ) {
                    continue;
                }
                $results[ $idx ]['content_type'] = self::detect_content_type( $row['request_path'], $row['http_status'] );
            }
        }

        return $results ? $results : array();
    }

    /**
     * Query visits with optional filters and server-side pagination.
     *
     * Supported args:
     *  - crawlers     array<string>  Match any of these crawler_name values.
     *  - category     string         Match a specific crawler_category.
     *  - content_type string         One of the keys in get_content_type_options().
     *  - http_status  int|string     Either an exact code (200, 404…) or "other"
     *                                meaning anything outside the well-known set.
     *  - date_from    string         Y-m-d.
     *  - date_to      string         Y-m-d.
     *  - page         int            1-based page number. Default 1.
     *  - per_page     int            Page size. Default 20, max 100.
     *
     * Returns an array with:
     *  - items       array<array> Matching rows.
     *  - total       int          Total rows matching the filters.
     *  - page        int          Page returned.
     *  - per_page    int          Page size used.
     *  - total_pages int          ceil(total / per_page).
     *
     * @param array $args Query args.
     * @return array
     */
    public static function query_visits( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'crawlers'     => array(),
            'category'     => '',
            'content_type' => '',
            'http_status'  => '',
            'date_from'    => '',
            'date_to'      => '',
            'page'         => 1,
            'per_page'     => 20,
        );
        $args = wp_parse_args( $args, $defaults );

        $per_page = min( 100, max( 1, (int) $args['per_page'] ) );
        $page     = max( 1, (int) $args['page'] );
        $offset   = ( $page - 1 ) * $per_page;

        $table_name = self::get_table_name();
        $clauses    = array( '1=1' );
        $values     = array();

        if ( ! empty( $args['crawlers'] ) && is_array( $args['crawlers'] ) ) {
            $crawlers = array_values( array_filter( array_map( 'sanitize_text_field', $args['crawlers'] ) ) );
            if ( ! empty( $crawlers ) ) {
                $placeholders = implode( ', ', array_fill( 0, count( $crawlers ), '%s' ) );
                $clauses[]    = 'crawler_name IN (' . $placeholders . ')';
                $values       = array_merge( $values, $crawlers );
            }
        }

        if ( ! empty( $args['category'] ) ) {
            $clauses[] = 'crawler_category = %s';
            $values[]  = sanitize_text_field( $args['category'] );
        }

        if ( '' !== $args['http_status'] ) {
            if ( 'other' === $args['http_status'] ) {
                $known     = array( 200, 301, 304, 403, 404, 410 );
                $clauses[] = 'http_status NOT IN (' . implode( ', ', array_map( 'intval', $known ) ) . ')';
            } else {
                $clauses[] = 'http_status = %d';
                $values[]  = (int) $args['http_status'];
            }
        }

        if ( ! empty( $args['date_from'] ) ) {
            $clauses[] = 'visit_date >= %s';
            $values[]  = sanitize_text_field( $args['date_from'] ) . ' 00:00:00';
        }
        if ( ! empty( $args['date_to'] ) ) {
            $clauses[] = 'visit_date <= %s';
            $values[]  = sanitize_text_field( $args['date_to'] ) . ' 23:59:59';
        }

        if ( ! empty( $args['content_type'] ) ) {
            // Indexed lookup on the column populated at insert time.
            // Rows captured before VigIA 2.0.0 may still have content_type=''
            // until the hourly backfill cron drains them. When the user
            // filters by content_type we eagerly fill any empty rows inside
            // the requested date range so the filter is accurate immediately
            // instead of "looking broken" until the next cron tick.
            self::backfill_content_types_in_range(
                isset( $args['date_from'] ) ? $args['date_from'] : '',
                isset( $args['date_to'] ) ? $args['date_to'] : '',
                2000
            );

            $clauses[] = 'content_type = %s';
            $values[]  = sanitize_key( $args['content_type'] );
        }

        $where = implode( ' AND ', $clauses );

        // $where is a dynamically composed fragment that contains ONLY %s and
        // %d placeholders (every clause in $clauses appends a placeholder, and
        // each placeholder has its matching value pushed into $values). The
        // resulting concatenation is passed verbatim to wpdb::prepare(), which
        // fills every placeholder, so the final SQL is fully escaped. Static
        // analysers cannot follow the runtime composition, hence the
        // phpcs:disable block below.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE ' . $where,
                array_merge( array( $table_name ), $values )
            )
        );

        $items = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT crawler_name, crawler_category, request_path, ip_address, http_status, content_type, visit_date FROM %i WHERE ' . $where . ' ORDER BY visit_date DESC LIMIT %d OFFSET %d',
                array_merge( array( $table_name ), $values, array( $per_page, $offset ) )
            ),
            ARRAY_A
        );
        // phpcs:enable

        // Fill in content_type on the fly for rows captured before VigIA
        // 2.0.0, plus revisit rows still marked "other" by DB version 1.1.0
        // so they land in the right bucket (home / not-found / admin /
        // wp-system) until the cron persists the new value.
        if ( $items ) {
            foreach ( $items as $idx => $row ) {
                if ( ! empty( $row['content_type'] ) && 'other' !== $row['content_type'] ) {
                    continue;
                }
                $items[ $idx ]['content_type'] = self::detect_content_type( $row['request_path'], $row['http_status'] );
            }
        }

        return array(
            'items'       => $items ? $items : array(),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
        );
    }

    /**
     * Returns the list of supported content_type keys for the activity filter.
     *
     * The labels are not localized here; UI layer is responsible for that.
     * Order matters: this is the order users see in the dropdown, so the
     * common cases (post/page/product) come first, followed by any other
     * public custom post type registered on the site.
     *
     * @return array<string, string> Map of key => english label.
     */
    public static function get_content_type_options() {
        $options = array(
            'home' => 'Home',
            'post' => 'Post',
            'page' => 'Page',
        );

        $public_post_types = get_post_types( array( 'public' => true ), 'objects' );
        unset( $public_post_types['post'], $public_post_types['page'], $public_post_types['attachment'] );

        // Surface "product" right after page when present, then any other CPT.
        if ( isset( $public_post_types['product'] ) ) {
            $options['product'] = isset( $public_post_types['product']->labels->singular_name )
                ? $public_post_types['product']->labels->singular_name
                : 'Product';
            unset( $public_post_types['product'] );
        }

        foreach ( $public_post_types as $name => $pt ) {
            $options[ $name ] = isset( $pt->labels->singular_name ) ? $pt->labels->singular_name : $name;
        }

        $options['category']  = 'Category archive';
        $options['tag']       = 'Tag archive';
        $options['archive']   = 'Date / author archive';
        $options['feed']      = 'Feed';
        $options['sitemap']   = 'Sitemap';
        $options['api']       = 'REST API';
        $options['file']      = 'File';
        $options['admin']     = 'Admin / login attempt';
        $options['wp-system'] = 'WordPress system';
        $options['not-found'] = '404 Not found';
        $options['other']     = 'Other';

        return $options;
    }

    /**
     * LIKE patterns per content_type used by detect_content_type().
     *
     * @return array<string, array<string>>
     */
    private static function content_type_like_patterns() {
        $category_base = get_option( 'category_base' );
        if ( empty( $category_base ) ) {
            $category_base = 'category';
        }

        $tag_base = get_option( 'tag_base' );
        if ( empty( $tag_base ) ) {
            $tag_base = 'tag';
        }

        return array(
            // Order matters in detect_content_type() — first match wins.
            // wp-system goes before admin so /wp-admin/admin-ajax.php is
            // classified as a legitimate system endpoint instead of an
            // admin-panel probe.
            'wp-system' => array(
                '/wp-admin/admin-ajax.php',
                '/wp-admin/admin-ajax.php/%',
                '/xmlrpc.php',
                '/xmlrpc.php/%',
                '/wp-cron.php',
                '/wp-cron.php/%',
                '/wp-comments-post.php',
                '/wp-comments-post.php/%',
            ),
            'admin'     => array(
                '/wp-login.php',
                '/wp-login.php/%',
                '/wp-admin',
                '/wp-admin/',
                '/wp-admin/%',
            ),
            'feed'      => array( '%/feed', '%/feed/', '%/feed/%' ),
            'sitemap'   => array( '%sitemap%.xml', '%sitemap%.xml/%', '%/wp-sitemap%' ),
            'api'       => array( '%/wp-json/%', '%/wp-json' ),
            'file'      => array(
                '%.pdf', '%.doc', '%.docx', '%.xls', '%.xlsx', '%.ppt', '%.pptx',
                '%.zip', '%.gz', '%.tar',
                '%.jpg', '%.jpeg', '%.png', '%.gif', '%.webp', '%.svg', '%.ico',
                '%.mp3', '%.mp4', '%.mov', '%.avi', '%.webm',
                '%.csv', '%.txt', '%.json', '%.md',
            ),
            'category'  => array( '/' . $category_base . '/%' ),
            'tag'       => array( '/' . $tag_base . '/%' ),
            'archive'   => array( '/author/%' ),
        );
    }

    /**
     * Classify a request path into one of the get_content_type_options() keys.
     *
     * Detection order matters: structural patterns first (feed, sitemap, REST
     * API, files) because a `.xml` inside `/feed/` should resolve to feed, not
     * file. After structural matches, we try the post lookup to distinguish
     * post / page / product / custom CPTs from raw "other" paths.
     *
     * @param string $path Request path (already URL-decoded).
     * @return string One of the keys in get_content_type_options().
     */
    public static function detect_content_type( $path, $http_status = null ) {
        $path = (string) $path;

        // 404 wins regardless of the path: a bot probing a post slug that
        // returns 404 is more useful classified as "not found" than as the
        // post it was trying to reach.
        if ( null !== $http_status && 404 === (int) $http_status ) {
            return 'not-found';
        }

        if ( '' === $path || '/' === $path ) {
            return 'home';
        }

        // In-request memoization. AI crawlers tend to hit the same handful of
        // URLs repeatedly, and the backfill cron iterates over many rows that
        // share paths. Cache key includes the http_status when relevant so a
        // 200 hit and a 404 hit on the same path stay distinct.
        static $cache = array();
        $cache_key = $path;
        if ( isset( $cache[ $cache_key ] ) ) {
            return $cache[ $cache_key ];
        }

        $patterns = self::content_type_like_patterns();

        // Structural patterns first.
        foreach ( $patterns as $type => $likes ) {
            foreach ( $likes as $like ) {
                if ( self::path_matches_like( $path, $like ) ) {
                    $cache[ $cache_key ] = $type;
                    return $type;
                }
            }
        }

        // Fall back to a WordPress lookup for post/page/CPT routes. Three
        // strategies in order of cost: url_to_postid (needs pretty
        // permalinks), get_page_by_path (resolves nested paths), and a slug
        // lookup on the last path segment (catches single-slug post permalinks
        // like /sample-post/).
        $post_type = self::detect_post_type_for_path( $path );
        if ( $post_type ) {
            $cache[ $cache_key ] = $post_type;
            return $post_type;
        }

        $cache[ $cache_key ] = 'other';
        return 'other';
    }

    /**
     * Try to resolve a request path into a registered post type.
     *
     * @param string $path Request path.
     * @return string Post type slug or empty string when nothing matches.
     */
    private static function detect_post_type_for_path( $path ) {
        $clean_path = trim( $path, '/' );
        if ( '' === $clean_path ) {
            return '';
        }

        $full_url = home_url( '/' . $clean_path . '/' );

        // 1. url_to_postid works with the site's real permalink structure.
        $post_id = url_to_postid( $full_url );
        if ( $post_id > 0 ) {
            return (string) get_post_type( $post_id );
        }

        // Restrict subsequent lookups to public post types only.
        $public_post_types = get_post_types( array( 'public' => true ) );
        unset( $public_post_types['attachment'] );
        $public_post_types = array_values( $public_post_types );
        if ( empty( $public_post_types ) ) {
            return '';
        }

        // 2. get_page_by_path handles parent/child page paths and works even
        //    when the permalink structure is plain (?p=ID).
        $found = get_page_by_path( $clean_path, OBJECT, $public_post_types );
        if ( $found instanceof WP_Post ) {
            return (string) $found->post_type;
        }

        // 3. Last segment as a slug. Catches default post permalinks like
        //    /my-first-post/ and most CPT single URLs.
        $segments = explode( '/', $clean_path );
        $slug     = end( $segments );
        if ( $slug ) {
            $posts = get_posts(
                array(
                    'name'                   => $slug,
                    'post_type'              => $public_post_types,
                    'post_status'            => 'publish',
                    'numberposts'            => 1,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                )
            );
            if ( ! empty( $posts ) ) {
                return (string) $posts[0]->post_type;
            }
        }

        return '';
    }

    /**
     * Backfill content_type for rows that pre-date the column (DB version < 1.1.0).
     *
     * Runs in batches over WP-Cron so a large history does not block the
     * activation request. Each invocation processes up to $batch rows; the
     * cron re-schedules itself until the queue is drained.
     *
     * @param int $batch Number of rows to process in this run.
     * @return int Rows updated in this call.
     */
    public static function backfill_content_types( $batch = 200 ) {
        return self::backfill_content_types_in_range( '', '', $batch );
    }

    /**
     * Backfill content_type for rows captured before VigIA 2.0.0, optionally
     * restricted to a date range so an on-demand fill (triggered when the
     * user filters by content_type) does not scan the entire history.
     *
     * @param string $date_from Y-m-d. Empty for no lower bound.
     * @param string $date_to   Y-m-d. Empty for no upper bound.
     * @param int    $batch     Rows to process in this run.
     * @return int Rows updated.
     */
    public static function backfill_content_types_in_range( $date_from = '', $date_to = '', $batch = 500 ) {
        global $wpdb;

        $table_name = self::get_table_name();
        $batch      = max( 1, (int) $batch );

        // Reclassify both unprocessed rows (content_type='' / NULL) and the
        // legacy "other" bucket — DB 1.2.0 splits "other" into home /
        // not-found / admin / wp-system, so previously-classified rows need
        // a second pass to land in the right bucket.
        $clauses = array( "(content_type = '' OR content_type IS NULL OR content_type = 'other')" );
        $values  = array( $table_name );

        if ( ! empty( $date_from ) ) {
            $clauses[] = 'visit_date >= %s';
            $values[]  = sanitize_text_field( $date_from ) . ' 00:00:00';
        }
        if ( ! empty( $date_to ) ) {
            $clauses[] = 'visit_date <= %s';
            $values[]  = sanitize_text_field( $date_to ) . ' 23:59:59';
        }

        $values[] = $batch;
        $where    = implode( ' AND ', $clauses );

        // $where is composed only of literal SQL plus %s placeholders that
        // wpdb::prepare() resolves with $values. Static analysers cannot
        // follow the runtime composition; the actual query is fully prepared.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, request_path, http_status FROM %i WHERE ' . $where . ' LIMIT %d',
                $values
            ),
            ARRAY_A
        );
        // phpcs:enable

        if ( empty( $rows ) ) {
            return 0;
        }

        $updated = 0;
        foreach ( $rows as $row ) {
            $type = self::detect_content_type( $row['request_path'], $row['http_status'] );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, backfill cron
            $wpdb->update(
                $table_name,
                array( 'content_type' => $type ),
                array( 'id' => (int) $row['id'] ),
                array( '%s' ),
                array( '%d' )
            );
            $updated++;
        }

        return $updated;
    }

    /**
     * Test whether a path matches a SQL LIKE pattern (handles % as wildcard).
     *
     * Anchors are derived from the position of % in the pattern, so
     * `%/feed/%`, `/category/%` and `%.pdf` all work as expected.
     *
     * @param string $path Path to test.
     * @param string $like LIKE pattern using % as wildcard.
     * @return bool
     */
    private static function path_matches_like( $path, $like ) {
        // preg_quote() does not escape %, so a direct replacement converts each
        // SQL wildcard into a PCRE wildcard.
        $regex = '/^' . str_replace( '%', '.*', preg_quote( $like, '/' ) ) . '$/i';
        return (bool) preg_match( $regex, $path );
    }

    /**
     * Export data to CSV format
     *
     * Accepts the same filter args as query_visits() so the export can be
     * restricted to the rows the user is currently looking at on the
     * activity table. Pagination args are ignored — the export always
     * returns the full filtered set.
     *
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date   End date (Y-m-d).
     * @param array  $filters    Optional filters: crawlers[], category, content_type, http_status.
     * @return array CSV data rows.
     */
    public static function export_data( $start_date, $end_date, $filters = array() ) {
        // Reuse query_visits() so the WHERE construction stays in one place.
        // Pull every matching row in a single page by bumping per_page very high.
        $args = array_merge(
            (array) $filters,
            array(
                'date_from' => $start_date,
                'date_to'   => $end_date,
                'page'      => 1,
                'per_page'  => 100, // Will be overridden below.
            )
        );

        // We bypass the per_page cap (100) by paging through the results.
        $all      = array();
        $per_page = 100;
        $page     = 1;

        do {
            $args['per_page'] = $per_page;
            $args['page']     = $page;
            $batch            = self::query_visits( $args );
            if ( empty( $batch['items'] ) ) {
                break;
            }
            $all = array_merge( $all, $batch['items'] );
            if ( count( $all ) >= $batch['total'] ) {
                break;
            }
            $page++;
            // Safety stop: hard cap at 50k rows to prevent runaway exports.
            if ( count( $all ) >= 50000 ) {
                break;
            }
        } while ( true );

        return $all;
    }

    /**
     * Delete old records (data retention)
     *
     * @param int $days Number of days to keep.
     * @return int Number of deleted rows.
     */
    public static function cleanup_old_data( $days = 90 ) {
        global $wpdb;

        $table_name = self::get_table_name();
        $cutoff     = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, data retention cleanup
        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE visit_date < %s',
                $table_name,
                $cutoff
            )
        );

        return $deleted ? $deleted : 0;
    }

    /**
     * Truncate the visits table (delete all data)
     *
     * @return bool True on success.
     */
    public static function truncate_table() {
        global $wpdb;

        $table_name = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom analytics table, intentional truncate
        $wpdb->query(
            $wpdb->prepare( 'TRUNCATE TABLE %i', $table_name )
        );

        return true;
    }

    /**
     * Get click data per path from AI Share & Summarize (if active)
     *
     * Queries the AISS clicks table to get click counts for crawled paths.
     * Resolves request_path to post_id via url_to_postid(), so only
     * paths that map to actual posts/pages will return data (sitemaps,
     * robots.txt, etc. are automatically excluded).
     *
     * @since 1.6.0
     * @param array  $paths      Array of request_path strings.
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date   End date (Y-m-d).
     * @return array Associative array request_path => click_count.
     */
    public static function get_aiss_clicks_for_paths( $paths, $start_date, $end_date ) {
        if ( ! class_exists( 'AyudaWP_AISS_Database' ) || empty( $paths ) ) {
            return array();
        }

        global $wpdb;

        $aiss_table      = $wpdb->prefix . 'aiss_clicks';
        $aiss_table_safe = esc_sql( $aiss_table );

        // Check if AISS table exists (one-time structural check).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time existence check.
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
                DB_NAME,
                $aiss_table
            )
        );

        if ( ! $table_exists ) {
            return array();
        }

        $click_data = array();
        $site_url   = home_url();

        foreach ( $paths as $path ) {
            // Resolve path to post ID.
            $full_url = trailingslashit( $site_url ) . ltrim( $path, '/' );
            $post_id  = url_to_postid( $full_url );

            if ( ! $post_id ) {
                continue;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- AISS external table, name is escaped with esc_sql().
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                    FROM {$aiss_table_safe}
                    WHERE post_id = %d
                    AND click_date >= %s AND click_date < %s",
                    $post_id,
                    $start_date . ' 00:00:00',
                    $end_date . ' 23:59:59'
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            $click_data[ $path ] = (int) $count;
        }

        return $click_data;
    }

    /**
     * Get visits breakdown by crawler for each day (for tooltip details)
     *
     * @param string $start_date Start date.
     * @param string $end_date   End date.
     * @return array Associative array of date => array of crawlers with counts.
     */
    public static function get_daily_crawler_breakdown( $start_date, $end_date ) {
        global $wpdb;

        $table_name = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, real-time data
        $results = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT DATE(visit_date) as date, crawler_name, COUNT(*) as visit_count 
                FROM %i 
                WHERE visit_date BETWEEN %s AND %s 
                GROUP BY DATE(visit_date), crawler_name 
                ORDER BY date ASC, visit_count DESC',
                $table_name,
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59'
            ),
            ARRAY_A
        );

        // Organize by date
        $breakdown = array();
        if ( $results ) {
            foreach ( $results as $row ) {
                $date = $row['date'];
                if ( ! isset( $breakdown[ $date ] ) ) {
                    $breakdown[ $date ] = array();
                }
                $breakdown[ $date ][] = array(
                    'name'  => $row['crawler_name'],
                    'count' => (int) $row['visit_count'],
                );
            }
        }

        return $breakdown;
    }
}