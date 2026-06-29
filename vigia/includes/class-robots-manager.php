<?php
/**
 * Robots Manager class
 *
 * Manages robots.txt rules for AI crawlers using WordPress virtual robots.txt.
 *
 * @package VigIA
 * @since 1.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Robots Manager class
 */
class VigIA_Robots_Manager {

    /**
     * Option name for AI robots rules
     */
    const OPTION_NAME = 'vigia_robots_rules';

    /**
     * Marker for AI crawler rules section
     */
    const AI_RULES_MARKER = '# VigIA - AI Crawler Rules';

    /**
     * Initialize robots manager hooks
     */
    public static function init() {
        add_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ), 999, 2 );
    }

    /**
     * Filter robots.txt content to add AI crawler rules
     *
     * @param string $output  Robots.txt content.
     * @param bool   $public  Whether the site is public.
     * @return string Modified robots.txt content.
     */
    public static function filter_robots_txt( $output, $public ) {
        if ( ! $public ) {
            return $output;
        }

        // Cede the robots.txt rules for AI to the Visibility sibling when it
        // manages them: don't append our block (it owns the robots-for-AI
        // editor). VigIA keeps its real enforcement, the PHP/403 blocker, which
        // is a separate subsystem untouched by this. See VigIA_Sibling_Visibility.
        if ( self::is_ceded_to_visibility() ) {
            return $output;
        }

        // Normalize: ensure existing content ends with exactly one newline
        // to prevent our sections from merging with previous content.
        $output = rtrim( $output ) . "\n";

        $rules = self::get_ai_rules();

        // Add AI crawler rules section.
        if ( ! empty( $rules['disallow'] ) || ! empty( $rules['allow'] ) ) {
            $ai_section = "\n" . self::AI_RULES_MARKER . "\n";

            // Add disallow rules.
            if ( ! empty( $rules['disallow'] ) ) {
                foreach ( $rules['disallow'] as $crawler ) {
                    $ai_section .= "User-agent: {$crawler}\n";
                    $ai_section .= "Disallow: /\n\n";
                }
            }

            // Add allow rules (explicit allow after global disallow).
            if ( ! empty( $rules['allow'] ) ) {
                foreach ( $rules['allow'] as $crawler ) {
                    $ai_section .= "User-agent: {$crawler}\n";
                    $ai_section .= "Allow: /\n\n";
                }
            }

            $output .= $ai_section;
        }

        // Add LLMs.txt references if enabled AND files exist.
        // Use direct DB query to bypass object cache.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $llms_option = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                'vigia_llms_settings'
            )
        );
        $llms_settings = array();
        if ( $llms_option ) {
            // Decode without allowing object instantiation (guards against PHP object injection).
            $decoded       = is_serialized( $llms_option ) ? unserialize( $llms_option, array( 'allowed_classes' => false ) ) : $llms_option;
            $llms_settings = is_array( $decoded ) ? $decoded : array();
        }
        
        // Only add reference if enabled AND file actually exists.
        $has_llms_ref  = ! empty( $llms_settings['robots_llms'] ) && file_exists( ABSPATH . 'llms.txt' );
        $has_full_ref  = ! empty( $llms_settings['robots_llms_full'] ) && ! empty( $llms_settings['generate_full'] ) && file_exists( ABSPATH . 'llms-full.txt' );

        if ( $has_llms_ref || $has_full_ref ) {
            $llms_section = "\n# VigIA LLMs\n";

            if ( $has_llms_ref ) {
                $llms_section .= 'LLMs: ' . home_url( '/llms.txt' ) . "\n";
            }

            if ( $has_full_ref ) {
                $llms_section .= 'LLMs-full: ' . home_url( '/llms-full.txt' ) . "\n";
            }

            $output .= $llms_section;
        }

        // Normalize output: ensure it ends with exactly one newline
        // so plugins hooked after us don't merge with our content.
        return rtrim( $output ) . "\n";
    }

    /**
     * Get AI crawler rules
     *
     * @return array
     */
    public static function get_ai_rules() {
        // Delegate to Visibility when it owns the robots-for-AI rules, so the
        // compliance monitor and the read-only editor reflect the rules actually
        // served. The robots.txt write paths cede separately (see the is_ceded
        // checks in filter_robots_txt() and sync_physical_robots()).
        if ( self::is_ceded_to_visibility() ) {
            return self::visibility_ai_rules();
        }

        $rules = get_option(
            self::OPTION_NAME,
            array(
                'disallow' => array(),
                'allow'    => array(),
            )
        );

        // Ensure proper structure.
        if ( ! isset( $rules['disallow'] ) ) {
            $rules['disallow'] = array();
        }
        if ( ! isset( $rules['allow'] ) ) {
            $rules['allow'] = array();
        }

        return $rules;
    }

    /**
     * The AI-crawler disallow list Visibility is serving, mapped to VigIA's rules
     * shape, so the compliance monitor checks against the rules actually in effect
     * when VigIA has ceded the robots-for-AI editor. Read via Visibility's public
     * helper when available, with a raw-settings fallback; any bot named in its
     * custom robots lines is folded in too. Allow is always empty (Visibility's
     * model is a disallow list).
     *
     * @return array{disallow:array<int,string>,allow:array<int,string>}
     */
    private static function visibility_ai_rules() {
        $disallow = array();

        if ( (bool) VigIA_Sibling_Visibility::setting( 'noindex', 'robots_block_ai', false ) ) {
            if ( class_exists( 'Native_AEO_Pack_Settings' ) && method_exists( 'Native_AEO_Pack_Settings', 'get_robots_ai_agents' ) ) {
                $disallow = (array) Native_AEO_Pack_Settings::get_robots_ai_agents();
            } else {
                $agents   = VigIA_Sibling_Visibility::setting( 'noindex', 'robots_ai_agents', array() );
                $disallow = is_array( $agents ) ? $agents : array();
            }
        }

        // Fold in any User-agent named in Visibility's custom robots lines.
        $custom = (string) VigIA_Sibling_Visibility::setting( 'noindex', 'robots_custom', '' );
        foreach ( preg_split( '/\r\n|\r|\n/', $custom ) as $line ) {
            if ( preg_match( '/^\s*User-agent:\s*(.+?)\s*$/i', (string) $line, $matches ) && '*' !== $matches[1] ) {
                $disallow[] = $matches[1];
            }
        }

        $disallow = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $disallow ) ) ) );

        return array(
            'disallow' => $disallow,
            'allow'    => array(),
        );
    }

    /**
     * Add disallow rule for crawler
     *
     * @param string $crawler_name Crawler name/User-Agent.
     * @return bool
     */
    public static function add_disallow( $crawler_name ) {
        // Read-only while Visibility owns the rules: it is the source of truth.
        if ( self::is_ceded_to_visibility() ) {
            return false;
        }
        $rules    = self::get_ai_rules();
        $crawler  = sanitize_text_field( $crawler_name );

        // Remove from allow if present.
        $rules['allow'] = array_diff( $rules['allow'], array( $crawler ) );

        // Add to disallow if not already there.
        if ( ! in_array( $crawler, $rules['disallow'], true ) ) {
            $rules['disallow'][] = $crawler;
        }

        $result = update_option( self::OPTION_NAME, $rules );

        // Update physical robots.txt if it exists.
        self::sync_physical_robots();

        return $result;
    }

    /**
     * Remove disallow rule for crawler
     *
     * @param string $crawler_name Crawler name/User-Agent.
     * @return bool
     */
    public static function remove_disallow( $crawler_name ) {
        // Read-only while Visibility owns the rules: it is the source of truth.
        if ( self::is_ceded_to_visibility() ) {
            return false;
        }
        $rules   = self::get_ai_rules();
        $crawler = sanitize_text_field( $crawler_name );

        $rules['disallow'] = array_values( array_diff( $rules['disallow'], array( $crawler ) ) );

        $result = update_option( self::OPTION_NAME, $rules );

        // Update physical robots.txt if it exists.
        self::sync_physical_robots();

        return $result;
    }

    /**
     * Add allow rule for crawler
     *
     * @param string $crawler_name Crawler name/User-Agent.
     * @return bool
     */
    public static function add_allow( $crawler_name ) {
        // Read-only while Visibility owns the rules: it is the source of truth.
        if ( self::is_ceded_to_visibility() ) {
            return false;
        }
        $rules   = self::get_ai_rules();
        $crawler = sanitize_text_field( $crawler_name );

        // Remove from disallow if present.
        $rules['disallow'] = array_diff( $rules['disallow'], array( $crawler ) );

        // Add to allow if not already there.
        if ( ! in_array( $crawler, $rules['allow'], true ) ) {
            $rules['allow'][] = $crawler;
        }

        $result = update_option( self::OPTION_NAME, $rules );

        // Update physical robots.txt if it exists.
        self::sync_physical_robots();

        return $result;
    }

    /**
     * Remove allow rule for crawler
     *
     * @param string $crawler_name Crawler name/User-Agent.
     * @return bool
     */
    public static function remove_allow( $crawler_name ) {
        // Read-only while Visibility owns the rules: it is the source of truth.
        if ( self::is_ceded_to_visibility() ) {
            return false;
        }
        $rules   = self::get_ai_rules();
        $crawler = sanitize_text_field( $crawler_name );

        $rules['allow'] = array_values( array_diff( $rules['allow'], array( $crawler ) ) );

        $result = update_option( self::OPTION_NAME, $rules );

        // Update physical robots.txt if it exists.
        self::sync_physical_robots();

        return $result;
    }

    /**
     * Check if crawler is disallowed
     *
     * @param string $crawler_name Crawler name.
     * @return bool
     */
    public static function is_disallowed( $crawler_name ) {
        $rules = self::get_ai_rules();
        return in_array( $crawler_name, $rules['disallow'], true );
    }

    /**
     * Check if crawler is allowed
     *
     * @param string $crawler_name Crawler name.
     * @return bool
     */
    public static function is_allowed( $crawler_name ) {
        $rules = self::get_ai_rules();
        return in_array( $crawler_name, $rules['allow'], true );
    }

    /**
     * Sync AI crawler rules to physical robots.txt file
     * @since 1.2.9
     *
     * Updates the physical robots.txt file with current AI crawler rules.
     * Only runs if a physical robots.txt exists.
     *
     * @return bool|WP_Error True on success, WP_Error on failure, false if no physical file.
     */
    public static function sync_physical_robots() {
        if ( ! self::has_physical_robots() ) {
            return false;
        }

        $robots_path = ABSPATH . 'robots.txt';

        // Check if writable.
        if ( ! wp_is_writable( $robots_path ) ) {
            return new WP_Error( 'not_writable', __( 'robots.txt file is not writable.', 'vigia' ) );
        }

        // Read current content.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
        $content = file_get_contents( $robots_path );

        if ( false === $content ) {
            return new WP_Error( 'read_error', __( 'Could not read robots.txt file.', 'vigia' ) );
        }

        // Remove existing VigIA AI Crawler Rules section.
        $content = self::remove_ai_rules_section( $content );

        // Get current rules.
        $rules = self::get_ai_rules();

        // When ceding robots-for-AI to the Visibility sibling, write the file
        // back WITHOUT our block (the strip above already removed it, and we add
        // nothing here). This is what keeps the two from fighting over the
        // physical robots.txt on every save/cron.
        if ( self::is_ceded_to_visibility() ) {
            $rules = array(
                'disallow' => array(),
                'allow'    => array(),
            );
        }

        // Build new AI rules section if there are rules.
        if ( ! empty( $rules['disallow'] ) || ! empty( $rules['allow'] ) ) {
            $ai_section = "\n" . self::AI_RULES_MARKER . "\n";

            // Add disallow rules.
            if ( ! empty( $rules['disallow'] ) ) {
                foreach ( $rules['disallow'] as $crawler ) {
                    $ai_section .= "User-agent: {$crawler}\n";
                    $ai_section .= "Disallow: /\n\n";
                }
            }

            // Add allow rules.
            if ( ! empty( $rules['allow'] ) ) {
                foreach ( $rules['allow'] as $crawler ) {
                    $ai_section .= "User-agent: {$crawler}\n";
                    $ai_section .= "Allow: /\n\n";
                }
            }

            $content = rtrim( $content ) . "\n" . $ai_section;
        }

        // Write back using WP_Filesystem.
        global $wp_filesystem;

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Initialize with direct method.
        if ( ! WP_Filesystem( false, ABSPATH, true ) ) {
            // Fallback to direct file write.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- Fallback when WP_Filesystem fails; robots.txt must live at the site root (ABSPATH), wp_upload_dir() is not an option for it.
            $result = file_put_contents( $robots_path, $content );
            return false !== $result ? true : new WP_Error( 'write_error', __( 'Could not write to robots.txt file.', 'vigia' ) );
        }

        if ( ! $wp_filesystem->put_contents( $robots_path, $content, FS_CHMOD_FILE ) ) {
            return new WP_Error( 'write_error', __( 'Could not write to robots.txt file.', 'vigia' ) );
        }

        return true;
    }

    /**
     * Remove AI rules section from robots.txt content
     *
     * @param string $content Robots.txt content.
     * @return string Content without AI rules section.
     */
    private static function remove_ai_rules_section( $content ) {
        $lines     = explode( "\n", $content );
        $new_lines = array();
        $in_section = false;

        foreach ( $lines as $line ) {
            $trimmed = trim( $line );

            // Check if we're entering the AI rules section.
            if ( $trimmed === self::AI_RULES_MARKER ) {
                $in_section = true;
                continue;
            }

            // If in section, skip User-agent, Disallow, and Allow lines for our rules.
            if ( $in_section ) {
                // Check if this line is part of our section (User-agent, Disallow, Allow, or empty).
                if ( empty( $trimmed ) ||
                     preg_match( '/^User-agent:\s/i', $trimmed ) ||
                     preg_match( '/^Disallow:\s*\/?\s*$/i', $trimmed ) ||
                     preg_match( '/^Allow:\s*\/?\s*$/i', $trimmed ) ) {
                    continue;
                }
                // If we hit something else, we've left our section.
                $in_section = false;
            }

            $new_lines[] = $line;
        }

        return rtrim( implode( "\n", $new_lines ) );
    }

    /**
     * Get current robots.txt content
     *
     * @return string
     */
    public static function get_current_robots() {
        // Check for physical robots.txt first.
        if ( self::has_physical_robots() ) {
            return self::get_physical_robots_content();
        }

        // Fall back to virtual robots.txt.
        $site_url = wp_parse_url( home_url(), PHP_URL_HOST );

        // Get WordPress default robots.txt.
        $public = get_option( 'blog_public' );
        $robots = "User-agent: *\n";

        if ( '0' === $public ) {
            $robots .= "Disallow: /\n";
        } else {
            $robots .= "Disallow: /wp-admin/\n";
            $robots .= "Allow: /wp-admin/admin-ajax.php\n";
        }

        // Apply WordPress filters to get actual content.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
        $robots = apply_filters( 'robots_txt', $robots, $public );

        return $robots;
    }

    /**
     * Is VigIA ceding the robots.txt rules for AI to the Visibility sibling?
     *
     * @return bool
     */
    private static function is_ceded_to_visibility() {
        return class_exists( 'VigIA_Sibling_Visibility' )
            && VigIA_Sibling_Visibility::should_defer( 'robots' );
    }

    /**
     * Remove VigIA's AI rules block from the physical robots.txt when ceding to
     * the Visibility sibling, so the two don't fight over the file. No-op when
     * there is no physical robots.txt (the virtual filter already bails). Called
     * from the admin reconciler; relies on sync_physical_robots() detecting the
     * ceded state and writing the file back without our block.
     *
     * @return bool|WP_Error
     */
    public static function cleanup_for_cession() {
        if ( ! self::has_physical_robots() ) {
            return false;
        }

        // Idempotency guard: only rewrite when our block is actually still in the
        // file, so we don't rewrite robots.txt on every admin load while ceded.
        $content = self::get_physical_robots_content();
        if ( '' === $content || false === strpos( $content, self::AI_RULES_MARKER ) ) {
            return false;
        }

        // sync_physical_robots() detects the ceded state and writes the file back
        // without our block.
        return self::sync_physical_robots();
    }

    /**
     * Check if physical robots.txt file exists
     *
     * @return bool
     */
    public static function has_physical_robots() {
        $robots_path = ABSPATH . 'robots.txt';
        return file_exists( $robots_path ) && is_file( $robots_path );
    }

    /**
     * Get physical robots.txt content
     *
     * @return string
     */
    public static function get_physical_robots_content() {
        $robots_path = ABSPATH . 'robots.txt';
        if ( ! self::has_physical_robots() ) {
            return '';
        }

        global $wp_filesystem;
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();

        if ( $wp_filesystem ) {
            return $wp_filesystem->get_contents( $robots_path );
        }

        return '';
    }

    /**
     * Update physical robots.txt with LLMs references
     *
     * @param bool $add_llms      Add llms.txt reference.
     * @param bool $add_llms_full Add llms-full.txt reference.
     * @return bool|WP_Error
     */
    public static function update_physical_robots_llms( $add_llms, $add_llms_full ) {
        $robots_path = ABSPATH . 'robots.txt';

        if ( ! self::has_physical_robots() ) {
            return new WP_Error( 'no_physical', __( 'No physical robots.txt file found.', 'vigia' ) );
        }

        // Check if writable.
        if ( ! wp_is_writable( $robots_path ) ) {
            return new WP_Error( 'not_writable', __( 'robots.txt file is not writable.', 'vigia' ) );
        }

        // Read current content.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
        $content = file_get_contents( $robots_path );

        if ( false === $content ) {
            return new WP_Error( 'read_error', __( 'Could not read robots.txt file.', 'vigia' ) );
        }

        // Markers for our section (current and legacy).
        $start_marker        = '# VigIA LLMs';
        $legacy_start_marker = '# VigIA LLMs.txt references';
        $legacy_end_marker   = '# End VigIA LLMs.txt references';

        // First, remove legacy format with start/end markers.
        $pattern = '/' . preg_quote( $legacy_start_marker, '/' ) . '.*?' . preg_quote( $legacy_end_marker, '/' ) . '\s*/s';
        $content = preg_replace( $pattern, '', $content );

        // Remove current format (marker line + following LLMs lines).
        $lines     = explode( "\n", $content );
        $new_lines = array();
        $skip      = false;

        foreach ( $lines as $line ) {
            $trimmed = trim( $line );
            if ( $trimmed === $start_marker ) {
                $skip = true;
                continue;
            }
            if ( $skip && preg_match( '/^LLMs(-full)?:\s/i', $trimmed ) ) {
                continue;
            }
            $skip        = false;
            $new_lines[] = $line;
        }

        $content = rtrim( implode( "\n", $new_lines ) );

        // Only add references if files actually exist.
        $add_llms      = $add_llms && file_exists( ABSPATH . 'llms.txt' );
        $add_llms_full = $add_llms_full && file_exists( ABSPATH . 'llms-full.txt' );

        // Build new section if needed.
        if ( $add_llms || $add_llms_full ) {
            $llms_section = "\n\n{$start_marker}\n";

            if ( $add_llms ) {
                $llms_section .= 'LLMs: ' . home_url( '/llms.txt' ) . "\n";
            }

            if ( $add_llms_full ) {
                $llms_section .= 'LLMs-full: ' . home_url( '/llms-full.txt' ) . "\n";
            }

            $content .= $llms_section;
        }

        // Write back using WP_Filesystem.
        global $wp_filesystem;

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Initialize with direct method.
        if ( ! WP_Filesystem( false, ABSPATH, true ) ) {
            // Fallback to direct file write.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- Fallback when WP_Filesystem fails; robots.txt must live at the site root (ABSPATH), wp_upload_dir() is not an option for it.
            $result = file_put_contents( $robots_path, $content );
            return false !== $result ? true : new WP_Error( 'write_error', __( 'Could not write to robots.txt file.', 'vigia' ) );
        }

        if ( ! $wp_filesystem->put_contents( $robots_path, $content, FS_CHMOD_FILE ) ) {
            return new WP_Error( 'write_error', __( 'Could not write to robots.txt file.', 'vigia' ) );
        }

        return true;
    }

    /**
     * Update robots.txt with LLMs references (handles both physical and virtual)
     *
     * @param bool $add_llms      Add llms.txt reference.
     * @param bool $add_llms_full Add llms-full.txt reference.
     * @return bool|WP_Error
     */
    public static function update_llms_references( $add_llms, $add_llms_full ) {
        // If physical robots.txt exists, update it directly.
        if ( self::has_physical_robots() ) {
            return self::update_physical_robots_llms( $add_llms, $add_llms_full );
        }

        // For virtual robots.txt, the settings are read by filter_robots_txt().
        // Just return true as settings are saved separately.
        return true;
    }

    /**
     * Get preview of robots.txt with VigIA rules
     *
     * @return string
     */
    public static function get_preview() {
        return self::get_current_robots();
    }

    /**
     * Get compliance data - which crawlers respect/ignore robots.txt
     *
     * @return array
     */
    public static function get_compliance_data() {
        $rules = self::get_ai_rules();

        if ( empty( $rules['disallow'] ) ) {
            return array(
                'compliant'     => array(),
                'non_compliant' => array(),
            );
        }

        // Get recent visits from disallowed crawlers.
        $disallowed = $rules['disallow'];

        // Check database for visits from disallowed crawlers.
        global $wpdb;

        // Get visits in last 30 days from disallowed crawlers.
        // Build the query safely - table name uses wpdb prefix directly.
        $placeholders = implode( ',', array_fill( 0, count( $disallowed ), '%s' ) );

        // Cache key for this query.
        $cache_key = 'vigia_compliance_' . md5( implode( '_', $disallowed ) );
        $results   = wp_cache_get( $cache_key, 'vigia' );

        if ( false === $results ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT crawler_name, COUNT(*) as visit_count, MAX(visit_date) as last_visit 
                    FROM {$wpdb->prefix}vigia_visits 
                    WHERE crawler_name IN ({$placeholders}) 
                    AND visit_date > DATE_SUB(NOW(), INTERVAL 30 DAY) 
                    GROUP BY crawler_name",
                    ...$disallowed
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

            wp_cache_set( $cache_key, $results, 'vigia', HOUR_IN_SECONDS );
        }

        $non_compliant = array();
        if ( $results ) {
            foreach ( $results as $row ) {
                $non_compliant[ $row['crawler_name'] ] = array(
                    'visits'     => (int) $row['visit_count'],
                    'last_visit' => $row['last_visit'],
                );
            }
        }

        // Compliant are those in disallow list but not in results.
        $compliant = array_diff( $disallowed, array_keys( $non_compliant ) );

        return array(
            'compliant'     => array_values( $compliant ),
            'non_compliant' => $non_compliant,
        );
    }

    /**
     * Clear all AI rules
     *
     * @return bool
     */
    public static function clear_all() {
        $result = delete_option( self::OPTION_NAME );

        // Update physical robots.txt to remove rules.
        self::sync_physical_robots();

        return $result;
    }
}

// Initialize hooks.
add_action( 'init', array( 'VigIA_Robots_Manager', 'init' ) );