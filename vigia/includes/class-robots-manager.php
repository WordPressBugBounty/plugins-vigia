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
     * Closing marker for the AI crawler rules section.
     *
     * Written since 2.4.5. Without it the only way to know where our block ended
     * was to keep eating every line that looked like ours, which swallowed the
     * rules of whichever plugin wrote right after us.
     */
    const AI_RULES_END_MARKER = '# End VigIA - AI Crawler Rules';

    /**
     * Marker for the llms.txt references section.
     */
    const LLMS_REFS_MARKER = '# VigIA LLMs';

    /**
     * Legacy markers for the llms.txt references section (pre 1.2.9).
     */
    const LLMS_LEGACY_START = '# VigIA LLMs.txt references';
    const LLMS_LEGACY_END   = '# End VigIA LLMs.txt references';

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
            $output .= "\n" . self::build_ai_section( $rules );
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
            $output .= "\n" . self::build_llms_section( $has_llms_ref, $has_full_ref );
        }

        // Normalize output: ensure it ends with exactly one newline
        // so plugins hooked after us don't merge with our content.
        return self::normalize( $output );
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

        // Repair markers glued to the line above before anything else, so a rule
        // change also clears a broken llms marker this writer does not otherwise
        // touch.
        $content = self::repair_glued_markers( $content );

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
            $content = rtrim( $content ) . "\n\n" . self::build_ai_section( $rules );
        }

        // Always leave exactly one newline at the end, including when there are
        // no rules left and nothing was appended above.
        $content = self::normalize( $content );

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
     * Build the AI crawler rules block, opening and closing markers included.
     *
     * Single source for the virtual filter and the physical writer, so both
     * always emit the closing marker that bounds the block.
     *
     * @param array $rules Rules array with 'disallow' and 'allow' keys.
     * @return string Block ending in a single newline.
     */
    private static function build_ai_section( $rules ) {
        $section = self::AI_RULES_MARKER . "\n";

        if ( ! empty( $rules['disallow'] ) ) {
            foreach ( $rules['disallow'] as $crawler ) {
                $section .= "User-agent: {$crawler}\n";
                $section .= "Disallow: /\n\n";
            }
        }

        if ( ! empty( $rules['allow'] ) ) {
            foreach ( $rules['allow'] as $crawler ) {
                $section .= "User-agent: {$crawler}\n";
                $section .= "Allow: /\n\n";
            }
        }

        return $section . self::AI_RULES_END_MARKER . "\n";
    }

    /**
     * Build the llms.txt references block.
     *
     * @param bool $add_llms      Include the llms.txt reference.
     * @param bool $add_llms_full Include the llms-full.txt reference.
     * @return string Block ending in a single newline.
     */
    private static function build_llms_section( $add_llms, $add_llms_full ) {
        $section = self::LLMS_REFS_MARKER . "\n";

        if ( $add_llms ) {
            $section .= 'LLMs: ' . home_url( '/llms.txt' ) . "\n";
        }

        if ( $add_llms_full ) {
            $section .= 'LLMs-full: ' . home_url( '/llms-full.txt' ) . "\n";
        }

        return $section;
    }

    /**
     * Locate one of our markers in a line, tolerating a marker another plugin
     * has glued to the end of the previous line.
     *
     * robots.txt is a shared file: several plugins rewrite it, and one of them
     * removing its own block can join the line before it with the line after.
     * Matching the marker with `===` meant a glued marker became invisible, so
     * the block was never cleaned up and a fresh copy was appended on every
     * regeneration. Reported on a live site in July 2026, where the join had
     * produced `Disallow: /# VigIA LLMs` sitting inside the `User-agent: *`
     * group, a site-wide Disallow nobody had written.
     *
     * @param string $line   Line to inspect.
     * @param string $marker Marker to look for.
     * @return string|false Text that preceded the marker (trimmed, '' when the
     *                      marker was alone on the line), or false if not found.
     */
    private static function marker_prefix( $line, $marker ) {
        $pattern = '/^(.*?)' . preg_quote( $marker, '/' ) . '\s*$/i';

        if ( ! preg_match( $pattern, trim( (string) $line ), $matches ) ) {
            return false;
        }

        return trim( $matches[1] );
    }

    /**
     * Is this the tail of one of our own directives, glued in front of a marker?
     *
     * Our AI rules block emits nothing but `Disallow: /` and `Allow: /`, so a
     * fragment like that in front of a marker is our own leftover and goes away
     * with the block. Anything else belongs to another plugin and is preserved.
     *
     * @param string $prefix Text found in front of the marker.
     * @return bool
     */
    private static function is_own_leftover( $prefix ) {
        return (bool) preg_match( '#^(?:Disallow|Allow):\s*/?$#i', $prefix );
    }

    /**
     * Leave the file with no leading blank lines, no runs of blank lines, and
     * exactly one newline at the end.
     *
     * The trailing newline matters as much as the rest: writing a bare rtrim()
     * is what lets the next plugin appending to robots.txt glue its first line
     * onto our last one.
     *
     * @param string $content Robots.txt content.
     * @return string
     */
    private static function normalize( $content ) {
        $content = (string) preg_replace( "/\n{3,}/", "\n\n", (string) $content );

        return rtrim( ltrim( $content, "\n" ) ) . "\n";
    }

    /**
     * Put every marker another plugin glued to the line above back on its own
     * line, without touching the blocks themselves.
     *
     * Runs first in both physical writers, so any write repairs the damage no
     * matter which one it came through. The block-level cleanups are not enough
     * on their own: sync_physical_robots() only strips the AI rules block, so a
     * glued llms marker would survive a rule change untouched, and with it the
     * orphaned `Disallow: /` that is the actual danger.
     *
     * @param string $content Robots.txt content.
     * @return string
     */
    private static function repair_glued_markers( $content ) {
        $markers   = array( self::LLMS_REFS_MARKER, self::AI_RULES_END_MARKER, self::AI_RULES_MARKER );
        $new_lines = array();

        foreach ( explode( "\n", (string) $content ) as $line ) {
            foreach ( $markers as $marker ) {
                $prefix = self::marker_prefix( $line, $marker );

                if ( false === $prefix || '' === $prefix ) {
                    continue;
                }

                // Our own leftover goes away with the block it came from; a third
                // party's line is kept, on a line of its own.
                if ( ! self::is_own_leftover( $prefix ) ) {
                    $new_lines[] = $prefix;
                }

                $line = $marker;
                break;
            }

            $new_lines[] = $line;
        }

        return implode( "\n", $new_lines );
    }

    /**
     * Remove AI rules section from robots.txt content
     *
     * @param string $content Robots.txt content.
     * @return string Content without AI rules section.
     */
    private static function remove_ai_rules_section( $content ) {
        $lines      = explode( "\n", $content );
        $new_lines  = array();
        $in_section = false;

        foreach ( $lines as $line ) {
            $trimmed = trim( $line );

            if ( ! $in_section ) {
                $prefix = self::marker_prefix( $line, self::AI_RULES_MARKER );

                if ( false !== $prefix ) {
                    $in_section = true;

                    // Keep whatever another plugin glued in front of our marker,
                    // on its own line, unless it is our own leftover.
                    if ( '' !== $prefix && ! self::is_own_leftover( $prefix ) ) {
                        $new_lines[] = $prefix;
                    }

                    continue;
                }

                $new_lines[] = $line;
                continue;
            }

            // Inside our block. The closing marker ends it precisely.
            if ( false !== self::marker_prefix( $line, self::AI_RULES_END_MARKER ) ) {
                $in_section = false;
                continue;
            }

            // Files written before 2.4.5 carry no closing marker, so fall back to
            // consuming only the shapes our own writer emits and stop at anything
            // else, which is what keeps a neighbouring plugin's rules alive. The
            // first sync after upgrading rewrites the block with its closing
            // marker and this branch stops being needed.
            if ( '' === $trimmed
                || preg_match( '/^User-agent:\s/i', $trimmed )
                || preg_match( '#^Disallow:\s*/?$#i', $trimmed )
                || preg_match( '#^Allow:\s*/?$#i', $trimmed ) ) {
                continue;
            }

            $in_section  = false;
            $new_lines[] = $line;
        }

        return implode( "\n", $new_lines );
    }

    /**
     * Remove the llms.txt references section from robots.txt content, in both
     * the current and the legacy format, and repair a marker another plugin has
     * glued to the previous line.
     *
     * @param string $content Robots.txt content.
     * @return string Content without our llms.txt references.
     */
    private static function remove_llms_section( $content ) {
        // Legacy start/end format. Replaced with a newline, never with an empty
        // string: the trailing \s* eats the line breaks after the block, so an
        // empty replacement would join the lines around it.
        $pattern = '/' . preg_quote( self::LLMS_LEGACY_START, '/' ) . '.*?' . preg_quote( self::LLMS_LEGACY_END, '/' ) . '\s*/s';
        $content = (string) preg_replace( $pattern, "\n", (string) $content );

        $lines     = explode( "\n", $content );
        $new_lines = array();
        $skip      = false;

        foreach ( $lines as $line ) {
            $prefix = self::marker_prefix( $line, self::LLMS_REFS_MARKER );

            if ( false !== $prefix ) {
                $skip = true;

                if ( '' !== $prefix && ! self::is_own_leftover( $prefix ) ) {
                    $new_lines[] = $prefix;
                }

                continue;
            }

            if ( $skip && preg_match( '/^LLMs(-full)?:\s/i', trim( $line ) ) ) {
                continue;
            }

            $skip        = false;
            $new_lines[] = $line;
        }

        return implode( "\n", $new_lines );
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
     * Does the file carry one of our markers glued to the end of another line?
     *
     * @param string $content Robots.txt content.
     * @return bool
     */
    private static function has_glued_marker( $content ) {
        $markers = array( self::LLMS_REFS_MARKER, self::AI_RULES_MARKER, self::AI_RULES_END_MARKER );

        foreach ( explode( "\n", (string) $content ) as $line ) {
            foreach ( $markers as $marker ) {
                $prefix = self::marker_prefix( $line, $marker );

                if ( false !== $prefix && '' !== $prefix ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Repair a physical robots.txt whose markers were glued to the line above by
     * another plugin's rewrite.
     *
     * A site already damaged does not heal on its own: until 2.4.5 the glued
     * marker was invisible to the cleanup, so toggling the settings only ever
     * appended one more copy of the block while the broken line stayed put, and
     * with it a `Disallow: /` orphaned inside whatever User-agent group preceded
     * it. Called once per update from the version upgrade routine.
     *
     * Both writers strip every copy of their own block through the tolerant
     * removal, which is what repairs the glued line, and then re-emit a single
     * one from current settings, so calling them is the repair.
     *
     * @return bool True when the file needed repairing and was rewritten.
     */
    public static function repair_physical_robots() {
        if ( ! self::has_physical_robots() ) {
            return false;
        }

        $content = self::get_physical_robots_content();

        // Idempotency guard: nothing glued means nothing to fix, so an updated
        // site never rewrites a robots.txt that was already fine.
        if ( '' === $content || ! self::has_glued_marker( $content ) ) {
            return false;
        }

        self::sync_physical_robots();

        $llms_settings = class_exists( 'VigIA_LLMS_Generator' ) ? VigIA_LLMS_Generator::get_settings() : array();

        self::update_physical_robots_llms(
            ! empty( $llms_settings['robots_llms'] ),
            ! empty( $llms_settings['robots_llms_full'] ) && ! empty( $llms_settings['generate_full'] )
        );

        return true;
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

        // Remove our section in both formats, repairing a marker another plugin
        // has glued to the previous line.
        $content = rtrim( self::remove_llms_section( self::repair_glued_markers( $content ) ) );

        // Only add references if files actually exist.
        $add_llms      = $add_llms && file_exists( ABSPATH . 'llms.txt' );
        $add_llms_full = $add_llms_full && file_exists( ABSPATH . 'llms-full.txt' );

        // Build new section if needed.
        if ( $add_llms || $add_llms_full ) {
            $content .= "\n\n" . self::build_llms_section( $add_llms, $add_llms_full );
        }

        // Always leave exactly one newline at the end, including when both
        // references are off and nothing was appended above.
        $content = self::normalize( $content );

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