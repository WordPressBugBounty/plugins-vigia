<?php
/**
 * Email Alerts class
 *
 * Handles email notifications for AI crawler activity.
 *
 * @package VigIA
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Email Alerts class
 */
class VigIA_Email_Alerts {

    /**
     * Option name for email settings
     */
    const OPTION_NAME = 'vigia_email_settings';

    /**
     * Default settings
     *
     * @var array
     */
    private static $defaults = array(
        'enabled'   => false,
        'frequency' => 'weekly',
        'level'     => 'normal',
        'email'     => '',
    );

    /**
     * Get email settings
     *
     * @return array
     */
    public static function get_settings() {
        $settings = get_option( self::OPTION_NAME, array() );
        $settings = wp_parse_args( $settings, self::$defaults );

        // Use admin email if not set.
        if ( empty( $settings['email'] ) ) {
            $settings['email'] = get_option( 'admin_email' );
        }

        return $settings;
    }

    /**
     * Save email settings
     *
     * @param array $new_settings Settings to save.
     * @return bool
     */
    public static function save_settings( $new_settings ) {
        $settings = self::get_settings();
        $settings = wp_parse_args( $new_settings, $settings );

        // Validate frequency.
        $valid_frequencies = array( 'daily', 'weekly', 'monthly' );
        if ( ! in_array( $settings['frequency'], $valid_frequencies, true ) ) {
            $settings['frequency'] = 'weekly';
        }

        // Validate level.
        $valid_levels = array( 'minimal', 'normal', 'complete' );
        if ( ! in_array( $settings['level'], $valid_levels, true ) ) {
            $settings['level'] = 'normal';
        }

        return update_option( self::OPTION_NAME, $settings );
    }

    /**
     * Schedule email alerts based on settings
     */
    public static function schedule_alerts() {
        // Clear existing schedule.
        wp_clear_scheduled_hook( 'vigia_send_email_alerts' );

        $settings = self::get_settings();

        if ( ! $settings['enabled'] ) {
            return;
        }

        // Schedule based on frequency.
        $schedules = array(
            'daily'   => 'daily',
            'weekly'  => 'weekly',
            'monthly' => 'monthly',
        );

        $schedule = isset( $schedules[ $settings['frequency'] ] ) ? $schedules[ $settings['frequency'] ] : 'weekly';

        // Add monthly schedule if not exists.
        add_filter( 'cron_schedules', array( __CLASS__, 'add_monthly_schedule' ) );

        if ( ! wp_next_scheduled( 'vigia_send_email_alerts' ) ) {
            wp_schedule_event( time() + DAY_IN_SECONDS, $schedule, 'vigia_send_email_alerts' );
        }
    }

    /**
     * Add monthly schedule to cron
     *
     * @param array $schedules Existing schedules.
     * @return array Modified schedules.
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
     * Send scheduled alerts
     */
    public static function send_scheduled_alerts() {
        $settings = self::get_settings();

        if ( ! $settings['enabled'] ) {
            return;
        }

        $report = self::generate_report( $settings['level'], $settings['frequency'] );

        if ( empty( $report['has_data'] ) ) {
            return; // No data to report.
        }

        $subject = self::get_email_subject( $settings['frequency'] );
        $body    = self::format_email_body( $report, $settings['level'] );

        wp_mail(
            $settings['email'],
            $subject,
            $body,
            array( 'Content-Type: text/html; charset=UTF-8' )
        );
    }

    /**
     * Send test email
     *
     * @return bool
     */
    public static function send_test_email() {
        $settings = self::get_settings();
        $report   = self::generate_report( 'complete', 'weekly' );

        $subject = '[TEST] ' . self::get_email_subject( 'weekly' );
        $body    = self::format_email_body( $report, 'complete', true );

        return wp_mail(
            $settings['email'],
            $subject,
            $body,
            array( 'Content-Type: text/html; charset=UTF-8' )
        );
    }

