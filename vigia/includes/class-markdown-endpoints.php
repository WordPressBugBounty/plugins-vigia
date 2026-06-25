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
		'taxonomies'           => array(),
	);

	/**
	 * Initialize hooks
	 */
	public static function init() {
		$settings = self::get_settings();

		if ( ! $settings['enabled'] ) {
			return;
		}

		// Cede Markdown for agents to the Visibility sibling when it serves it.
		// Visibility intercepts on do_parse_request (ahead of our
		// template_redirect), so it already wins the /{slug}.md collision; bailing
		// here also stops us advertising a duplicate .md <link> / Link header and
		// from registering rewrite rules we would never use. See
		// VigIA_Sibling_Visibility for the emit/observe split.
		if ( VigIA_Sibling_Visibility::should_defer( 'markdown' ) ) {
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

		if ( isset( $settings['taxonomies'] ) && is_array( $settings['taxonomies'] ) ) {
			$normalized['taxonomies'] = array_values( array_filter( array_map( 'sanitize_key', $settings['taxonomies'] ) ) );
		}

		// Flush rewrite rules when enabling/disabling or when the taxonomies
		// set changes (term lookups depend on the active taxonomy list, not on
		// the rewrite rules themselves, but we keep the trigger consistent so
		// admins can recover from broken permalinks by toggling the setting).
		$old_settings        = self::get_settings();
		$taxonomies_changed  = $old_settings['taxonomies'] !== $normalized['taxonomies'];
		if (
			$old_settings['enabled'] !== $normalized['enabled']
			|| $old_settings['enable_md_urls'] !== $normalized['enable_md_urls']
			|| $taxonomies_changed
		) {
			update_option( 'vigia_flush_rewrite', true );
		}

		return update_option( self::OPTION_NAME, $normalized );
	}

	/**
	 * List public taxonomies for the settings UI.
	 *
	 * Mirrors VigIA_LLMS_Generator::get_public_post_types() but for taxonomies.
	 * Filters out non-public ones and attachments' taxonomies, returns the
	 * registered label and a term count per taxonomy.
	 *
	 * @return array<string, array{name:string,label:string,count:int}>
	 */
	public static function get_public_taxonomies() {
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		$result     = array();

		foreach ( $taxonomies as $tax ) {
			$count = wp_count_terms(
				array(
					'taxonomy'   => $tax->name,
					'hide_empty' => false,
				)
			);

			$result[ $tax->name ] = array(
				'name'  => $tax->name,
				'label' => isset( $tax->labels->name ) ? $tax->labels->name : $tax->name,
				'count' => is_wp_error( $count ) ? 0 : (int) $count,
			);
		}

		return $result;
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
			} elseif ( is_tax() || is_category() || is_tag() ) {
				$term = get_queried_object();
				if ( $term instanceof WP_Term && self::is_term_eligible( $term ) ) {
					self::serve_markdown_response_for_term( $term );
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

		if ( $the_post && self::is_post_eligible( $the_post ) ) {
			self::serve_markdown_response( $the_post );
			return;
		}

		// Fall back to taxonomy term lookup when no post matches the path.
		$term = self::find_term_by_path( $path );

		if ( $term && self::is_term_eligible( $term ) ) {
			self::serve_markdown_response_for_term( $term );
			return;
		}

		self::send_404();
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
		if ( $page && 'publish' === $page->post_status && '' === $page->post_password ) {
			return $page;
		}

		// Try as a post slug.
		$slug       = basename( $path );
		$settings   = self::get_settings();
		$post_types = ! empty( $settings['post_types'] ) ? $settings['post_types'] : array( 'post', 'page' );

		$posts = get_posts(
			array(
				'name'         => $slug,
				'post_type'    => $post_types,
				'post_status'  => 'publish',
				'has_password' => false,
				'numberposts'  => 1,
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

		// Never serve password-protected posts as markdown; that would bypass the password form.
		if ( '' !== $the_post->post_password ) {
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
	// Taxonomy term resolution and eligibility
	// =========================================================================

	/**
	 * Resolve a URL path into a taxonomy term.
	 *
	 * Strategy: iterate over enabled taxonomies, try each one with its rewrite
	 * base stripped from the path and use get_term_by('slug', $tail). When the
	 * path is hierarchical (e.g. parent/child), only the last segment is the
	 * slug — WordPress allows duplicate slugs across different parents within
	 * the same taxonomy, so we re-verify by comparing the resolved term link
	 * against the original request path.
	 *
	 * @param string $path Request path without the .md suffix and trimmed slashes.
	 * @return WP_Term|null
	 */
	private static function find_term_by_path( $path ) {
		$settings = self::get_settings();

		if ( empty( $settings['taxonomies'] ) ) {
			return null;
		}

		$path     = trim( $path, '/' );
		$segments = explode( '/', $path );
		$slug     = end( $segments );

		if ( empty( $slug ) ) {
			return null;
		}

		$home_url = trailingslashit( home_url( '/' ) );

		foreach ( $settings['taxonomies'] as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'slug'       => $slug,
					'hide_empty' => false,
				)
			);

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( is_wp_error( $link ) ) {
					continue;
				}

				$link_path = trim( str_replace( $home_url, '', trailingslashit( $link ) ), '/' );

				if ( $link_path === $path ) {
					return $term;
				}
			}

			// Single slug match with no path collision is good enough.
			if ( 1 === count( $terms ) && count( $segments ) === 1 ) {
				return $terms[0];
			}
		}

		return null;
	}

	/**
	 * Check if a taxonomy term is eligible for markdown serving.
	 *
	 * @param WP_Term $term Term object.
	 * @return bool
	 */
	private static function is_term_eligible( $term ) {
		if ( ! $term instanceof WP_Term ) {
			return false;
		}

		$settings = self::get_settings();

		if ( empty( $settings['taxonomies'] ) || ! in_array( $term->taxonomy, $settings['taxonomies'], true ) ) {
			return false;
		}

		// Respect LLMs.txt exclusion rules when enabled: URL patterns and the
		// noindex term meta from SEO plugins that support per-term robots.
		if ( $settings['respect_llms_filters'] && class_exists( 'VigIA_LLMS_Generator' ) ) {
			$llms_settings = VigIA_LLMS_Generator::get_settings();

			if ( ! empty( $llms_settings['exclude_patterns'] ) ) {
				$patterns = array_filter( array_map( 'trim', explode( "\n", $llms_settings['exclude_patterns'] ) ) );
				$url      = get_term_link( $term );

				if ( ! is_wp_error( $url ) ) {
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
			}

			if ( self::is_term_noindex( $term ) ) {
				return false;
			}
		}

		/**
		 * Filter whether a taxonomy term is eligible for markdown output.
		 *
		 * @param bool    $eligible Whether the term is eligible.
		 * @param WP_Term $term     Term object.
		 */
		return apply_filters( 'vigia_markdown_term_eligible', true, $term );
	}

	/**
	 * Check if a term is flagged as noindex by SEO plugins that support per-term robots.
	 *
	 * @param WP_Term $term Term object.
	 * @return bool
	 */
	private static function is_term_noindex( $term ) {
		// Yoast SEO stores per-term meta in its own option table, not term meta.
		if ( class_exists( 'WPSEO_Taxonomy_Meta' ) ) {
			$noindex = WPSEO_Taxonomy_Meta::get_term_meta( $term->term_id, $term->taxonomy, 'noindex' );
			if ( 'noindex' === $noindex ) {
				return true;
			}
		}

		// Rank Math stores it in term meta.
		$rankmath = get_term_meta( $term->term_id, 'rank_math_robots', true );
		if ( is_array( $rankmath ) && in_array( 'noindex', $rankmath, true ) ) {
			return true;
		}

		// All in One SEO stores it in term meta as a string flag.
		if ( '1' === get_term_meta( $term->term_id, '_aioseo_noindex', true ) ) {
			return true;
		}

		// SEOPress.
		if ( 'yes' === get_term_meta( $term->term_id, '_seopress_robots_index', true ) ) {
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
		self::send_markdown_response(
			self::generate_post_markdown( $the_post ),
			get_permalink( $the_post )
		);
	}

	/**
	 * Serve a markdown response for a taxonomy term.
	 *
	 * @param WP_Term $term Term object.
	 */
	private static function serve_markdown_response_for_term( $term ) {
		$link = get_term_link( $term );
		if ( is_wp_error( $link ) ) {
			self::send_404();
			return;
		}

		self::send_markdown_response(
			self::generate_term_markdown( $term ),
			$link
		);
	}

	/**
	 * Shared response writer used by both post and term markdown responses.
	 *
	 * Handles user-agent based blocking, analytics tracking, headers and the
	 * actual body output. Exits on completion.
	 *
	 * @param string $markdown      Markdown body to serve.
	 * @param string $canonical_url Canonical URL for the Link header.
	 */
	private static function send_markdown_response( $markdown, $canonical_url ) {
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

		self::maybe_track_request();

		$token_count = (int) ceil( mb_strlen( $markdown, 'UTF-8' ) / 4 );

		status_header( 200 );
		nocache_headers();

		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Vary: Accept' );
		header( 'X-Markdown-Tokens: ' . $token_count );
		header( 'Link: <' . esc_url( $canonical_url ) . '>; rel="canonical"' );

		// The endpoint returns plain Markdown with a `Content-Type: text/markdown`
		// header, not HTML. Escaping it as HTML (esc_html/wp_kses) would corrupt the
		// Markdown syntax that AI agents consume. The body is generated by the plugin
		// from already-filtered post content, never echoed straight from request input.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text Markdown response; see note above.
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

		// This request is served and exited here, but the shutdown tracker
		// would still fire afterwards and log the same hit again. Mark it so
		// that does not happen.
		VigIA_Crawler_Detector::mark_logged();
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
	 * Generate markdown for a taxonomy term archive.
	 *
	 * The body has three optional sections:
	 *  - the term description (rendered through the_content filter),
	 *  - a list of child terms when the taxonomy is hierarchical,
	 *  - a list of the most recent posts assigned to the term.
	 *
	 * @param WP_Term $term Term object.
	 * @return string
	 */
	private static function generate_term_markdown( $term ) {
		$output  = self::build_term_frontmatter( $term );
		$output .= '# ' . $term->name . "\n\n";

		$description = self::get_term_clean_content( $term );
		if ( '' !== $description ) {
			$output .= $description . "\n\n";
		}

		$children = self::get_term_children_section( $term );
		if ( '' !== $children ) {
			$output .= $children;
		}

		$posts = self::get_term_posts_section( $term );
		if ( '' !== $posts ) {
			$output .= $posts;
		}

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

		// WooCommerce: enrich product frontmatter with schema-like data
		// (price, sale price, currency, rating, sku, stock status). This is
		// the closest equivalent to schema.org Product inside YAML; AI agents
		// reading the .md can parse it without extra requests.
		if ( 'product' === $the_post->post_type && function_exists( 'wc_get_product' ) ) {
			$fm .= self::build_woocommerce_product_frontmatter( $the_post );
		}

		$fm .= "---\n\n";

		return $fm;
	}

	/**
	 * Append WooCommerce product fields to a YAML frontmatter string.
	 *
	 * @param WP_Post $the_post Product post.
	 * @return string YAML fragment (lines ending in \n) or empty string.
	 */
	private static function build_woocommerce_product_frontmatter( $the_post ) {
		$product = wc_get_product( $the_post->ID );
		if ( ! $product ) {
			return '';
		}

		$out = '';

		$sku = $product->get_sku();
		if ( '' !== $sku ) {
			$out .= 'sku: "' . self::escape_yaml( $sku ) . '"' . "\n";
		}

		$out .= 'product_type: ' . $product->get_type() . "\n";

		$regular = $product->get_regular_price();
		$sale    = $product->get_sale_price();
		$price   = $product->get_price();

		if ( '' !== $price ) {
			$out .= 'price: ' . $price . "\n";
		}
		if ( '' !== $regular && $regular !== $price ) {
			$out .= 'regular_price: ' . $regular . "\n";
		}
		if ( '' !== $sale ) {
			$out .= 'sale_price: ' . $sale . "\n";
		}

		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$out .= 'currency: ' . get_woocommerce_currency() . "\n";
		}

		$stock_status = $product->get_stock_status();
		if ( $stock_status ) {
			$out .= 'availability: ' . $stock_status . "\n";
		}
		if ( $product->managing_stock() ) {
			$qty = $product->get_stock_quantity();
			if ( null !== $qty ) {
				$out .= 'stock_quantity: ' . (int) $qty . "\n";
			}
		}

		$rating_count = (int) $product->get_rating_count();
		if ( $rating_count > 0 ) {
			$out .= 'rating: ' . (float) $product->get_average_rating() . "\n";
			$out .= 'rating_count: ' . $rating_count . "\n";
			$out .= 'review_count: ' . (int) $product->get_review_count() . "\n";
		}

		return $out;
	}

	/**
	 * Inline WooCommerce snippet for a product listed inside a term archive.
	 *
	 * Returns a short markdown fragment ("12,90 EUR · was 19,90 · ★4.5 (12)")
	 * to append after the post excerpt in get_term_posts_section().
	 *
	 * @param WP_Post $the_post Product post.
	 * @return string
	 */
	private static function product_summary_inline( $the_post ) {
		if ( 'product' !== $the_post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$product = wc_get_product( $the_post->ID );
		if ( ! $product ) {
			return '';
		}

		$parts = array();

		$price   = $product->get_price();
		$regular = $product->get_regular_price();
		$sale    = $product->get_sale_price();

		if ( '' !== $sale && '' !== $regular ) {
			$parts[] = wp_strip_all_tags( wc_price( $sale ) );
			$parts[] = wp_strip_all_tags(
				sprintf(
					/* translators: %s: original price before the discount. */
					__( 'was %s', 'vigia' ),
					wc_price( $regular )
				)
			);
		} elseif ( '' !== $price ) {
			$parts[] = wp_strip_all_tags( wc_price( $price ) );
		}

		$rating_count = (int) $product->get_rating_count();
		if ( $rating_count > 0 ) {
			$parts[] = '★ ' . number_format_i18n( (float) $product->get_average_rating(), 1 ) . ' (' . $rating_count . ')';
		}

		$stock_status = $product->get_stock_status();
		if ( 'outofstock' === $stock_status ) {
			$parts[] = __( 'out of stock', 'vigia' );
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return ' · ' . implode( ' · ', $parts );
	}

	/**
	 * Build YAML frontmatter for a taxonomy term.
	 *
	 * @param WP_Term $term Term object.
	 * @return string
	 */
	private static function build_term_frontmatter( $term ) {
		$fm = "---\n";

		$fm .= 'title: "' . self::escape_yaml( $term->name ) . '"' . "\n";

		$description = trim( wp_strip_all_tags( (string) $term->description ) );
		if ( '' !== $description ) {
			if ( strlen( $description ) > 200 ) {
				$description = substr( $description, 0, 200 );
				$pos         = strrpos( $description, ' ' );
				if ( false !== $pos ) {
					$description = substr( $description, 0, $pos ) . '...';
				}
			}
			$fm .= 'description: "' . self::escape_yaml( $description ) . '"' . "\n";
		}

		$link = get_term_link( $term );
		if ( ! is_wp_error( $link ) ) {
			$fm .= 'url: ' . $link . "\n";
		}

		$fm .= 'type: term' . "\n";
		$fm .= 'taxonomy: ' . $term->taxonomy . "\n";

		$tax_object = get_taxonomy( $term->taxonomy );
		if ( $tax_object && ! empty( $tax_object->labels->singular_name ) ) {
			$fm .= 'taxonomy_label: "' . self::escape_yaml( $tax_object->labels->singular_name ) . '"' . "\n";
		}

		if ( $term->parent ) {
			$parent = get_term( $term->parent, $term->taxonomy );
			if ( $parent && ! is_wp_error( $parent ) ) {
				$fm .= 'parent: "' . self::escape_yaml( $parent->name ) . '"' . "\n";
				$fm .= 'parent_slug: ' . $parent->slug . "\n";
			}
		}

		$fm .= 'count: ' . (int) $term->count . "\n";

		$image_url = self::get_term_image_url( $term );
		if ( $image_url ) {
			$fm .= 'image: ' . $image_url . "\n";
		}

		$locale = get_locale();
		if ( $locale ) {
			$fm .= 'lang: ' . substr( $locale, 0, 2 ) . "\n";
		}

		$fm .= "---\n\n";

		return $fm;
	}

	/**
	 * Resolve a term image URL from common term meta keys.
	 *
	 * WooCommerce stores it as `thumbnail_id` (attachment ID). Other plugins
	 * use ad-hoc keys; we try a reasonable handful before giving up.
	 *
	 * @param WP_Term $term Term object.
	 * @return string Empty string when no image is found.
	 */
	private static function get_term_image_url( $term ) {
		$keys = array( 'thumbnail_id', 'image', 'category_image_id', 'term_image' );

		foreach ( $keys as $key ) {
			$value = get_term_meta( $term->term_id, $key, true );
			if ( empty( $value ) ) {
				continue;
			}

			if ( is_numeric( $value ) ) {
				$url = wp_get_attachment_image_url( (int) $value, 'full' );
				if ( $url ) {
					return $url;
				}
			} elseif ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Render the term description through the_content filter and convert to markdown.
	 *
	 * @param WP_Term $term Term object.
	 * @return string
	 */
	private static function get_term_clean_content( $term ) {
		$raw = (string) $term->description;
		if ( '' === trim( $raw ) ) {
			return '';
		}

		$content = $raw;

		if ( false !== strpos( $content, '[' ) ) {
			$content = do_shortcode( $content );
		}

		remove_filter( 'the_content', 'do_shortcode', 11 );
		$content = apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		add_filter( 'the_content', 'do_shortcode', 11 );

		if ( preg_match( '/\[[a-z][a-z0-9_-]*[\s\]]/i', $content ) ) {
			$content = self::extract_text_from_shortcodes( $raw );
		}

		$content = strip_shortcodes( $content );

		return self::html_to_markdown( $content );
	}

	/**
	 * Build a markdown list with the direct child terms of a hierarchical taxonomy.
	 *
	 * @param WP_Term $term Term object.
	 * @return string Empty string when there are no children or the taxonomy is flat.
	 */
	private static function get_term_children_section( $term ) {
		if ( ! is_taxonomy_hierarchical( $term->taxonomy ) ) {
			return '';
		}

		$children = get_terms(
			array(
				'taxonomy'   => $term->taxonomy,
				'parent'     => $term->term_id,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $children ) || empty( $children ) ) {
			return '';
		}

		$tax_object = get_taxonomy( $term->taxonomy );
		$heading    = $tax_object && ! empty( $tax_object->labels->name ) ? $tax_object->labels->name : __( 'Subcategories', 'vigia' );

		$lines = array( '## ' . $heading, '' );
		foreach ( $children as $child ) {
			$link = get_term_link( $child );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$lines[] = sprintf( '- [%s](%s) (%d)', $child->name, $link, (int) $child->count );
		}

		return implode( "\n", $lines ) . "\n\n";
	}

	/**
	 * Build a markdown list with the most recent posts assigned to the term.
	 *
	 * Limited to the first 20 entries to keep markdown payloads bounded. Posts
	 * are ordered by menu_order then date desc so manually curated product
	 * archives surface their pinned items first.
	 *
	 * @param WP_Term $term Term object.
	 * @return string Empty string when there are no eligible posts.
	 */
	private static function get_term_posts_section( $term ) {
		$limit = (int) apply_filters( 'vigia_markdown_term_posts_limit', 20, $term );
		if ( $limit < 1 ) {
			return '';
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => $limit,
				'no_found_rows'          => false,
				'orderby'                => array(
					'menu_order' => 'ASC',
					'date'       => 'DESC',
				),
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => $term->taxonomy,
						'field'    => 'term_id',
						'terms'    => $term->term_id,
					),
				),
			)
		);

		if ( empty( $query->posts ) ) {
			return '';
		}

		$tax_object = get_taxonomy( $term->taxonomy );
		$is_product = $tax_object && in_array( 'product', (array) $tax_object->object_type, true );
		$heading    = $is_product ? __( 'Products in this category', 'vigia' ) : __( 'Latest entries', 'vigia' );

		$lines = array( '## ' . $heading, '' );

		foreach ( $query->posts as $entry ) {
			$permalink = get_permalink( $entry );
			$title     = get_the_title( $entry );
			$excerpt   = self::get_clean_excerpt( $entry );

			$line = sprintf( '- [%s](%s)', $title, $permalink );
			if ( $excerpt ) {
				$line .= ' — ' . $excerpt;
			}
			$line   .= self::product_summary_inline( $entry );
			$lines[] = $line;
		}

		$total = (int) $query->found_posts;
		if ( $total > $limit ) {
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: %d: number of additional entries not listed. */
				__( '...and %d more.', 'vigia' ),
				$total - $limit
			);
		}

		wp_reset_postdata();

		return implode( "\n", $lines ) . "\n\n";
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

		// Code blocks (pre > code). Capture the attributes of both <pre> and
		// <code> so a `language-xxx` class on either one is detected. The old
		// single greedy `<code[^>]*` swallowed the class before the optional
		// group could capture it, so fences always came out without a language.
		$html = preg_replace_callback(
			'/<pre([^>]*)>\s*<code([^>]*)>(.*?)<\/code>\s*<\/pre>/is',
			function ( $matches ) {
				$lang = '';
				if ( preg_match( '/language-([a-z0-9_+#-]+)/i', $matches[1] . ' ' . $matches[2], $lang_match ) ) {
					$lang = strtolower( $lang_match[1] );
				}
				$code = html_entity_decode( wp_strip_all_tags( $matches[3] ), ENT_QUOTES, 'UTF-8' );
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

		// Bold, italic, strikethrough. Done before lists so the list walker
		// (which reads each <li> as text) sees the markers already in place;
		// otherwise <strong>/<em> inside an item would be stripped, the way
		// they used to be lost inside <ol>.
		//
		// The \b after the tag name is load-bearing: without it <b> also matches
		// the start of <button>/<br>/<body>, <i> matches <img>/<iframe>, and <s>
		// matches <span>/<svg>/<section>. A stray such tag (e.g. the <button> that
		// Gutenberg's image lightbox injects) would then pair with a later
		// </strong>/</em> and scatter ** / * markers across the output.
		$html = preg_replace( '/<(strong|b)\b[^>]*>(.*?)<\/(strong|b)>/is', '**$2**', $html );
		$html = preg_replace( '/<(em|i)\b[^>]*>(.*?)<\/(em|i)>/is', '*$2*', $html );
		$html = preg_replace( '/<(del|s|strike)\b[^>]*>(.*?)<\/(del|s|strike)>/is', '~~$2~~', $html );

		// Lists (<ul>/<ol>, including nested and mixed). Walked with DOMDocument
		// so nesting, ordered/unordered markers and indentation survive; the old
		// flat regexes dropped the first nested item's bullet and all indent.
		// Each rendered block is parked as a placeholder to shield its per-line
		// indentation from the whitespace pass further down. $protected is shared
		// with the code-span protection step below.
		$protected = array();
		$html      = self::convert_lists_to_markdown( $html, $protected );

		// Paragraphs and line breaks.
		$html = preg_replace( '/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $html );
		$html = preg_replace( '/<br[^>]*\/?>/is', "  \n", $html );

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

		// Protect already-generated code spans (fenced blocks and inline code)
		// from the cleanup and whitespace passes below. Without this, bracketed
		// text inside code (e.g. $arr[key]) is stripped as if it were a
		// shortcode, and per-line trimming flattens code-block indentation.
		// Reuses the same $protected store as the list blocks parked earlier.
		$protect = function ( $matches ) use ( &$protected ) {
			$protected[] = $matches[0];
			return 'VIGIAPLACEHOLDER' . ( count( $protected ) - 1 ) . 'END';
		};
		$html = preg_replace_callback( '/```.*?```/s', $protect, $html );
		$html = preg_replace_callback( '/`[^`\n]+`/', $protect, $html );

		// Clean up artifacts left by unregistered shortcodes. The negative
		// lookahead (?!\() preserves markdown links [text](url) and images
		// ![alt](url): their label is bracketed text this pattern would otherwise
		// delete, leaving a bare "(url)" with no anchor text.
		$html = preg_replace( '/\[[a-z][a-z0-9_-]*[^\]]*\](?!\()/is', '', $html );
		$html = preg_replace( '/\[\/[a-z][a-z0-9_-]*\]/is', '', $html );

		// Clean up whitespace.
		$html = preg_replace( '/\n{3,}/', "\n\n", $html );
		$html = preg_replace( '/[ \t]+/', ' ', $html );
		$lines = explode( "\n", $html );
		$lines = array_map( 'trim', $lines );
		$html  = implode( "\n", $lines );
		$html  = preg_replace( '/\n{3,}/', "\n\n", $html );

		// Restore the protected list blocks and code spans verbatim, now that the
		// whitespace pass is done, so nested indentation and inner brackets stay.
		if ( ! empty( $protected ) ) {
			$html = preg_replace_callback(
				'/VIGIAPLACEHOLDER(\d+)END/',
				function ( $matches ) use ( $protected ) {
					$index = (int) $matches[1];
					return isset( $protected[ $index ] ) ? $protected[ $index ] : '';
				},
				$html
			);
		}

		return trim( $html );
	}

	/**
	 * Convert HTML lists to markdown, keeping nested and mixed ul/ol structure.
	 *
	 * Top-level lists are matched with a recursive pattern so each block keeps
	 * its nested sublists, then walked with DOMDocument and rendered with two
	 * spaces of indentation per level. Inline markup (links, code, bold/italic)
	 * must already be markdown before this runs: the walk reads items as text.
	 *
	 * Each rendered block is parked in $store (returning a placeholder token) so
	 * the later per-line trim pass cannot flatten the nested indentation.
	 *
	 * @param string $html  HTML with inline markup already converted to markdown.
	 * @param array  $store Reference to the shared placeholder store.
	 * @return string
	 */
	private static function convert_lists_to_markdown( $html, &$store ) {
		if ( false === stripos( $html, '<ul' ) && false === stripos( $html, '<ol' ) ) {
			return $html;
		}

		// Match a top-level <ul>/<ol> with all its (possibly nested, possibly
		// mixed) content. (?R) keeps balanced nesting together; closing on any
		// </ul>|</ol> tolerates mixed nesting in well-formed WordPress markup.
		$pattern = '/<(?:ul|ol)\b[^>]*>(?:[^<]++|<(?!\/?(?:ul|ol)\b)[^<]*+|(?R))*+<\/(?:ul|ol)>/is';

		$result = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( &$store ) {
				$markdown = self::render_html_list( $matches[0] );
				if ( '' === trim( $markdown ) ) {
					return '';
				}
				$store[] = $markdown;
				return "\n\nVIGIAPLACEHOLDER" . ( count( $store ) - 1 ) . "END\n\n";
			},
			$html
		);

		// preg_replace_callback returns null on a PCRE failure (e.g. hitting the
		// backtrack limit on pathological input); fall back to the original HTML
		// so the rest of the converter still runs.
		return ( null === $result ) ? $html : $result;
	}

	/**
	 * Render one top-level HTML list block to markdown via DOMDocument.
	 *
	 * @param string $list_html A single <ul>/<ol>…</…> block.
	 * @return string
	 */
	private static function render_html_list( $list_html ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			// Minimal fallback when ext-dom is unavailable: flat bullets.
			$flat = preg_replace( '/<li[^>]*>/i', "\n- ", $list_html );
			return trim( wp_strip_all_tags( $flat ) );
		}

		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<?xml encoding="UTF-8"><div>' . $list_html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$divs = $dom->getElementsByTagName( 'div' );
		if ( 0 === $divs->length ) {
			return '';
		}

		foreach ( $divs->item( 0 )->childNodes as $node ) {
			if ( XML_ELEMENT_NODE === $node->nodeType ) {
				$name = strtolower( $node->nodeName );
				if ( 'ul' === $name || 'ol' === $name ) {
					return self::render_list_node( $node, '' );
				}
			}
		}

		return '';
	}

	/**
	 * Recursively render a <ul>/<ol> DOM node to an indented markdown list.
	 *
	 * @param DOMElement $list   List element.
	 * @param string     $indent Leading whitespace for this level's items.
	 * @return string
	 */
	private static function render_list_node( $list, $indent ) {
		$ordered = ( 'ol' === strtolower( $list->nodeName ) );
		$lines   = array();
		$counter = 1;

		foreach ( $list->childNodes as $item ) {
			if ( XML_ELEMENT_NODE !== $item->nodeType || 'li' !== strtolower( $item->nodeName ) ) {
				continue;
			}

			// Split each <li> into its own inline text and any nested sublists.
			$own_text = '';
			$sublists = array();

			foreach ( $item->childNodes as $child ) {
				$name = strtolower( $child->nodeName );
				if ( XML_ELEMENT_NODE === $child->nodeType && ( 'ul' === $name || 'ol' === $name ) ) {
					$sublists[] = $child;
				} else {
					$own_text .= $child->textContent;
				}
			}

			$own_text = trim( preg_replace( '/\s+/', ' ', $own_text ) );
			$marker   = $ordered ? ( $counter . '. ' ) : '- ';
			$lines[]  = rtrim( $indent . $marker . $own_text );

			// Align sublists with the start of this item's text so CommonMark
			// keeps them nested (ordered markers need more than two spaces).
			$child_indent = $indent . str_repeat( ' ', strlen( $marker ) );
			foreach ( $sublists as $sublist ) {
				$rendered = self::render_list_node( $sublist, $child_indent );
				if ( '' !== $rendered ) {
					$lines[] = $rendered;
				}
			}

			$counter++;
		}

		return implode( "\n", $lines );
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
					$text = trim( wp_strip_all_tags( $cell ) );
					// Escape pipes and flatten line breaks so a cell's content
					// can't break out of its column in the markdown table.
					$text    = str_replace( array( "\r\n", "\n", "\r", '|' ), array( ' ', ' ', ' ', '\\|' ), $text );
					$cells[] = $text;
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
		$md_url = self::resolve_current_markdown_url();
		if ( $md_url ) {
			echo '<link rel="alternate" type="text/markdown" href="' . esc_url( $md_url ) . '" />' . "\n";
		}
	}

	/**
	 * Add Link HTTP header for markdown alternate
	 */
	public static function add_link_header() {
		$md_url = self::resolve_current_markdown_url();
		if ( $md_url ) {
			header( 'Link: <' . esc_url( $md_url ) . '>; rel="alternate"; type="text/markdown"', false );
		}
	}

	/**
	 * Resolve the markdown URL for the current request, if any.
	 *
	 * Handles both singular posts and taxonomy term archives.
	 *
	 * @return string|false
	 */
	private static function resolve_current_markdown_url() {
		if ( is_singular() ) {
			$the_post = get_queried_object();
			if ( $the_post && self::is_post_eligible( $the_post ) ) {
				return self::get_markdown_url( $the_post );
			}
			return false;
		}

		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term && self::is_term_eligible( $term ) ) {
				return self::get_markdown_url_for_term( $term );
			}
		}

		return false;
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
	 * Get the markdown URL for a taxonomy term.
	 *
	 * @param WP_Term $term Term object.
	 * @return string|false
	 */
	public static function get_markdown_url_for_term( $term ) {
		$settings = self::get_settings();

		if ( ! $settings['enable_md_urls'] ) {
			return false;
		}

		$link = get_term_link( $term );
		if ( is_wp_error( $link ) ) {
			return false;
		}

		$home_url = home_url( '/' );
		$path     = trim( str_replace( $home_url, '', $link ), '/' );

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