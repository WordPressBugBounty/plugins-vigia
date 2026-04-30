<?php
/**
 * Dashboard widget class
 *
 * Adds a summary widget to the WordPress admin dashboard.
 *
 * @package VigIA
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Dashboard widget class
 */
class VigIA_Dashboard_Widget {

    /**
     * Register the dashboard widget
     */
    public static function register() {
        // Only for users who can manage options
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        wp_add_dashboard_widget(
            'vigia_dashboard_widget',
            __( 'VigIA - AI Crawler Activity Analytics & Control', 'vigia' ),
            array( __CLASS__, 'render_widget' )
        );
    }

    /**
     * Render the widget content
     * Note: Styles are loaded from admin-styles.css (enqueued in main plugin file)
     */
    public static function render_widget() {
        // Get stats for last 7 days
        $end_date   = gmdate( 'Y-m-d' );
        $start_date = gmdate( 'Y-m-d', strtotime( '-6 days' ) );
        $stats      = VigIA_Database::get_stats( $start_date, $end_date );
        $crawlers   = VigIA_Database::get_visits_by_crawler( $start_date, $end_date, 5 );
        $timeline   = VigIA_Database::get_visits_over_time( $start_date, $end_date );

        $category_labels = VigIA_Crawler_Detector::get_category_labels();
        $category_colors = VigIA_Crawler_Detector::get_category_colors();

        // Prepare sparkline data (fill missing days with 0)
        $sparkline_data = self::prepare_sparkline_data( $timeline, $start_date, $end_date );
        ?>
        <div class="vigia-widget">
            <div class="vigia-widget-stats">
                <div class="vigia-widget-stat">
                    <span class="vigia-widget-stat-value"><?php echo esc_html( number_format_i18n( $stats['total_visits'] ) ); ?></span>
                    <span class="vigia-widget-stat-label"><?php echo esc_html__( 'visits', 'vigia' ); ?></span>
                </div>
                <div class="vigia-widget-stat">
                    <span class="vigia-widget-stat-value"><?php echo esc_html( number_format_i18n( $stats['unique_crawlers'] ) ); ?></span>
                    <span class="vigia-widget-stat-label"><?php echo esc_html__( 'crawlers', 'vigia' ); ?></span>
                </div>
                <div class="vigia-widget-stat">
                    <span class="vigia-widget-stat-value"><?php echo esc_html( number_format_i18n( $stats['unique_pages'] ) ); ?></span>
                    <span class="vigia-widget-stat-label"><?php echo esc_html__( 'pages', 'vigia' ); ?></span>
                </div>
            </div>

            <?php if ( ! empty( $sparkline_data ) && array_sum( $sparkline_data ) > 0 ) : ?>
                <div class="vigia-widget-sparkline">
                    <h4><?php echo esc_html__( 'Last 7 days', 'vigia' ); ?></h4>
                    <?php self::render_sparkline( $sparkline_data ); ?>
                </div>
            <?php endif; ?>

            <div style="clear: both; height: 25px;"></div>

            <?php if ( ! empty( $crawlers ) ) : ?>
                <h4 class="vigia-widget-section-title"><?php echo esc_html__( 'Top crawlers', 'vigia' ); ?></h4>
                <ul class="vigia-widget-list">
                    <?php foreach ( $crawlers as $crawler ) : ?>
                        <?php
                        $category       = $crawler['crawler_category'];
                        $category_label = isset( $category_labels[ $category ] ) ? $category_labels[ $category ] : $category;
                        $category_color = isset( $category_colors[ $category ] ) ? $category_colors[ $category ] : '#95a5a6';
                        ?>
                        <li>
                            <span class="vigia-widget-crawler-name"><?php echo esc_html( $crawler['crawler_name'] ); ?></span>
                            <span class="vigia-widget-crawler-category" style="background-color: <?php echo esc_attr( $category_color ); ?>">
                                <?php echo esc_html( $category_label ); ?>
                            </span>
                            <span class="vigia-widget-crawler-count"><?php echo esc_html( number_format_i18n( $crawler['visit_count'] ) ); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p class="vigia-widget-empty"><?php echo esc_html__( 'No crawler activity recorded yet.', 'vigia' ); ?></p>
            <?php endif; ?>

            <p class="vigia-widget-footer">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=vigia' ) ); ?>">
                    <?php echo esc_html__( 'View full analytics', 'vigia' ); ?> &rarr;
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Prepare sparkline data with all days filled
     *
     * @param array  $timeline   Raw timeline data from database.
     * @param string $start_date Start date.
     * @param string $end_date   End date.
     * @return array Associative array of date => count.
     */
    private static function prepare_sparkline_data( $timeline, $start_date, $end_date ) {
        $data = array();

        // Create array with all dates set to 0
        $current = strtotime( $start_date );
        $end     = strtotime( $end_date );

        while ( $current <= $end ) {
            $date_key          = gmdate( 'Y-m-d', $current );
            $data[ $date_key ] = 0;
            $current           = strtotime( '+1 day', $current );
        }

        // Fill in actual values
        foreach ( $timeline as $row ) {
            if ( isset( $data[ $row['date'] ] ) ) {
                $data[ $row['date'] ] = (int) $row['visit_count'];
            }
        }

        return $data;
    }

    /**
     * Render sparkline chart
     *
     * @param array $data Sparkline data.
     */
    private static function render_sparkline( $data ) {
        $values = array_values( $data );
        $dates  = array_keys( $data );
        $max    = max( $values );

        if ( 0 === $max ) {
            $max = 1; // Prevent division by zero
        }

        ?>
        <div class="vigia-sparkline-container">
            <?php foreach ( $values as $index => $value ) : ?>
                <?php
                $height  = ( $value / $max ) * 100;
                $date    = isset( $dates[ $index ] ) ? $dates[ $index ] : '';
                $tooltip = sprintf(
                    /* translators: 1: date, 2: number of visits */
                    __( '%1$s: %2$s visits', 'vigia' ),
                    wp_date( get_option( 'date_format' ), strtotime( $date ) ),
                    number_format_i18n( $value )
                );
                ?>
                <div 
                    class="vigia-sparkline-bar" 
                    style="height: <?php echo esc_attr( max( $height, 5 ) ); ?>%;"
                    title="<?php echo esc_attr( $tooltip ); ?>"
                ></div>
            <?php endforeach; ?>
        </div>
        <div class="vigia-sparkline-labels">
            <span><?php echo esc_html( wp_date( 'M j', strtotime( $dates[0] ) ) ); ?></span>
            <span><?php echo esc_html( wp_date( 'M j', strtotime( end( $dates ) ) ) ); ?></span>
        </div>
        <?php
    }
}
