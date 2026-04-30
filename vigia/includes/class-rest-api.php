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
                'args'                => self::get_date_args(),
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
     * Get recent activity
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public static function get_recent( $request ) {
        $recent = VigIA_Database::get_recent_visits( 500 );

        return rest_ensure_response( $recent );
    }

    /**
     * Export data as CSV
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public static function export_data( $request ) {
        $range = self::get_date_range_from_request( $request );
        $data  = VigIA_Database::export_data( $range['start'], $range['end'] );

        // Build CSV content with translatable headers
        $csv_lines   = array();
        $csv_lines[] = array(
            __( 'Crawler', 'vigia' ),
            __( 'Category', 'vigia' ),
            __( 'Page', 'vigia' ),
            __( 'IP Address', 'vigia' ),
            __( 'HTTP Status', 'vigia' ),
            __( 'Date', 'vigia' ),
        );

        foreach ( $data as $row ) {
            $csv_lines[] = array(
                $row['crawler_name'],
                $row['crawler_category'],
                $row['request_path'],
                $row['ip_address'],
                $row['http_status'],
                $row['visit_date'],
            );
        }

        // Convert to CSV string
        $csv_content = '';
        foreach ( $csv_lines as $line ) {
            $csv_content .= '"' . implode( '","', array_map( 'esc_attr', $line ) ) . '"' . "\n";
        }

        return rest_ensure_response(
            array(
                'filename' => 'vigia-' . gmdate( 'Y-m-d' ) . '.csv',
                'content'  => $csv_content,
            )
        );
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
        $csv_lines = array();

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

        // Convert to CSV string
        $csv_content = '';
        foreach ( $csv_lines as $line ) {
            $csv_content .= '"' . implode( '","', array_map( 'esc_attr', $line ) ) . '"' . "\n";
        }

        return rest_ensure_response(
            array(
                'filename' => 'vigia-timeline-' . gmdate( 'Y-m-d' ) . '.csv',
                'content'  => $csv_content,
            )
        );
    }
}