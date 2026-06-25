<?php
/**
 * Extras page class
 *
 * Handles the Extras submenu page with robots.txt, blocking, alerts, and llms.txt.
 *
 * @package VigIA
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Extras page class
 */
class VigIA_Extras_Page {

    /**
     * Name used for the WordPress Application Password VigIA generates for
     * the MCP server. Used both to create and to detect existing entries.
     */
    const MCP_APP_PASSWORD_NAME = 'VigIA MCP';

    /**
     * Register admin submenu
     */
    public static function register_menu() {
        add_submenu_page(
            'vigia',
            __( 'VigIA Extras', 'vigia' ),
            __( 'Extras', 'vigia' ),
            'manage_options',
            'vigia-extras',
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * Render the extras page
     */
    public static function render_page() {
        // Tabs available on the Extras page. This array also acts as the
        // allowlist that constrains the ?tab= query var read below.
        $tabs = array(
            'robots'   => __( 'Disallow & Blocking', 'vigia' ),
            'llms'     => __( 'LLMs.txt Generator', 'vigia' ),
            'markdown' => __( 'Markdown for Agents', 'vigia' ),
            'jsonld'   => __( 'JSON-LD', 'vigia' ),
            'alerts'   => __( 'Email Alerts', 'vigia' ),
            'mcp'      => __( 'MCP', 'vigia' ),
        );

        // Which tab to show. This is read-only navigation with no state change,
        // so the tab links intentionally carry no nonce: requiring one would
        // break bookmarks and shared URLs. The requested value is constrained to
        // the $tabs allowlist, so an arbitrary ?tab= can only ever resolve to a
        // known tab or fall back to 'robots'.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation validated against the $tabs allowlist; no data processing.
        $requested_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
        $current_tab   = isset( $tabs[ $requested_tab ] ) ? $requested_tab : 'robots';

        ?>
        <div class="wrap vigia-wrap vigia-extras-wrap">
            <h1>
                <span class="vigia-title-icon">
                    <img src="<?php echo esc_url( VIGIA_PLUGIN_URL . 'assets/images/icon-header-color.png' ); ?>" alt="" width="32" height="32">
                </span>
                <?php echo esc_html__( 'VigIA Extras', 'vigia' ); ?>
            </h1>

            <div class="vigia-extras-layout">
                <div class="vigia-extras-main">
                    <nav class="nav-tab-wrapper vigia-nav-tabs">
                        <?php foreach ( $tabs as $tab_id => $tab_name ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=vigia-extras&tab=' . $tab_id ) ); ?>" 
                               class="nav-tab <?php echo $current_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
                                <?php echo esc_html( $tab_name ); ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>

                    <div class="vigia-extras-content">
                        <?php
                        switch ( $current_tab ) {
                            case 'llms':
                                self::render_llms_tab();
                                break;
                            case 'markdown':
                                self::render_markdown_tab();
                                break;
                            case 'jsonld':
                                self::render_jsonld_tab();
                                break;
                            case 'alerts':
                                self::render_alerts_tab();
                                break;
                            case 'mcp':
                                self::render_mcp_tab();
                                break;
                            default:
                                self::render_robots_tab();
                                break;
                        }
                        ?>
                    </div>
                </div>

                <aside class="vigia-extras-sidebar">
                    <?php self::render_sidebar_promos(); ?>
                </aside>
            </div>
        </div>
        <?php
    }

    /**
     * Render robots.txt and blocking tab
     */
    /**
     * Print a neutral coexistence notice when the Visibility sibling owns an
     * emission signal that VigIA has yielded.
     *
     * The AyudaWP family splits by nature: Visibility emits the AI/search signals
     * (identity schema, llms.txt, Markdown, robots-for-AI) and VigIA observes and
     * controls (analytics, stats, PHP/403 blocking, alerts). When Visibility is
     * emitting a signal, VigIA steps back to avoid duplicates and shows this
     * notice so the now-inert controls below make sense. This is coordination
     * between siblings, not the third-party "duplicate schema" conflict.
     *
     * @param string $signal Signal key: identity, llms, markdown, robots.
     * @param string $detail Sentence describing what Visibility handles and where.
     */
    private static function render_visibility_coexistence_notice( $signal, $detail ) {
        if ( ! class_exists( 'VigIA_Sibling_Visibility' ) || ! VigIA_Sibling_Visibility::should_defer( $signal ) ) {
            return;
        }
        ?>
        <div class="vigia-notice vigia-notice-info vigia-visibility-coexistence">
            <p>
                <span class="dashicons dashicons-info-outline"></span>
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: Visibility plugin name, in bold. */
                        __( '%s is active and now owns this signal, so VigIA has stepped back to avoid duplicates.', 'vigia' ),
                        '<strong>' . esc_html( VigIA_Sibling_Visibility::name() ) . '</strong>'
                    ),
                    array( 'strong' => array() )
                );
                echo ' ' . esc_html( $detail ) . ' ';
                esc_html_e( 'VigIA keeps measuring and controlling: crawler analytics, stats, blocking and alerts are unaffected.', 'vigia' );
                ?>
            </p>
        </div>
        <?php
    }

    private static function render_robots_tab() {
        $blocked_crawlers = VigIA_Blocker::get_blocked_crawlers();
        $robots_rules     = VigIA_Robots_Manager::get_ai_rules();
        $compliance       = VigIA_Robots_Manager::get_compliance_data();
        $all_crawlers     = VigIA_Crawler_Detector::get_all_crawlers();
        $category_labels  = VigIA_Crawler_Detector::get_category_labels();
        $category_colors  = VigIA_Crawler_Detector::get_category_colors();

        // Detect if physical robots.txt exists or use virtual.
        $physical_robots = ABSPATH . 'robots.txt';
        $robots_url      = file_exists( $physical_robots ) ? home_url( '/robots.txt' ) : home_url( '/?robots=1' );
        ?>

        <div class="vigia-extras-section">
            <h2><span class="dashicons dashicons-admin-site-alt3"></span> <?php esc_html_e( 'Robots.txt Disallow', 'vigia' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Manage which AI crawlers can access your site via robots.txt. Note: robots.txt is advisory only - crawlers may choose to ignore it.', 'vigia' ); ?>
            </p>

            <?php
            self::render_visibility_coexistence_notice(
                'robots',
                __( 'Manage the robots.txt rules for AI crawlers from Visibility; VigIA still enforces hard blocks via PHP (HTTP 403).', 'vigia' )
            );
            ?>

            <div class="vigia-robots-container">
                <!-- Current robots.txt preview -->
                <div class="vigia-robots-preview">
                    <h3><?php esc_html_e( 'Current robots.txt preview', 'vigia' ); ?></h3>
                    <pre id="vigia-robots-preview"><?php echo esc_html( VigIA_Robots_Manager::get_preview() ); ?></pre>
                    <p class="description">
                        <a href="<?php echo esc_url( $robots_url ); ?>" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e( 'View live robots.txt', 'vigia' ); ?> <span class="dashicons dashicons-external"></span>
                        </a>
                        <?php if ( ! file_exists( $physical_robots ) ) : ?>
                            <span class="vigia-robots-type">(<?php esc_html_e( 'virtual', 'vigia' ); ?>)</span>
                        <?php endif; ?>
                    </p>
                </div>

                <!-- Disallow rules -->
                <div class="vigia-robots-rules">
                    <div class="vigia-table-header">
                        <h3><?php esc_html_e( 'AI Crawler rules', 'vigia' ); ?></h3>
                        <div class="vigia-pager" id="vigia-disallow-pager"></div>
                    </div>
                    
                    <?php if ( ! empty( $robots_rules['disallow'] ) ) : ?>
                        <table class="wp-list-table widefat fixed striped" id="vigia-disallow-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Crawler', 'vigia' ); ?></th>
                                    <th><?php esc_html_e( 'Status', 'vigia' ); ?></th>
                                    <th><?php esc_html_e( 'Actions', 'vigia' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $robots_rules['disallow'] as $crawler ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $crawler ); ?></td>
                                        <td>
                                            <span class="vigia-status vigia-status-disallow"><?php esc_html_e( 'Disallow', 'vigia' ); ?></span>
                                        </td>
                                        <td>
                                            <button type="button" class="button button-small vigia-remove-robots-rule" 
                                                    data-crawler="<?php echo esc_attr( $crawler ); ?>" data-action="disallow">
                                                <?php esc_html_e( 'Remove', 'vigia' ); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p class="vigia-no-rules"><?php esc_html_e( 'No robots.txt rules configured for AI crawlers.', 'vigia' ); ?></p>
                    <?php endif; ?>

                    <!-- Add rule form -->
                    <div class="vigia-add-rule-form">
                        <h4><?php esc_html_e( 'Add robots.txt rule', 'vigia' ); ?></h4>
                        <div class="vigia-form-row">
                            <select id="vigia-robots-crawler">
                                <option value=""><?php esc_html_e( 'Select crawler...', 'vigia' ); ?></option>
                                <?php foreach ( $all_crawlers as $pattern => $crawler ) : ?>
                                    <?php if ( ! in_array( $crawler['name'], $robots_rules['disallow'], true ) ) : ?>
                                        <option value="<?php echo esc_attr( $crawler['name'] ); ?>">
                                            <?php echo esc_html( $crawler['name'] . ' (' . $crawler['company'] . ')' ); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="vigia-add-disallow" class="button button-secondary">
                                <?php esc_html_e( 'Add Disallow', 'vigia' ); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Compliance panel -->
            <?php if ( ! empty( $robots_rules['disallow'] ) ) : ?>
            <div class="vigia-compliance-panel">
                <div class="vigia-table-header">
                    <h3><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Compliance check', 'vigia' ); ?></h3>
                    <div class="vigia-pager" id="vigia-compliance-pager"></div>
                </div>
                <p class="description"><?php esc_html_e( 'Crawlers that visited your site in the last 30 days despite being in your Disallow list:', 'vigia' ); ?></p>
                
                <?php if ( ! empty( $compliance['non_compliant'] ) ) : ?>
                    <table class="wp-list-table widefat fixed striped vigia-non-compliant" id="vigia-compliance-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Crawler', 'vigia' ); ?></th>
                                <th><?php esc_html_e( 'Visits', 'vigia' ); ?></th>
                                <th><?php esc_html_e( 'Last visit', 'vigia' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'vigia' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $compliance['non_compliant'] as $crawler => $data ) : ?>
                                <tr class="vigia-row-warning">
                                    <td>
                                        <span class="dashicons dashicons-warning" style="color: #d63638;"></span>
                                        <?php echo esc_html( $crawler ); ?>
                                    </td>
                                    <td><?php echo esc_html( number_format_i18n( $data['visits'] ) ); ?></td>
                                    <td><?php echo esc_html( $data['last_visit'] ); ?></td>
                                    <td>
                                        <?php if ( ! VigIA_Blocker::is_blocked( $crawler ) ) : ?>
                                            <button type="button" class="button button-small vigia-block-php" 
                                                    data-crawler="<?php echo esc_attr( $crawler ); ?>">
                                                <?php esc_html_e( 'Block via PHP', 'vigia' ); ?>
                                            </button>
                                        <?php else : ?>
                                            <span class="vigia-already-blocked"><?php esc_html_e( 'Already blocked via PHP', 'vigia' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p class="vigia-compliance-ok">
                        <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                        <?php esc_html_e( 'All crawlers in your Disallow list are respecting your robots.txt (no visits in the last 30 days).', 'vigia' ); ?>
                    </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <hr class="vigia-section-divider">

        <div class="vigia-extras-section">
            <h2><span class="dashicons dashicons-dismiss"></span> <?php esc_html_e( 'PHP Blocking', 'vigia' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Block crawlers at PHP level by User-Agent or IP address. This is more effective than robots.txt as it returns a 403 Forbidden response.', 'vigia' ); ?>
            </p>

            <div class="vigia-notice vigia-notice-warning">
                <p>
                    <strong><?php esc_html_e( 'Warning:', 'vigia' ); ?></strong>
                    <?php esc_html_e( 'PHP blocking will completely prevent blocked crawlers from accessing your site. Make sure you understand the implications before blocking.', 'vigia' ); ?>
                    <a href="<?php echo esc_url( 'https://wordpress.org/plugins/vigia/#will%20blocking%20crawlers%20affect%20my%20seo%3F' ); ?>" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e( 'Will blocking crawlers affect my SEO?', 'vigia' ); ?> <span class="dashicons dashicons-external"></span>
                    </a>
                </p>
            </div>

            <!-- User-Agent blocks -->
            <div class="vigia-blocking-subsection">
                <div class="vigia-table-header">
                    <h3><?php esc_html_e( 'Blocked User-Agents', 'vigia' ); ?></h3>
                    <div class="vigia-pager" id="vigia-ua-blocks-pager"></div>
                </div>
                <?php
                $ua_blocks = VigIA_Blocker::get_blocked_by_type( 'useragent' );
                if ( ! empty( $ua_blocks ) ) :
                    ?>
                    <table class="wp-list-table widefat fixed striped" id="vigia-ua-blocks-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Name', 'vigia' ); ?></th>
                                <th><?php esc_html_e( 'User-Agent pattern', 'vigia' ); ?></th>
                                <th><?php esc_html_e( 'Blocked since', 'vigia' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'vigia' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $ua_blocks as $block ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $block['name'] ); ?></td>
                                    <td><code><?php echo esc_html( $block['pattern'] ); ?></code></td>
                                    <td><?php echo esc_html( $block['blocked_at'] ); ?></td>
                                    <td>
                                        <button type="button" class="button button-small button-link-delete vigia-unblock" 
                                                data-id="<?php echo esc_attr( $block['id'] ); ?>" data-type="useragent">
                                            <?php esc_html_e( 'Unblock', 'vigia' ); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p class="vigia-no-blocked"><?php esc_html_e( 'No User-Agent blocks configured.', 'vigia' ); ?></p>
                <?php endif; ?>

                <!-- Add User-Agent block form -->
                <div class="vigia-add-block-form">
                    <h4><?php esc_html_e( 'Block by User-Agent', 'vigia' ); ?></h4>
                    <div class="vigia-form-row">
                        <select id="vigia-block-crawler">
                            <option value=""><?php esc_html_e( 'Select crawler...', 'vigia' ); ?></option>
                            <?php foreach ( $all_crawlers as $pattern => $crawler ) : ?>
                                <?php if ( ! VigIA_Blocker::is_useragent_blocked( $pattern ) ) : ?>
                                    <option value="<?php echo esc_attr( $crawler['name'] ); ?>" data-useragent="<?php echo esc_attr( $pattern ); ?>">
                                        <?php echo esc_html( $crawler['name'] . ' (' . $crawler['company'] . ')' ); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="vigia-add-block-ua" class="button button-secondary">
                            <?php esc_html_e( 'Block', 'vigia' ); ?>
                        </button>
                    </div>
                    <p class="description" style="margin-top: 10px;">
                        <?php esc_html_e( 'Or add custom User-Agent:', 'vigia' ); ?>
                    </p>
                    <div class="vigia-form-row" style="margin-top: 8px;">
                        <input type="text" id="vigia-custom-ua-name" placeholder="<?php esc_attr_e( 'Name (e.g., CustomBot)', 'vigia' ); ?>" style="width: 150px;">
                        <input type="text" id="vigia-custom-ua-pattern" placeholder="<?php esc_attr_e( 'Pattern (e.g., CustomBot/1.0)', 'vigia' ); ?>" style="width: 200px;">
                        <button type="button" id="vigia-add-custom-block-ua" class="button button-secondary">
                            <?php esc_html_e( 'Block', 'vigia' ); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- IP blocks -->
            <div class="vigia-blocking-subsection" style="margin-top: 25px;">
                <div class="vigia-table-header">
                    <h3><?php esc_html_e( 'Blocked IP Addresses', 'vigia' ); ?></h3>
                    <div class="vigia-pager" id="vigia-ip-blocks-pager"></div>
                </div>
                <?php
                $ip_blocks = VigIA_Blocker::get_blocked_by_type( 'ip' );
                if ( ! empty( $ip_blocks ) ) :
                    ?>
                    <table class="wp-list-table widefat fixed striped" id="vigia-ip-blocks-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Name/Note', 'vigia' ); ?></th>
                                <th><?php esc_html_e( 'IP Address', 'vigia' ); ?></th>
                                <th><?php esc_html_e( 'Blocked since', 'vigia' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'vigia' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $ip_blocks as $block ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $block['name'] ); ?></td>
                                    <td><code><?php echo esc_html( $block['pattern'] ); ?></code></td>
                                    <td><?php echo esc_html( $block['blocked_at'] ); ?></td>
                                    <td>
                                        <button type="button" class="button button-small button-link-delete vigia-unblock" 
                                                data-id="<?php echo esc_attr( $block['id'] ); ?>" data-type="ip">
                                            <?php esc_html_e( 'Unblock', 'vigia' ); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p class="vigia-no-blocked"><?php esc_html_e( 'No IP blocks configured.', 'vigia' ); ?></p>
                <?php endif; ?>

                <!-- Add IP block form -->
                <div class="vigia-add-block-form">
                    <h4><?php esc_html_e( 'Block by IP Address', 'vigia' ); ?></h4>
                    <div class="vigia-form-row">
                        <input type="text" id="vigia-block-ip-name" placeholder="<?php esc_attr_e( 'Name/Note (optional)', 'vigia' ); ?>" style="width: 150px;">
                        <input type="text" id="vigia-block-ip" placeholder="<?php esc_attr_e( 'IP Address (e.g., 192.168.1.1)', 'vigia' ); ?>" style="width: 180px;">
                        <button type="button" id="vigia-add-block-ip" class="button button-secondary">
                            <?php esc_html_e( 'Block IP', 'vigia' ); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render email alerts tab
     */
    private static function render_alerts_tab() {
        $settings           = VigIA_Email_Alerts::get_settings();
        $frequency_options  = VigIA_Email_Alerts::get_frequency_options();
        $level_options      = VigIA_Email_Alerts::get_level_options();
        ?>

        <div class="vigia-extras-section">
            <h2><span class="dashicons dashicons-email-alt"></span> <?php esc_html_e( 'Email Alerts', 'vigia' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Receive periodic reports about AI crawler activity on your site.', 'vigia' ); ?>
            </p>

            <div class="vigia-email-settings">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable alerts', 'vigia' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" id="vigia-email-enabled" <?php checked( $settings['enabled'] ); ?>>
                                <?php esc_html_e( 'Send periodic email reports', 'vigia' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vigia-email-address"><?php esc_html_e( 'Email address', 'vigia' ); ?></label></th>
                        <td>
                            <input type="email" id="vigia-email-address" class="regular-text" value="<?php echo esc_attr( $settings['email'] ); ?>">
                            <p class="description"><?php esc_html_e( 'Leave empty to use admin email.', 'vigia' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vigia-email-frequency"><?php esc_html_e( 'Frequency', 'vigia' ); ?></label></th>
                        <td>
                            <select id="vigia-email-frequency">
                                <?php foreach ( $frequency_options as $value => $label ) : ?>
                                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['frequency'], $value ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vigia-email-level"><?php esc_html_e( 'Detail level', 'vigia' ); ?></label></th>
                        <td>
                            <select id="vigia-email-level">
                                <?php foreach ( $level_options as $value => $label ) : ?>
                                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['level'], $value ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <div class="vigia-email-actions">
                    <button type="button" id="vigia-save-email-settings" class="button button-primary">
                        <?php esc_html_e( 'Save settings', 'vigia' ); ?>
                    </button>
                    <button type="button" id="vigia-test-email" class="button button-secondary">
                        <?php esc_html_e( 'Send test email', 'vigia' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render LLMs.txt tab (v1.2.0 - completely rewritten)
     */
    private static function render_llms_tab() {
        $settings         = VigIA_LLMS_Generator::get_settings();
        $llms_exists      = VigIA_LLMS_Generator::llms_exists();
        $llms_full_exists = VigIA_LLMS_Generator::llms_full_exists();
        $llms_info        = VigIA_LLMS_Generator::get_file_info( 'llms.txt' );
        $llms_full_info   = VigIA_LLMS_Generator::get_file_info( 'llms-full.txt' );
        $post_types       = VigIA_LLMS_Generator::get_public_post_types();
        $seo_plugin       = VigIA_LLMS_Generator::detect_seo_plugin();
        $noindexer        = VigIA_LLMS_Generator::detect_noindexer();
        ?>

        <div class="vigia-extras-section">
            <h2><span class="dashicons dashicons-media-text"></span> <?php esc_html_e( 'LLMs.txt Generator', 'vigia' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Generate llms.txt and llms-full.txt files to help AI systems understand your site structure and content.', 'vigia' ); ?>
                <a href="<?php
                    /* translators: URL to llms.txt documentation article. Replace with localized version if available. */
                    echo esc_url( __( 'https://ayudawp-com.translate.goog/llms-txt-llms-full-txt/?_x_tr_sl=es&_x_tr_tl=en&_x_tr_hl=es&_x_tr_pto=wapp', 'vigia' ) );
                ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'Learn more about llms.txt', 'vigia' ); ?> <span class="dashicons dashicons-external"></span>
                </a>
            </p>

            <?php
            self::render_visibility_coexistence_notice(
                'llms',
                __( 'Manage llms.txt and llms-full.txt from Visibility; VigIA has removed its own physical copies so they do not shadow it.', 'vigia' )
            );
            ?>

            <!-- Current files status -->
            <div class="vigia-llms-status">
                <h3><?php esc_html_e( 'Current files', 'vigia' ); ?></h3>
                <div class="vigia-files-grid">
                    <div class="vigia-file-card <?php echo $llms_exists ? 'vigia-file-exists' : ''; ?>">
                        <span class="dashicons dashicons-<?php echo $llms_exists ? 'yes-alt' : 'minus'; ?>"></span>
                        <strong>llms.txt</strong>
                        <?php if ( $llms_info ) : ?>
                            <span class="vigia-file-size"><?php echo esc_html( size_format( $llms_info['size'] ) ); ?></span>
                            <a href="<?php echo esc_url( $llms_info['url'] ); ?>" target="_blank" rel="noopener noreferrer" class="vigia-file-link">
                                <?php esc_html_e( 'View', 'vigia' ); ?>
                            </a>
                        <?php else : ?>
                            <span class="vigia-file-missing"><?php esc_html_e( 'Not generated', 'vigia' ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="vigia-file-card <?php echo $llms_full_exists ? 'vigia-file-exists' : ''; ?>">
                        <span class="dashicons dashicons-<?php echo $llms_full_exists ? 'yes-alt' : 'minus'; ?>"></span>
                        <strong>llms-full.txt</strong>
                        <?php if ( $llms_full_info ) : ?>
                            <span class="vigia-file-size"><?php echo esc_html( size_format( $llms_full_info['size'] ) ); ?></span>
                            <a href="<?php echo esc_url( $llms_full_info['url'] ); ?>" target="_blank" rel="noopener noreferrer" class="vigia-file-link">
                                <?php esc_html_e( 'View', 'vigia' ); ?>
                            </a>
                        <?php else : ?>
                            <span class="vigia-file-missing"><?php esc_html_e( 'Not generated', 'vigia' ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ( $llms_exists || $llms_full_exists ) : ?>
                    <p class="vigia-last-generated">
                        <?php
                        /* translators: %s: formatted date/time */
                        printf( esc_html__( 'Last generated: %s', 'vigia' ), esc_html( VigIA_LLMS_Generator::get_last_generated_formatted() ) );
                        ?>
                    </p>
                    <div class="vigia-delete-buttons">
                        <?php if ( $llms_exists ) : ?>
                            <button type="button" class="vigia-delete-llms-file button button-link-delete" data-file="llms.txt">
                                <?php esc_html_e( 'Delete llms.txt', 'vigia' ); ?>
                            </button>
                        <?php endif; ?>
                        <?php if ( $llms_full_exists ) : ?>
                            <button type="button" class="vigia-delete-llms-file button button-link-delete" data-file="llms-full.txt">
                                <?php esc_html_e( 'Delete llms-full.txt', 'vigia' ); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Generator form -->
            <div class="vigia-llms-generator">
                <h3><?php esc_html_e( 'Generate files', 'vigia' ); ?></h3>

                <!-- Site info -->
                <div class="vigia-llms-section">
                    <h4><?php esc_html_e( 'Site information', 'vigia' ); ?></h4>
                    <table class="form-table vigia-compact-table">
                        <tr>
                            <th scope="row"><label for="vigia-llms-site-name"><?php esc_html_e( 'Site name', 'vigia' ); ?></label></th>
                            <td>
                                <input type="text" id="vigia-llms-site-name" class="regular-text" value="<?php echo esc_attr( $settings['site_name'] ); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="vigia-llms-description"><?php esc_html_e( 'Site description', 'vigia' ); ?></label></th>
                            <td>
                                <textarea id="vigia-llms-description" class="large-text" rows="2"><?php echo esc_textarea( $settings['site_description'] ); ?></textarea>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Content selection by post type -->
                <div class="vigia-llms-section">
                    <h4><?php esc_html_e( 'Include by content type', 'vigia' ); ?></h4>
                    <p class="description"><?php esc_html_e( 'Select which content types to include in their entirety.', 'vigia' ); ?></p>
                    
                    <div class="vigia-post-types-grid" id="vigia-post-types">
                        <?php foreach ( $post_types as $pt ) : ?>
                            <label class="vigia-post-type-item">
                                <input type="checkbox" name="vigia_post_types[]" value="<?php echo esc_attr( $pt['name'] ); ?>"
                                    data-count="<?php echo esc_attr( $pt['count'] ); ?>"
                                    <?php checked( in_array( $pt['name'], $settings['post_types'], true ) ); ?>>
                                <span class="vigia-pt-label"><?php echo esc_html( $pt['label'] ); ?></span>
                                <span class="vigia-pt-count">(<?php echo esc_html( number_format_i18n( $pt['count'] ) ); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- Taxonomy filters (dynamic) -->
                    <div class="vigia-taxonomy-filters" id="vigia-taxonomy-filters" style="display: none;">
                        <h5><?php esc_html_e( 'Filter by taxonomy', 'vigia' ); ?></h5>
                        <p class="description"><?php esc_html_e( 'Optionally filter selected content types by their taxonomies. Leave empty to include all.', 'vigia' ); ?></p>
                        <div id="vigia-taxonomy-selectors"></div>
                    </div>
                </div>

                <!-- Manual includes -->
                <div class="vigia-llms-section">
                    <h4><?php esc_html_e( 'Additional content (manual)', 'vigia' ); ?></h4>
                    <p class="description"><?php esc_html_e( 'Search and add specific content not covered by the post type selection above.', 'vigia' ); ?></p>
                    
                    <div class="vigia-manual-selector">
                        <div class="vigia-search-input-wrap">
                            <input type="text" id="vigia-include-search" class="vigia-ajax-search" 
                                   placeholder="<?php esc_attr_e( 'Search content to add...', 'vigia' ); ?>" autocomplete="off">
                            <div class="vigia-search-results" id="vigia-include-results"></div>
                        </div>
                        <div class="vigia-selected-items" id="vigia-manual-includes">
                            <?php if ( ! empty( $settings['manual_includes'] ) ) : ?>
                                <?php foreach ( $settings['manual_includes'] as $post_id ) : ?>
                                    <?php
                                    $post = get_post( $post_id );
                                    if ( ! $post ) {
                                        continue;
                                    }
                                    ?>
                                    <span class="vigia-selected-item" data-id="<?php echo esc_attr( $post_id ); ?>">
                                        <?php echo esc_html( get_the_title( $post ) ); ?>
                                        <button type="button" class="vigia-remove-item">&times;</button>
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Manual excludes -->
                <div class="vigia-llms-section">
                    <h4><?php esc_html_e( 'Exclude content', 'vigia' ); ?></h4>
                    <p class="description"><?php esc_html_e( 'Exclude specific content from the generated files.', 'vigia' ); ?></p>
                    
                    <div class="vigia-manual-selector">
                        <div class="vigia-search-input-wrap">
                            <input type="text" id="vigia-exclude-search" class="vigia-ajax-search" 
                                   placeholder="<?php esc_attr_e( 'Search content to exclude...', 'vigia' ); ?>" autocomplete="off">
                            <div class="vigia-search-results" id="vigia-exclude-results"></div>
                        </div>
                        <div class="vigia-selected-items" id="vigia-manual-excludes">
                            <?php if ( ! empty( $settings['manual_excludes'] ) ) : ?>
                                <?php foreach ( $settings['manual_excludes'] as $post_id ) : ?>
                                    <?php
                                    $post = get_post( $post_id );
                                    if ( ! $post ) {
                                        continue;
                                    }
                                    ?>
                                    <span class="vigia-selected-item" data-id="<?php echo esc_attr( $post_id ); ?>">
                                        <?php echo esc_html( get_the_title( $post ) ); ?>
                                        <button type="button" class="vigia-remove-item">&times;</button>
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="vigia-pattern-excludes">
                        <label for="vigia-exclude-patterns"><?php esc_html_e( 'Exclude by URL pattern', 'vigia' ); ?></label>
                        <textarea id="vigia-exclude-patterns" class="large-text" rows="3" 
                                  placeholder="<?php esc_attr_e( "*/thank-you/*\n*/landing/*\n*-demo", 'vigia' ); ?>"><?php echo esc_textarea( $settings['exclude_patterns'] ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'One pattern per line. Use * as wildcard. Example: */thank-you/* excludes all URLs containing /thank-you/', 'vigia' ); ?></p>
                    </div>
                </div>

                <!-- SEO integration -->
                <div class="vigia-llms-section">
                    <h4><?php esc_html_e( 'SEO integration', 'vigia' ); ?></h4>
                    <label class="vigia-checkbox-label">
                        <input type="checkbox" id="vigia-exclude-noindex" <?php checked( $settings['exclude_noindex'] ); ?>>
                        <?php esc_html_e( 'Exclude noindex content', 'vigia' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Automatically excludes posts marked as noindex by SEO plugins or NoIndexer.', 'vigia' ); ?>
                        <?php if ( $seo_plugin || $noindexer ) : ?>
                            <?php if ( $seo_plugin ) : ?>
                                <span class="vigia-seo-detected">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <?php
                                    /* translators: %s: SEO plugin name */
                                    printf( esc_html__( 'Detected: %s', 'vigia' ), esc_html( $seo_plugin['name'] ) );
                                    ?>
                                </span>
                            <?php endif; ?>
                            <?php if ( $noindexer ) : ?>
                                <span class="vigia-seo-detected">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <?php
                                    /* translators: %s: NoIndexer plugin name */
                                    printf( esc_html__( 'Detected: %s', 'vigia' ), esc_html( $noindexer['name'] ) );
                                    ?>
                                </span>
                            <?php endif; ?>
                        <?php else : ?>
                            <span class="vigia-seo-not-detected">
                                <?php esc_html_e( 'No supported SEO plugin or NoIndexer detected.', 'vigia' ); ?>
                            </span>
                        <?php endif; ?>
                    </p>
                </div>

                <!-- Full content options -->
                <div class="vigia-llms-section">
                    <h4><?php esc_html_e( 'Full content file', 'vigia' ); ?></h4>
                    <label class="vigia-checkbox-label">
                        <input type="checkbox" id="vigia-generate-full" <?php checked( $settings['generate_full'] ); ?>>
                        <?php esc_html_e( 'Also generate llms-full.txt with complete content', 'vigia' ); ?>
                    </label>
                    
                    <div class="vigia-full-options" id="vigia-full-options" style="<?php echo $settings['generate_full'] ? '' : 'display:none;'; ?>">
                        <p>
                            <label>
                                <input type="radio" name="vigia_full_mode" value="full" <?php checked( $settings['full_mode'], 'full' ); ?>>
                                <?php esc_html_e( 'Full content', 'vigia' ); ?>
                            </label>
                            <label style="margin-left: 20px;">
                                <input type="radio" name="vigia_full_mode" value="excerpt" <?php checked( $settings['full_mode'], 'excerpt' ); ?>>
                                <?php esc_html_e( 'Excerpt only (smaller file)', 'vigia' ); ?>
                            </label>
                        </p>
                    </div>
                </div>

                <!-- Auto-regeneration -->
                <div class="vigia-llms-section">
                    <h4><?php esc_html_e( 'Regeneration', 'vigia' ); ?></h4>
                    <div class="vigia-regen-options">
                        <label>
                            <input type="radio" name="vigia_auto_regenerate" value="manual" <?php checked( $settings['auto_regenerate'], 'manual' ); ?>>
                            <?php esc_html_e( 'Manual only', 'vigia' ); ?>
                        </label>
                        <label>
                            <input type="radio" name="vigia_auto_regenerate" value="daily" <?php checked( $settings['auto_regenerate'], 'daily' ); ?>>
                            <?php esc_html_e( 'Daily', 'vigia' ); ?>
                        </label>
                        <label>
                            <input type="radio" name="vigia_auto_regenerate" value="weekly" <?php checked( $settings['auto_regenerate'], 'weekly' ); ?>>
                            <?php esc_html_e( 'Weekly', 'vigia' ); ?>
                        </label>
                        <label>
                            <input type="radio" name="vigia_auto_regenerate" value="monthly" <?php checked( $settings['auto_regenerate'], 'monthly' ); ?>>
                            <?php esc_html_e( 'Monthly', 'vigia' ); ?>
                        </label>
                    </div>
                    <?php
                    $next_regen = VigIA_LLMS_Generator::get_next_regeneration();
                    if ( 'manual' !== $settings['auto_regenerate'] ) :
                        ?>
                        <p class="vigia-next-regen">
                            <?php
                            /* translators: %s: next scheduled date/time */
                            printf( esc_html__( 'Next scheduled: %s', 'vigia' ), esc_html( $next_regen ) );
                            ?>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Robots.txt integration -->
                <div class="vigia-llms-section">
                    <h4><?php esc_html_e( 'Robots.txt integration', 'vigia' ); ?></h4>
                    <label class="vigia-checkbox-label">
                        <input type="checkbox" id="vigia-robots-llms" <?php checked( $settings['robots_llms'] ); ?>>
                        <?php esc_html_e( 'Add llms.txt reference to robots.txt', 'vigia' ); ?>
                    </label>
                    <br>
                    <label class="vigia-checkbox-label">
                        <input type="checkbox" id="vigia-robots-llms-full" <?php checked( $settings['robots_llms_full'] ); ?>>
                        <?php esc_html_e( 'Add llms-full.txt reference to robots.txt', 'vigia' ); ?>
                    </label>
                </div>

                <!-- Content summary -->
                <div class="vigia-content-summary" id="vigia-content-summary">
                    <span class="dashicons dashicons-info-outline"></span>
                    <span id="vigia-summary-text"><?php esc_html_e( 'Select content types to see estimated count.', 'vigia' ); ?></span>
                </div>

                <!-- Action buttons -->
                <div class="vigia-llms-actions">
                    <button type="button" id="vigia-generate-llms" class="button button-primary">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e( 'Save and generate', 'vigia' ); ?>
                    </button>
                </div>
            </div>
        </div>
        
        <script>
        /* Pass saved taxonomy filters to JS */
        var vigiaSavedTaxonomyFilters = <?php echo wp_json_encode( ! empty( $settings['taxonomy_filters'] ) ? $settings['taxonomy_filters'] : new stdClass() ); ?>;
        </script>
        <?php
    }

    /**
     * Render markdown endpoints tab (v1.5.0)
     */
    private static function render_markdown_tab() {
        $settings   = VigIA_Markdown_Endpoints::get_settings();
        $post_types = VigIA_LLMS_Generator::get_public_post_types();
        ?>

        <div class="vigia-extras-section">
            <h2><span class="dashicons dashicons-editor-code"></span> <?php esc_html_e( 'Markdown for Agents', 'vigia' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Serve individual posts and pages as optimized markdown for AI agents. Supports Accept: text/markdown content negotiation and dedicated .md URL endpoints.', 'vigia' ); ?>
                <a href="<?php
                    /* translators: URL to Markdown for Agents documentation article. Replace with localized version if available. */
                    echo esc_url( __( 'https://ayudawp-com.translate.goog/wordpress-org-markdown/?_x_tr_sl=es&_x_tr_tl=en&_x_tr_hl=es&_x_tr_pto=wapp', 'vigia' ) );
                ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'Learn more about Markdown for Agents', 'vigia' ); ?> <span class="dashicons dashicons-external"></span>
                </a>
            </p>

            <?php
            self::render_visibility_coexistence_notice(
                'markdown',
                __( 'Markdown for agents is served by Visibility at the same .md URLs, so VigIA no longer intercepts them.', 'vigia' )
            );
            ?>

            <!-- How it works -->
            <div class="vigia-markdown-howto">
                <h3><?php esc_html_e( 'How it works', 'vigia' ); ?></h3>
                <div class="vigia-howto-grid">
                    <div class="vigia-howto-item">
                        <strong><?php esc_html_e( '.md URL endpoints', 'vigia' ); ?></strong>
                        <p class="description">
                            <?php esc_html_e( 'Each post gets a .md URL that returns markdown. Example:', 'vigia' ); ?>
                            <code><?php echo esc_html( home_url( '/sample-post.md' ) ); ?></code>
                        </p>
                    </div>
                    <div class="vigia-howto-item">
                        <strong><?php esc_html_e( 'Content negotiation', 'vigia' ); ?></strong>
                        <p class="description">
                            <?php esc_html_e( 'AI agents requesting Accept: text/markdown on any post URL receive markdown instead of HTML.', 'vigia' ); ?>
                        </p>
                    </div>
                    <div class="vigia-howto-item">
                        <strong><?php esc_html_e( 'Discoverability', 'vigia' ); ?></strong>
                        <p class="description">
                            <?php esc_html_e( 'Adds link headers and HTML tags so AI agents can discover the markdown version of each page.', 'vigia' ); ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Settings form -->
            <div class="vigia-markdown-settings">
                <h3><?php esc_html_e( 'Settings', 'vigia' ); ?></h3>

                <table class="form-table vigia-compact-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable markdown endpoints', 'vigia' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" id="vigia-md-enabled" <?php checked( $settings['enabled'] ); ?>>
                                <?php esc_html_e( 'Serve individual posts as markdown for AI agents', 'vigia' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Delivery methods', 'vigia' ); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" id="vigia-md-urls" <?php checked( $settings['enable_md_urls'] ); ?>>
                                    <?php esc_html_e( '.md URL endpoints (e.g., /post-slug.md)', 'vigia' ); ?>
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" id="vigia-md-negotiation" <?php checked( $settings['enable_negotiation'] ); ?>>
                                    <?php esc_html_e( 'Accept: text/markdown content negotiation', 'vigia' ); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Discoverability', 'vigia' ); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" id="vigia-md-link-tag" <?php checked( $settings['enable_link_tag'] ); ?>>
                                    <?php
                                    printf(
                                        /* translators: %s: HTML code example */
                                        esc_html__( 'Add %s in HTML head', 'vigia' ),
                                        '<code>&lt;link rel="alternate" type="text/markdown"&gt;</code>'
                                    );
                                    ?>
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" id="vigia-md-link-header" <?php checked( $settings['enable_link_header'] ); ?>>
                                    <?php esc_html_e( 'Add Link HTTP header for markdown alternate', 'vigia' ); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Content types', 'vigia' ); ?></th>
                        <td>
                            <fieldset id="vigia-md-post-types">
                                <?php foreach ( $post_types as $pt ) : ?>
                                    <label>
                                        <input type="checkbox" name="vigia_md_post_types[]" value="<?php echo esc_attr( $pt['name'] ); ?>"
                                            <?php checked( in_array( $pt['name'], $settings['post_types'], true ) ); ?>>
                                        <?php echo esc_html( $pt['label'] ); ?>
                                        <span class="vigia-pt-count">(<?php echo esc_html( number_format_i18n( $pt['count'] ) ); ?>)</span>
                                    </label>
                                    <br>
                                <?php endforeach; ?>
                            </fieldset>
                        </td>
                    </tr>
                    <?php
                    $taxonomies        = VigIA_Markdown_Endpoints::get_public_taxonomies();
                    $selected_tax      = isset( $settings['taxonomies'] ) ? (array) $settings['taxonomies'] : array();
                    $taxonomies_count  = count( $taxonomies );
                    $taxonomies_collapse = $taxonomies_count > 20;
                    if ( ! empty( $taxonomies ) ) :
                    ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Taxonomies', 'vigia' ); ?></th>
                        <td>
                            <p class="description" style="margin-top:0;">
                                <?php esc_html_e( 'Serve markdown for taxonomy archive pages (categories, tags, product categories, custom taxonomies). Disabled by default. Useful for online shops with rich category descriptions.', 'vigia' ); ?>
                            </p>
                            <?php if ( $taxonomies_collapse ) : ?>
                            <details class="vigia-md-tax-details">
                                <summary>
                                    <?php
                                    printf(
                                        /* translators: %d: total number of public taxonomies on the site. */
                                        esc_html__( 'Show all taxonomies (%d)', 'vigia' ),
                                        (int) $taxonomies_count
                                    );
                                    ?>
                                </summary>
                            <?php endif; ?>
                            <fieldset id="vigia-md-taxonomies" class="vigia-md-tax-grid">
                                <?php foreach ( $taxonomies as $tax ) : ?>
                                    <label>
                                        <input type="checkbox" name="vigia_md_taxonomies[]" value="<?php echo esc_attr( $tax['name'] ); ?>"
                                            <?php checked( in_array( $tax['name'], $selected_tax, true ) ); ?>>
                                        <?php echo esc_html( $tax['label'] ); ?>
                                        <code class="vigia-md-tax-slug"><?php echo esc_html( $tax['name'] ); ?></code>
                                        <span class="vigia-pt-count">(<?php echo esc_html( number_format_i18n( $tax['count'] ) ); ?>)</span>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <?php if ( $taxonomies_collapse ) : ?>
                            </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Exclusion filters', 'vigia' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" id="vigia-md-respect-llms" <?php checked( $settings['respect_llms_filters'] ); ?>>
                                <?php esc_html_e( 'Respect LLMs.txt exclusion filters (noindex, URL patterns, manual excludes)', 'vigia' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'When enabled, posts excluded from your llms.txt will also be excluded from markdown endpoints.', 'vigia' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <div class="vigia-markdown-actions">
                    <button type="button" id="vigia-save-markdown-settings" class="button button-primary">
                        <?php esc_html_e( 'Save settings', 'vigia' ); ?>
                    </button>
                </div>

                <?php if ( $settings['enabled'] ) : ?>
                <div class="vigia-markdown-note vigia-info-panel" style="margin-top: 15px;">
                    <span class="dashicons dashicons-info-outline"></span>
                    <p>
                        <?php esc_html_e( 'Markdown endpoints are active. Blocked crawlers will receive a 403 response instead of markdown.', 'vigia' ); ?>
                        <?php if ( $settings['enable_md_urls'] ) : ?>
                            <?php esc_html_e( 'Remember to flush your permalinks if .md URLs are not working (Settings > Permalinks > Save).', 'vigia' ); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render JSON-LD tab (v1.7.0)
     */
    private static function render_jsonld_tab() {
        $settings    = VigIA_JsonLD_Generator::get_settings();
        $conflicts   = VigIA_JsonLD_Generator::detect_schema_conflict();
        $ai_features = VigIA_JsonLD_Generator::get_ai_discovery_features();
        $pages       = VigIA_JsonLD_Generator::get_pages_for_selector();
        $preview     = VigIA_JsonLD_Generator::get_preview();
        ?>

        <div class="vigia-extras-section vigia-jsonld-generator">
            <h2><span class="dashicons dashicons-editor-code"></span> <?php esc_html_e( 'JSON-LD structured data', 'vigia' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Generate JSON-LD structured data to help search engines and AI systems identify your site, understand your brand, and discover your AI-ready content.', 'vigia' ); ?>
                <a href="<?php echo esc_url( __( 'https://ayudawp-com.translate.goog/json-ld/?_x_tr_sl=es&_x_tr_tl=en', 'vigia' ) ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'Learn more about JSON-LD', 'vigia' ); ?> <span class="dashicons dashicons-external"></span>
                </a>
            </p>

            <?php
            self::render_visibility_coexistence_notice(
                'identity',
                __( 'Manage your Site Identity schema (Organization/Person and WebSite) from Visibility.', 'vigia' )
            );
            ?>

            <?php if ( $conflicts && ! ( class_exists( 'VigIA_Sibling_Visibility' ) && VigIA_Sibling_Visibility::should_defer( 'identity' ) ) ) : ?>
            <div class="vigia-notice vigia-notice-warning vigia-jsonld-conflict">
                <p>
                    <span class="dashicons dashicons-warning"></span>
                    <?php
                    $names = array_map( function( $c ) {
                        return '<strong>' . esc_html( $c['name'] ) . '</strong>';
                    }, $conflicts );
                    printf(
                        /* translators: %s: comma-separated list of SEO plugin names */
                        esc_html__( 'Detected active SEO plugins generating WebSite/Organization schema: %s. Enabling Site Identity below may create duplicate structured data. Consider disabling the equivalent schema in your SEO plugin first, or use only the AI Discovery section.', 'vigia' ),
                        wp_kses( implode( ', ', $names ), array( 'strong' => array() ) )
                    );
                    ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- Site Identity section -->
            <div class="vigia-jsonld-section">
                <h3>
                    <label>
                        <input type="checkbox" id="vigia-jsonld-identity-enabled" <?php checked( $settings['site_identity_enabled'] ); ?>>
                        <?php esc_html_e( 'Site Identity', 'vigia' ); ?>
                    </label>
                </h3>
                <p class="description">
                    <?php esc_html_e( 'Generates WebSite and Organization/Person schema. Helps search engines and AI systems identify who you are, connect with your Knowledge Graph entity, and understand your brand.', 'vigia' ); ?>
                </p>

                <div class="vigia-jsonld-identity-fields" id="vigia-jsonld-identity-fields" style="<?php echo $settings['site_identity_enabled'] ? '' : 'display:none;'; ?>">
                    <table class="form-table vigia-compact-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Entity type', 'vigia' ); ?></th>
                            <td>
                                <label>
                                    <input type="radio" name="vigia_entity_type" value="Organization" <?php checked( $settings['entity_type'], 'Organization' ); ?>>
                                    <?php esc_html_e( 'Organization', 'vigia' ); ?>
                                </label>
                                <label style="margin-left: 20px;">
                                    <input type="radio" name="vigia_entity_type" value="Person" <?php checked( $settings['entity_type'], 'Person' ); ?>>
                                    <?php esc_html_e( 'Person', 'vigia' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="vigia-jsonld-name"><?php esc_html_e( 'Name', 'vigia' ); ?></label>
                            </th>
                            <td>
                                <input type="text" id="vigia-jsonld-name" class="regular-text"
                                    value="<?php echo esc_attr( $settings['entity_name'] ); ?>"
                                    placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
                                <p class="description"><?php esc_html_e( 'Leave empty to use site title.', 'vigia' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="vigia-jsonld-description"><?php esc_html_e( 'Description', 'vigia' ); ?></label>
                            </th>
                            <td>
                                <textarea id="vigia-jsonld-description" class="large-text" rows="3"
                                    placeholder="<?php echo esc_attr( get_bloginfo( 'description' ) ); ?>"><?php echo esc_textarea( $settings['entity_description'] ); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="vigia-jsonld-logo"><?php esc_html_e( 'Logo URL', 'vigia' ); ?></label>
                            </th>
                            <td>
                                <div class="vigia-jsonld-logo-field">
                                    <input type="text" id="vigia-jsonld-logo" class="regular-text"
                                        value="<?php echo esc_attr( $settings['entity_logo'] ); ?>"
                                        placeholder="https://example.com/logo.png">
                                    <button type="button" id="vigia-jsonld-logo-btn" class="button">
                                        <?php esc_html_e( 'Select image', 'vigia' ); ?>
                                    </button>
                                </div>
                                <p class="description"><?php esc_html_e( 'Recommended: square image, minimum 112x112px.', 'vigia' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="vigia-jsonld-url"><?php esc_html_e( 'Entity URL', 'vigia' ); ?></label>
                            </th>
                            <td>
                                <input type="url" id="vigia-jsonld-url" class="regular-text"
                                    value="<?php echo esc_attr( $settings['entity_url'] ); ?>"
                                    placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
                                <p class="description"><?php esc_html_e( 'Leave empty to use site URL.', 'vigia' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Site search', 'vigia' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="vigia-jsonld-search-action" <?php checked( $settings['search_action'] ); ?>>
                                    <?php esc_html_e( 'Add SearchAction (enables sitelinks search box in Google)', 'vigia' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="vigia-jsonld-sameas"><?php esc_html_e( 'Social profiles and sameAs', 'vigia' ); ?></label>
                            </th>
                            <td>
                                <textarea id="vigia-jsonld-sameas" class="large-text" rows="5"
                                    placeholder="https://twitter.com/yourprofile&#10;https://www.facebook.com/yourpage&#10;https://www.linkedin.com/company/yourcompany&#10;https://www.youtube.com/@yourchannel&#10;https://es.wikipedia.org/wiki/YourEntity&#10;https://www.wikidata.org/wiki/Q12345"><?php echo esc_textarea( $settings['same_as'] ); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e( 'One URL per line. Add your social profiles, Wikipedia, Wikidata, and any other authoritative page that represents you or your organization. This helps AI systems and search engines connect your brand identity across the web.', 'vigia' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <hr class="vigia-section-divider">

            <!-- AI Discovery section -->
            <div class="vigia-jsonld-section">
                <h3>
                    <label>
                        <input type="checkbox" id="vigia-jsonld-ai-enabled" <?php checked( $settings['ai_discovery_enabled'] ); ?>>
                        <?php esc_html_e( 'AI Discovery', 'vigia' ); ?>
                    </label>
                </h3>
                <p class="description">
                    <?php esc_html_e( 'Adds ReadAction pointers in your JSON-LD so AI systems can discover your machine-readable content endpoints. This structured signal complements your llms.txt and Markdown for Agents features.', 'vigia' ); ?>
                </p>

                <div class="vigia-jsonld-ai-fields" id="vigia-jsonld-ai-fields" style="<?php echo $settings['ai_discovery_enabled'] ? '' : 'display:none;'; ?>">
                    <table class="form-table vigia-compact-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Content endpoints', 'vigia' ); ?></th>
                            <td>
                                <fieldset>
                                    <label class="<?php echo $ai_features['llms_txt'] ? '' : 'vigia-feature-unavailable'; ?>">
                                        <input type="checkbox" id="vigia-jsonld-ai-llms"
                                            <?php checked( $settings['ai_discovery_llms'] ); ?>
                                            <?php disabled( ! $ai_features['llms_txt'] ); ?>>
                                        <?php esc_html_e( 'llms.txt', 'vigia' ); ?>
                                        <?php if ( ! $ai_features['llms_txt'] ) : ?>
                                            <span class="vigia-feature-status"><?php esc_html_e( '(file not generated yet)', 'vigia' ); ?></span>
                                        <?php endif; ?>
                                    </label>
                                    <br>
                                    <label class="<?php echo $ai_features['llms_full_txt'] ? '' : 'vigia-feature-unavailable'; ?>">
                                        <input type="checkbox" id="vigia-jsonld-ai-llms-full"
                                            <?php checked( $settings['ai_discovery_llms_full'] ); ?>
                                            <?php disabled( ! $ai_features['llms_full_txt'] ); ?>>
                                        <?php esc_html_e( 'llms-full.txt', 'vigia' ); ?>
                                        <?php if ( ! $ai_features['llms_full_txt'] ) : ?>
                                            <span class="vigia-feature-status"><?php esc_html_e( '(file not generated yet)', 'vigia' ); ?></span>
                                        <?php endif; ?>
                                    </label>
                                    <br>
                                    <label class="<?php echo $ai_features['markdown'] ? '' : 'vigia-feature-unavailable'; ?>">
                                        <input type="checkbox" id="vigia-jsonld-ai-markdown"
                                            <?php checked( $settings['ai_discovery_markdown'] ); ?>
                                            <?php disabled( ! $ai_features['markdown'] ); ?>>
                                        <?php esc_html_e( 'Markdown for Agents endpoints', 'vigia' ); ?>
                                        <?php if ( ! $ai_features['markdown'] ) : ?>
                                            <span class="vigia-feature-status"><?php esc_html_e( '(not enabled)', 'vigia' ); ?></span>
                                        <?php endif; ?>
                                    </label>
                                </fieldset>
                                <p class="description">
                                    <?php esc_html_e( 'Only available features can be selected. Generate your llms.txt files or enable Markdown for Agents in their respective tabs first.', 'vigia' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <hr class="vigia-section-divider">

            <!-- Output page selector (visible only when at least one section is enabled) -->
            <?php
            $output_visible = $settings['site_identity_enabled'] || $settings['ai_discovery_enabled'];
            ?>
            <div class="vigia-jsonld-section" id="vigia-jsonld-output-section" style="<?php echo $output_visible ? '' : 'display:none;'; ?>">
                <h3><?php esc_html_e( 'Output page', 'vigia' ); ?></h3>
                <p class="description">
                    <?php esc_html_e( 'Choose which page will include the JSON-LD markup. Typically the homepage, but you can choose any published page (e.g., an About page for sites where the homepage is a blog or shop catalog).', 'vigia' ); ?>
                </p>
                <select id="vigia-jsonld-output-page">
                    <option value="front_page" <?php selected( $settings['output_page'], 'front_page' ); ?>>
                        <?php esc_html_e( 'Front page (homepage)', 'vigia' ); ?>
                    </option>
                    <?php foreach ( $pages as $page ) : ?>
                        <option value="<?php echo esc_attr( $page['id'] ); ?>" <?php selected( $settings['output_page'], (string) $page['id'] ); ?>>
                            <?php echo esc_html( $page['title'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Actions -->
            <div class="vigia-jsonld-actions">
                <button type="button" id="vigia-save-jsonld-settings" class="button button-primary">
                    <?php esc_html_e( 'Save settings', 'vigia' ); ?>
                </button>
            </div>

            <hr class="vigia-section-divider">

            <!-- Live preview -->
            <div class="vigia-jsonld-section">
                <h3>
                    <span class="dashicons dashicons-visibility"></span>
                    <?php esc_html_e( 'Preview', 'vigia' ); ?>
                    <button type="button" id="vigia-jsonld-toggle-preview" class="button button-small">
                        <?php esc_html_e( 'Show/Hide', 'vigia' ); ?>
                    </button>
                </h3>
                <div id="vigia-jsonld-preview-wrap" style="display:none;">
                    <p class="description">
                        <?php esc_html_e( 'This is a live preview of the JSON-LD that will be injected. Changes update in real time as you modify the settings above.', 'vigia' ); ?>
                    </p>
                    <pre id="vigia-jsonld-preview" class="vigia-jsonld-preview-code"><?php echo esc_html( $preview ); ?></pre>
                    <p class="description" id="vigia-jsonld-preview-empty" style="<?php echo ! empty( $preview ) ? 'display:none;' : ''; ?>">
                        <?php esc_html_e( 'Enable at least one section above to see the preview.', 'vigia' ); ?>
                    </p>
                </div>
            </div>

            <?php
            // Hidden data for JS preview builder.
            $preview_data = array(
                'siteUrl'     => untrailingslashit( home_url() ),
                'siteName'    => get_bloginfo( 'name' ),
                'aiFeatures'  => $ai_features,
            );
            ?>
            <script type="text/javascript">
                var vigiaJsonldData = <?php echo wp_json_encode( $preview_data ); ?>;
            </script>
        </div>
        <?php
    }

    /**
     * Render MCP server tab
     *
     * Informational panel that replaces the global admin notice. Shows the
     * adapter status, the endpoint URL, authentication instructions and
     * client connection snippets for Claude Code, Cursor and Claude Desktop.
     *
     * @since 1.11.0
     */
    private static function render_mcp_tab() {
        $adapter_loaded  = class_exists( 'VigIA_MCP_Server' ) && VigIA_MCP_Server::is_adapter_available();
        $abilities_ready = class_exists( 'VigIA_MCP_Server' ) && VigIA_MCP_Server::is_abilities_api_available();
        $mcp_active      = $adapter_loaded && $abilities_ready;
        $endpoint_url    = home_url( '/wp-json/vigia/v1/mcp' );
        $app_pass_url    = admin_url( 'profile.php#application-passwords-section' );
        $write_allowed   = (bool) apply_filters( 'vigia_can_write_via_abilities', true );

        // Detect existing VigIA MCP application password for the current user.
        $current_user        = wp_get_current_user();
        $app_pass_available  = function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();
        $existing_app_pass   = null;
        if ( $current_user && $current_user->ID && class_exists( '\\WP_Application_Passwords' ) ) {
            $passwords = \WP_Application_Passwords::get_user_application_passwords( $current_user->ID );
            if ( is_array( $passwords ) ) {
                foreach ( $passwords as $pw ) {
                    if ( isset( $pw['name'] ) && self::MCP_APP_PASSWORD_NAME === $pw['name'] ) {
                        $existing_app_pass = $pw;
                        break;
                    }
                }
            }
        }

        $read_abilities  = array(
            'vigia/get-crawler-stats'  => __( 'Crawler statistics (totals, unique crawlers, pages crawled)', 'vigia' ),
            'vigia/get-top-crawlers'   => __( 'Top crawlers by activity', 'vigia' ),
            'vigia/get-top-pages'      => __( 'Most crawled pages', 'vigia' ),
            'vigia/get-blocked-items'  => __( 'List of blocked crawlers and IPs', 'vigia' ),
            'vigia/get-robots-rules'   => __( 'Current AI rules in robots.txt', 'vigia' ),
        );
        $write_abilities = array(
            'vigia/block-crawler'      => __( 'Block a crawler by User-Agent or IP', 'vigia' ),
            'vigia/unblock-crawler'    => __( 'Remove an existing block', 'vigia' ),
            'vigia/add-robots-disallow' => __( 'Add a Disallow directive to robots.txt', 'vigia' ),
            'vigia/remove-robots-rule' => __( 'Remove a robots.txt rule', 'vigia' ),
        );
        ?>

        <div class="vigia-extras-section">
            <h2><span class="dashicons dashicons-rest-api"></span> <?php esc_html_e( 'MCP Server (Model Context Protocol)', 'vigia' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Expose VigIA abilities as MCP tools to any compatible client (Claude Code, Cursor, Claude Desktop, Codex CLI, Antigravity, Continue, etc.) over a single authenticated REST endpoint. Powered by the official WordPress MCP Adapter.', 'vigia' ); ?>
            </p>

            <!-- Server status -->
            <div class="vigia-mcp-status">
                <h3><?php esc_html_e( 'Server status', 'vigia' ); ?></h3>
                <?php if ( $mcp_active ) : ?>
                    <p>
                        <span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>
                        <strong><?php esc_html_e( 'MCP server active.', 'vigia' ); ?></strong>
                        <?php esc_html_e( 'The endpoint is registered and ready to receive authenticated requests.', 'vigia' ); ?>
                    </p>
                <?php elseif ( ! $adapter_loaded ) : ?>
                    <p>
                        <span class="dashicons dashicons-warning" style="color:#dba617;"></span>
                        <strong><?php esc_html_e( 'MCP server inactive: bundled adapter not found.', 'vigia' ); ?></strong>
                        <?php esc_html_e( 'The WordPress MCP Adapter is shipped inside the plugin under vendor/ and should load automatically. If you see this message, the vendor directory is missing or unreadable.', 'vigia' ); ?>
                    </p>
                    <p>
                        <?php
                        printf(
                            /* translators: %s: expected vendor path */
                            esc_html__( 'Reinstall the plugin or restore the directory %s and reload this page.', 'vigia' ),
                            '<code>' . esc_html( WP_PLUGIN_DIR . '/vigia/vendor/' ) . '</code>'
                        );
                        ?>
                    </p>
                <?php else : ?>
                    <p>
                        <span class="dashicons dashicons-warning" style="color:#dba617;"></span>
                        <strong><?php esc_html_e( 'MCP server inactive: Abilities API not available.', 'vigia' ); ?></strong>
                        <?php esc_html_e( 'The MCP Adapter is loaded but wp_register_ability() is not defined, so the adapter bails out silently and no routes are registered. The Abilities API ships with WordPress 6.9 and later. Update WordPress to enable MCP.', 'vigia' ); ?>
                    </p>
                <?php endif; ?>
            </div>

            <?php if ( $mcp_active ) : ?>
                <!-- Endpoint -->
                <div class="vigia-mcp-endpoint">
                    <h3><?php esc_html_e( 'Endpoint', 'vigia' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'Point your MCP client at this URL:', 'vigia' ); ?></p>
                    <pre><code><?php echo esc_html( $endpoint_url ); ?></code></pre>
                </div>

                <!-- Quick connect (one-click flow) -->
                <div class="vigia-mcp-quick-connect"
                     data-endpoint="<?php echo esc_attr( $endpoint_url ); ?>"
                     data-username="<?php echo esc_attr( $current_user ? $current_user->user_login : '' ); ?>">
                    <h3><?php esc_html_e( 'Quick connect', 'vigia' ); ?></h3>

                    <?php if ( ! $app_pass_available ) : ?>
                        <p>
                            <span class="dashicons dashicons-warning" style="color:#dba617;"></span>
                            <strong><?php esc_html_e( 'Application Passwords are disabled on this site.', 'vigia' ); ?></strong>
                            <?php esc_html_e( 'They require HTTPS and may be disabled by a filter. Enable them to use the one-click flow, or use the manual instructions below.', 'vigia' ); ?>
                        </p>
                    <?php elseif ( $existing_app_pass ) : ?>
                        <p>
                            <span class="dashicons dashicons-info" style="color:#2271b1;"></span>
                            <?php
                            $created_ts = isset( $existing_app_pass['created'] ) ? (int) $existing_app_pass['created'] : 0;
                            $created    = $created_ts ? wp_date( get_option( 'date_format' ), $created_ts ) : '';
                            printf(
                                /* translators: 1: application password name, 2: creation date */
                                esc_html__( 'A %1$s Application Password already exists (created %2$s). The plain password was only displayed once at creation time.', 'vigia' ),
                                '<code>' . esc_html( self::MCP_APP_PASSWORD_NAME ) . '</code>',
                                esc_html( $created )
                            );
                            ?>
                        </p>
                        <p>
                            <button type="button"
                                    class="button button-secondary vigia-mcp-revoke"
                                    data-uuid="<?php echo esc_attr( isset( $existing_app_pass['uuid'] ) ? $existing_app_pass['uuid'] : '' ); ?>">
                                <?php esc_html_e( 'Revoke and generate a new one', 'vigia' ); ?>
                            </button>
                            <span class="vigia-mcp-revoke-status" style="margin-left:8px;"></span>
                        </p>
                    <?php else : ?>
                        <p class="description">
                            <?php esc_html_e( 'Generate a dedicated Application Password and ready-to-paste connection commands for Claude Code, Cursor and Claude Desktop. The plain password is shown only once.', 'vigia' ); ?>
                        </p>
                        <p>
                            <button type="button"
                                    class="button button-primary vigia-mcp-generate"
                                    <?php disabled( ! current_user_can( 'manage_options' ) ); ?>>
                                <?php esc_html_e( 'Generate password and connection commands', 'vigia' ); ?>
                            </button>
                            <span class="vigia-mcp-generate-status" style="margin-left:8px;"></span>
                        </p>
                    <?php endif; ?>

                    <!-- Result panel (populated by JS after generation) -->
                    <div class="vigia-mcp-quick-result" style="display:none;">
                        <div class="notice notice-warning inline" style="margin:12px 0;">
                            <p><strong><?php esc_html_e( 'Pick the client you actually use and copy only that block.', 'vigia' ); ?></strong>
                            <?php esc_html_e( 'You do not need to copy all of them. The plain password is shown only once: WordPress does not store it. If you lose it, revoke this entry and generate a new one.', 'vigia' ); ?></p>
                        </div>

                        <h4><?php esc_html_e( 'Claude Code', 'vigia' ); ?></h4>
                        <p class="description">
                            <?php esc_html_e( 'Open a terminal and paste this command. Claude Code will register VigIA as an MCP server and reuse it from any project.', 'vigia' ); ?>
                        </p>
                        <div class="vigia-mcp-cmd-row">
                            <pre><code class="vigia-mcp-cmd-claudecode"></code></pre>
                            <button type="button" class="button vigia-mcp-copy" data-target=".vigia-mcp-cmd-claudecode">
                                <?php esc_html_e( 'Copy', 'vigia' ); ?>
                            </button>
                        </div>

                        <h4><?php esc_html_e( 'Cursor', 'vigia' ); ?></h4>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: 1: file path, 2: in-app menu path */
                                esc_html__( 'If the file does not exist yet, save this as %1$s (or paste it from inside Cursor at %2$s). If you already have a config file, use the safe merger below.', 'vigia' ),
                                '<code>~/.cursor/mcp.json</code>',
                                '<em>' . esc_html__( 'Settings → Cursor Settings → MCP', 'vigia' ) . '</em>'
                            );
                            ?>
                        </p>
                        <div class="vigia-mcp-cmd-row">
                            <pre><code class="vigia-mcp-cmd-cursor"></code></pre>
                            <button type="button" class="button vigia-mcp-copy" data-target=".vigia-mcp-cmd-cursor">
                                <?php esc_html_e( 'Copy', 'vigia' ); ?>
                            </button>
                        </div>
                        <details class="vigia-mcp-merger" data-client="cursor">
                            <summary><?php esc_html_e( 'I already have a Cursor config file → safe merger', 'vigia' ); ?></summary>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: config file path */
                                    esc_html__( 'Paste the current contents of %s below. The plugin will add VigIA to it without breaking your other MCP servers, and give you back the full file ready to save.', 'vigia' ),
                                    '<code>~/.cursor/mcp.json</code>'
                                );
                                ?>
                            </p>
                            <textarea class="vigia-mcp-merger-input" rows="6" spellcheck="false"
                                      placeholder='{ &quot;mcpServers&quot;: { ... } }'></textarea>
                            <p>
                                <button type="button" class="button button-secondary vigia-mcp-merger-go">
                                    <?php esc_html_e( 'Merge VigIA into my config', 'vigia' ); ?>
                                </button>
                                <span class="vigia-mcp-merger-status"></span>
                            </p>
                            <div class="vigia-mcp-merger-output" style="display:none;">
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %s: config file path */
                                        esc_html__( 'Replace the entire contents of %s with this. Backup the file first if you are unsure.', 'vigia' ),
                                        '<code>~/.cursor/mcp.json</code>'
                                    );
                                    ?>
                                </p>
                                <div class="vigia-mcp-cmd-row">
                                    <pre><code class="vigia-mcp-merger-result"></code></pre>
                                    <button type="button" class="button vigia-mcp-copy-merged">
                                        <?php esc_html_e( 'Copy', 'vigia' ); ?>
                                    </button>
                                </div>
                            </div>
                        </details>

                        <h4><?php esc_html_e( 'Claude Desktop', 'vigia' ); ?></h4>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: 1: macOS path, 2: Windows path */
                                esc_html__( 'If the file does not exist yet, save this as %1$s on macOS or %2$s on Windows, then restart the app. If you already have a config file, use the safe merger below — pasting on top will break Claude Desktop.', 'vigia' ),
                                '<code>~/Library/Application Support/Claude/claude_desktop_config.json</code>',
                                '<code>%APPDATA%\\Claude\\claude_desktop_config.json</code>'
                            );
                            ?>
                        </p>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: 1: Node.js download link, 2: alternative clients */
                                esc_html__( 'Claude Desktop only speaks stdio to local processes, so we launch a small bridge (%1$s) via npx that proxies to VigIA over HTTP. This requires %2$s installed on your machine. If you do not want to install Node.js, connect from Claude Code or Cursor instead — they speak HTTP MCP natively.', 'vigia' ),
                                '<code>mcp-remote</code>',
                                '<a href="https://nodejs.org/" target="_blank" rel="noopener noreferrer">Node.js</a>'
                            );
                            ?>
                        </p>
                        <div class="vigia-mcp-cmd-row">
                            <pre><code class="vigia-mcp-cmd-claudedesktop"></code></pre>
                            <button type="button" class="button vigia-mcp-copy" data-target=".vigia-mcp-cmd-claudedesktop">
                                <?php esc_html_e( 'Copy', 'vigia' ); ?>
                            </button>
                        </div>
                        <details class="vigia-mcp-merger" data-client="claudedesktop">
                            <summary><?php esc_html_e( 'I already have a Claude Desktop config file → safe merger', 'vigia' ); ?></summary>
                            <p class="description">
                                <?php esc_html_e( 'Paste the current contents of your claude_desktop_config.json below. The plugin will add VigIA to it without touching your existing preferences or other MCP servers, and give you back the full file ready to save.', 'vigia' ); ?>
                            </p>
                            <textarea class="vigia-mcp-merger-input" rows="6" spellcheck="false"
                                      placeholder='{ &quot;preferences&quot;: { ... }, &quot;mcpServers&quot;: { ... } }'></textarea>
                            <p>
                                <button type="button" class="button button-secondary vigia-mcp-merger-go">
                                    <?php esc_html_e( 'Merge VigIA into my config', 'vigia' ); ?>
                                </button>
                                <span class="vigia-mcp-merger-status"></span>
                            </p>
                            <div class="vigia-mcp-merger-output" style="display:none;">
                                <p class="description">
                                    <?php esc_html_e( 'Replace the entire contents of your claude_desktop_config.json with this, then restart Claude Desktop. Backup the file first if you are unsure.', 'vigia' ); ?>
                                </p>
                                <div class="vigia-mcp-cmd-row">
                                    <pre><code class="vigia-mcp-merger-result"></code></pre>
                                    <button type="button" class="button vigia-mcp-copy-merged">
                                        <?php esc_html_e( 'Copy', 'vigia' ); ?>
                                    </button>
                                </div>
                            </div>
                        </details>

                        <h4><?php esc_html_e( 'Other MCP clients (Codex CLI, Continue, Cline, Antigravity, Zed…)', 'vigia' ); ?></h4>
                        <p class="description">
                            <?php esc_html_e( 'Most MCP clients accept HTTP transport with custom headers. Use these two values inside whatever configuration format your client expects.', 'vigia' ); ?>
                        </p>
                        <p class="description"><strong><?php esc_html_e( 'Server URL', 'vigia' ); ?></strong></p>
                        <div class="vigia-mcp-cmd-row">
                            <pre><code class="vigia-mcp-cmd-generic-url"></code></pre>
                            <button type="button" class="button vigia-mcp-copy" data-target=".vigia-mcp-cmd-generic-url">
                                <?php esc_html_e( 'Copy', 'vigia' ); ?>
                            </button>
                        </div>
                        <p class="description"><strong><?php esc_html_e( 'Authorization header', 'vigia' ); ?></strong></p>
                        <div class="vigia-mcp-cmd-row">
                            <pre><code class="vigia-mcp-cmd-generic-header"></code></pre>
                            <button type="button" class="button vigia-mcp-copy" data-target=".vigia-mcp-cmd-generic-header">
                                <?php esc_html_e( 'Copy', 'vigia' ); ?>
                            </button>
                        </div>
                        <p class="description">
                            <?php esc_html_e( 'AI Studio, ChatGPT web and other browser-only assistants without an MCP client cannot connect. They need a desktop / CLI client that speaks MCP over HTTP.', 'vigia' ); ?>
                        </p>

                        <p class="description vigia-mcp-help-link">
                            <?php
                            printf(
                                /* translators: %s: link to the WordPress.org plugin FAQ */
                                esc_html__( 'Need to merge this with an existing config file, or troubleshooting a connection problem? See the %s.', 'vigia' ),
                                '<a href="https://wordpress.org/plugins/vigia/#faq" target="_blank" rel="noopener noreferrer">' . esc_html__( 'full setup guide and FAQ', 'vigia' ) . ' <span class="dashicons dashicons-external"></span></a>'
                            );
                            ?>
                        </p>
                    </div>
                </div>

                <!-- What you can ask your AI now -->
                <div class="vigia-mcp-prompts">
                    <h3><?php esc_html_e( 'What can I ask my AI now?', 'vigia' ); ?></h3>
                    <p class="description">
                        <?php esc_html_e( 'Once your client is connected, just talk to it in plain language. The AI will pick the right VigIA tool automatically. Some examples to try:', 'vigia' ); ?>
                    </p>
                    <ul class="vigia-mcp-prompt-examples">
                        <li><em><?php esc_html_e( '"Show me the AI crawlers visiting my site this week."', 'vigia' ); ?></em></li>
                        <li><em><?php esc_html_e( '"Which pages of my site does GPTBot crawl the most?"', 'vigia' ); ?></em></li>
                        <li><em><?php esc_html_e( '"Compare ClaudeBot and PerplexityBot activity over the last 30 days."', 'vigia' ); ?></em></li>
                        <li><em><?php esc_html_e( '"Block ClaudeBot from my site."', 'vigia' ); ?></em></li>
                        <li><em><?php esc_html_e( '"Add a Disallow for PerplexityBot in robots.txt."', 'vigia' ); ?></em></li>
                        <li><em><?php esc_html_e( '"Which crawlers are ignoring my robots.txt?"', 'vigia' ); ?></em></li>
                        <li><em><?php esc_html_e( '"List all crawlers I have blocked and let me unblock OpenAI."', 'vigia' ); ?></em></li>
                    </ul>
                    <p class="description">
                        <?php
                        if ( $write_allowed ) {
                            esc_html_e( 'Write actions (block, unblock, robots changes) are currently enabled. Switch to read-only below if you only want to allow consultation.', 'vigia' );
                        } else {
                            esc_html_e( 'Write actions are currently disabled (read-only mode). Your AI can answer questions but cannot block crawlers or change robots.txt. Toggle this below.', 'vigia' );
                        }
                        ?>
                    </p>
                </div>

                <!-- Manual instructions (collapsed by default) -->
                <div class="vigia-mcp-manual">
                    <details>
                        <summary style="cursor:pointer;font-weight:600;">
                            <?php esc_html_e( 'Manual setup (advanced — only if Quick connect does not fit your case)', 'vigia' ); ?>
                        </summary>

                        <h4><?php esc_html_e( 'Authentication', 'vigia' ); ?></h4>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: link to the WordPress profile page application passwords section */
                                esc_html__( 'The endpoint uses HTTP Basic auth with a WordPress Application Password. You can generate one yourself from %s for any user with the manage_options capability.', 'vigia' ),
                                '<a href="' . esc_url( $app_pass_url ) . '">' . esc_html__( 'your profile → Application Passwords', 'vigia' ) . '</a>'
                            );
                            ?>
                        </p>
                        <p class="description">
                            <?php esc_html_e( 'Then build the Authorization header by base64-encoding "username:application-password":', 'vigia' ); ?>
                        </p>
                        <pre><code>echo -n "username:xxxx xxxx xxxx xxxx xxxx xxxx" | base64</code></pre>

                        <h4><?php esc_html_e( 'Claude Code', 'vigia' ); ?></h4>
                        <pre><code>claude mcp add --transport http vigia <?php echo esc_html( $endpoint_url ); ?> \
  --header "Authorization: Basic BASE64_OF_USER_AND_APP_PASSWORD"</code></pre>

                        <h4><?php esc_html_e( 'Cursor', 'vigia' ); ?></h4>
                        <pre><code>{
  "mcpServers": {
    "vigia": {
      "type": "http",
      "url": "<?php echo esc_html( $endpoint_url ); ?>",
      "headers": {
        "Authorization": "Basic BASE64_OF_USER_AND_APP_PASSWORD"
      }
    }
  }
}</code></pre>

                        <h4><?php esc_html_e( 'Claude Desktop', 'vigia' ); ?></h4>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: package name */
                                esc_html__( 'Requires Node.js installed on your machine (Claude Desktop launches %s via npx as a local stdio bridge to the HTTP server).', 'vigia' ),
                                '<code>mcp-remote</code>'
                            );
                            ?>
                        </p>
                        <pre><code>{
  "mcpServers": {
    "vigia": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "<?php echo esc_html( $endpoint_url ); ?>",
        "--header",
        "Authorization: Basic BASE64_OF_USER_AND_APP_PASSWORD"
      ]
    }
  }
}</code></pre>
                    </details>
                </div>

                <!-- Exposed abilities -->
                <div class="vigia-mcp-abilities">
                    <h3><?php esc_html_e( 'Exposed abilities', 'vigia' ); ?></h3>
                    <p class="description">
                        <?php esc_html_e( 'VigIA exposes 9 abilities. Read abilities only require manage_options. Write abilities additionally honour the vigia_can_write_via_abilities filter.', 'vigia' ); ?>
                    </p>

                    <h4>
                        <?php esc_html_e( 'Read', 'vigia' ); ?>
                        <span class="vigia-mcp-pill vigia-mcp-pill-read"><?php esc_html_e( 'always available', 'vigia' ); ?></span>
                    </h4>
                    <ul class="vigia-mcp-abilities-list">
                        <?php foreach ( $read_abilities as $ability => $desc ) : ?>
                            <li><code><?php echo esc_html( $ability ); ?></code> — <?php echo esc_html( $desc ); ?></li>
                        <?php endforeach; ?>
                    </ul>

                    <h4>
                        <?php esc_html_e( 'Write', 'vigia' ); ?>
                        <?php if ( $write_allowed ) : ?>
                            <span class="vigia-mcp-pill vigia-mcp-pill-write-on"><?php esc_html_e( 'enabled', 'vigia' ); ?></span>
                        <?php else : ?>
                            <span class="vigia-mcp-pill vigia-mcp-pill-write-off"><?php esc_html_e( 'disabled by filter', 'vigia' ); ?></span>
                        <?php endif; ?>
                    </h4>
                    <ul class="vigia-mcp-abilities-list">
                        <?php foreach ( $write_abilities as $ability => $desc ) : ?>
                            <li><code><?php echo esc_html( $ability ); ?></code> — <?php echo esc_html( $desc ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Read-only mode -->
                <div class="vigia-mcp-readonly">
                    <h3><?php esc_html_e( 'Read-only mode', 'vigia' ); ?></h3>
                    <p class="description">
                        <?php esc_html_e( 'When enabled, your AI can only read data through MCP (statistics, top crawlers, blocked items, robots.txt rules). Actions that change anything on the site (blocking crawlers, editing robots.txt) are rejected.', 'vigia' ); ?>
                    </p>
                    <p class="description">
                        <?php esc_html_e( 'Useful when you grant an MCP password to a third party (a colleague, an automation, a SaaS panel) and only want them to consult, not modify.', 'vigia' ); ?>
                    </p>
                    <p>
                        <label class="vigia-mcp-readonly-toggle">
                            <input type="checkbox"
                                   id="vigia-mcp-readonly-checkbox"
                                   <?php checked( (bool) get_option( 'vigia_mcp_read_only', false ) ); ?> />
                            <strong><?php esc_html_e( 'Enable read-only mode', 'vigia' ); ?></strong>
                        </label>
                        <span class="vigia-mcp-readonly-status" style="margin-left:8px;"></span>
                    </p>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: filter name */
                            esc_html__( 'Developers: this toggle simply turns the %s filter to false. You can also keep it disabled here and force read-only from a mu-plugin.', 'vigia' ),
                            '<code>vigia_can_write_via_abilities</code>'
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render sidebar promotional boxes
     */
    private static function render_sidebar_promos() {
        // Add Thickbox support for plugin install popups.
        add_thickbox();

        // Render promotional banner with random plugins and services.
        $promo_banner = new Vigia_Promo_Banner( 'vigia', 'vigia', 'vigia' );
        $promo_banner->render( 'vertical' );
    }
}