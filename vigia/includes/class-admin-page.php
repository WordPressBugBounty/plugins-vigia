<?php
/**
 * Admin page class
 *
 * Handles the main analytics dashboard in WordPress admin.
 *
 * @package VigIA
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin page class
 */
class VigIA_Admin_Page {

    /**
     * Register admin menu
     */
	public static function register_menu() {
		$icon_svg = VIGIA_PLUGIN_URL . 'assets/images/icon-menu-white.png';

		add_menu_page(
			__( 'VigIA - AI Visibility, Analytics & Control', 'vigia' ),
			__( 'VigIA', 'vigia' ),
			'manage_options',
			'vigia',
			array( __CLASS__, 'render_page' ),
			$icon_svg,
			3
		);

		// Rename the first submenu (auto-created by add_menu_page)
		add_submenu_page(
			'vigia',
			__( 'VigIA - AI Visibility, Analytics & Control', 'vigia' ),
			__( 'Analytics', 'vigia' ),
			'manage_options',
			'vigia',
			array( __CLASS__, 'render_page' )
		);
	}

    /**
     * Render the admin page
     */
    public static function render_page() {
        $end_date   = gmdate( 'Y-m-d' );
        $start_date = gmdate( 'Y-m-d', strtotime( '-30 days' ) );

        $stats               = VigIA_Database::get_stats( $start_date, $end_date );
        $category_labels     = VigIA_Crawler_Detector::get_category_labels();
        $category_colors     = VigIA_Crawler_Detector::get_category_colors();
        $content_type_labels = VigIA_Rest_API::get_localized_content_type_labels();
        $settings            = VigIA_Settings::get_settings();
        $retention_options   = VigIA_Settings::get_retention_options();
        $custom_crawlers     = VigIA_Settings::get_custom_crawlers();
        $crawlers_collapsed  = ! empty( $settings['crawlers_box_collapsed'] );
        $blocked_crawlers    = VigIA_Blocker::get_blocked_crawlers();
        $robots_rules        = VigIA_Robots_Manager::get_ai_rules();

        // Get blocked crawler names for quick lookup.
        $blocked_names = array_column( $blocked_crawlers, 'name' );

        // Load Thickbox for plugin install modals.
        add_thickbox();
        ?>
        <div class="wrap vigia-wrap">
            <h1>
                <span class="vigia-title-icon">
                    <img src="<?php echo esc_url( VIGIA_PLUGIN_URL . 'assets/images/icon-header-color.png' ); ?>" alt="" width="32" height="32">
                </span>
                <?php echo esc_html__( 'VigIA - AI Visibility, Analytics & Control', 'vigia' ); ?>
            </h1>

            <?php
            // Show AI Share & Summarize tip if not installed and not dismissed.
            $show_aiss_tip = ! class_exists( 'AyudaWP_AISS_Database' ) && ! get_option( 'vigia_aiss_tip_dismissed', false );
            if ( $show_aiss_tip ) :
                ?>
                <div class="notice notice-info is-dismissible vigia-aiss-tip" id="vigia-aiss-tip">
                    <p>
                        <strong><?php echo esc_html__( 'Tip:', 'vigia' ); ?></strong>
                        <?php echo esc_html__( 'Install AI Share & Summarize to see share button clicks per page in the Most Crawled Pages table.', 'vigia' ); ?>
                        <a href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=ai-share-summarize&TB_iframe=true&width=772&height=618' ) ); ?>" class="thickbox">
                            <?php echo esc_html__( 'Learn more', 'vigia' ); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Date range selector -->
            <div class="vigia-date-filter">
                <div class="vigia-date-filter-left">
                    <label for="vigia-date-range"><?php echo esc_html__( 'Period:', 'vigia' ); ?></label>
                    <select id="vigia-date-range">
                        <option value="1"><?php echo esc_html__( 'Today', 'vigia' ); ?></option>
                        <option value="7"><?php echo esc_html__( 'Last 7 days', 'vigia' ); ?></option>
                        <option value="14"><?php echo esc_html__( 'Last 14 days', 'vigia' ); ?></option>
                        <option value="30" selected><?php echo esc_html__( 'Last 30 days', 'vigia' ); ?></option>
                        <option value="60"><?php echo esc_html__( 'Last 60 days', 'vigia' ); ?></option>
                        <option value="90"><?php echo esc_html__( 'Last 90 days', 'vigia' ); ?></option>
                        <option value="180"><?php echo esc_html__( 'Last 6 months', 'vigia' ); ?></option>
                        <option value="365"><?php echo esc_html__( 'Last year', 'vigia' ); ?></option>
                        <option value="0"><?php echo esc_html__( 'All time', 'vigia' ); ?></option>
                        <option value="custom"><?php echo esc_html__( 'Custom range', 'vigia' ); ?></option>
                    </select>

                    <span id="vigia-custom-dates" class="vigia-custom-dates" style="display: none;">
                        <input type="date" id="vigia-date-from" title="<?php echo esc_attr__( 'From', 'vigia' ); ?>">
                        <span class="vigia-date-separator">&mdash;</span>
                        <input type="date" id="vigia-date-to" title="<?php echo esc_attr__( 'To', 'vigia' ); ?>">
                        <button type="button" id="vigia-apply-custom-dates" class="button button-small"><?php echo esc_html__( 'Apply', 'vigia' ); ?></button>
                    </span>

                    <label for="vigia-compare-toggle" class="vigia-compare-label">
                        <input type="checkbox" id="vigia-compare-toggle">
                        <?php echo esc_html__( 'Compare with:', 'vigia' ); ?>
                    </label>
                    <select id="vigia-compare-range" disabled>
                        <option value="previous"><?php echo esc_html__( 'Previous period', 'vigia' ); ?></option>
                        <option value="year"><?php echo esc_html__( 'Same period last year', 'vigia' ); ?></option>
                        <option value="custom"><?php echo esc_html__( 'Custom range', 'vigia' ); ?></option>
                    </select>

                    <span id="vigia-compare-custom-dates" class="vigia-custom-dates" style="display: none;">
                        <input type="date" id="vigia-compare-date-from" title="<?php echo esc_attr__( 'From', 'vigia' ); ?>">
                        <span class="vigia-date-separator">&mdash;</span>
                        <input type="date" id="vigia-compare-date-to" title="<?php echo esc_attr__( 'To', 'vigia' ); ?>">
                        <button type="button" id="vigia-apply-compare-dates" class="button button-small"><?php echo esc_html__( 'Apply', 'vigia' ); ?></button>
                    </span>
                </div>
                <div class="vigia-date-filter-right">
                    <div class="vigia-export-dropdown">
                        <button type="button" id="vigia-export-csv" class="button">
                            <span class="dashicons dashicons-download"></span>
                            <?php echo esc_html__( 'Export CSV', 'vigia' ); ?>
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </button>
                        <div class="vigia-export-menu" id="vigia-export-menu">
                            <button type="button" class="vigia-export-option" data-export="current">
                                <?php echo esc_html__( 'Current period', 'vigia' ); ?>
                            </button>
                            <button type="button" class="vigia-export-option" data-export="timeline">
                                <?php echo esc_html__( 'Timeline summary', 'vigia' ); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats cards -->
            <div class="vigia-stats-grid">
                <div class="vigia-stat-card">
                    <span class="vigia-stat-icon dashicons dashicons-chart-bar"></span>
                    <div class="vigia-stat-content">
                        <span class="vigia-stat-value" id="vigia-total-visits"><?php echo esc_html( number_format_i18n( $stats['total_visits'] ) ); ?></span>
                        <span class="vigia-stat-label"><?php echo esc_html__( 'Total Crawler Visits', 'vigia' ); ?></span>
                        <span class="vigia-stat-compare" id="vigia-total-visits-compare"></span>
                    </div>
                </div>
                <div class="vigia-stat-card">
                    <span class="vigia-stat-icon dashicons dashicons-groups"></span>
                    <div class="vigia-stat-content">
                        <span class="vigia-stat-value" id="vigia-unique-crawlers"><?php echo esc_html( number_format_i18n( $stats['unique_crawlers'] ) ); ?></span>
                        <span class="vigia-stat-label"><?php echo esc_html__( 'Unique Crawlers', 'vigia' ); ?></span>
                        <span class="vigia-stat-compare" id="vigia-unique-crawlers-compare"></span>
                    </div>
                </div>
                <div class="vigia-stat-card">
                    <span class="vigia-stat-icon dashicons dashicons-admin-page"></span>
                    <div class="vigia-stat-content">
                        <span class="vigia-stat-value" id="vigia-unique-pages"><?php echo esc_html( number_format_i18n( $stats['unique_pages'] ) ); ?></span>
                        <span class="vigia-stat-label"><?php echo esc_html__( 'Pages Crawled', 'vigia' ); ?></span>
                        <span class="vigia-stat-compare" id="vigia-unique-pages-compare"></span>
                    </div>
                </div>
            </div>

            <!-- Charts row -->
            <div class="vigia-charts-row">
                <div class="vigia-chart-container">
                    <h3>
                        <?php echo esc_html__( 'Crawler activity over time', 'vigia' ); ?>
                        <span class="vigia-period-indicator" id="vigia-timeline-period"></span>
                    </h3>
                    <div class="vigia-chart-wrapper">
                        <canvas id="vigia-timeline-chart"></canvas>
                    </div>
                </div>
                <div class="vigia-chart-container vigia-chart-small">
                    <h3>
                        <?php echo esc_html__( 'By category', 'vigia' ); ?>
                        <span class="vigia-period-indicator" id="vigia-category-period"></span>
                    </h3>
                    <div class="vigia-chart-wrapper">
                        <canvas id="vigia-category-chart"></canvas>
                    </div>
                    <div class="vigia-category-legend" id="vigia-category-legend"></div>
                </div>
            </div>

            <!-- Data tables row -->
            <div class="vigia-tables-row">
                <div class="vigia-table-container">
                    <div class="vigia-table-header">
                        <h3>
                            <?php echo esc_html__( 'Top crawlers', 'vigia' ); ?>
                            <span class="vigia-period-indicator" id="vigia-crawlers-period"></span>
                        </h3>
                        <div class="vigia-pager" id="vigia-crawlers-pager"></div>
                    </div>
                    <table class="wp-list-table widefat fixed striped" id="vigia-crawlers-table">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__( 'Crawler', 'vigia' ); ?></th>
                                <th><?php echo esc_html__( 'Category', 'vigia' ); ?></th>
                                <th class="num"><?php echo esc_html__( 'Visits', 'vigia' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="vigia-no-data">
                                <td colspan="3" class="vigia-loading"><?php echo esc_html__( 'Loading...', 'vigia' ); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="vigia-table-container">
                    <div class="vigia-table-header">
                        <h3>
                            <?php echo esc_html__( 'Most crawled pages', 'vigia' ); ?>
                            <span class="vigia-period-indicator" id="vigia-pages-period"></span>
                        </h3>
                        <div class="vigia-pager" id="vigia-pages-pager"></div>
                    </div>
                    <table class="wp-list-table widefat fixed striped" id="vigia-pages-table">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__( 'URL', 'vigia' ); ?></th>
                                <th class="num"><?php echo esc_html__( 'Visits', 'vigia' ); ?></th>
                                <th class="num"><?php echo esc_html__( 'Crawlers', 'vigia' ); ?></th>
                                <?php if ( class_exists( 'AyudaWP_AISS_Database' ) ) : ?>
                                    <th class="num"><?php echo esc_html__( 'Clicks', 'vigia' ); ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="vigia-no-data">
                                <td colspan="<?php echo class_exists( 'AyudaWP_AISS_Database' ) ? '4' : '3'; ?>" class="vigia-loading"><?php echo esc_html__( 'Loading...', 'vigia' ); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent activity -->
            <div class="vigia-recent-activity">
                <div class="vigia-table-header">
                    <h3><?php echo esc_html__( 'Recent activity', 'vigia' ); ?></h3>
                    <div class="vigia-pager" id="vigia-recent-pager"></div>
                </div>

                <div class="vigia-recent-filters">
                    <div class="vigia-filter-row">
                        <div class="vigia-filter-field vigia-filter-crawlers">
                            <label id="vigia-filter-crawlers-label"><?php echo esc_html__( 'Crawlers', 'vigia' ); ?></label>
                            <div class="vigia-multiselect" id="vigia-filter-crawlers" aria-labelledby="vigia-filter-crawlers-label">
                                <button type="button" class="button vigia-multiselect-toggle" aria-haspopup="listbox" aria-expanded="false">
                                    <span class="vigia-multiselect-label"><?php echo esc_html__( 'All crawlers', 'vigia' ); ?></span>
                                    <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                                </button>
                                <div class="vigia-multiselect-panel" role="listbox" aria-multiselectable="true" hidden>
                                    <div class="vigia-multiselect-options"></div>
                                </div>
                            </div>
                        </div>
                        <div class="vigia-filter-field">
                            <label for="vigia-filter-category"><?php echo esc_html__( 'Category', 'vigia' ); ?></label>
                            <select id="vigia-filter-category">
                                <option value=""><?php echo esc_html__( 'All categories', 'vigia' ); ?></option>
                                <?php foreach ( $category_labels as $key => $label ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="vigia-filter-field">
                            <label for="vigia-filter-content-type"><?php echo esc_html__( 'Content type', 'vigia' ); ?></label>
                            <select id="vigia-filter-content-type">
                                <option value=""><?php echo esc_html__( 'All types', 'vigia' ); ?></option>
                                <?php foreach ( $content_type_labels as $ct_key => $ct_label ) : ?>
                                    <option value="<?php echo esc_attr( $ct_key ); ?>"><?php echo esc_html( $ct_label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="vigia-filter-field">
                            <label for="vigia-filter-http-status"><?php echo esc_html__( 'HTTP status', 'vigia' ); ?></label>
                            <select id="vigia-filter-http-status">
                                <option value=""><?php echo esc_html__( 'All statuses', 'vigia' ); ?></option>
                                <option value="200">200</option>
                                <option value="301">301</option>
                                <option value="304">304</option>
                                <option value="403">403</option>
                                <option value="404">404</option>
                                <option value="410">410</option>
                                <option value="other"><?php echo esc_html__( 'Other', 'vigia' ); ?></option>
                            </select>
                        </div>
                        <div class="vigia-filter-field vigia-filter-date-range">
                            <label><?php echo esc_html__( 'Date range', 'vigia' ); ?></label>
                            <div class="vigia-date-range-inputs">
                                <input type="date" id="vigia-filter-date-from" title="<?php echo esc_attr__( 'From', 'vigia' ); ?>">
                                <span class="vigia-date-separator">&mdash;</span>
                                <input type="date" id="vigia-filter-date-to" title="<?php echo esc_attr__( 'To', 'vigia' ); ?>">
                            </div>
                        </div>
                        <div class="vigia-filter-field vigia-filter-buttons">
                            <button type="button" id="vigia-filter-apply" class="button button-primary"><?php echo esc_html__( 'Apply filters', 'vigia' ); ?></button>
                            <button type="button" id="vigia-filter-clear" class="button"><?php echo esc_html__( 'Clear', 'vigia' ); ?></button>
                            <button type="button" id="vigia-filter-export" class="button" disabled>
                                <span class="dashicons dashicons-download"></span>
                                <?php echo esc_html__( 'Export filtered CSV', 'vigia' ); ?>
                            </button>
                            <span class="vigia-filter-badge" id="vigia-filter-badge" hidden></span>
                        </div>
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped" id="vigia-recent-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__( 'Crawler', 'vigia' ); ?></th>
                            <th><?php echo esc_html__( 'Category', 'vigia' ); ?></th>
                            <th><?php echo esc_html__( 'URL', 'vigia' ); ?></th>
                            <th class="vigia-col-content-type"><?php echo esc_html__( 'Content type', 'vigia' ); ?></th>
                            <th class="vigia-col-http"><?php echo esc_html__( 'HTTP', 'vigia' ); ?></th>
                            <th><?php echo esc_html__( 'IP Address', 'vigia' ); ?></th>
                            <th><?php echo esc_html__( 'Date', 'vigia' ); ?></th>
                            <th class="vigia-actions-col"><?php echo esc_html__( 'Actions', 'vigia' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="vigia-no-data">
                            <td colspan="8" class="vigia-loading"><?php echo esc_html__( 'Loading...', 'vigia' ); ?></td>
                        </tr>
                    </tbody>
                </table>
                <div class="vigia-pager-bottom-wrap">
                    <div class="vigia-pager" id="vigia-recent-pager-bottom"></div>
                </div>
            </div>

            <!-- Known crawlers list - Collapsible -->
            <div class="vigia-crawler-list vigia-collapsible <?php echo $crawlers_collapsed ? 'collapsed' : ''; ?>">
                <h3 class="vigia-collapsible-header">
                    <span class="dashicons dashicons-arrow-<?php echo $crawlers_collapsed ? 'right' : 'down'; ?>-alt2"></span>
                    <?php echo esc_html__( 'Monitored AI crawlers', 'vigia' ); ?>
                    <span class="vigia-crawler-count">(<?php echo count( VigIA_Crawler_Detector::get_crawler_list() ); ?>)</span>
                </h3>
                <div class="vigia-collapsible-content" <?php echo $crawlers_collapsed ? 'style="display:none;"' : ''; ?>>
                    <p class="description"><?php echo esc_html__( 'This plugin monitors the following AI crawlers. The list is updated with each plugin release.', 'vigia' ); ?></p>
                    <div class="vigia-crawler-grid">
                        <?php
                        $crawlers = VigIA_Crawler_Detector::get_crawler_list();
                        foreach ( $crawlers as $crawler ) :
                            $category_label = isset( $category_labels[ $crawler['category'] ] ) ? $category_labels[ $crawler['category'] ] : $crawler['category'];
                            $category_color = isset( $category_colors[ $crawler['category'] ] ) ? $category_colors[ $crawler['category'] ] : '#95a5a6';
                            ?>
                            <div class="vigia-crawler-item">
                                <span class="vigia-crawler-name"><?php echo esc_html( $crawler['name'] ); ?></span>
                                <span class="vigia-crawler-company"><?php echo esc_html( $crawler['company'] ); ?></span>
                                <span class="vigia-crawler-category" style="background-color: <?php echo esc_attr( $category_color ); ?>">
                                    <?php echo esc_html( $category_label ); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Custom crawlers -->
            <div class="vigia-custom-crawlers">
                <h3><?php echo esc_html__( 'Custom crawlers', 'vigia' ); ?></h3>
                <p class="description"><?php echo esc_html__( 'Add custom crawlers to monitor. Enter the User-Agent string pattern to match.', 'vigia' ); ?></p>

                <div class="vigia-custom-crawler-form">
                    <div class="vigia-form-row">
                        <div class="vigia-form-field">
                            <label for="vigia-custom-useragent"><?php echo esc_html__( 'User-Agent pattern', 'vigia' ); ?></label>
                            <input type="text" id="vigia-custom-useragent" placeholder="<?php echo esc_attr__( 'e.g., Other-AI-Crawler', 'vigia' ); ?>">
                        </div>
                        <div class="vigia-form-field">
                            <label for="vigia-custom-name"><?php echo esc_html__( 'Display name', 'vigia' ); ?></label>
                            <input type="text" id="vigia-custom-name" placeholder="<?php echo esc_attr__( 'e.g., Other AI Crawler', 'vigia' ); ?>">
                        </div>
                        <div class="vigia-form-field">
                            <label for="vigia-custom-company"><?php echo esc_html__( 'Company/AI', 'vigia' ); ?></label>
                            <input type="text" id="vigia-custom-company" placeholder="<?php echo esc_attr__( 'e.g., Other AI Company', 'vigia' ); ?>">
                        </div>
                        <div class="vigia-form-field">
                            <label for="vigia-custom-category"><?php echo esc_html__( 'Category', 'vigia' ); ?></label>
                            <select id="vigia-custom-category">
                                <?php foreach ( $category_labels as $key => $label ) : ?>
                                    <?php if ( 'unknown' !== $key ) : ?>
                                        <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="vigia-form-field vigia-form-field-button">
                            <button type="button" id="vigia-add-custom-crawler" class="button button-secondary">
                                <?php echo esc_html__( 'Add crawler', 'vigia' ); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="vigia-custom-crawlers-list" id="vigia-custom-crawlers-list">
                    <?php if ( ! empty( $custom_crawlers ) ) : ?>
                        <div class="vigia-table-header vigia-table-header-pager-only">
                            <div class="vigia-pager" id="vigia-custom-crawlers-pager"></div>
                        </div>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__( 'User-Agent', 'vigia' ); ?></th>
                                    <th><?php echo esc_html__( 'Name', 'vigia' ); ?></th>
                                    <th><?php echo esc_html__( 'Company', 'vigia' ); ?></th>
                                    <th><?php echo esc_html__( 'Category', 'vigia' ); ?></th>
                                    <th><?php echo esc_html__( 'Actions', 'vigia' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                foreach ( $custom_crawlers as $id => $crawler ) :
                                    $cat_label = isset( $category_labels[ $crawler['category'] ] ) ? $category_labels[ $crawler['category'] ] : $crawler['category'];
                                    $cat_color = isset( $category_colors[ $crawler['category'] ] ) ? $category_colors[ $crawler['category'] ] : '#95a5a6';
                                    ?>
                                    <tr data-crawler-id="<?php echo esc_attr( $id ); ?>">
                                        <td><code><?php echo esc_html( $crawler['user_agent'] ); ?></code></td>
                                        <td><?php echo esc_html( $crawler['name'] ); ?></td>
                                        <td><?php echo esc_html( $crawler['company'] ); ?></td>
                                        <td>
                                            <span class="vigia-crawler-category" style="background-color: <?php echo esc_attr( $cat_color ); ?>">
                                                <?php echo esc_html( $cat_label ); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button type="button" class="button button-link-delete vigia-remove-custom-crawler" data-id="<?php echo esc_attr( $id ); ?>">
                                                <?php echo esc_html__( 'Remove', 'vigia' ); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p class="vigia-no-custom-crawlers"><?php echo esc_html__( 'No custom crawlers added yet.', 'vigia' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Data settings box -->
            <div class="vigia-settings-box">
                <h3><span class="dashicons dashicons-admin-settings"></span> <?php echo esc_html__( 'Data settings', 'vigia' ); ?></h3>
                <div class="vigia-settings-row">
                    <div class="vigia-setting">
                        <label for="vigia-retention-days"><?php echo esc_html__( 'Data retention:', 'vigia' ); ?></label>
                        <select id="vigia-retention-days">
                            <?php foreach ( $retention_options as $days => $label ) : ?>
                                <option value="<?php echo esc_attr( $days ); ?>" <?php selected( $settings['retention_days'], $days ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="description"><?php echo esc_html__( 'Older data will be automatically deleted daily.', 'vigia' ); ?></span>
                    </div>
                    <div class="vigia-setting">
                        <label>
                            <input type="checkbox" id="vigia-delete-on-uninstall" <?php checked( $settings['delete_on_uninstall'] ); ?>>
                            <?php echo esc_html__( 'Delete all data when uninstalling the plugin', 'vigia' ); ?>
                        </label>
                        <span class="description"><?php echo esc_html__( 'If unchecked, data will be preserved for reinstallation.', 'vigia' ); ?></span>
                    </div>
                    <div class="vigia-setting vigia-setting-buttons">
                        <button type="button" id="vigia-save-settings" class="button button-primary">
                            <?php echo esc_html__( 'Save settings', 'vigia' ); ?>
                        </button>
                        <button type="button" id="vigia-delete-all-data" class="button button-link-delete">
                            <?php echo esc_html__( 'Delete all data', 'vigia' ); ?>
                        </button>
                    </div>
                </div>
            </div>

            <?php
            // Render promotional banner with random plugins and services.
            $promo_banner = new Vigia_Promo_Banner( 'vigia', 'vigia', 'vigia' );
            $promo_banner->render( 'horizontal' );
            ?>

        </div>

        <!-- Hidden data for JS -->
        <script type="text/javascript">
            var vigiaBlockedCrawlers = <?php echo wp_json_encode( $blocked_names ); ?>;
            var vigiaRobotsDisallow = <?php echo wp_json_encode( $robots_rules['disallow'] ); ?>;
        </script>
        <?php
    }
}