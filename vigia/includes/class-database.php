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
     */
    const DB_VERSION = '1.0.0';

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
            visit_date datetime NOT NULL,
            PRIMARY KEY (id),
            KEY crawler_name (crawler_name),
            KEY crawler_category (crawler_category),
            KEY visit_date (visit_date),
            KEY request_path (request_path(191))
        ) {$charset_collate};";
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'vigia_db_version', self::DB_VERSION );
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, not a core WP table
        $result = $wpdb->insert(
            self::get_table_name(),
            $data,
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
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
     * @param int $limit Max results.
     * @return array Recent visits.
     */
    public static function get_recent_visits( $limit = 50 ) {
        global $wpdb;

        $table_name = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, real-time data
        $results = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT crawler_name, crawler_category, request_path, ip_address, visit_date 
                FROM %i 
                ORDER BY visit_date DESC 
                LIMIT %d',
                $table_name,
                $limit
            ),
            ARRAY_A
        );

        return $results ? $results : array();
    }

    /**
     * Export data to CSV format
     *
     * @param string $start_date Start date.
     * @param string $end_date   End date.
     * @return array CSV data rows.
     */
    public static function export_data( $start_date, $end_date ) {
        global $wpdb;

        $table_name = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, export requires fresh data
        $results = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT crawler_name, crawler_category, request_path, ip_address, http_status, visit_date 
                FROM %i 
                WHERE visit_date BETWEEN %s AND %s 
                ORDER BY visit_date DESC',
                $table_name,
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59'
            ),
            ARRAY_A
        );

        return $results ? $results : array();
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