    /**
     * Generate report data
     *
     * @param string $level     Report level (minimal, normal, complete).
     * @param string $frequency Report frequency for date range.
     * @return array Report data.
     */
    private static function generate_report( $level, $frequency ) {
        // Determine date range.
        $end_date   = gmdate( 'Y-m-d' );
        $days_back  = array(
            'daily'   => 1,
            'weekly'  => 7,
            'monthly' => 30,
        );
        $days       = isset( $days_back[ $frequency ] ) ? $days_back[ $frequency ] : 7;
        $start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

        // Previous period for comparison.
        $prev_end   = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
        $prev_start = gmdate( 'Y-m-d', strtotime( '-' . ( $days * 2 ) . ' days' ) );

        // Get current period stats.
        $current_stats = VigIA_Database::get_stats( $start_date, $end_date );
        $prev_stats    = VigIA_Database::get_stats( $prev_start, $prev_end );

        // Calculate changes.
        $visits_change = 0;
        if ( $prev_stats['total_visits'] > 0 ) {
            $visits_change = round( ( ( $current_stats['total_visits'] - $prev_stats['total_visits'] ) / $prev_stats['total_visits'] ) * 100 );
        }

        $report = array(
            'has_data'       => $current_stats['total_visits'] > 0,
            'period'         => array(
                'start' => $start_date,
                'end'   => $end_date,
                'days'  => $days,
            ),
            'stats'          => $current_stats,
            'previous_stats' => $prev_stats,
            'visits_change'  => $visits_change,
        );

        // Add more data based on level.
        if ( 'minimal' !== $level ) {
            // Normal level: add non-compliant crawlers.
            $compliance                  = VigIA_Robots_Manager::get_compliance_data();
            $report['non_compliant']     = $compliance['non_compliant'];

            // New crawlers (first seen in period).
            $report['top_crawlers'] = VigIA_Database::get_visits_by_crawler( $start_date, $end_date, 10 );
        }

        if ( 'complete' === $level ) {
            // Complete level: add top pages and activity peaks.
            $report['top_pages'] = VigIA_Database::get_top_pages( $start_date, $end_date, 5 );
            $report['timeline']  = VigIA_Database::get_visits_over_time( $start_date, $end_date );

            // Calculate average and detect peaks.
            if ( ! empty( $report['timeline'] ) ) {
                $total = array_sum( array_column( $report['timeline'], 'visit_count' ) );
                $avg   = $total / count( $report['timeline'] );

                $report['daily_average'] = round( $avg );
                $report['peaks']         = array();

                foreach ( $report['timeline'] as $day ) {
                    if ( $day['visit_count'] > ( $avg * 2 ) ) {
                        $report['peaks'][] = $day;
                    }
                }
            }
        }

        return $report;
    }

    /**
     * Get email subject
     *
     * @param string $frequency Report frequency.
     * @return string
     */
    private static function get_email_subject( $frequency ) {
        $site_name = get_bloginfo( 'name' );

        $subjects = array(
            'daily'   => /* translators: %s: site name */ __( '[VigIA] Daily AI Crawler Report - %s', 'vigia' ),
            'weekly'  => /* translators: %s: site name */ __( '[VigIA] Weekly AI Crawler Report - %s', 'vigia' ),
            'monthly' => /* translators: %s: site name */ __( '[VigIA] Monthly AI Crawler Report - %s', 'vigia' ),
        );

        $subject_template = isset( $subjects[ $frequency ] ) ? $subjects[ $frequency ] : $subjects['weekly'];

        return sprintf( $subject_template, $site_name );
    }

