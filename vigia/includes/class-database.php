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
     * 1.2.0 — replaces the single-column indexes with the composite ones the
     *        dashboard actually needs (see self::PERFORMANCE_INDEXES). New
     *        installs get them from dbDelta; existing tables are converted in
     *        the background by create_performance_indexes().
     */
    const DB_VERSION = '1.2.0';

    /**
     * Date ranges longer than this (in days) get their aggregates cached.
     *
     * Short ranges stay live: a bot that hit the site a minute ago must show up
     * on "today" straight away. Long ranges are dominated by history that is
     * not going to change, so recomputing them on every page load only buys
     * staleness in the last few hours of the window.
     *
     * @since 2.5.0
     */
    const CACHE_MIN_RANGE_DAYS = 60;

    /**
     * How long a cached aggregate stays valid.
     *
     * Deliberately longer than the hourly warm-up cron, so a cached value is
     * refreshed before it can expire and nobody lands on an empty cache.
     *
     * @since 2.5.0
     */
    const CACHE_TTL = 3 * HOUR_IN_SECONDS;

    /**
     * Rows the dashboard tables request per page.
     *
     * Single source of truth for the REST default and for the cache warm-up,
     * which must precompute the same page the dashboard will ask for.
     *
     * @since 2.5.0
     */
    const DASHBOARD_PAGE_SIZE = 10;

    /**
     * How many unclassified rows an export will resolve on the fly.
     *
     * Above this, the export ships the column as stored rather than paying a
     * lookup per row. See export_data().
     *
     * @since 2.5.0
     */
    const EXPORT_CLASSIFY_LIMIT = 500;

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
     * Length of a date range in days.
     *
     * @since 2.5.0
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date   End date (Y-m-d).
     * @return int Days covered, 0 when either bound is unparseable.
     */
    private static function range_days( $start_date, $end_date ) {
        $start = strtotime( $start_date );
        $end   = strtotime( $end_date );

        if ( ! $start || ! $end || $end < $start ) {
            return 0;
        }

        return (int) round( ( $end - $start ) / DAY_IN_SECONDS );
    }

    /**
     * Whether a date range already contains every row in the table.
     *
     * "All time" asks for 2000-01-01 to today, and a BETWEEN that matches
     * everything still forces MySQL to evaluate it row by row: it cannot use
     * the loose index scan that answers "how many distinct pages are there"
     * by reading one entry per distinct value. Dropping a WHERE that excludes
     * nothing takes that query from 1.3 s to 0.09 s on 610k visits.
     *
     * @since 2.5.0
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date   End date (Y-m-d).
     * @return bool
     */
    private static function covers_full_history( $start_date, $end_date ) {
        global $wpdb;

        // A range that stops before today cannot cover rows recorded since.
        if ( $end_date < gmdate( 'Y-m-d' ) ) {
            return false;
        }

        $first = get_transient( 'vigia_first_visit_date' );

        if ( false === $first ) {
            $table_name = self::get_table_name();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached in the transient right below; indexed MIN(), effectively free.
            $first = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(visit_date) FROM %i', $table_name ) );
            $first = '' !== $first ? $first : 'none';

            // Only changes when data is deleted, and flush_stats_cache()
            // clears it when that happens.
            set_transient( 'vigia_first_visit_date', $first, 12 * HOUR_IN_SECONDS );
        }

        if ( 'none' === $first ) {
            return false;
        }

        return ( $start_date . ' 00:00:00' ) <= $first;
    }

    /**
     * Build a cache key for an aggregate query.
     *
     * The salt lets cleanup_old_data() and truncate_table() invalidate every
     * cached aggregate at once without having to enumerate transients.
     *
     * @since 2.5.0
     * @param string $bucket Query identifier.
     * @param array  $parts  Query arguments that change the result.
     * @return string Transient name.
     */
    private static function cache_key( $bucket, $parts ) {
        $salt = (int) get_option( 'vigia_stats_cache_salt', 0 );
        return 'vigia_q_' . md5( $bucket . '|' . $salt . '|' . wp_json_encode( $parts ) );
    }

    /**
     * Read a cached aggregate, if the range qualifies for caching.
     *
     * @since 2.5.0
     * @param string $key        Cache key from self::cache_key().
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date   End date (Y-m-d).
     * @return mixed Cached value, or false when absent or not cacheable.
     */
    private static function cache_get( $key, $start_date, $end_date ) {
        if ( self::range_days( $start_date, $end_date ) <= self::CACHE_MIN_RANGE_DAYS ) {
            return false;
        }

        return get_transient( $key );
    }

    /**
     * Store an aggregate, if the range qualifies for caching.
     *
     * @since 2.5.0
     * @param string $key        Cache key from self::cache_key().
     * @param mixed  $value      Value to store.
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date   End date (Y-m-d).
     * @return void
     */
    private static function cache_set( $key, $value, $start_date, $end_date ) {
        if ( self::range_days( $start_date, $end_date ) <= self::CACHE_MIN_RANGE_DAYS ) {
            return;
        }

        set_transient( $key, $value, self::CACHE_TTL );
    }

    /**
     * Invalidate every cached aggregate.
     *
     * Bumping the salt changes every future cache key, so the stale transients
     * are simply never read again and expire on their own.
     *
     * @since 2.5.0
     * @return void
     */
    public static function flush_stats_cache() {
        update_option( 'vigia_stats_cache_salt', (int) get_option( 'vigia_stats_cache_salt', 0 ) + 1, false );
        delete_transient( 'vigia_first_visit_date' );
    }

    /**
     * Note that somebody is using the statistics screens.
     *
     * Only sites where the dashboard actually gets opened pay for the cache
     * warm-up below; on the rest the cron does nothing.
     *
     * @since 2.5.0
     * @return void
     */
    public static function mark_dashboard_in_use() {
        if ( ! get_transient( 'vigia_dashboard_in_use' ) ) {
            set_transient( 'vigia_dashboard_in_use', 1, WEEK_IN_SECONDS );
        }

        // Until the first warm-up runs, every long range is computed from
        // scratch on whoever asks for it first. Waiting for the hourly tick
        // would leave a freshly updated site slow for up to an hour, so the
        // first run is pulled forward to a minute after somebody opens the
        // dashboard.
        if ( ! get_option( 'vigia_stats_warmed_at', 0 ) && ! wp_next_scheduled( 'vigia_warm_stats_cache_now' ) ) {
            wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'vigia_warm_stats_cache_now' );
        }
    }

    /**
     * Precompute the aggregates for the long ranges offered by the UI.
     *
     * Caching alone still leaves the first visitor after each expiry waiting
     * for the full computation. Doing it from cron means that on a site people
     * actually look at, the long ranges are essentially always served warm.
     *
     * The ranges here mirror the options in the period selector that exceed
     * CACHE_MIN_RANGE_DAYS, and both the ranges and the page size are built
     * exactly as the REST layer builds them (first page, default limit) so the
     * keys match what the dashboard will actually ask for. Warming a different
     * limit would compute the values and still leave the user waiting.
     *
     * @since 2.5.0
     * @return int Number of ranges warmed.
     */
    public static function warm_stats_cache() {
        if ( ! get_transient( 'vigia_dashboard_in_use' ) ) {
            return 0;
        }

        $end    = gmdate( 'Y-m-d' );
        $limit  = self::DASHBOARD_PAGE_SIZE;
        $ranges = array(
            gmdate( 'Y-m-d', strtotime( '-90 days' ) ),
            gmdate( 'Y-m-d', strtotime( '-180 days' ) ),
            gmdate( 'Y-m-d', strtotime( '-365 days' ) ),
            '2000-01-01', // "All time", as get_date_range_from_request() builds it.
        );

        foreach ( $ranges as $start ) {
            $period_days = max( 1, (int) round( ( strtotime( $end ) - strtotime( $start ) ) / DAY_IN_SECONDS ) );
            $prev_end    = gmdate( 'Y-m-d', strtotime( $start . ' -1 day' ) );
            $prev_start  = gmdate( 'Y-m-d', strtotime( $prev_end . " -{$period_days} days" ) );

            // "All time" has no comparable previous window, and the REST layer
            // asks for it without trend dates.
            if ( '2000-01-01' === $start ) {
                self::get_top_pages( $start, $end, $limit, 0 );
            } else {
                self::get_top_pages( $start, $end, $limit, 0, $prev_start, $prev_end );
            }

            self::get_pages_count( $start, $end );
            self::get_visits_by_crawler( $start, $end, $limit, 0 );
            self::get_crawlers_count( $start, $end );
            self::get_stats( $start, $end );
            self::get_visits_over_time( $start, $end );
            self::get_daily_crawler_breakdown( $start, $end );
            self::get_visits_by_category( $start, $end );
        }

        update_option( 'vigia_stats_warmed_at', time(), false );

        return count( $ranges );
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
            KEY crawler_category (crawler_category),
            KEY content_type (content_type)
        ) {$charset_collate};";
        // The composite indexes are deliberately NOT declared here. dbDelta
        // runs on an admin request, and on a table with a long history adding
        // them takes minutes — exactly the wait this release exists to remove.
        // create_performance_indexes() adds them from cron (or from the button
        // in Settings) instead, and on a fresh install straight away, where the
        // table is empty and it costs nothing.
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

        // Everything expensive is handed to cron. Sites upgrading via wp.org
        // auto-update do not go through the activation hook, so this is where
        // the queues get scheduled for them.
        self::nudge_backfill_cron();
        self::schedule_index_optimization();

        if ( ! wp_next_scheduled( 'vigia_warm_stats_cache' ) ) {
            wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), 'hourly', 'vigia_warm_stats_cache' );
        }
    }

    /**
     * Queue the index creation to run in the background.
     *
     * @since 2.5.0
     * @return void
     */
    public static function schedule_index_optimization() {
        global $wpdb;

        if ( self::has_performance_indexes() ) {
            return;
        }

        // On a small table (a fresh install, or one that has barely collected
        // anything) the ALTER is instant, so there is no reason to make the
        // user wait for a cron tick to get a properly indexed table.
        $table_name = self::get_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Row count decides whether the index build is safe to do inline; must not be cached.
        $rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name ) );

        if ( $rows < 50000 ) {
            self::create_performance_indexes();
            return;
        }

        if ( ! wp_next_scheduled( 'vigia_optimize_indexes' ) ) {
            wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'vigia_optimize_indexes' );
        }
    }

    /**
     * Index names currently present on the visits table.
     *
     * @since 2.5.0
     * @return array<string> Index names, empty when the table is missing.
     */
    private static function get_existing_indexes() {
        global $wpdb;

        $table_name = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Structural check on a custom table, must not be cached.
        $rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table_name ), ARRAY_A );

        if ( empty( $rows ) ) {
            return array();
        }

        return array_values( array_unique( wp_list_pluck( $rows, 'Key_name' ) ) );
    }

    /**
     * Whether every composite index is already in place.
     *
     * @since 2.5.0
     * @return bool
     */
    public static function has_performance_indexes() {
        return empty( self::get_missing_indexes() );
    }

    /**
     * Which composite indexes are still missing.
     *
     * @since 2.5.0
     * @return array<string> Missing index names.
     */
    public static function get_missing_indexes() {
        global $wpdb;

        $table_name = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Structural check on a custom table, must not be cached.
        $table_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

        if ( '' === $table_exists ) {
            // No table yet (the plugin has never been activated here): there is
            // nothing to index and nothing to report.
            return array();
        }

        $existing = self::get_existing_indexes();

        // The table is there but its indexes could not be read — a restricted
        // database user, most likely. Report everything as missing rather than
        // assuming all is well: an optimistic answer here would hide the button
        // AND skip the creation, leaving the site slow with no way out.
        if ( empty( $existing ) ) {
            return self::PERFORMANCE_INDEXES;
        }

        $missing = array();
        foreach ( self::PERFORMANCE_INDEXES as $name ) {
            if ( ! in_array( $name, $existing, true ) ) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * Status of the index optimization, for the settings screen.
     *
     * @since 2.5.0
     * @return array {
     *     @type bool  $ready     True when every index is in place.
     *     @type array $missing   Names of the indexes still missing.
     *     @type bool  $scheduled True when a background run is already queued.
     * }
     */
    public static function get_index_status() {
        $missing = self::get_missing_indexes();

        return array(
            'ready'     => empty( $missing ),
            'missing'   => $missing,
            'scheduled' => (bool) wp_next_scheduled( 'vigia_optimize_indexes' ),
        );
    }

    /**
     * Create the composite indexes, then drop the ones they supersede.
     *
     * Runs from cron (or from the button in Settings), never during a normal
     * page load: on a table with millions of rows the ALTER can take minutes.
     * INPLACE/LOCK=NONE keeps the site writable while it runs, with a plain
     * ALTER as fallback for servers that reject those clauses, and a
     * 191-char-prefix definition as a second fallback for tables in the old
     * COMPACT row format, where a key is capped at 767 bytes.
     *
     * @since 2.5.0
     * @return array<string> Names of the indexes created in this run.
     */
    public static function create_performance_indexes() {
        global $wpdb;

        $missing = self::get_missing_indexes();
        if ( empty( $missing ) ) {
            self::drop_redundant_indexes();
            return array();
        }

        $created  = array();
        $suppress = $wpdb->suppress_errors( true );

        foreach ( $missing as $name ) {
            if ( self::add_index( $name ) ) {
                $created[] = $name;
            }
        }

        $wpdb->suppress_errors( $suppress );

        if ( self::has_performance_indexes() ) {
            self::drop_redundant_indexes();
            update_option( 'vigia_indexes_ready', true, false );
        }

        return $created;
    }

    /**
     * Create one index by name.
     *
     * Every statement is a literal, with only %i identifiers filled in by
     * prepare(): no SQL is built from variables, so there is nothing here for
     * an injection to reach and nothing a static analyser has to take on
     * trust. Each index is tried twice, first asking for INPLACE/LOCK=NONE so
     * the site stays writable, then plainly for servers that reject those
     * clauses.
     *
     * The indexes on request_path cover the column in full, which is what lets
     * MySQL group and count distinct paths from the index alone. A 500-char
     * utf8mb4 column needs a 2000-byte key, over the 767-byte limit of the old
     * COMPACT row format, so each of those falls back to a 191-char prefix:
     * slower, but correct, and only on tables old enough to still use that
     * format.
     *
     * @since 2.5.0
     * @param string $name One of self::PERFORMANCE_INDEXES.
     * @return bool True when the index exists after this call.
     */
    private static function add_index( $name ) {
        global $wpdb;

        $table = self::get_table_name();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Creating the analytics indexes is the purpose of this method; it runs from cron or an explicit admin action, never on a page load.
        switch ( $name ) {
            case 'vigia_vd_crawler':
                $done = $wpdb->query(
                    $wpdb->prepare( 'ALTER TABLE %i ADD KEY %i (visit_date, crawler_name, crawler_category), ALGORITHM=INPLACE, LOCK=NONE', $table, $name )
                );
                if ( false === $done ) {
                    $done = $wpdb->query(
                        $wpdb->prepare( 'ALTER TABLE %i ADD KEY %i (visit_date, crawler_name, crawler_category)', $table, $name )
                    );
                }
                break;

            case 'vigia_path_vd':
                $done = $wpdb->query(
                    $wpdb->prepare( 'ALTER TABLE %i ADD KEY %i (request_path, visit_date), ALGORITHM=INPLACE, LOCK=NONE', $table, $name )
                );
                if ( false === $done ) {
                    $done = $wpdb->query(
                        $wpdb->prepare( 'ALTER TABLE %i ADD KEY %i (request_path, visit_date)', $table, $name )
                    );
                }
                if ( false === $done ) {
                    // Old COMPACT row format: the full column will not fit in a key.
                    $done = $wpdb->query(
                        $wpdb->prepare( 'ALTER TABLE %i ADD KEY %i (request_path(191), visit_date)', $table, $name )
                    );
                }
                break;

            case 'vigia_crawler_vd':
                $done = $wpdb->query(
                    $wpdb->prepare( 'ALTER TABLE %i ADD KEY %i (crawler_name, visit_date, request_path), ALGORITHM=INPLACE, LOCK=NONE', $table, $name )
                );
                if ( false === $done ) {
                    $done = $wpdb->query(
                        $wpdb->prepare( 'ALTER TABLE %i ADD KEY %i (crawler_name, visit_date, request_path)', $table, $name )
                    );
                }
                if ( false === $done ) {
                    $done = $wpdb->query(
                        $wpdb->prepare( 'ALTER TABLE %i ADD KEY %i (crawler_name, visit_date)', $table, $name )
                    );
                }
                break;

            default:
                $done = false;
                break;
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

        return false !== $done;
    }

    /**
     * Drop the single-column indexes the composite ones make redundant.
     *
     * Only ever called once the replacements are confirmed present, so the
     * table is never left without a usable index.
     *
     * @since 2.5.0
     * @return void
     */
    private static function drop_redundant_indexes() {
        global $wpdb;

        $existing = self::get_existing_indexes();
        if ( empty( $existing ) ) {
            return;
        }

        $table    = self::get_table_name();
        $suppress = $wpdb->suppress_errors( true );

        foreach ( self::REDUNDANT_INDEXES as $name ) {
            if ( ! in_array( $name, $existing, true ) ) {
                continue;
            }

            // Same literal-query rule as add_index(): only %i identifiers are
            // filled in, nothing is concatenated.
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the superseded indexes is the purpose of this method; it only runs after the replacements are confirmed present.
            $done = $wpdb->query(
                $wpdb->prepare( 'ALTER TABLE %i DROP KEY %i, ALGORITHM=INPLACE, LOCK=NONE', $table, $name )
            );

            if ( false === $done ) {
                // Retry without the INPLACE clauses for servers that reject them.
                $wpdb->query(
                    $wpdb->prepare( 'ALTER TABLE %i DROP KEY %i', $table, $name )
                );
            }
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        }

        $wpdb->suppress_errors( $suppress );
    }

    /**
     * Composite indexes the analytics screens rely on.
     *
     * Every dashboard query filters by a date range and then groups by page or
     * by crawler. With only the single-column indexes the table shipped with,
     * MySQL judged the range too wide to be worth an index lookup and fell back
     * to scanning the whole table — including the user_agent and request_url
     * TEXT columns, which are the bulk of its size.
     *
     * - vigia_vd_crawler (visit_date, crawler_name, crawler_category)
     *   Top crawlers, timeline, per-day breakdown, totals by range.
     * - vigia_path_vd (request_path, visit_date)
     *   Grouping and counting by page, plus the per-page lookups.
     * - vigia_crawler_vd (crawler_name, visit_date, request_path)
     *   Per-crawler lookups, including the unique page count without
     *   touching the rows.
     *
     * request_path is indexed in full, not by a prefix. That is what lets
     * MySQL group and count distinct values straight from the index: with a
     * prefix it cannot tell two paths apart from the index alone and falls
     * back to reading every row. Measured on 610k visits, grouping the whole
     * history went from 2.3 s to 0.29 s, and counting distinct pages from
     * 1.3 s to 0.09 s — while the index total actually got *smaller*, because
     * three well-chosen indexes replace the five the table used to carry.
     *
     * The definitions live in add_index() as literal queries, one per index, so
     * no SQL is ever assembled from variables.
     *
     * @since 2.5.0
     */
    const PERFORMANCE_INDEXES = array( 'vigia_vd_crawler', 'vigia_path_vd', 'vigia_crawler_vd' );

    /**
     * Single-column indexes made redundant by the composite ones.
     *
     * Each is a left-most prefix of one of the new indexes, so MySQL can use
     * the composite wherever it used these. Dropping them keeps the table from
     * carrying two copies of the same data.
     *
     * @since 2.5.0
     */
    const REDUNDANT_INDEXES = array( 'visit_date', 'request_path', 'crawler_name' );

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

        $cache_key = self::cache_key( 'stats', array( $start_date, $end_date ) );
        $cached    = self::cache_get( $cache_key, $start_date, $end_date );
        if ( false !== $cached ) {
            return $cached;
        }

        $table_name = self::get_table_name();

        if ( self::covers_full_history( $start_date, $end_date ) ) {
            // Three separate queries here, not one: without a WHERE each
            // COUNT DISTINCT is answered by a loose index scan that reads one
            // entry per distinct value. Combining them into a single row would
            // force a full pass and undo the gain (measured: 0.20 s split,
            // 1.89 s combined).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
            $total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
            $crawlers = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT crawler_name) FROM %i', $table_name ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
            $pages = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT request_path) FROM %i', $table_name ) );

            $stats = array(
                'total_visits'    => absint( $total ),
                'unique_crawlers' => absint( $crawlers ),
                'unique_pages'    => absint( $pages ),
            );
        } else {
            // One pass over the range for the three numbers. With a WHERE the
            // loose index scan is off the table anyway, so a single query beats
            // three passes over the same rows.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT COUNT(*) as total_visits,
                        COUNT(DISTINCT crawler_name) as unique_crawlers,
                        COUNT(DISTINCT request_path) as unique_pages
                    FROM %i
                    WHERE visit_date BETWEEN %s AND %s',
                    $table_name,
                    $start_date . ' 00:00:00',
                    $end_date . ' 23:59:59'
                ),
                ARRAY_A
            );

            $stats = array(
                'total_visits'    => isset( $row['total_visits'] ) ? absint( $row['total_visits'] ) : 0,
                'unique_crawlers' => isset( $row['unique_crawlers'] ) ? absint( $row['unique_crawlers'] ) : 0,
                'unique_pages'    => isset( $row['unique_pages'] ) ? absint( $row['unique_pages'] ) : 0,
            );
        }

        self::cache_set( $cache_key, $stats, $start_date, $end_date );

        return $stats;
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

        $cache_key = self::cache_key( 'visits_by_crawler', array( $start_date, $end_date, $limit, $offset ) );
        $cached    = self::cache_get( $cache_key, $start_date, $end_date );
        if ( false !== $cached ) {
            return $cached;
        }

        $table_name = self::get_table_name();

        // unique_pages is deliberately NOT part of this aggregate. A
        // COUNT(DISTINCT request_path) alongside the GROUP BY forces MySQL to
        // hold every distinct path per crawler in a temporary table, which on a
        // year of history took longer than the rest of the dashboard put
        // together. It is resolved below, once per crawler actually shown.
        $full_history = self::covers_full_history( $start_date, $end_date );

        if ( $full_history ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT crawler_name, crawler_category, COUNT(*) as visit_count, MAX(visit_date) as last_visit
                    FROM %i
                    GROUP BY crawler_name, crawler_category
                    ORDER BY visit_count DESC, crawler_name ASC
                    LIMIT %d OFFSET %d',
                    $table_name,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT crawler_name, crawler_category, COUNT(*) as visit_count, MAX(visit_date) as last_visit
                    FROM %i
                    WHERE visit_date BETWEEN %s AND %s
                    GROUP BY crawler_name, crawler_category
                    ORDER BY visit_count DESC, crawler_name ASC
                    LIMIT %d OFFSET %d',
                    $table_name,
                    $start_date . ' 00:00:00',
                    $end_date . ' 23:59:59',
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        }

        if ( ! $results ) {
            return array();
        }

        // Counting the rows of a GROUP BY beats COUNT(DISTINCT) here by about a
        // fifth on a busy crawler: MySQL collapses duplicate paths as it walks
        // the index instead of accumulating them all to deduplicate at the end.
        // Same number, less work.
        foreach ( $results as &$row ) {
            if ( $full_history ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
                $unique_pages = $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT COUNT(*) FROM (
                            SELECT request_path FROM %i
                            WHERE crawler_name = %s AND crawler_category = %s
                            GROUP BY request_path
                        ) as pages',
                        $table_name,
                        $row['crawler_name'],
                        $row['crawler_category']
                    )
                );
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
                $unique_pages = $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT COUNT(*) FROM (
                            SELECT request_path FROM %i
                            WHERE crawler_name = %s AND crawler_category = %s
                            AND visit_date BETWEEN %s AND %s
                            GROUP BY request_path
                        ) as pages',
                        $table_name,
                        $row['crawler_name'],
                        $row['crawler_category'],
                        $start_date . ' 00:00:00',
                        $end_date . ' 23:59:59'
                    )
                );
            }
            $row['unique_pages'] = (int) $unique_pages;
        }
        unset( $row );

        self::cache_set( $cache_key, $results, $start_date, $end_date );

        return $results;
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

        $cache_key = self::cache_key( 'crawlers_count', array( $start_date, $end_date ) );
        $cached    = self::cache_get( $cache_key, $start_date, $end_date );
        if ( false !== $cached ) {
            return (int) $cached;
        }

        $table_name = self::get_table_name();

        if ( self::covers_full_history( $start_date, $end_date ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
            $count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT crawler_name) FROM %i', $table_name ) );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(DISTINCT crawler_name) FROM %i WHERE visit_date BETWEEN %s AND %s',
                    $table_name,
                    $start_date . ' 00:00:00',
                    $end_date . ' 23:59:59'
                )
            );
        }

        $count = absint( $count );
        self::cache_set( $cache_key, $count, $start_date, $end_date );

        return $count;
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

        $cache_key = self::cache_key( 'visits_by_category', array( $start_date, $end_date ) );
        $cached    = self::cache_get( $cache_key, $start_date, $end_date );
        if ( false !== $cached ) {
            return $cached;
        }

        $table_name = self::get_table_name();

        if ( self::covers_full_history( $start_date, $end_date ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT crawler_category, COUNT(*) as visit_count
                    FROM %i
                    GROUP BY crawler_category
                    ORDER BY visit_count DESC, crawler_category ASC',
                    $table_name
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT crawler_category, COUNT(*) as visit_count
                    FROM %i
                    WHERE visit_date BETWEEN %s AND %s
                    GROUP BY crawler_category
                    ORDER BY visit_count DESC, crawler_category ASC',
                    $table_name,
                    $start_date . ' 00:00:00',
                    $end_date . ' 23:59:59'
                ),
                ARRAY_A
            );
        }

        $results = $results ? $results : array();
        self::cache_set( $cache_key, $results, $start_date, $end_date );

        return $results;
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

        $cache_key = self::cache_key( 'visits_over_time', array( $start_date, $end_date ) );
        $cached    = self::cache_get( $cache_key, $start_date, $end_date );
        if ( false !== $cached ) {
            return $cached;
        }

        $table_name = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
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

        $results = $results ? $results : array();
        self::cache_set( $cache_key, $results, $start_date, $end_date );

        return $results;
    }

    /**
     * Get most crawled pages with pagination support
     *
     * @param string $start_date Start date.
     * @param string $end_date   End date.
     * @param int    $limit      Max results (default 20).
     * @param int    $offset     Offset for pagination (default 0).
     * @param string $prev_start Optional previous-period start date. When both
     *                           prev dates are set, each row also carries a
     *                           `prev_visit_count` for trend calculation.
     * @param string $prev_end   Optional previous-period end date.
     * @return array Page visit counts.
     */
    public static function get_top_pages( $start_date, $end_date, $limit = 20, $offset = 0, $prev_start = '', $prev_end = '' ) {
        global $wpdb;

        $cache_key = self::cache_key( 'top_pages', array( $start_date, $end_date, $limit, $offset, $prev_start, $prev_end ) );
        $cached    = self::cache_get( $cache_key, $start_date, $end_date );
        if ( false !== $cached ) {
            return $cached;
        }

        $table_name = self::get_table_name();
        $with_trend = ( '' !== $prev_start && '' !== $prev_end );

        // Two-step instead of one query with correlated subqueries.
        //
        // Step 1 ranks the paths with a plain aggregate. On a site with a long
        // history this is the only query that touches the whole date range, and
        // the (visit_date, request_path) index resolves it without reading the
        // rows themselves.
        //
        // Step 2 fills in the per-path details, but only for the handful of
        // paths this page actually shows. The previous implementation put those
        // details in correlated subqueries, which MySQL evaluates for *every*
        // group in the range — tens of thousands of them on a real site — before
        // the ORDER BY/LIMIT trims the result down to 20. That is what made the
        // table unusable beyond 60-90 days.
        //
        // The tie-breaker on request_path keeps the ranking stable across pages;
        // without it two paths with the same visit_count could swap places
        // between requests and appear twice (or not at all) when paginating.
        $full_history = self::covers_full_history( $start_date, $end_date );

        if ( $full_history ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT request_path, COUNT(*) as visit_count
                    FROM %i
                    GROUP BY request_path
                    ORDER BY visit_count DESC, request_path ASC
                    LIMIT %d OFFSET %d',
                    $table_name,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT request_path, COUNT(*) as visit_count
                    FROM %i
                    WHERE visit_date BETWEEN %s AND %s
                    GROUP BY request_path
                    ORDER BY visit_count DESC, request_path ASC
                    LIMIT %d OFFSET %d',
                    $table_name,
                    $start_date . ' 00:00:00',
                    $end_date . ' 23:59:59',
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        }

        if ( ! $results ) {
            return array();
        }

        foreach ( $results as &$row ) {
            $path = $row['request_path'];

            if ( $full_history ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
                $crawler_count = $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT COUNT(DISTINCT crawler_name) FROM %i WHERE request_path = %s',
                        $table_name,
                        $path
                    )
                );
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
                $crawler_count = $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT COUNT(DISTINCT crawler_name) FROM %i
                        WHERE request_path = %s AND visit_date BETWEEN %s AND %s',
                        $table_name,
                        $path,
                        $start_date . ' 00:00:00',
                        $end_date . ' 23:59:59'
                    )
                );
            }
            $row['crawler_count'] = (int) $crawler_count;

            // Dominant content_type for the path (most frequent value), not
            // MAX() — a page hit as both 200 (post) and 404 must show the type
            // it mostly served, and MAX() would just return the
            // alphabetically-last value.
            if ( $full_history ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
                $content_type = (string) $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT content_type FROM %i
                        WHERE request_path = %s
                        GROUP BY content_type
                        ORDER BY COUNT(*) DESC
                        LIMIT 1',
                        $table_name,
                        $path
                    )
                );
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
                $content_type = (string) $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT content_type FROM %i
                        WHERE request_path = %s AND visit_date BETWEEN %s AND %s
                        GROUP BY content_type
                        ORDER BY COUNT(*) DESC
                        LIMIT 1',
                        $table_name,
                        $path,
                        $start_date . ' 00:00:00',
                        $end_date . ' 23:59:59'
                    )
                );
            }

            // Rows captured before VigIA 2.0.0 still carry an empty
            // content_type until the backfill cron drains them. Classify the
            // handful of paths on screen on the fly (detect_content_type()
            // memoizes per request) so the column is right immediately,
            // instead of running a 2000-row backfill on every page load.
            if ( '' === $content_type ) {
                $content_type = self::detect_content_type( $path );
            }
            $row['content_type'] = '' !== $content_type ? $content_type : 'other';

            if ( $with_trend ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
                $row['prev_visit_count'] = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT COUNT(*) FROM %i
                        WHERE request_path = %s AND visit_date BETWEEN %s AND %s',
                        $table_name,
                        $path,
                        $prev_start . ' 00:00:00',
                        $prev_end . ' 23:59:59'
                    )
                );
            }
        }
        unset( $row );

        self::cache_set( $cache_key, $results, $start_date, $end_date );

        return $results;
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

        $cache_key = self::cache_key( 'pages_count', array( $start_date, $end_date ) );
        $cached    = self::cache_get( $cache_key, $start_date, $end_date );
        if ( false !== $cached ) {
            return (int) $cached;
        }

        $table_name = self::get_table_name();

        if ( self::covers_full_history( $start_date, $end_date ) ) {
            // Answered from the index alone, one entry per distinct path.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
            $count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT request_path) FROM %i', $table_name ) );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(DISTINCT request_path) FROM %i WHERE visit_date BETWEEN %s AND %s',
                    $table_name,
                    $start_date . ' 00:00:00',
                    $end_date . ' 23:59:59'
                )
            );
        }

        $count = absint( $count );
        self::cache_set( $cache_key, $count, $start_date, $end_date );

        return $count;
    }

    /**
     * Get the per-crawler breakdown for a single page (request_path).
     *
     * Powers the expandable row in the "Most crawled pages" table: which bots
     * hit this exact URL and how many times. Single-path lookup (no IN clause),
     * fully prepared.
     *
     * @param string $request_path The page path to break down.
     * @param string $start_date   Start date.
     * @param string $end_date     End date.
     * @param int    $limit        Max crawlers to return (default 50).
     * @return array Rows of crawler_name, crawler_category, visit_count.
     */
    public static function get_crawlers_for_path( $request_path, $start_date, $end_date, $limit = 50 ) {
        global $wpdb;

        $table_name = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, real-time data
        $results = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT crawler_name, crawler_category, COUNT(*) as visit_count
                FROM %i
                WHERE request_path = %s
                AND visit_date BETWEEN %s AND %s
                GROUP BY crawler_name, crawler_category
                ORDER BY visit_count DESC, crawler_name ASC
                LIMIT %d',
                $table_name,
                $request_path,
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59',
                $limit
            ),
            ARRAY_A
        );

        return $results ? $results : array();
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
        list( $where, $values ) = self::build_visits_filter( $args );

        // $where is a dynamically composed fragment that contains ONLY %s and
        // %d placeholders (every clause appends a placeholder, and each
        // placeholder has its matching value pushed into $values). The
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

        // id breaks ties on visit_date. Crawlers arrive in bursts, so plenty of
        // visits share the same second; without a tie-breaker MySQL is free to
        // order them differently on each query and a row could show up on two
        // pages, or on none.
        $items = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT crawler_name, crawler_category, request_path, ip_address, http_status, content_type, visit_date FROM %i WHERE ' . $where . ' ORDER BY visit_date DESC, id DESC LIMIT %d OFFSET %d',
                array_merge( array( $table_name ), $values, array( $per_page, $offset ) )
            ),
            ARRAY_A
        );
        // phpcs:enable

        $items = self::fill_missing_content_types( $items );

        return array(
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
        );
    }

    /**
     * Build the WHERE fragment shared by the activity table and the CSV export.
     *
     * @since 2.5.0
     * @param array $args Filter args, as documented on query_visits().
     * @return array{0:string,1:array} The WHERE body (no "WHERE" keyword) and its values.
     */
    private static function build_visits_filter( $args ) {
        $clauses = array( '1=1' );
        $values  = array();

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

        if ( isset( $args['http_status'] ) && '' !== $args['http_status'] ) {
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
            // Indexed lookup on the column populated at insert time. Rows
            // captured before VigIA 2.0.0 may still have content_type='' until
            // the backfill cron drains them; nudging that cron is as far as
            // this goes. Filling rows inline here used to cost thousands of
            // queries inside the user's request, which is precisely what made
            // the filtered views unusable on sites with a long history.
            self::nudge_backfill_cron();

            $clauses[] = 'content_type = %s';
            $values[]  = sanitize_key( $args['content_type'] );
        }

        return array( implode( ' AND ', $clauses ), $values );
    }

    /**
     * Classify the rows that the backfill cron has not reached yet.
     *
     * Only rows with no value at all are looked up. Rows already stored as
     * "other" are a finished answer, not a pending one: re-deriving them on
     * every read was costing a lookup per row, which is what made exporting a
     * month of activity fire tens of thousands of queries.
     *
     * @since 2.5.0
     * @param array $items Rows with request_path, http_status and content_type.
     * @return array The same rows, with content_type filled in.
     */
    private static function fill_missing_content_types( $items ) {
        if ( empty( $items ) ) {
            return array();
        }

        foreach ( $items as $idx => $row ) {
            if ( ! empty( $row['content_type'] ) ) {
                continue;
            }
            // detect_content_type() memoizes per request, so repeated paths
            // within the same page cost one lookup between them all.
            $items[ $idx ]['content_type'] = self::detect_content_type( $row['request_path'], $row['http_status'] );
        }

        return $items;
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
        //    It dereferences the global $wp_rewrite, which WordPress only
        //    instantiates between plugins_loaded and init. On an early-exit
        //    request (for example a redirect plugin issuing a 301 and exit()
        //    before init) the shutdown tracker runs with $wp_rewrite still
        //    null, so guard the call and fall through to the lookups below
        //    that do not need the rewrite rules.
        $post_id = empty( $GLOBALS['wp_rewrite'] ) ? 0 : url_to_postid( $full_url );
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
     * Ask the backfill cron to run soon, without doing any work here.
     *
     * @since 2.5.0
     * @return void
     */
    private static function nudge_backfill_cron() {
        if ( ! wp_next_scheduled( 'vigia_backfill_content_type' ) ) {
            wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'vigia_backfill_content_type' );
        }
    }

    /**
     * Backfill content_type for rows captured before VigIA 2.0.0, optionally
     * restricted to a date range.
     *
     * Only rows that were never classified (content_type '' or NULL) are
     * picked up. Rows already bucketed as "other" are legitimate results, not
     * pending work: re-reading them on every pass meant this never drained and
     * kept re-running the same thousands of lookups forever. The one-off
     * re-classification of the legacy "other" bucket is handled separately by
     * reclassify_other_bucket(), which runs once from cron.
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

        $clauses = array( "(content_type = '' OR content_type IS NULL)" );
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
     * One-off re-classification of the legacy "other" bucket.
     *
     * DB version 1.1.0 split "other" into home / not-found / admin /
     * wp-system, so rows classified by earlier versions need a second pass.
     * This used to happen inside backfill_content_types_in_range() on every
     * call, which meant a page load could re-detect thousands of rows that
     * were going to come back as "other" anyway. It now runs from cron and
     * stops for good once the whole table has been swept.
     *
     * @since 2.5.0
     * @param int $batch Rows to process in this run.
     * @return int Rows updated. 0 when there is nothing left to do.
     */
    public static function reclassify_other_bucket( $batch = 500 ) {
        global $wpdb;

        if ( get_option( 'vigia_other_bucket_swept', false ) ) {
            return 0;
        }

        $table_name = self::get_table_name();
        $batch      = max( 1, (int) $batch );
        $last_id    = (int) get_option( 'vigia_other_bucket_cursor', 0 );

        // Walking by id keeps this O(batch) per run: rows that stay "other"
        // would otherwise match the WHERE again on the next tick and the sweep
        // would never advance.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, maintenance cron
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, request_path, http_status FROM %i
                WHERE id > %d AND content_type = 'other'
                ORDER BY id ASC
                LIMIT %d",
                $table_name,
                $last_id,
                $batch
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            update_option( 'vigia_other_bucket_swept', true, false );
            delete_option( 'vigia_other_bucket_cursor' );
            return 0;
        }

        $updated = 0;
        foreach ( $rows as $row ) {
            $type = self::detect_content_type( $row['request_path'], $row['http_status'] );
            if ( 'other' !== $type ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, maintenance cron
                $wpdb->update(
                    $table_name,
                    array( 'content_type' => $type ),
                    array( 'id' => (int) $row['id'] ),
                    array( '%s' ),
                    array( '%d' )
                );
                $updated++;
            }
            $last_id = (int) $row['id'];
        }

        update_option( 'vigia_other_bucket_cursor', $last_id, false );

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
        global $wpdb;

        $args = array_merge(
            (array) $filters,
            array(
                'date_from' => $start_date,
                'date_to'   => $end_date,
            )
        );

        $table_name = self::get_table_name();
        list( $where, $values ) = self::build_visits_filter( $args );

        $all      = array();
        $batch    = 1000;
        $last_id  = PHP_INT_MAX;
        $max_rows = 50000; // Safety stop, to keep a runaway export from eating the whole table.

        // Walk down by id instead of paging with OFFSET. The old version called
        // query_visits() once per 100 rows, which meant a full COUNT(*) per
        // page (477 of them for a month of data) and an OFFSET that grows until
        // MySQL is skipping tens of thousands of rows to reach the next batch.
        // A descending id also gives the same ordering as visit_date without
        // ties, so no row can be exported twice or skipped.
        do {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where holds only literal SQL plus placeholders, resolved by prepare() with $values; see build_visits_filter().
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id, crawler_name, crawler_category, request_path, ip_address, http_status, content_type, visit_date FROM %i WHERE ' . $where . ' AND id < %d ORDER BY id DESC LIMIT %d',
                    array_merge( array( $table_name ), $values, array( $last_id, $batch ) )
                ),
                ARRAY_A
            );
            // phpcs:enable

            if ( empty( $rows ) ) {
                break;
            }

            $last_row = end( $rows );
            $last_id  = (int) $last_row['id'];

            foreach ( $rows as $row ) {
                unset( $row['id'] );
                $all[] = $row;
            }

            if ( count( $rows ) < $batch || count( $all ) >= $max_rows ) {
                break;
            }
        } while ( true );

        // Classifying the rows the backfill cron has not reached yet costs one
        // lookup each, and an export can carry tens of thousands of them —
        // that alone kept this slow even after the queries were fixed. So it
        // only happens when there are few enough for the cost to be invisible.
        // On a site whose backfill has finished, which is every site after a
        // few hours, there are none and this is free. Beyond the threshold the
        // column is exported as stored and shows as "other" until the cron
        // catches up.
        $pending = 0;
        foreach ( $all as $row ) {
            if ( '' === (string) $row['content_type'] ) {
                $pending++;
                if ( $pending > self::EXPORT_CLASSIFY_LIMIT ) {
                    return $all;
                }
            }
        }

        return $pending > 0 ? self::fill_missing_content_types( $all ) : $all;
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

        if ( $deleted ) {
            self::flush_stats_cache();
        }

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

        self::flush_stats_cache();

        return true;
    }

    /**
     * Get click data per path from Share Buttons & AI-powered Summaries (if active)
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

        // Existence check, cached for a day. It reads information_schema,
        // which is slow on hosts with thousands of tables, and the answer only
        // changes when the sibling plugin is installed or removed.
        $table_exists = get_transient( 'vigia_aiss_table_exists' );

        if ( false === $table_exists ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Structural check, cached in the transient right below.
            $table_exists = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
                    DB_NAME,
                    $aiss_table
                )
            ) ? 1 : 0;

            set_transient( 'vigia_aiss_table_exists', $table_exists, DAY_IN_SECONDS );
        }

        if ( ! $table_exists ) {
            return array();
        }

        $click_data = array();
        $site_url   = home_url();

        // url_to_postid() parses the rewrite rules and hits the database on
        // every call. Resolving the same handful of paths on every dashboard
        // load added up, so the mapping is remembered for a day.
        $path_map     = get_transient( 'vigia_path_postid_map' );
        $path_map     = is_array( $path_map ) ? $path_map : array();
        $map_modified = false;

        foreach ( $paths as $path ) {
            if ( array_key_exists( $path, $path_map ) ) {
                $post_id = (int) $path_map[ $path ];
            } else {
                $full_url            = trailingslashit( $site_url ) . ltrim( $path, '/' );
                $post_id             = (int) url_to_postid( $full_url );
                $path_map[ $path ]   = $post_id;
                $map_modified        = true;
            }

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

        if ( $map_modified ) {
            // Cap the map so a site crawled across thousands of URLs does not
            // grow an unbounded option row.
            if ( count( $path_map ) > 2000 ) {
                $path_map = array_slice( $path_map, -1000, null, true );
            }
            set_transient( 'vigia_path_postid_map', $path_map, DAY_IN_SECONDS );
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

        $cache_key = self::cache_key( 'daily_crawler_breakdown', array( $start_date, $end_date ) );
        $cached    = self::cache_get( $cache_key, $start_date, $end_date );
        if ( false !== $cached ) {
            return $cached;
        }

        $table_name = self::get_table_name();

        if ( self::covers_full_history( $start_date, $end_date ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT DATE(visit_date) as date, crawler_name, COUNT(*) as visit_count
                    FROM %i
                    GROUP BY DATE(visit_date), crawler_name
                    ORDER BY date ASC, visit_count DESC',
                    $table_name
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics table, cached above via self::cache_get()
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
        }

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

        self::cache_set( $cache_key, $breakdown, $start_date, $end_date );

        return $breakdown;
    }
}