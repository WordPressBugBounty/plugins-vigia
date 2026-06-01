<?php
/**
 * REST API class
 *
 * Provides REST endpoints for AJAX data loading in the admin dashboard.
 *
 * @package VigIA
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API class
 */
class VigIA_Rest_API {

    /**
     * API namespace
     */
    const API_NAMESPACE = 'vigia/v1';

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // Stats endpoint
        register_rest_route(
            self::API_NAMESPACE,
            '/stats',
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'get_stats' ),
                'permission_callback' => array( __CLASS__, 'check_permission' ),
                'args'                => self::get_date_args(),
            )
        );

        // Stats comparison endpoint
        register_rest_route(
            self::API_NAMESPACE,
            '/stats/compare',
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'get_stats_compare' ),
                'permission_callback' => array( __CLASS__, 'check_permission' ),
                'args'                => array_merge(
                    self::get_date_args(),
                    array(
                        'compare' => array(
                            'type'              => 'string',
                            'default'           => 'previous',
                            'sanitize_callback' => 'sanitize_text_field',
                        ),
                    )
                ),
            )
        );

        // Visits by crawler endpoint with pagination
        register_rest_route(
            self::API_NAMESPACE,
            '/crawlers',
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'get_crawlers' ),
                'permission_callback' => array( __CLASS__, 'check_permission' ),
                'args'                => array_merge(
                    self::get_date_args(),
                    self::get_pagination_args()
                ),
            )
        );

        // Visits by category endpoint
        register_rest_route(
            self::API_NAMESPACE,
            '/categories',
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'get_categories' ),
                'permission_callback' => array( __CLASS__, 'check_permission' ),
                'args'                => self::get_date_args(),
            )
        );

        // Timeline endpoint
        register_rest_route(
            self::API_NAMESPACE,
            '/timeline',
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'get_timeline' ),
                'permission_callback' => array( __CLASS__, 'check_permission' ),
                'args'                => self::get_date_args(),
            )
        );

        // Timeline comparison endpoint
        register_rest_route(
            self::API_NAMESPACE,
            '/timeline/compare',
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'get_timeline_compare' ),
                'permission_callback' => array( __CLASS__, 'check_permission' ),
                'args'                => array_merge(
                    self::get_date_args(),
                    array(
                        'compare' => array(
                            'type'              => 'string',
                            'default'           => 'previous',
                            'sanitize_callback' => 'sanitize_text_field',
                        ),
                    )
                ),
            )
        );

        // Top pages endpoint with pagination
        register_rest_route(
            self::API_NAMESPACE,
            '/pages',
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'get_pages' ),
                'permission_callback' => array( __CLASS__, 'check_permission' ),
                'args'                => array_merge(
                    self::get_date_args(),
                    self::get_pagination_args()
                ),
            )
        );

        // Recent activity endpoint
        register_rest_route(
            self::API_NAMESPACE,
            '/recent',
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'get_recent' ),
                'permission_callback' => array( __CLASS__, 'check_permission' ),
                'args'                => self::get_activity_filter_args(),
            )
        );

        // Export endpoint
        register_rest_route(
            self::API_NAMESPACE,
            '/export',
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'export_data' ),
                'permission_callback' => array( __CLASS__, 'check_permission' ),
                'args'                => array_merge( self::get_date_args(), self::get_activity_filter_args() ),
            )
        );

        // Export timeline endpoint
        register_rest_route(
            self::API_NAMESPACE,
            '/export/timeline',
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'export_timeline' ),
                'permission_callback' => array( __CLASS__, 'check_permission' ),
                'args'                => array_merge(
                    self::get_date_args(),
                    array(
                        'compare'           => array(
                            'type'              => 'string',
                            'default'           => '',
                            'sanitize_callback' => 'sanitize_text_field',
                        ),
                        'compare_date_from' => array(
                            'type'              => 'string',
                            'default'           => '',
                            'sanitize_callback' => 'sanitize_text_field',
                        ),
                        'compare_date_to'   => array(
                            'type'              => 'string',
                            'default'           => '',
                            'sanitize_callback' => 'sanitize_text_field',
                        ),
                    )
                ),
            )
        );
    }

    /**
     * Check user permission
     *
     * @return bool
     */
    public static function check_permission() {
        return current_user_can( 'manage_options' );
    }

    /**
     * Get common date arguments
     *
     * @return array
     */
    private static function get_date_args() {
        return array(
            'days'      => array(
                'type'              => 'integer',
                'default'           => 30,
                'sanitize_callback' => 'absint',
                'validate_callback' => function ( $param ) {
                    return $param >= 0 && $param <= 3650; // 0 = all time, max 10 years
                },
            ),
            'date_from' => array(
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'date_to'   => array(
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        );
    }

    /**
     * Get recent activity filter arguments.
     *
     * Used by /recent and /export to filter results by crawler, category,
     * content type, HTTP status code, and to paginate server-side.
     *
     * @return array
     */
    private static function get_activity_filter_args() {
        return array(
            'crawlers'     => array(
                'type'              => 'array',
                'default'           => array(),
                'items'             => array( 'type' => 'string' ),
                'sanitize_callback' => function ( $value ) {
                    if ( ! is_array( $value ) ) {
                        $value = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
                    }
                    return array_values( array_filter( array_map( 'sanitize_text_field', $value ) ) );
                },
            ),
            'category'     => array(
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'content_type' => array(
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => function ( $value ) {
                    if ( '' === $value ) {
                        return true;
                    }
                    return array_key_exists( $value, VigIA_Database::get_content_type_options() );
                },
            ),
            'http_status'  => array(
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'page'         => array(
                'type'              => 'integer',
                'default'           => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => function ( $param ) {
                    return $param >= 1;
                },
            ),
            'per_page'     => array(
                'type'              => 'integer',
                'default'           => 20,
                'sanitize_callback' => 'absint',
                'validate_callback' => function ( $param ) {
                    return $param >= 1 && $param <= 100;
                },
            ),
            'mode'         => array(
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_key',
            ),
        );
    }

    /**
     * Get pagination arguments for endpoints
     *
     * @return array Pagination arguments.
     */
    private static function get_pagination_args() {
        return array(
            'limit'  => array(
                'type'              => 'integer',
                'default'           => 10,
                'sanitize_callback' => 'absint',
                'validate_callback' => function ( $param ) {
                    return $param >= 1 && $param <= 100;
                },
            ),
            'offset' => array(
                'type'              => 'integer',
                'default'           => 0,
                'sanitize_callback' => 'absint',
            ),
        );
    }

    /**
     * Calculate date range from request parameters
     *
     * @param WP_REST_Request $request Request object.
     * @return array Start and end dates.
     */
    private static function get_date_range_from_request( $request ) {
        $date_from = $request->get_param( 'date_from' );
        $date_to   = $request->get_param( 'date_to' );

        // Custom date range
        if ( ! empty( $date_from ) && ! empty( $date_to ) ) {
            return array(
                'start' => sanitize_text_field( $date_from ),
                'end'   => sanitize_text_field( $date_to ),
            );
        }

        $days = $request->get_param( 'days' );

        // All time (days = 0)
        if ( 0 === $days ) {
            return array(
                'start' => '2000-01-01', // Far past date
                'end'   => gmdate( 'Y-m-d' ),
            );
        }

        // Standard days-based range
        return array(
            'start' => gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ),
            'end'   => gmdate( 'Y-m-d' ),
        );
    }

    /**
     * Calculate date range from days parameter (legacy)
     *
     * @param int $days Number of days.
     * @return array Start and end dates.
     */
    private static function get_date_range( $days ) {
        if ( 0 === $days ) {
            return array(
                'start' => '2000-01-01',
                'end'   => gmdate( 'Y-m-d' ),
            );
        }
        return array(
            'start' => gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ),
            'end'   => gmdate( 'Y-m-d' ),
        );
    }

    /**
     * Get statistics
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public static function get_stats( $request ) {
        $range = self::get_date_range_from_request( $request );
        $stats = VigIA_Database::get_stats( $range['start'], $range['end'] );

        return rest_ensure_response( $stats );
    }

    /**
     * Get statistics comparison
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public static function get_stats_compare( $request ) {
        $compare = $request->get_param( 'compare' );

        // Current period
        $current_range = self::get_date_range_from_request( $request );
        $current_stats = VigIA_Database::get_stats( $current_range['start'], $current_range['end'] );

        // Calculate period length for previous period calculation
        $period_days = ( strtotime( $current_range['end'] ) - strtotime( $current_range['start'] ) ) / DAY_IN_SECONDS;

        // Previous period
        if ( 'custom' === $compare ) {
            // Custom comparison dates
            $compare_date_from = $request->get_param( 'compare_date_from' );
            $compare_date_to   = $request->get_param( 'compare_date_to' );

            if ( ! empty( $compare_date_from ) && ! empty( $compare_date_to ) ) {
                $previous_start = sanitize_text_field( $compare_date_from );
                $previous_end   = sanitize_text_field( $compare_date_to );
            } else {
                // Fallback to previous period if no custom dates provided
                $previous_end   = gmdate( 'Y-m-d', strtotime( $current_range['start'] . ' -1 day' ) );
                $previous_start = gmdate( 'Y-m-d', strtotime( $previous_end . " -{$period_days} days" ) );
            }
        } elseif ( 'year' === $compare ) {
            // Same period last year
            $previous_start = gmdate( 'Y-m-d', strtotime( $current_range['start'] . ' -1 year' ) );
            $previous_end   = gmdate( 'Y-m-d', strtotime( $current_range['end'] . ' -1 year' ) );
        } else {
            // Previous period (default)
            $previous_end   = gmdate( 'Y-m-d', strtotime( $current_range['start'] . ' -1 day' ) );
            $previous_start = gmdate( 'Y-m-d', strtotime( $previous_end . " -{$period_days} days" ) );
        }

        $previous_stats = VigIA_Database::get_stats( $previous_start, $previous_end );

        // Calculate percentage changes
        $response = array(
            'total_visits_previous'    => $previous_stats['total_visits'],
            'total_visits_change'      => self::calculate_change( $current_stats['total_visits'], $previous_stats['total_visits'] ),
            'unique_crawlers_previous' => $previous_stats['unique_crawlers'],
            'unique_crawlers_change'   => self::calculate_change( $current_stats['unique_crawlers'], $previous_stats['unique_crawlers'] ),
            'unique_pages_previous'    => $previous_stats['unique_pages'],
            'unique_pages_change'      => self::calculate_change( $current_stats['unique_pages'], $previous_stats['unique_pages'] ),
        );

        return rest_ensure_response( $response );
    }

    /**
     * Calculate percentage change
     *
     * @param int $current  Current value.
     * @param int $previous Previous value.
     * @return float Percentage change.
     */
    private static function calculate_change( $current, $previous ) {
        if ( 0 === (int) $previous ) {
            return $current > 0 ? 100 : 0;
        }
        return ( ( $current - $previous ) / $previous ) * 100;
    }

    /**
     * Get visits by crawler with pagination
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response with items array and total count.
     */
    public static function get_crawlers( $request ) {
        $range  = self::get_date_range_from_request( $request );
        $limit  = $request->get_param( 'limit' );
        $offset = $request->get_param( 'offset' );

        $crawlers = VigIA_Database::get_visits_by_crawler( $range['start'], $range['end'], $limit, $offset );
        $total    = VigIA_Database::get_crawlers_count( $range['start'], $range['end'] );

        return rest_ensure_response(
            array(
                'items' => $crawlers,
                'total' => $total,
            )
        );
    }

    /**
     * Get visits by category
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public static function get_categories( $request ) {
        $range      = self::get_date_range_from_request( $request );
        $categories = VigIA_Database::get_visits_by_category( $range['start'], $range['end'] );

        return rest_ensure_response( $categories );
    }

    /**
     * Get timeline data
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public static function get_timeline( $request ) {
        $range     = self::get_date_range_from_request( $request );
        $timeline  = VigIA_Database::get_visits_over_time( $range['start'], $range['end'] );
        $breakdown = VigIA_Database::get_daily_crawler_breakdown( $range['start'], $range['end'] );

        // Merge breakdown into timeline data
        foreach ( $timeline as &$day ) {
            $date              = $day['date'];
            $day['crawlers']   = isset( $breakdown[ $date ] ) ? $breakdown[ $date ] : array();
        }

        return rest_ensure_response( $timeline );
    }

    /**
     * Get timeline comparison data
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public static function get_timeline_compare( $request ) {
        $compare = $request->get_param( 'compare' );

        // Current period range
        $current_range = self::get_date_range_from_request( $request );
        $period_days   = ( strtotime( $current_range['end'] ) - strtotime( $current_range['start'] ) ) / DAY_IN_SECONDS;

        // Calculate comparison period
        if ( 'custom' === $compare ) {
            // Custom comparison dates
            $compare_date_from = $request->get_param( 'compare_date_from' );
            $compare_date_to   = $request->get_param( 'compare_date_to' );

            if ( ! empty( $compare_date_from ) && ! empty( $compare_date_to ) ) {
                $compare_start = sanitize_text_field( $compare_date_from );
                $compare_end   = sanitize_text_field( $compare_date_to );
            } else {
                // Fallback to previous period
                $compare_end   = gmdate( 'Y-m-d', strtotime( $current_range['start'] . ' -1 day' ) );
                $compare_start = gmdate( 'Y-m-d', strtotime( $compare_end . ' -' . ( $period_days - 1 ) . ' days' ) );
            }
        } elseif ( 'year' === $compare ) {
            // Same period last year
            $compare_start = gmdate( 'Y-m-d', strtotime( $current_range['start'] . ' -1 year' ) );
            $compare_end   = gmdate( 'Y-m-d', strtotime( $current_range['end'] . ' -1 year' ) );
        } else {
            // Previous period (default)
            $compare_end   = gmdate( 'Y-m-d', strtotime( $current_range['start'] . ' -1 day' ) );
            $compare_start = gmdate( 'Y-m-d', strtotime( $compare_end . ' -' . ( $period_days - 1 ) . ' days' ) );
        }

        $timeline = VigIA_Database::get_visits_over_time( $compare_start, $compare_end );

        // If no data, generate empty timeline with all dates
        if ( empty( $timeline ) ) {
            $timeline = array();
            $current  = strtotime( $compare_start );
            $end      = strtotime( $compare_end );

            while ( $current <= $end ) {
                $timeline[] = array(
                    'date'        => gmdate( 'Y-m-d', $current ),
                    'visit_count' => 0,
                );
                $current = strtotime( '+1 day', $current );
            }
        }

        return rest_ensure_response( $timeline );
    }

    /**
     * Get top pages with pagination
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response with items array and total count.
     */
    public static function get_pages( $request ) {
        $range  = self::get_date_range_from_request( $request );
        $limit  = $request->get_param( 'limit' );
        $offset = $request->get_param( 'offset' );

        $pages = VigIA_Database::get_top_pages( $range['start'], $range['end'], $limit, $offset );
        $total = VigIA_Database::get_pages_count( $range['start'], $range['end'] );

        // If AI Share & Summarize is active, add click data per path.
        $aiss_active = class_exists( 'AyudaWP_AISS_Database' );
        $click_data  = array();

        if ( $aiss_active && ! empty( $pages ) ) {
            $paths      = wp_list_pluck( $pages, 'request_path' );
            $click_data = VigIA_Database::get_aiss_clicks_for_paths(
                $paths,
                $range['start'],
                $range['end']
            );
        }

        return rest_ensure_response(
            array(
                'items'       => $pages,
                'total'       => $total,
                'aiss_active' => $aiss_active,
                'click_data'  => $click_data,
            )
        );
    }

    /**
     * Get recent activity.
     *
     * Backwards compatible: when no filter/pagination params are passed, the
     * response is a flat array of the latest 500 visits (legacy clients still
     * work). When any of crawlers/category/content_type/http_status/page/
     * per_page is present, the response is a structured object with pagination
     * metadata so the table can render server-side controls.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public static function get_recent( $request ) {
        $params = $request->get_params();

        $filter_keys = array( 'crawlers', 'category', 'content_type', 'http_status', 'page', 'per_page', 'date_from', 'date_to' );
        $has_filters = false;
        foreach ( $filter_keys as $key ) {
            if ( isset( $params[ $key ] ) && '' !== $params[ $key ] && array() !== $params[ $key ] ) {
                $has_filters = true;
                break;
            }
        }

        if ( ! $has_filters ) {
            return rest_ensure_response( VigIA_Database::get_recent_visits( 500 ) );
        }

        // When filters are present but the caller did not pass an explicit date
        // range, derive one from the days param so the dashboard's range
        // selector ("Last 7 days", "Last 30 days"...) applies to recent activity
        // the same way it applies to every other endpoint.
        $date_from = isset( $params['date_from'] ) ? $params['date_from'] : '';
        $date_to   = isset( $params['date_to'] ) ? $params['date_to'] : '';
        if ( '' === $date_from && '' === $date_to ) {
            $range     = self::get_date_range_from_request( $request );
            $date_from = $range['start'];
            $date_to   = $range['end'];
        }

        $result = VigIA_Database::query_visits(
            array(
                'crawlers'     => isset( $params['crawlers'] ) ? (array) $params['crawlers'] : array(),
                'category'     => isset( $params['category'] ) ? $params['category'] : '',
                'content_type' => isset( $params['content_type'] ) ? $params['content_type'] : '',
                'http_status'  => isset( $params['http_status'] ) ? $params['http_status'] : '',
                'date_from'    => $date_from,
                'date_to'      => $date_to,
                'page'         => isset( $params['page'] ) ? (int) $params['page'] : 1,
                'per_page'     => isset( $params['per_page'] ) ? (int) $params['per_page'] : 20,
            )
        );

        return rest_ensure_response( $result );
    }

    /**
     * Export data as CSV
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public static function export_data( $request ) {
        $range = self::get_date_range_from_request( $request );

        $filters = array(
            'crawlers'     => (array) $request->get_param( 'crawlers' ),
            'category'     => (string) $request->get_param( 'category' ),
            'content_type' => (string) $request->get_param( 'content_type' ),
            'http_status'  => (string) $request->get_param( 'http_status' ),
        );

        // The dashboard sends mode=filtered when the user clicks the
        // "Export filtered CSV" button. That button is only enabled when at
        // least one filter is active, so when the flag is present we always
        // brand the export as filtered (filename + Export type header),
        // even if the actually-active filter happens to be just the date
        // range — that case still meant "filter" from the user's point of
        // view.
        $mode_filtered = 'filtered' === (string) $request->get_param( 'mode' );
        $has_filters   = $mode_filtered
            || ! empty( $filters['crawlers'] )
            || '' !== $filters['category']
            || '' !== $filters['content_type']
            || '' !== $filters['http_status'];

        $data = VigIA_Database::export_data( $range['start'], $range['end'], $filters );

        $type_labels = self::get_localized_content_type_labels();
        $export_type = $has_filters
            ? __( 'Activity (filtered)', 'vigia' )
            : __( 'Activity', 'vigia' );

        $applied_filters = self::describe_activity_filters( $filters, $range );

        // CSV body starts with a metadata banner so downstream tools (and the
        // user) can tell at a glance which site and which selection produced
        // the file.
        $csv_lines = self::build_csv_metadata( $export_type, $range, $applied_filters );

        $csv_lines[] = array(
            __( 'Crawler', 'vigia' ),
            __( 'Category', 'vigia' ),
            __( 'Page', 'vigia' ),
            __( 'IP Address', 'vigia' ),
            __( 'HTTP Status', 'vigia' ),
            __( 'Content type', 'vigia' ),
            __( 'Date', 'vigia' ),
        );

        foreach ( $data as $row ) {
            $type = isset( $row['content_type'] ) && '' !== $row['content_type']
                ? $row['content_type']
                : VigIA_Database::detect_content_type( $row['request_path'] );

            $csv_lines[] = array(
                $row['crawler_name'],
                $row['crawler_category'],
                $row['request_path'],
                $row['ip_address'],
                $row['http_status'],
                isset( $type_labels[ $type ] ) ? $type_labels[ $type ] : $type,
                $row['visit_date'],
            );
        }

        $csv_content = self::csv_lines_to_string( $csv_lines );

        $prefix   = $has_filters ? 'vigia-filtered-' : 'vigia-';
        $filename = $prefix . gmdate( 'Y-m-d' ) . '.csv';

        return rest_ensure_response(
            array(
                'filename' => $filename,
                'content'  => $csv_content,
            )
        );
    }

    /**
     * Build the metadata banner shared by every CSV export.
     *
     * Returns a list of rows ready to be CSV-serialized. Two-column shape
     * (label, value) keeps the file readable in any spreadsheet app and easy
     * to parse with a CSV reader.
     *
     * @param string $export_type     Human-readable export type.
     * @param array  $range           ['start' => Y-m-d, 'end' => Y-m-d].
     * @param array  $applied_filters Pre-formatted list of "Label: value" strings.
     * @return array<int, array>
     */
    private static function build_csv_metadata( $export_type, $range, $applied_filters = array() ) {
        $lines = array(
            array( __( 'Site name', 'vigia' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
            array( __( 'Site URL', 'vigia' ), home_url( '/' ) ),
            array( __( 'Export type', 'vigia' ), $export_type ),
            array( __( 'Date range', 'vigia' ), $range['start'] . ' / ' . $range['end'] ),
            array( __( 'Export date', 'vigia' ), gmdate( 'Y-m-d H:i:s' ) . ' UTC' ),
            array(
                __( 'Generated by', 'vigia' ),
                /* translators: %s: VigIA plugin version. */
                sprintf( __( 'VigIA plugin %s', 'vigia' ), defined( 'VIGIA_VERSION' ) ? VIGIA_VERSION : '' ),
            ),
        );

        if ( ! empty( $applied_filters ) ) {
            $lines[] = array( __( 'Applied filters', 'vigia' ), implode( ' | ', $applied_filters ) );
        }

        // Empty row separates the banner from the column headers.
        $lines[] = array( '', '' );

        return $lines;
    }

    /**
     * Render filters into a list of "Label: value" strings for the CSV banner.
     *
     * @param array $filters Filter args.
     * @param array $range   Date range.
     * @return array<int, string>
     */
    private static function describe_activity_filters( $filters, $range ) {
        $out = array();

        if ( ! empty( $filters['crawlers'] ) ) {
            $out[] = __( 'Crawlers', 'vigia' ) . ': ' . implode( ', ', (array) $filters['crawlers'] );
        }
        if ( ! empty( $filters['category'] ) ) {
            $out[] = __( 'Category', 'vigia' ) . ': ' . $filters['category'];
        }
        if ( ! empty( $filters['content_type'] ) ) {
            $labels = self::get_localized_content_type_labels();
            $value  = isset( $labels[ $filters['content_type'] ] ) ? $labels[ $filters['content_type'] ] : $filters['content_type'];
            $out[]  = __( 'Content type', 'vigia' ) . ': ' . $value;
        }
        if ( '' !== (string) $filters['http_status'] ) {
            $value = 'other' === $filters['http_status']
                ? __( 'Other', 'vigia' )
                : (string) $filters['http_status'];
            $out[] = __( 'HTTP status', 'vigia' ) . ': ' . $value;
        }

        return $out;
    }

    /**
     * Serialize a list of CSV rows into a single string with proper escaping.
     *
     * @param array<int, array> $lines CSV rows.
     * @return string
     */
    private static function csv_lines_to_string( $lines ) {
        $out = '';
        foreach ( $lines as $line ) {
            $out .= '"' . implode( '","', array_map( 'esc_attr', $line ) ) . '"' . "\n";
        }
        return $out;
    }

    /**
     * Localized labels for the content_type keys exposed by VigIA_Database.
     *
     * Kept here (REST layer) rather than in the database class because i18n
     * belongs at the presentation boundary. Built on top of
     * VigIA_Database::get_content_type_options() so any custom post type
     * registered on the site is exposed automatically.
     *
     * @return array<string, string>
     */
    public static function get_localized_content_type_labels() {
        $options = VigIA_Database::get_content_type_options();

        // Translate the curated entries; CPT singular names from
        // get_content_type_options() are already what the site owner
        // registered, so we leave them as-is.
        $i18n_overrides = array(
            'home'      => __( 'Home', 'vigia' ),
            'post'      => __( 'Post', 'vigia' ),
            'page'      => __( 'Page', 'vigia' ),
            'product'   => __( 'Product', 'vigia' ),
            'feed'      => __( 'Feed', 'vigia' ),
            'sitemap'   => __( 'Sitemap', 'vigia' ),
            'api'       => __( 'REST API', 'vigia' ),
            'file'      => __( 'File', 'vigia' ),
            'category'  => __( 'Category archive', 'vigia' ),
            'tag'       => __( 'Tag archive', 'vigia' ),
            'archive'   => __( 'Date / author archive', 'vigia' ),
            'admin'     => __( 'Admin / login attempt', 'vigia' ),
            'wp-system' => __( 'WordPress system', 'vigia' ),
            'not-found' => __( '404 Not found', 'vigia' ),
            'other'     => __( 'Other', 'vigia' ),
        );

        foreach ( $i18n_overrides as $key => $label ) {
            if ( isset( $options[ $key ] ) ) {
                $options[ $key ] = $label;
            }
        }

        return $options;
    }

    /**
     * Export timeline summary as CSV
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public static function export_timeline( $request ) {
        $compare = $request->get_param( 'compare' );
        $range   = self::get_date_range_from_request( $request );

        // Get current period timeline
        $timeline = VigIA_Database::get_visits_over_time( $range['start'], $range['end'] );

        // Get comparison data if enabled
        $compare_data = array();
        if ( ! empty( $compare ) ) {
            $period_days = ( strtotime( $range['end'] ) - strtotime( $range['start'] ) ) / DAY_IN_SECONDS;

            if ( 'custom' === $compare ) {
                $compare_date_from = $request->get_param( 'compare_date_from' );
                $compare_date_to   = $request->get_param( 'compare_date_to' );

                if ( ! empty( $compare_date_from ) && ! empty( $compare_date_to ) ) {
                    $compare_start = sanitize_text_field( $compare_date_from );
                    $compare_end   = sanitize_text_field( $compare_date_to );
                } else {
                    $compare_end   = gmdate( 'Y-m-d', strtotime( $range['start'] . ' -1 day' ) );
                    $compare_start = gmdate( 'Y-m-d', strtotime( $compare_end . " -{$period_days} days" ) );
                }
            } elseif ( 'year' === $compare ) {
                $compare_start = gmdate( 'Y-m-d', strtotime( $range['start'] . ' -1 year' ) );
                $compare_end   = gmdate( 'Y-m-d', strtotime( $range['end'] . ' -1 year' ) );
            } else {
                $compare_end   = gmdate( 'Y-m-d', strtotime( $range['start'] . ' -1 day' ) );
                $compare_start = gmdate( 'Y-m-d', strtotime( $compare_end . " -{$period_days} days" ) );
            }

            $compare_timeline = VigIA_Database::get_visits_over_time( $compare_start, $compare_end );
            foreach ( $compare_timeline as $item ) {
                $compare_data[ $item['date'] ] = $item['visit_count'];
            }
        }

        // Build CSV content
        $applied = array();
        if ( ! empty( $compare ) ) {
            $applied[] = __( 'Comparison', 'vigia' ) . ': ' . $compare;
        }
        $csv_lines = self::build_csv_metadata( __( 'Timeline summary', 'vigia' ), $range, $applied );

        // Headers depend on whether comparison is enabled
        if ( ! empty( $compare ) ) {
            $csv_lines[] = array(
                __( 'Date', 'vigia' ),
                __( 'Visits', 'vigia' ),
                __( 'Comparison date', 'vigia' ),
                __( 'Comparison visits', 'vigia' ),
                __( 'Difference', 'vigia' ),
                __( 'Change %', 'vigia' ),
            );
        } else {
            $csv_lines[] = array(
                __( 'Date', 'vigia' ),
                __( 'Visits', 'vigia' ),
            );
        }

        // Add data rows
        $compare_dates = array_keys( $compare_data );
        foreach ( $timeline as $index => $item ) {
            $current_visits = (int) $item['visit_count'];

            if ( ! empty( $compare ) ) {
                // Get corresponding comparison date
                $compare_date   = isset( $compare_dates[ $index ] ) ? $compare_dates[ $index ] : '';
                $compare_visits = isset( $compare_data[ $compare_date ] ) ? (int) $compare_data[ $compare_date ] : 0;
                $difference     = $current_visits - $compare_visits;
                $change_percent = 0 === $compare_visits
                    ? ( $current_visits > 0 ? 100 : 0 )
                    : round( ( ( $current_visits - $compare_visits ) / $compare_visits ) * 100, 1 );

                $csv_lines[] = array(
                    $item['date'],
                    $current_visits,
                    $compare_date,
                    $compare_visits,
                    $difference,
                    $change_percent . '%',
                );
            } else {
                $csv_lines[] = array(
                    $item['date'],
                    $current_visits,
                );
            }
        }

        $csv_content = self::csv_lines_to_string( $csv_lines );

        return rest_ensure_response(
            array(
                'filename' => 'vigia-timeline-' . gmdate( 'Y-m-d' ) . '.csv',
                'content'  => $csv_content,
            )
        );
    }
}