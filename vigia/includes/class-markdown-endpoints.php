<?php
/**
 * Markdown Endpoints class
 *
 * Serves individual posts/pages as markdown for AI agents.
 * Supports Accept: text/markdown content negotiation and .md URL endpoints.
 * Follows the Markdown for Agents standard (Cloudflare).
 *
 * @package VigIA
 * @since 1.5.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Markdown Endpoints class
 */
class VigIA_Markdown_Endpoints {

	/**
	 * Option name for markdown settings
	 */
	const OPTION_NAME = 'vigia_markdown_settings';

	/**
	 * Default settings
	 *
	 * @var array
	 */
	private static $defaults = array(
		'enabled'              => false,
		'enable_md_urls'       => true,
		'enable_negotiation'   => true,
		'enable_link_header'   => true,
		'enable_link_tag'      => true,
		'respect_llms_filters' => true,
		'post_types'           => array( 'post', 'page' ),
	);

	/**
	 * Initialize hooks
	 */
	public static function init() {
		$settings = self::get_settings();

		if ( ! $settings['enabled'] ) {
			return;
		}

		// Register rewrite rules for .md URLs.
		if ( $settings['enable_md_urls'] ) {
			add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ), 20 );
			add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		}

		// Content negotiation and .md URL handling.
		add_action( 'template_redirect', array( __CLASS__, 'handle_request' ), 5 );

		// Add <link rel="alternate"> in HTML head.
		if ( $settings['enable_link_tag'] ) {
			add_action( 'wp_head', array( __CLASS__, 'add_link_alternate_tag' ) );
		}

		// Add Link header in HTTP response.
		if ( $settings['enable_link_header'] ) {
			add_action( 'template_redirect', array( __CLASS__, 'add_link_header' ), 1 );
		}
	}

	// =========================================================================
	// Settings
	// =========================================================================

	/**
	 * Get settings
	 *
	 * @return array
	 */
	public static function get_settings() {
		$settings = get_option( self::OPTION_NAME, array() );
		return wp_parse_args( $settings, self::$defaults );
	}

	/**
	 * Save settings
	 *
	 * @param array $settings Settings to save.
	 * @return bool
	 */
	public static function save_settings( $settings ) {
		$normalized = self::$defaults;

		// Booleans.
		$bool_keys = array( 'enabled', 'enable_md_urls', 'enable_negotiation', 'enable_link_header', 'enable_link_tag', 'respect_llms_filters' );
		foreach ( $bool_keys as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$normalized[ $key ] = self::to_bool( $settings[ $key ] );
			}
		}

		// Arrays.
		if ( isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ) {
			$normalized['post_types'] = array_map( 'sanitize_key', $settings['post_types'] );
		}

		// Flush rewrite rules when enabling/disabling.
		$old_settings = self::get_settings();
		if ( $old_settings['enabled'] !== $normalized['enabled'] || $old_settings['enable_md_urls'] !== $normalized['enable_md_urls'] ) {
			update_option( 'vigia_flush_rewrite', true );
		}

		return update_option( self::OPTION_NAME, $normalized );
	}

	/**
	 * Convert value to boolean
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( 'true', '1', 'yes', 'on' ), true );
		}
		return (bool) $value;
	}

	// =========================================================================
	// Rewrite rules for .md URLs
	// =========================================================================

	/**
	 * Add rewrite rules for .md endpoints
	 */
	public static function add_rewrite_rules() {
		// Flush if needed (after settings change).
		if ( get_option( 'vigia_flush_rewrite' ) ) {
			delete_option( 'vigia_flush_rewrite' );
			flush_rewrite_rules();
		}

		// Match any path ending in .md.
		add_rewrite_rule(
			'^(.+)\.md$',
			'index.php?vigia_markdown=1&vigia_markdown_path=$matches[1]',
			'top'
		);
	}

	/**
	 * Add custom query vars
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'vigia_markdown';
		$vars[] = 'vigia_markdown_path';
		return $vars;
	}

	// =========================================================================
	// Request handling
	// =========================================================================

	/**
	 * Handle incoming request - check for .md URL or Accept header
	 */
	public static function handle_request() {
		$settings = self::get_settings();

		// Check for .md URL endpoint.
		if ( $settings['enable_md_urls'] && get_query_var( 'vigia_markdown' ) ) {
			$path = get_query_var( 'vigia_markdown_path' );
			if ( $path ) {
				self::serve_markdown_by_path( sanitize_text_field( $path ) );
				return;
			}
		}

		// Check for Accept: text/markdown content negotiation.
		if ( $settings['enable_negotiation'] && self::accepts_markdown() ) {
			if ( is_singular() ) {
				$the_post = get_queried_object();
				if ( $the_post && self::is_post_eligible( $the_post ) ) {
					self::serve_markdown_response( $the_post );
				}
			}
		}
	}

	/**
	 * Check if the request accepts markdown via Accept header
	 *
	 * @return bool
	 */
	private static function accepts_markdown() {
		if ( ! isset( $_SERVER['HTTP_ACCEPT'] ) ) {
			return false;
		}

		$accept = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) );
		return false !== stripos( $accept, 'text/markdown' );
	}

	/**
	 * Serve markdown from a .md URL path
	 *
	 * @param string $path URL path without .md extension.
	 */
	private static function serve_markdown_by_path( $path ) {
		$path = trim( $path, '/' );

		if ( empty( $path ) ) {
			self::send_404();
			return;
		}

		$the_post = self::find_post_by_path( $path );

		if ( ! $the_post || ! self::is_post_eligible( $the_post ) ) {
			self::send_404();
			return;
		}

		self::serve_markdown_response( $the_post );
	}

	/**
	 * Find a post by its URL path
	 *
	 * Handles both simple slugs and nested paths (parent/child for pages).
	 *
	 * @param string $path URL path.
	 * @return WP_Post|null
	 */
	private static function find_post_by_path( $path ) {
		// Try page path first (handles nested pages like parent/child).
		$page = get_page_by_path( $path );
		if ( $page && 'publish' === $page->post_status ) {
			return $page;
		}

		// Try as a post slug.
		$slug       = basename( $path );
		$settings   = self::get_settings();
		$post_types = ! empty( $settings['post_types'] ) ? $settings['post_types'] : array( 'post', 'page' );

		$posts = get_posts(
			array(
				'name'        => $slug,
				'post_type'   => $post_types,
				'post_status' => 'publish',
				'numberposts' => 1,
			)
		);

		if ( ! empty( $posts ) ) {
			return $posts[0];
		}

		return null;
	}

	/**
	 * Check if a post is eligible for markdown serving
	 *
	 * @param WP_Post $the_post Post object.
	 * @return bool
	 */
	private static function is_post_eligible( $the_post ) {
		if ( 'publish' !== $the_post->post_status ) {
			return false;
		}

		$settings   = self::get_settings();
		$post_types = ! empty( $settings['post_types'] ) ? $settings['post_types'] : array( 'post', 'page' );

		if ( ! in_array( $the_post->post_type, $post_types, true ) ) {
			return false;
		}

		// Respect LLMs.txt exclusion filters if enabled.
		if ( $settings['respect_llms_filters'] && class_exists( 'VigIA_LLMS_Generator' ) ) {
			$llms_settings = VigIA_LLMS_Generator::get_settings();

			// Check noindex exclusion.
			if ( ! empty( $llms_settings['exclude_noindex'] ) && self::is_post_noindex( $the_post->ID ) ) {
				return false;
			}

			// Check URL pattern exclusion.
			if ( ! empty( $llms_settings['exclude_patterns'] ) ) {
				$patterns = array_filter( array_map( 'trim', explode( "\n", $llms_settings['exclude_patterns'] ) ) );
				$url      = get_permalink( $the_post->ID );

				foreach ( $patterns as $pattern ) {
					if ( empty( $pattern ) ) {
						continue;
					}
					$regex = '#' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '#i';
					if ( preg_match( $regex, $url ) ) {
						return false;
					}
				}
			}

			// Check manual excludes.
			if ( ! empty( $llms_settings['manual_excludes'] ) && in_array( $the_post->ID, array_map( 'absint', $llms_settings['manual_excludes'] ), true ) ) {
				return false;
			}
		}

		/**
		 * Filter whether a post is eligible for markdown output
		 *
		 * @param bool    $eligible Whether the post is eligible.
		 * @param WP_Post $the_post Post object.
		 */
		return apply_filters( 'vigia_markdown_post_eligible', true, $the_post );
	}

	/**
	 * Check if a post is set to noindex by SEO plugins
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_post_noindex( $post_id ) {
		// NoIndexer plugin (AyudaWP) - check in parallel to any SEO plugin.
		// Use static method if available (handles bulk rules + exclusions).
		// Fall back to direct meta check when class is not loaded (e.g. admin/AJAX context).
		if ( class_exists( 'Noindexer_Frontend' ) ) {
			if ( Noindexer_Frontend::is_noindex( $post_id ) ) {
				return true;
			}
		} elseif ( class_exists( 'VigIA_LLMS_Generator' ) && VigIA_LLMS_Generator::is_noindexer_active() ) {
			if ( get_post_meta( $post_id, '_noindexer_noindex', true ) ) {
				return true;
			}
		}

		// Yoast SEO.
		if ( '1' === get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return true;
		}

		// Rank Math.
		$rankmath = get_post_meta( $post_id, 'rank_math_robots', true );
		if ( is_array( $rankmath ) && in_array( 'noindex', $rankmath, true ) ) {
			return true;
		}

		// All in One SEO.
		if ( '1' === get_post_meta( $post_id, '_aioseo_noindex', true ) ) {
			return true;
		}

		// SEOPress.
		if ( 'yes' === get_post_meta( $post_id, '_seopress_robots_index', true ) ) {
			return true;
		}

		// The SEO Framework.
		if ( '1' === get_post_meta( $post_id, '_genesis_noindex', true ) ) {
			return true;
		}

		return false;
	}

	// =========================================================================
	// Markdown generation and response
	// =========================================================================

	/**
	 * Serve a markdown response for a post
	 *
	 * @param WP_Post $the_post Post object.
	 */
	private static function serve_markdown_response( $the_post ) {
		// Check if crawler is blocked.
		if ( class_exists( 'VigIA_Blocker' ) ) {
			$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
			if ( ! empty( $user_agent ) ) {
				$blocks = VigIA_Blocker::get_all_blocks();
				foreach ( $blocks as $block ) {
					if ( 'useragent' === $block['type'] && false !== stripos( $user_agent, $block['pattern'] ) ) {
						status_header( 403 );
						nocache_headers();
						header( 'Content-Type: text/plain; charset=utf-8' );
						echo 'Access denied';
						exit;
					}
				}
			}
		}

		// Track this request if from a known crawler.
		self::maybe_track_request();

		// Generate markdown.
		$markdown = self::generate_post_markdown( $the_post );

		// Estimate token count (~4 chars per token).
		$token_count = (int) ceil( mb_strlen( $markdown, 'UTF-8' ) / 4 );

		// Send response.
		status_header( 200 );
		nocache_headers();

		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Vary: Accept' );
		header( 'X-Markdown-Tokens: ' . $token_count );
		header( 'Link: <' . esc_url( get_permalink( $the_post ) ) . '>; rel="canonical"' );
		
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown plain text output.
		echo $markdown;
		exit;
	}

	/**
	 * Track the markdown request in VigIA analytics
	 */
	private static function maybe_track_request() {
		if ( ! class_exists( 'VigIA_Crawler_Detector' ) || ! class_exists( 'VigIA_Database' ) ) {
			return;
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$crawler    = VigIA_Crawler_Detector::detect( $user_agent );

		if ( ! $crawler ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		VigIA_Database::insert_visit(
			array(
				'crawler_name'     => $crawler['name'],
				'crawler_category' => $crawler['category'],
				'user_agent'       => $user_agent,
				'request_url'      => home_url( $request_uri ),
				'request_path'     => wp_parse_url( $request_uri, PHP_URL_PATH ),
				'ip_address'       => self::get_client_ip(),
				'http_status'      => 200,
				'visit_date'       => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Get client IP address
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		$ip_keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				if ( strpos( $ip, ',' ) !== false ) {
					$ips = explode( ',', $ip );
					$ip  = trim( $ips[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Generate markdown for a single post
	 *
	 * @param WP_Post $the_post Post object.
	 * @return string
	 */
	private static function generate_post_markdown( $the_post ) {
		$output  = self::build_frontmatter( $the_post );
		$output .= '# ' . get_the_title( $the_post ) . "\n\n";
		$output .= self::get_clean_content( $the_post ) . "\n";

		return $output;
	}

	/**
	 * Build YAML frontmatter with post metadata
	 *
	 * @param WP_Post $the_post Post object.
	 * @return string
	 */
	private static function build_frontmatter( $the_post ) {
		$fm = "---\n";

		$fm .= 'title: "' . self::escape_yaml( get_the_title( $the_post ) ) . '"' . "\n";

		$excerpt = self::get_clean_excerpt( $the_post );
		if ( $excerpt ) {
			$fm .= 'description: "' . self::escape_yaml( $excerpt ) . '"' . "\n";
		}

		$fm .= 'url: ' . get_permalink( $the_post ) . "\n";
		$fm .= 'date: ' . get_the_date( 'Y-m-d', $the_post ) . "\n";
		$fm .= 'modified: ' . get_the_modified_date( 'Y-m-d', $the_post ) . "\n";

		$author_name = get_the_author_meta( 'display_name', $the_post->post_author );
		if ( $author_name ) {
			$fm .= 'author: "' . self::escape_yaml( $author_name ) . '"' . "\n";
		}

		$thumbnail_url = get_the_post_thumbnail_url( $the_post, 'full' );
		if ( $thumbnail_url ) {
			$fm .= 'image: ' . $thumbnail_url . "\n";
		}

		$categories = get_the_category( $the_post->ID );
		if ( ! empty( $categories ) ) {
			$cat_names = array_map(
				function ( $cat ) {
					return '"' . self::escape_yaml( $cat->name ) . '"';
				},
				$categories
			);
			$fm .= 'categories: [' . implode( ', ', $cat_names ) . "]\n";
		}

		$tags = get_the_tags( $the_post->ID );
		if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
			$tag_names = array_map(
				function ( $tag ) {
					return '"' . self::escape_yaml( $tag->name ) . '"';
				},
				$tags
			);
			$fm .= 'tags: [' . implode( ', ', $tag_names ) . "]\n";
		}

		$fm .= 'type: ' . $the_post->post_type . "\n";

		$locale = get_locale();
		if ( $locale ) {
			$fm .= 'lang: ' . substr( $locale, 0, 2 ) . "\n";
		}

		$fm .= "---\n\n";

		return $fm;
	}

	/**
	 * Escape a string for YAML value
	 *
	 * @param string $value Value to escape.
	 * @return string
	 */
	private static function escape_yaml( $value ) {
		return str_replace( array( '"', "\n", "\r" ), array( '\\"', ' ', '' ), $value );
	}

	/**
	 * Get clean post excerpt
	 *
	 * @param WP_Post $the_post Post object.
	 * @return string
	 */
	private static function get_clean_excerpt( $the_post ) {
		if ( ! empty( $the_post->post_excerpt ) ) {
			return wp_strip_all_tags( $the_post->post_excerpt );
		}

		$content = wp_strip_all_tags( strip_shortcodes( $the_post->post_content ) );
		$content = preg_replace( '/\s+/', ' ', trim( $content ) );

		if ( strlen( $content ) > 200 ) {
			$content = substr( $content, 0, 200 );
			$content = substr( $content, 0, strrpos( $content, ' ' ) ) . '...';
		}

		return $content;
	}

	/**
	 * Get clean content converted to markdown
	 *
	 * @param WP_Post $the_post Post object.
	 * @return string
	 */
	private static function get_clean_content( $the_post ) {
		$content          = $the_post->post_content;
		$original_content = $content;

		// Save and set up post context for shortcodes.
		global $post;
		$original_post   = $post;
		$GLOBALS['post'] = $the_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $the_post );

		// Execute shortcodes.
		$content = do_shortcode( $content );

		// Apply content filters.
		remove_filter( 'the_content', 'do_shortcode', 11 );
		$content = apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		add_filter( 'the_content', 'do_shortcode', 11 );

		// Restore original post.
		if ( isset( $original_post ) ) {
			$GLOBALS['post'] = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $original_post );
		} else {
			wp_reset_postdata();
		}

		// Fall back to raw extraction if shortcodes remain unprocessed.
		if ( preg_match( '/\[[a-z][a-z0-9_-]*[\s\]]/i', $content ) ) {
			$content = self::extract_text_from_shortcodes( $original_content );
		}

		$content = strip_shortcodes( $content );

		return self::html_to_markdown( $content );
	}

	/**
	 * Extract readable text from unprocessed shortcodes (page builders fallback)
	 *
	 * @param string $content Content with shortcodes.
	 * @return string
	 */
	private static function extract_text_from_shortcodes( $content ) {
		// Remove self-closing shortcodes.
		$content = preg_replace( '/\[[a-z][a-z0-9_-]*[^\]]*\/\]/is', '', $content );

		// Remove opening and closing shortcode tags, keep content between them.
		$content = preg_replace( '/\[\/?[a-z][a-z0-9_-]*[^\]]*\]/is', '', $content );

		return $content;
	}

	/**
	 * Convert HTML to markdown
	 *
	 * @param string $html HTML content.
	 * @return string Markdown content.
	 */
	private static function html_to_markdown( $html ) {
		// Remove script and style tags.
		$html = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html );
		$html = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $html );

		// Remove empty page builder wrappers.
		$html = preg_replace( '/<(div|section|article|aside|header|footer|nav|main)[^>]*>\s*<\/\1>/is', '', $html );

		// Images (before links to avoid conflicts).
		$html = preg_replace( '/<img[^>]*src=["\']([^"\']+)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*\/?>/is', '![$2]($1)', $html );
		$html = preg_replace( '/<img[^>]*alt=["\']([^"\']*)["\'][^>]*src=["\']([^"\']+)["\'][^>]*\/?>/is', '![$1]($2)', $html );
		$html = preg_replace( '/<img[^>]*src=["\']([^"\']+)["\'][^>]*\/?>/is', '![]($1)', $html );

		// Links.
		$html = preg_replace( '/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $html );

		// Code blocks (pre > code).
		$html = preg_replace_callback(
			'/<pre[^>]*>\s*<code[^>]*(?:class=["\'][^"\']*language-([^"\'\s]+)[^"\']*["\'])?[^>]*>(.*?)<\/code>\s*<\/pre>/is',
			function ( $matches ) {
				$lang = ! empty( $matches[1] ) ? $matches[1] : '';
				$code = html_entity_decode( wp_strip_all_tags( $matches[2] ), ENT_QUOTES, 'UTF-8' );
				return "\n\n```" . $lang . "\n" . trim( $code ) . "\n```\n\n";
			},
			$html
		);

		// Inline code.
		$html = preg_replace( '/<code[^>]*>(.*?)<\/code>/is', '`$1`', $html );

		// Blockquotes.
		$html = preg_replace_callback(
			'/<blockquote[^>]*>(.*?)<\/blockquote>/is',
			function ( $matches ) {
				$text  = wp_strip_all_tags( $matches[1] );
				$lines = explode( "\n", trim( $text ) );
				$lines = array_map(
					function ( $line ) {
						return '> ' . trim( $line );
					},
					$lines
				);
				return "\n\n" . implode( "\n", $lines ) . "\n\n";
			},
			$html
		);

		// Headings.
		$html = preg_replace( '/<h1[^>]*>(.*?)<\/h1>/is', "\n\n# $1\n\n", $html );
		$html = preg_replace( '/<h2[^>]*>(.*?)<\/h2>/is', "\n\n## $1\n\n", $html );
		$html = preg_replace( '/<h3[^>]*>(.*?)<\/h3>/is', "\n\n### $1\n\n", $html );
		$html = preg_replace( '/<h4[^>]*>(.*?)<\/h4>/is', "\n\n#### $1\n\n", $html );
		$html = preg_replace( '/<h5[^>]*>(.*?)<\/h5>/is', "\n\n##### $1\n\n", $html );
		$html = preg_replace( '/<h6[^>]*>(.*?)<\/h6>/is', "\n\n###### $1\n\n", $html );

		// Horizontal rules.
		$html = preg_replace( '/<hr[^>]*\/?>/is', "\n\n---\n\n", $html );

		// Ordered lists.
		$html = preg_replace_callback(
			'/<ol[^>]*>(.*?)<\/ol>/is',
			function ( $matches ) {
				$items = array();
				preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $matches[1], $li_matches );
				$counter = 1;
				foreach ( $li_matches[1] as $item ) {
					$items[] = $counter . '. ' . trim( wp_strip_all_tags( $item ) );
					$counter++;
				}
				return "\n\n" . implode( "\n", $items ) . "\n\n";
			},
			$html
		);

		// Unordered lists.
		$html = preg_replace( '/<li[^>]*>(.*?)<\/li>/is', "- $1\n", $html );
		$html = preg_replace( '/<\/?[ou]l[^>]*>/is', "\n", $html );

		// Paragraphs and line breaks.
		$html = preg_replace( '/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $html );
		$html = preg_replace( '/<br[^>]*\/?>/is', "  \n", $html );

		// Bold, italic, strikethrough.
		$html = preg_replace( '/<(strong|b)[^>]*>(.*?)<\/(strong|b)>/is', '**$2**', $html );
		$html = preg_replace( '/<(em|i)[^>]*>(.*?)<\/(em|i)>/is', '*$2*', $html );
		$html = preg_replace( '/<(del|s|strike)[^>]*>(.*?)<\/(del|s|strike)>/is', '~~$2~~', $html );

		// Tables.
		$html = preg_replace_callback(
			'/<table[^>]*>(.*?)<\/table>/is',
			array( __CLASS__, 'convert_table_to_markdown' ),
			$html
		);

		// Figure/figcaption.
		$html = preg_replace( '/<\/?figure[^>]*>/is', "\n", $html );
		$html = preg_replace( '/<figcaption[^>]*>(.*?)<\/figcaption>/is', "*$1*\n", $html );

		// Strip remaining wrappers (keep content).
		$html = preg_replace( '/<(div|section|article|aside|header|footer|nav|main|span)[^>]*>/is', '', $html );
		$html = preg_replace( '/<\/(div|section|article|aside|header|footer|nav|main|span)>/is', '', $html );

		// Strip any remaining HTML tags.
		$html = wp_strip_all_tags( $html );
		$html = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );

		// Clean up shortcode artifacts.
		$html = preg_replace( '/\[[a-z][a-z0-9_-]*[^\]]*\]/is', '', $html );
		$html = preg_replace( '/\[\/[a-z][a-z0-9_-]*\]/is', '', $html );

		// Clean up whitespace.
		$html = preg_replace( '/\n{3,}/', "\n\n", $html );
		$html = preg_replace( '/[ \t]+/', ' ', $html );
		$lines = explode( "\n", $html );
		$lines = array_map( 'trim', $lines );
		$html  = implode( "\n", $lines );
		$html  = preg_replace( '/\n{3,}/', "\n\n", $html );

		return trim( $html );
	}

	/**
	 * Convert an HTML table to markdown table
	 *
	 * @param array $matches Regex matches.
	 * @return string
	 */
	private static function convert_table_to_markdown( $matches ) {
		$table_html = $matches[1];
		$rows       = array();

		preg_match_all( '/<tr[^>]*>(.*?)<\/tr>/is', $table_html, $tr_matches );

		if ( empty( $tr_matches[1] ) ) {
			return '';
		}

		$is_first_row = true;

		foreach ( $tr_matches[1] as $row_html ) {
			$cells = array();
			preg_match_all( '/<(th|td)[^>]*>(.*?)<\/\1>/is', $row_html, $cell_matches );

			if ( ! empty( $cell_matches[2] ) ) {
				foreach ( $cell_matches[2] as $cell ) {
					$cells[] = trim( wp_strip_all_tags( $cell ) );
				}
			}

			if ( ! empty( $cells ) ) {
				$rows[] = '| ' . implode( ' | ', $cells ) . ' |';

				if ( $is_first_row ) {
					$separator = array_fill( 0, count( $cells ), '---' );
					$rows[]    = '| ' . implode( ' | ', $separator ) . ' |';
					$is_first_row = false;
				}
			}
		}

		return empty( $rows ) ? '' : "\n\n" . implode( "\n", $rows ) . "\n\n";
	}

	// =========================================================================
	// Link headers and alternate tags for discoverability
	// =========================================================================

	/**
	 * Add <link rel="alternate" type="text/markdown"> in HTML head
	 */
	public static function add_link_alternate_tag() {
		if ( ! is_singular() ) {
			return;
		}

		$the_post = get_queried_object();
		if ( ! $the_post || ! self::is_post_eligible( $the_post ) ) {
			return;
		}

		$md_url = self::get_markdown_url( $the_post );
		if ( $md_url ) {
			echo '<link rel="alternate" type="text/markdown" href="' . esc_url( $md_url ) . '" />' . "\n";
		}
	}

	/**
	 * Add Link HTTP header for markdown alternate
	 */
	public static function add_link_header() {
		if ( ! is_singular() ) {
			return;
		}

		$the_post = get_queried_object();
		if ( ! $the_post || ! self::is_post_eligible( $the_post ) ) {
			return;
		}

		$md_url = self::get_markdown_url( $the_post );
		if ( $md_url ) {
			header( 'Link: <' . esc_url( $md_url ) . '>; rel="alternate"; type="text/markdown"', false );
		}
	}

	/**
	 * Get the markdown URL for a post
	 *
	 * @param WP_Post $the_post Post object.
	 * @return string|false
	 */
	public static function get_markdown_url( $the_post ) {
		$settings = self::get_settings();

		if ( ! $settings['enable_md_urls'] ) {
			return false;
		}

		$permalink = get_permalink( $the_post );
		$home_url  = home_url( '/' );
		$path      = str_replace( $home_url, '', $permalink );
		$path      = trim( $path, '/' );

		if ( empty( $path ) ) {
			return false;
		}

		return home_url( '/' . $path . '.md' );
	}

	/**
	 * Send a 404 response
	 */
	private static function send_404() {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Not found';
		exit;
	}
}