    /**
     * Format email body as HTML
     *
     * @param array $report  Report data.
     * @param string $level   Report level.
     * @param bool   $is_test Whether this is a test email.
     * @return string HTML email body.
     */
    private static function format_email_body( $report, $level, $is_test = false ) {
        $site_name = get_bloginfo( 'name' );
        $site_url  = home_url();

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
                h1 { color: #1d2327; font-size: 24px; margin-bottom: 20px; }
                h2 { color: #1d2327; font-size: 18px; margin-top: 30px; border-bottom: 1px solid #ddd; padding-bottom: 10px; }
                .stat-box { background: #f0f0f1; padding: 15px; border-radius: 4px; margin: 10px 0; }
                .stat-value { font-size: 28px; font-weight: bold; color: #2271b1; }
                .stat-label { color: #646970; font-size: 14px; }
                .change-positive { color: #00a32a; }
                .change-negative { color: #d63638; }
                .warning { background: #fcf9e8; border-left: 4px solid #dba617; padding: 10px 15px; margin: 10px 0; }
                .warning-title { color: #996800; font-weight: bold; }
                table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
                th { background: #f0f0f1; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #646970; }
                .button { display: inline-block; background: #2271b1; color: #fff !important; padding: 10px 20px; text-decoration: none; border-radius: 4px; }
            </style>
        </head>
        <body>
            <?php if ( $is_test ) : ?>
            <p style="background: #d63638; color: #fff; padding: 10px; text-align: center; margin: 0 0 20px;">
                <?php esc_html_e( 'This is a test email', 'vigia' ); ?>
            </p>
            <?php endif; ?>

            <h1>
                <?php
                printf(
                    /* translators: %s: site name */
                    esc_html__( 'AI Crawler Report - %s', 'vigia' ),
                    esc_html( $site_name )
                );
                ?>
            </h1>

            <p>
                <?php
                printf(
                    /* translators: 1: start date, 2: end date */
                    esc_html__( 'Report period: %1$s to %2$s', 'vigia' ),
                    esc_html( $report['period']['start'] ),
                    esc_html( $report['period']['end'] )
                );
                ?>
            </p>

            <div class="stat-box">
                <div class="stat-value"><?php echo esc_html( number_format_i18n( $report['stats']['total_visits'] ) ); ?></div>
                <div class="stat-label">
                    <?php esc_html_e( 'Total crawler visits', 'vigia' ); ?>
                    <?php if ( 0 !== $report['visits_change'] ) : ?>
                        <span class="<?php echo $report['visits_change'] > 0 ? 'change-positive' : 'change-negative'; ?>">
                            (<?php echo $report['visits_change'] > 0 ? '+' : ''; ?><?php echo esc_html( $report['visits_change'] ); ?>% <?php esc_html_e( 'vs previous period', 'vigia' ); ?>)
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <p>
                <strong><?php echo esc_html( number_format_i18n( $report['stats']['unique_crawlers'] ) ); ?></strong> <?php esc_html_e( 'unique crawlers', 'vigia' ); ?> |
                <strong><?php echo esc_html( number_format_i18n( $report['stats']['unique_pages'] ) ); ?></strong> <?php esc_html_e( 'pages crawled', 'vigia' ); ?>
            </p>

            <?php if ( 'minimal' !== $level && ! empty( $report['non_compliant'] ) ) : ?>
            <div class="warning">
                <div class="warning-title"><?php esc_html_e( 'Crawlers ignoring your robots.txt', 'vigia' ); ?></div>
                <p><?php esc_html_e( 'The following crawlers are set to Disallow in your robots.txt but continue to visit:', 'vigia' ); ?></p>
                <ul>
                    <?php foreach ( $report['non_compliant'] as $crawler => $data ) : ?>
                    <li>
                        <strong><?php echo esc_html( $crawler ); ?></strong>: 
                        <?php
                        printf(
                            /* translators: %d: number of visits */
                            esc_html( _n( '%d visit', '%d visits', $data['visits'], 'vigia' ) ),
                            esc_html( $data['visits'] )
                        );
                        ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if ( 'minimal' !== $level && ! empty( $report['top_crawlers'] ) ) : ?>
            <h2><?php esc_html_e( 'Top crawlers', 'vigia' ); ?></h2>
            <table>
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Crawler', 'vigia' ); ?></th>
                        <th><?php esc_html_e( 'Category', 'vigia' ); ?></th>
                        <th><?php esc_html_e( 'Visits', 'vigia' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $report['top_crawlers'] as $crawler ) : ?>
                    <tr>
                        <td><?php echo esc_html( $crawler['crawler_name'] ); ?></td>
                        <td><?php echo esc_html( $crawler['crawler_category'] ); ?></td>
                        <td><?php echo esc_html( number_format_i18n( $crawler['visit_count'] ) ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if ( 'complete' === $level && ! empty( $report['top_pages'] ) ) : ?>
            <h2><?php esc_html_e( 'Most crawled pages', 'vigia' ); ?></h2>
            <table>
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Page', 'vigia' ); ?></th>
                        <th><?php esc_html_e( 'Visits', 'vigia' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $report['top_pages'] as $page ) : ?>
                    <tr>
                        <td><?php echo esc_html( $page['request_path'] ); ?></td>
                        <td><?php echo esc_html( number_format_i18n( $page['visit_count'] ) ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if ( 'complete' === $level && ! empty( $report['peaks'] ) ) : ?>
            <h2><?php esc_html_e( 'Activity peaks', 'vigia' ); ?></h2>
            <p>
                <?php
                printf(
                    /* translators: %d: daily average */
                    esc_html__( 'Daily average: %d visits. The following days had unusually high activity:', 'vigia' ),
                    esc_html( $report['daily_average'] )
                );
                ?>
            </p>
            <ul>
                <?php foreach ( $report['peaks'] as $peak ) : ?>
                <li>
                    <?php echo esc_html( $peak['date'] ); ?>: 
                    <?php
                    printf(
                        /* translators: %d: number of visits */
                        esc_html( _n( '%d visit', '%d visits', $peak['visit_count'], 'vigia' ) ),
                        esc_html( $peak['visit_count'] )
                    );
                    ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <p style="margin-top: 30px;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=vigia' ) ); ?>" class="button">
                    <?php esc_html_e( 'View full analytics', 'vigia' ); ?>
                </a>
            </p>

            <div class="footer">
                <p>
                    <?php esc_html_e( 'This email was sent by VigIA - AI Visibility, Analytics & Control.', 'vigia' ); ?>
                    <br>
                    <?php
                    printf(
                        /* translators: %s: link to extras page */
                        esc_html__( 'Manage your email preferences in %s.', 'vigia' ),
                        '<a href="' . esc_url( admin_url( 'admin.php?page=vigia-extras' ) ) . '">' . esc_html__( 'VigIA Extras', 'vigia' ) . '</a>'
                    );
                    ?>
                </p>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Get available frequency options
     *
     * @return array
     */
    public static function get_frequency_options() {
        return array(
            'daily'   => __( 'Daily', 'vigia' ),
            'weekly'  => __( 'Weekly', 'vigia' ),
            'monthly' => __( 'Monthly', 'vigia' ),
        );
    }

    /**
     * Get available level options
     *
     * @return array
     */
    public static function get_level_options() {
        return array(
            'minimal'  => __( 'Minimal - New crawlers only', 'vigia' ),
            'normal'   => __( 'Normal - New crawlers + non-compliant + summary + period comparison', 'vigia' ),
            'complete' => __( 'Complete - All above + peaks + top pages', 'vigia' ),
        );
    }
}

// Register monthly schedule.
add_filter( 'cron_schedules', array( 'VigIA_Email_Alerts', 'add_monthly_schedule' ) );
