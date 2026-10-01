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
	 * Transient prefix for the cached markdown documents.
	 *
	 * @since 2.6.1
	 */
	const CACHE_PREFIX = 'vigia_md_';

	/**
	 * Option holding the cache key salt, bumped to flush every document at once.
	 *
	 * @since 2.6.1
	 */
	const CACHE_SALT_OPTION = 'vigia_md_cache_salt';

	/**
	 * How long a cached markdown document lives.
	 *
	 * Long, because the cache is dropped by hand whenever the entry it was built
	 * from changes; the expiry is only the backstop for whatever edits the plugin
	 * cannot see.
	 *
	 * @since 2.6.1
	 */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * How long clients may reuse a markdown response, in seconds.
	 *
	 * @since 2.6.1
	 */
	const HTTP_MAX_AGE = 3600;

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
	 * What every placeholder of html_to_markdown() starts with.
	 *
	 * The converter sets its code blocks, its code spans and its lists aside
	 * under a placeholder and puts them back at the end, untouched by the passes
	 * in between. Each call draws its own value for it (2.6.7): with a fixed
	 * one, an author who wrote the placeholder in a paragraph got the content
	 * of a code block of the same page put back there, in the middle of a line,
	 * where its fence is no fence and its `<img onerror>` is markup again.
	 *
	 * @since 2.6.7
	 * @var string
	 */
	private static $marker = 'VIGIAPLACEHOLDER';

	/**
	 * Initialize hooks
	 */
	public static function init() {
		$settings = self::get_settings();

		if ( ! $settings['enabled'] ) {
			return;
		}

		// Consume a pending flush at the very end of `init`, once every other
		// callback registered below (including add_rewrite_rules()) has had its
		// say for this request. Registered unconditionally, on both sides of the
		// cession switch: add_rewrite_rules() itself only runs while NOT
		// deferring, so it cannot be the one to notice a flush that a flip in
		// maybe_mark_defer_change() below requires.
		add_action( 'init', array( __CLASS__, 'maybe_flush_pending' ), 999 );

		// Drop a cached document when what it was built from changes. Registered
		// before the deferral check below, so a site that hands the endpoint over
		// to Visibility for a while does not come back to a stale cache.
		add_action( 'save_post', array( __CLASS__, 'flush_post' ) );
		// Runs after the terms and meta of a REST save have been stored, which
		// save_post does not: the block editor writes them after wp_update_post().
		add_action( 'wp_after_insert_post', array( __CLASS__, 'flush_post' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_post' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'flush_post' ) );
		add_action( 'edited_term', array( __CLASS__, 'flush_term' ) );
		add_action( 'delete_term', array( __CLASS__, 'flush_term' ) );

		// Visibility takes priority: VigIA yields the markdown signal for as
		// long as Visibility actively emits it, and reclaims it the moment
		// Visibility stops. Either flip leaves the `^(.+)\.md$` rewrite rule out
		// of sync with reality (registered-but-unused, or missing-but-needed)
		// until something flushes it, so every request compares today's cession
		// state against the one stored last time and marks a flush when they
		// differ; maybe_flush_pending() above does the actual flushing.
		$now_defers = VigIA_Sibling_Visibility::should_defer( 'markdown' );
		self::maybe_mark_defer_change( $now_defers );

		// Cede Markdown for agents to the Visibility sibling when it serves it.
		// Visibility intercepts on do_parse_request (ahead of our
		// template_redirect), so it already wins the /{slug}.md collision; bailing
		// here also stops us advertising a duplicate .md <link> / Link header and
		// from registering rewrite rules we would never use. See
		// VigIA_Sibling_Visibility for the emit/observe split.
		if ( $now_defers ) {
			return;
		}

		// Register rewrite rules for .md URLs.
		if ( $settings['enable_md_urls'] ) {
			add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ), 20 );
			add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		}

		// Content negotiation and .md URL handling.
		add_action( 'template_redirect', array( __CLASS__, 'handle_request' ), 5 );

		// Announce Accept as a cache key on the HTML side of a negotiable URL,
		// before handle_request() decides which variant to serve.
		if ( $settings['enable_negotiation'] ) {
			add_action( 'template_redirect', array( __CLASS__, 'add_vary_header' ), 1 );
		}

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

		// Turning the module off stops the invalidation hooks from running, so
		// anything edited while it was off would come back from the cache when it
		// is turned on again. Dropping the cache on every settings save covers
		// that and any other change of what the documents are built from.
		if ( $old_settings !== $normalized ) {
			self::flush_all();
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
	 *
	 * Registration only; flushing is maybe_flush_pending()'s job, and only
	 * that order lets a flush see this call's own add_rewrite_rule() for the
	 * current request (see maybe_flush_pending()).
	 */
	public static function add_rewrite_rules() {
		// Match any path ending in .md.
		add_rewrite_rule(
			'^(.+)\.md$',
			'index.php?vigia_markdown=1&vigia_markdown_path=$matches[1]',
			'top'
		);
	}

	/**
	 * Track the cession state for the `markdown` signal across requests, and
	 * mark a flush pending when it changes.
	 *
	 * add_rewrite_rules() only runs on `init` while NOT deferring to
	 * Visibility, so nothing ever regenerates the rewrite rules when deferring
	 * STARTS: `^(.+)\.md$` stays behind in the saved `rewrite_rules` option,
	 * matched on every request that reaches it even though this class no
	 * longer hooks its query var or its template_redirect handler either.
	 * Measured on ayudawp.com (2.7.0 of the Visibility sibling): a stale match
	 * fed WordPress's canonical redirect an object it does not recognize,
	 * which appended a trailing slash the sibling's own routing then stripped
	 * again, an infinite 301 loop on any nonexistent `.md` URL including
	 * `/index.md`. A stored marker, compared on every request, is the only way
	 * to notice the flip from a hook that itself only fires on one side of it.
	 *
	 * @since 2.6.6
	 * @param bool $now_defers Current cession state for the markdown signal.
	 */
	private static function maybe_mark_defer_change( $now_defers ) {
		$stored = get_option( 'vigia_markdown_defer_state', '' );

		// No marker yet: a fresh install, or an upgrade from a version that
		// did not track this. Record the current state without flushing;
		// there is nothing stale left behind to clean up yet.
		if ( '' === $stored ) {
			update_option( 'vigia_markdown_defer_state', $now_defers ? '1' : '0' );
			return;
		}

		if ( ( '1' === $stored ) !== $now_defers ) {
			update_option( 'vigia_flush_rewrite', true );
			update_option( 'vigia_markdown_defer_state', $now_defers ? '1' : '0' );
		}
	}

	/**
	 * Flush rewrite rules once if a settings save (see save_settings()) or a
	 * cession-state flip (see maybe_mark_defer_change()) left one pending.
	 *
	 * Hooked at a priority late enough (999) to run after every other `init`
	 * callback this class registers, including add_rewrite_rules() at 20:
	 * flushing earlier would persist a rule set built before this request's
	 * own add_rewrite_rule() call, so a rule that just started being needed
	 * again would still be missing from what gets saved.
	 *
	 * @since 2.6.6
	 */
	public static function maybe_flush_pending() {
		if ( get_option( 'vigia_flush_rewrite' ) ) {
			delete_option( 'vigia_flush_rewrite' );
			flush_rewrite_rules();
		}
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
			// What decides is the address that was asked, not the query variable. The
			// two variables of the rewrite rule are public, and WordPress takes a
			// public variable from a POST body or from the query string before it
			// takes it from the rule (`wp-includes/class-wp.php:319-336` in 7.1.2).
			// So `/any-page?vigia_markdown=1&vigia_markdown_path=other` used to serve
			// the document of `other`, and `/a.md?vigia_markdown_path=b` the one of
			// `b`: a second address for every document, and one that builds with
			// whatever else the query string carries.
			//
			// esc_url_raw(), not sanitize_text_field(): the latter drops every
			// %XX octet (_sanitize_text_fields(), wp-includes/formatting.php), so
			// a non-Latin path segment (stored percent-encoded, like any other
			// WordPress slug) came out empty and the redirect landed on the
			// wrong URL instead of the requested one.
			$requested                       = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			list( $only_path, $query_string ) = self::split_request_uri( $requested );

			// WordPress strips the trailing slash before matching the rewrite rule,
			// so `/entry.md/` reaches this rule exactly like `/entry.md` and used to
			// serve the same document at a second URL, with no canonical of its own
			// pointing back. A .md address is a file name, never a directory, so the
			// slashed form is redirected to the real one instead of answered. Without
			// its query, for the reason below.
			//
			// The same goes for the suffix written with percent-encoded characters:
			// WordPress matches its rules against the decoded path as well, so
			// `/entry%2Emd` reaches this rule too. It is the same address spelled
			// another way, and it is sent to the plain one.
			$without_slash = rtrim( $only_path, '/' );
			if ( '.md' !== substr( $without_slash, -3 )
				&& preg_match( '/(?:\.|%2e)(?:m|%6d)(?:d|%64)$/i', $without_slash, $suffix )
				&& '.md' === rawurldecode( $suffix[0] ) ) {
				$without_slash = substr( $without_slash, 0, -strlen( $suffix[0] ) ) . '.md';
			}
			if ( $without_slash !== $only_path && '.md' === substr( $without_slash, -3 ) ) {
				wp_safe_redirect( $without_slash, 301 );
				exit;
			}

			// The variables arrived on an address that is not a `.md`: nothing here
			// answers it.
			if ( '.md' !== substr( $only_path, -3 ) ) {
				self::send_404();
				return;
			}

			// A document is built for a read of its own address and for nothing else,
			// the rule the page address follows too (is_request_for(), in the access
			// class). A write sent to a `.md` is refused and not answered with the
			// document. And a query is redirected away, because a block can read it
			// even though the document does not: the query loop takes its page from
			// `$_GET` (`wp-includes/blocks/post-template.php:50-52`), which the neutral
			// context leaves alone, so `/my-courses.md?query-0-page=2` built the second
			// page of the loop and stored it as the document of everybody.
			if ( ! VigIA_Content_Access::is_read_request() ) {
				self::send_405();
				return;
			}

			if ( '' !== $query_string ) {
				wp_safe_redirect( $only_path, 301 );
				exit;
			}

			// The rewrite match arrives still percent-encoded (confirmed against
			// Testing: get_query_var() does not run it through urldecode()), same
			// as it would sit in a WordPress post_name for a non-Latin slug, so
			// serve_markdown_by_path() → find_post_by_path() / find_term_by_path()
			// can compare it directly. sanitize_text_field() used to sit here and
			// strip every %XX octet the same way as above, turning a non-Latin
			// entry, page or term 404, and a non-Latin child page into its parent
			// (whatever survived up to the first surviving slash). The one thing
			// still needed is lowercasing: a browser or agent writes the percent
			// encoding of what someone typed in uppercase hex, WordPress always
			// stores the slug in lowercase, and get_terms()'s exact 'slug' match
			// (unlike get_page_by_path()'s own decode/re-encode round trip) does
			// not normalize case on its own.
			// Whatever the path is, a `.md` address is answered here: `/0.md` used to
			// slip past a truthiness test and was handed on to WordPress, which
			// answered it with a redirect instead of this endpoint's 404.
			self::serve_markdown_by_path( strtolower( (string) get_query_var( 'vigia_markdown_path' ) ) );
			return;
		}

		// Check for Accept: text/markdown content negotiation.
		//
		// Only a read of the address of the page is answered with its document, as
		// only a read of its `.md` is (see above). The page with a parameter, the
		// second page of an archive, `/feed/` or an endpoint of My Account reach
		// this point with the same queried object, and what they show would be
		// stored as the document of everybody. They get the web page, which still
		// announces the alternate in its Link header.
		if ( $settings['enable_negotiation'] && self::accepts_markdown() && VigIA_Content_Access::is_read_request() ) {
			if ( is_singular() ) {
				$the_post = get_queried_object();
				if ( $the_post instanceof WP_Post
					&& self::is_post_eligible( $the_post )
					&& VigIA_Content_Access::is_request_for( get_permalink( $the_post ) ) ) {
					self::serve_markdown_response( $the_post );
				}
			} elseif ( is_tax() || is_category() || is_tag() ) {
				$term = get_queried_object();
				if ( $term instanceof WP_Term
					&& self::is_term_eligible( $term )
					&& VigIA_Content_Access::is_request_for( get_term_link( $term ) ) ) {
					self::serve_markdown_response_for_term( $term );
				}
			}
		}
	}

	/**
	 * A request URI split into its path and its query, cut by hand.
	 *
	 * Not with wp_parse_url(): parse_url() turns some bytes of raw UTF-8 into
	 * underscores, and a path that reaches the server undecoded is exactly that.
	 * The path comes back with a single leading slash whatever it brought, because
	 * it is what the redirects of handle_request() send the client to, and a
	 * browser reads `//host/x` and `/\host/x` as another domain.
	 *
	 * @since 2.6.7
	 * @param string $request_uri Request URI, already read with esc_url_raw().
	 * @return array{0:string,1:string} Path and query, the latter without its `?`.
	 */
	private static function split_request_uri( $request_uri ) {
		$request_uri = (string) $request_uri;

		// A request carries no fragment, and its query starts at the first `?`.
		$request_uri = substr( $request_uri, 0, strcspn( $request_uri, '#' ) );
		$cut         = strcspn( $request_uri, '?' );

		// The casts are for PHP 7, where substr() gives false past the end.
		$path  = '/' . ltrim( (string) substr( $request_uri, 0, $cut ), '/\\' );
		$query = (string) substr( $request_uri, $cut + 1 );

		return array( $path, $query );
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

		foreach ( self::path_candidates( $path ) as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}

			$the_post = self::find_post_by_path( $candidate );

			// find_post_by_path() falls back to the last segment of the path, so the
			// address of a term archive resolved to whatever entry shared its slug:
			// `/product-category/clothing/sweater.md`, which the archive of that
			// category advertises as its own, served the product called `sweater`.
			// A term whose address is exactly this path owns it, unless the entry
			// found lives at this very path too.
			if ( ! $the_post || ! self::is_own_path( $the_post, $candidate ) ) {
				$term = self::find_term_by_path( $candidate, true );

				if ( $term && self::is_term_eligible( $term ) ) {
					self::serve_markdown_response_for_term( $term );
					return;
				}
			}

			if ( $the_post && self::is_post_eligible( $the_post ) ) {
				self::serve_markdown_response( $the_post );
				return;
			}

			// Fall back to taxonomy term lookup when no post matches the path.
			$term = self::find_term_by_path( $candidate );

			if ( $term && self::is_term_eligible( $term ) ) {
				self::serve_markdown_response_for_term( $term );
				return;
			}
		}

		self::send_404();
	}

	/**
	 * Request paths to try, in order: the one asked for, then the same one with
	 * the spec's `index` segment removed.
	 *
	 * The spec writes a page's Markdown URL as the page URL with `.md` appended or
	 * its extension replaced, and adds that URLs with no file name should use
	 * `index.md` or `index.html.md` instead. WordPress permalinks have no file
	 * name, so `/hello-world/index.md` is what an agent following the spec to the
	 * letter builds, while `/hello-world.md` is the form we publish and advertise.
	 * Both answer.
	 *
	 * The literal path is always tried first, so a real entry whose own slug is
	 * `index` still wins over the rewritten form.
	 *
	 * @param string $path Request path without the .md suffix.
	 * @return array<int,string>
	 */
	private static function path_candidates( $path ) {
		$candidates = array( $path );

		foreach ( array( 'index.html', 'index' ) as $name ) {
			if ( $path === $name ) {
				break;
			}
			if ( substr( $path, - ( strlen( $name ) + 1 ) ) === '/' . $name ) {
				$candidates[] = substr( $path, 0, - ( strlen( $name ) + 1 ) );
				break;
			}
		}

		return $candidates;
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

		// Drop the types whose gating lives in their plugin's templates, so a
		// selection saved before this check existed cannot resolve one of them.
		$post_types = array_values(
			array_filter(
				$post_types,
				array( 'VigIA_Content_Access', 'is_servable_type' )
			)
		);

		if ( empty( $post_types ) ) {
			return null;
		}

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
	 * Is this request path the one the entry's own .md address has?
	 *
	 * Only used to settle who answers a path that is also the exact address of a
	 * term archive (see serve_markdown_by_path()), never to turn an entry away: a
	 * permalink structure this comparison does not understand must keep working as
	 * it did.
	 *
	 * @since 2.6.7
	 * @param WP_Post $the_post Entry found for the path.
	 * @param string  $path     Request path without the .md suffix, slashes trimmed.
	 * @return bool
	 */
	private static function is_own_path( $the_post, $path ) {
		$url = self::get_markdown_url( $the_post );
		if ( ! $url ) {
			return false;
		}

		return self::same_path( self::request_path_from_url( $url ), trim( (string) $path, '/' ) );
	}

	/**
	 * Is this post the page assigned as the static front page?
	 *
	 * @param WP_Post $the_post Post object.
	 * @return bool
	 */
	private static function is_front_page_post( $the_post ) {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return false;
		}

		$front_id = (int) get_option( 'page_on_front' );

		return $front_id > 0 && $front_id === (int) $the_post->ID;
	}

	/**
	 * Is this post the page assigned as the posts page (blog index)?
	 *
	 * @param WP_Post $the_post Post object.
	 * @return bool
	 */
	private static function is_posts_page( $the_post ) {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return false;
		}

		$posts_page_id = (int) get_option( 'page_for_posts' );

		return $posts_page_id > 0 && $posts_page_id === (int) $the_post->ID;
	}

	/**
	 * Is this post the page WooCommerce has assigned as its shop?
	 *
	 * @since 2.6.7
	 * @param WP_Post $the_post Post object.
	 * @return bool
	 */
	private static function is_shop_page( $the_post ) {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return false;
		}

		// `wc_get_page_id()` gives -1 when no page is assigned.
		$shop_id = (int) wc_get_page_id( 'shop' );

		return $shop_id > 0 && $shop_id === (int) $the_post->ID;
	}

	/**
	 * Is this plugin actually answering .md URLs on this site right now?
	 *
	 * Needed because the class is loaded unconditionally, so class_exists() says
	 * nothing about the feature being on, and neither is_post_eligible() nor
	 * get_markdown_url() looks at the module switch: the first weighs access and
	 * content types, the second only `enable_md_urls`, which defaults to true.
	 * Anything building a link to a .md must ask this first, or it publishes URLs
	 * that answer 404 with the module switched off.
	 *
	 * @return bool
	 */
	public static function serves_markdown() {
		$settings = self::get_settings();

		if ( empty( $settings['enabled'] ) || empty( $settings['enable_md_urls'] ) ) {
			return false;
		}

		// The Visibility sibling owns the endpoint when it serves Markdown, and we
		// return nothing: its URLs have the same shape but its own lists decide.
		return ! ( class_exists( 'VigIA_Sibling_Visibility' ) && VigIA_Sibling_Visibility::should_defer( 'markdown' ) );
	}

	/**
	 * The .md URL for an entry, but only when this module will actually answer
	 * it: the module switches, eligibility, and a round trip through the same
	 * resolver that serves the request. Building the URL and resolving it are
	 * different operations, so a URL that builds fine can still answer 404 (a
	 * custom type whose permalink carries a prefix its resolver does not strip,
	 * for instance). Publishing a link, in llms.txt or to the sibling plugin,
	 * must go through here, never through get_markdown_url() alone. Returns ''
	 * when the entry has no working .md.
	 *
	 * @param int|WP_Post $the_post Post.
	 * @return string
	 */
	public static function linkable_url( $the_post ) {
		if ( ! self::serves_markdown() ) {
			return '';
		}

		$the_post = get_post( $the_post );
		if ( ! $the_post instanceof WP_Post || ! self::is_post_eligible( $the_post ) ) {
			return '';
		}

		$url = self::get_markdown_url( $the_post );
		if ( ! $url ) {
			return '';
		}

		$found = self::find_post_by_path( self::request_path_from_url( $url ) );
		return ( $found instanceof WP_Post && (int) $found->ID === (int) $the_post->ID ) ? (string) $url : '';
	}

	/**
	 * The .md URL for a term on the same contract as linkable_url().
	 *
	 * @param WP_Term $term Term.
	 * @return string
	 */
	public static function linkable_url_for_term( $term ) {
		if ( ! self::serves_markdown() ) {
			return '';
		}

		if ( ! $term instanceof WP_Term || ! self::is_term_eligible( $term ) ) {
			return '';
		}

		$url = self::get_markdown_url_for_term( $term );
		if ( ! $url ) {
			return '';
		}

		$found = self::find_term_by_path( self::request_path_from_url( $url ) );
		return ( $found instanceof WP_Term && (int) $found->term_id === (int) $term->term_id ) ? (string) $url : '';
	}

	/**
	 * Reduce a full .md URL to the request path the resolvers expect: the URL
	 * path without the .md suffix, slashes trimmed, subdirectory prefix removed.
	 * The rewrite rule hands serve_markdown_by_path() exactly this shape.
	 *
	 * @param string $url Absolute .md URL.
	 * @return string
	 */
	private static function request_path_from_url( $url ) {
		$clean = trim( (string) substr( self::path_of( $url ), 0, -3 ), '/' );

		$home_path = trim( self::path_of( home_url() ), '/' );
		if ( '' !== $home_path && 0 === strpos( $clean, $home_path ) ) {
			$clean = ltrim( substr( $clean, strlen( $home_path ) ), '/' );
		}

		return $clean;
	}

	/**
	 * The path of a URL, cut by hand.
	 *
	 * Not with wp_parse_url(): parse_url() turns some bytes of raw UTF-8 into
	 * underscores, and a link under a base that is not ASCII carries them raw
	 * (`/categoría/news/` came back as `/categor_a/news/`). With that, the .md
	 * address of every term under such a base failed the round trip of
	 * linkable_url_for_term() and was never offered.
	 *
	 * @since 2.6.7
	 * @param string $url Absolute URL.
	 * @return string
	 */
	private static function path_of( $url ) {
		$uri = VigIA_Content_Access::request_uri_of( $url );

		return (string) substr( $uri, 0, strcspn( $uri, '?' ) );
	}

	/**
	 * Are these two request paths the same address?
	 *
	 * Compared decoded and without regard to case: a request brings the octets
	 * of a path that is not ASCII percent-encoded (and handle_request() lowers
	 * them), while a link WordPress builds carries a base that is not ASCII raw.
	 * Compared as they came, `categor%c3%ada/news` and `categoría/news` were two
	 * addresses, and the .md of a term under such a base answered 404 while its
	 * page advertised it.
	 *
	 * @since 2.6.7
	 * @param string $one   Path, slashes trimmed.
	 * @param string $other Path, slashes trimmed.
	 * @return bool
	 */
	private static function same_path( $one, $other ) {
		return 0 === strcasecmp( rawurldecode( (string) $one ), rawurldecode( (string) $other ) );
	}

	/**
	 * Check if a post is eligible for markdown serving
	 *
	 * @param WP_Post $the_post Post object.
	 * @return bool
	 */
	public static function is_post_eligible( $the_post ) {
		// Status, password, and whatever the LMS and membership plugins on this
		// site have to say about this entry. A `.md` is a second representation of
		// the page, so it answers to the same access rules the page does; rebuilt
		// outside the template, none of them apply unless we ask on purpose.
		if ( ! VigIA_Content_Access::is_public( $the_post ) ) {
			return false;
		}

		// The cart, the checkout and My Account are not content: they show whoever
		// visits them their own session, and a document is built once and kept for
		// everybody. Not served, not advertised and not linked from llms.txt, which
		// all ask here.
		if ( VigIA_Content_Access::is_visitor_page( $the_post ) ) {
			return false;
		}

		$settings   = self::get_settings();
		$post_types = ! empty( $settings['post_types'] ) ? $settings['post_types'] : array( 'post', 'page' );

		if ( ! in_array( $the_post->post_type, $post_types, true ) ) {
			return false;
		}

		// A type whose gating lives in its plugin's templates is withheld even if
		// it is still ticked in the settings from before this check existed.
		if ( VigIA_Content_Access::is_gated_type( $the_post->post_type ) ) {
			return false;
		}

		// The page assigned as "Posts page" in Settings > Reading never renders its
		// own content: WordPress shows the blog loop there instead. Serving its
		// post_content as markdown would hand agents something the site itself
		// never displays, and that content is empty on most installs.
		if ( self::is_posts_page( $the_post ) ) {
			return false;
		}

		// Nor does the page WooCommerce shows its product archive on: that
		// address is a listing, and of what the page itself holds the shop prints
		// at most a description above it (`woocommerce_product_archive_description()`,
		// `includes/wc-template-functions.php:1380` in 11.1.2), empty on most
		// stores. Its `.md` used to serve that text as if it were the page, while
		// the page asked with `Accept: text/markdown` gave the web page, because
		// an archive is not a single view.
		if ( self::is_shop_page( $the_post ) ) {
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
	 * @since 2.6.7 The `$strict` parameter.
	 *
	 * @param string $path   Request path without the .md suffix and trimmed slashes.
	 * @param bool   $strict Only a term whose archive address is exactly this path.
	 * @return WP_Term|null
	 */
	private static function find_term_by_path( $path, $strict = false ) {
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

				if ( self::same_path( $link_path, $path ) ) {
					return $term;
				}
			}

			// Single slug match with no path collision is good enough.
			if ( ! $strict && 1 === count( $terms ) && count( $segments ) === 1 ) {
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
	public static function is_term_eligible( $term ) {
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
		self::deny_blocked_crawler();

		$document = self::cached_post_markdown( $the_post );

		self::send_markdown_response(
			$document['content'],
			get_permalink( $the_post ),
			$document['shared']
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

		self::deny_blocked_crawler();

		$document = self::cached_term_markdown( $term );

		self::send_markdown_response(
			$document['content'],
			$link,
			$document['shared']
		);
	}

	/**
	 * Turn away a crawler blocked by its user agent, before anything is built.
	 *
	 * VigIA_Blocker already answers these on `plugins_loaded`, well before this
	 * endpoint runs, so in practice nothing gets here (measured: a blocked user
	 * agent got its 403 and no document was built). This is the same check as a
	 * second line, for a site where that early hook was taken off, and it now
	 * comes ahead of the build instead of after it.
	 *
	 * @since 2.6.7
	 */
	private static function deny_blocked_crawler() {
		$blocks = class_exists( 'VigIA_Blocker' ) ? VigIA_Blocker::get_all_blocks() : array();
		if ( empty( $blocks ) ) {
			return;
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $user_agent ) {
			return;
		}

		foreach ( $blocks as $block ) {
			if ( 'useragent' === $block['type'] && false !== stripos( $user_agent, $block['pattern'] ) ) {
				status_header( 403 );
				nocache_headers();
				header( 'Content-Type: text/plain; charset=utf-8' );
				header( 'X-Content-Type-Options: nosniff' );
				echo 'Access denied';
				exit;
			}
		}
	}

	/**
	 * Build a markdown document as a logged-out visitor, outside the page the
	 * request is for.
	 *
	 * The document is the same for everybody who asks for the URL, so it is built
	 * as the visitor everybody has in common. Two things depend on it: the access
	 * gate answers "can anybody read this?" rather than "can the one asking?",
	 * and `the_content` runs anonymous, so the membership plugins that gate by
	 * filtering it withhold the body on their own without VigIA knowing them.
	 *
	 * Without this an administrator, who Sensei grants every lesson through
	 * `sensei_all_access()`, would be handed the full body of a paid lesson at its
	 * `.md` URL while the HTML page still showed them the not-enrolled notice.
	 *
	 * And outside the main query (2.6.7). The same transient serves the `.md`
	 * address and the page address asked with `Accept: text/markdown`, and the two
	 * reach this point with a different main query: whichever was asked first
	 * decided which of two documents both served. See
	 * VigIA_Content_Access::begin_neutral_query().
	 *
	 * The address is an argument, so the caller has worked it out before either
	 * context opens: a filter on the permalink that threw from inside would
	 * otherwise leave the request running as user 0.
	 *
	 * @since 2.6.7 The `$address` parameter.
	 *
	 * @param callable $builder Generator to run.
	 * @param mixed    $subject Post or term to pass to it.
	 * @param mixed    $address URL of the page the document belongs to.
	 * @return string Markdown document.
	 */
	private static function build_as_anonymous( $builder, $subject, $address ) {
		VigIA_Content_Access::begin_anonymous_context();
		VigIA_Content_Access::begin_neutral_query( $address );

		try {
			return (string) call_user_func( $builder, $subject );
		} finally {
			VigIA_Content_Access::end_neutral_query();
			VigIA_Content_Access::end_anonymous_context();
		}
	}

	// =========================================================================
	// Document cache
	// =========================================================================

	/**
	 * Cached markdown document for a post.
	 *
	 * Building one converts the whole entry to markdown on every request, which
	 * is the most expensive thing this class does and gives the same answer every
	 * time: the document is built as a logged-out visitor and outside the page the
	 * request is for, so one cache entry serves everybody. What is stored is always
	 * the copy of everybody; see store() for who may write it.
	 *
	 * @since 2.6.1
	 * @since 2.6.7 Returns the document and whether it is the shared copy.
	 * @param WP_Post $the_post Post object.
	 * @return array{content:string,shared:bool}
	 */
	private static function cached_post_markdown( $the_post ) {
		$key    = self::cache_key( (int) $the_post->ID );
		$cached = get_transient( $key );

		if ( false !== $cached ) {
			return array(
				'content' => (string) $cached,
				'shared'  => true,
			);
		}

		$markdown = self::build_as_anonymous( array( __CLASS__, 'generate_post_markdown' ), $the_post, get_permalink( $the_post ) );

		return self::store( $key, $markdown );
	}

	/**
	 * Cached markdown document for a taxonomy term.
	 *
	 * @since 2.6.1
	 * @since 2.6.7 Returns the document and whether it is the shared copy.
	 * @param WP_Term $term Term object.
	 * @return array{content:string,shared:bool}
	 */
	private static function cached_term_markdown( $term ) {
		$key    = self::cache_key( (int) $term->term_id, 'term' );
		$cached = get_transient( $key );

		if ( false !== $cached ) {
			return array(
				'content' => (string) $cached,
				'shared'  => true,
			);
		}

		$markdown = self::build_as_anonymous( array( __CLASS__, 'generate_term_markdown' ), $term, get_term_link( $term ) );

		return self::store( $key, $markdown );
	}

	/**
	 * Cache a freshly built document, when the request may write the copy
	 * everybody is served, and return it.
	 *
	 * The only place a document is written, for both builders, and only a request
	 * with no cookies and nobody logged in writes it
	 * (VigIA_Content_Access::is_shareable_request()). What a block paints from a
	 * visitor's cookie belongs to that visitor: the basket and the notices a store
	 * ties to its session cookie, the currency or the language another plugin
	 * remembers. A mini-cart block set inside the content of an entry would paint
	 * the basket of whoever asked first into the document, and the transient hands
	 * it to everybody for twelve hours. The anonymous context does not reach it (it
	 * takes the user away and leaves the cookies). So a request with cookies still
	 * gets the document it asked for, marked as not shared, and the copy is left
	 * for one without them.
	 *
	 * @since 2.6.7
	 * @param string $key      Transient key.
	 * @param string $markdown Document.
	 * @return array{content:string,shared:bool}
	 */
	private static function store( $key, $markdown ) {
		if ( ! VigIA_Content_Access::is_shareable_request() ) {
			return array(
				'content' => $markdown,
				'shared'  => false,
			);
		}

		set_transient( $key, $markdown, self::CACHE_TTL );

		return array(
			'content' => $markdown,
			'shared'  => true,
		);
	}

	/**
	 * Transient key for a cached document.
	 *
	 * The salt is what makes a full flush possible: transients cannot be deleted
	 * by prefix on a site with a persistent object cache, so bumping the salt
	 * changes every future key and the old entries are simply never read again
	 * and expire on their own. Same technique as VigIA_Database.
	 *
	 * @since 2.6.1
	 * @param int    $id   Post or term id.
	 * @param string $kind Object kind, 'post' (default) or 'term'.
	 * @return string
	 */
	private static function cache_key( $id, $kind = 'post' ) {
		$salt = (int) get_option( self::CACHE_SALT_OPTION, 0 );

		// The version is part of the key (2.6.7), so an update leaves the documents
		// the previous one built unread. The salt is bumped on an update too, but
		// from `admin_init`: after an automatic update nobody visits wp-admin, and
		// an update whose whole point was what those documents carry kept serving
		// the old ones for up to twelve hours.
		$version = defined( 'VIGIA_VERSION' ) ? VIGIA_VERSION : '0';

		return self::CACHE_PREFIX . $salt . '_' . $version . '_' . ( 'term' === $kind ? 't' : 'p' ) . $id;
	}

	/**
	 * Drop the cached document of a post, and of the terms that list it.
	 *
	 * @since 2.6.1
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function flush_post( $post_id ) {
		$post_id = (int) $post_id;

		// Autosaves and revisions carry their own id; what is served is the entry
		// they belong to.
		$parent = wp_is_post_revision( $post_id );
		if ( $parent ) {
			$post_id = (int) $parent;
		}

		delete_transient( self::cache_key( $post_id ) );

		// A term document lists the latest entries in the term, so saving an entry
		// also dates the documents of the terms it belongs to.
		$settings   = self::get_settings();
		$taxonomies = $settings['taxonomies'];
		if ( empty( $taxonomies ) ) {
			return;
		}

		$terms = wp_get_object_terms( $post_id, $taxonomies, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			return;
		}

		foreach ( $terms as $term_id ) {
			delete_transient( self::cache_key( (int) $term_id, 'term' ) );
		}
	}

	/**
	 * Drop the cached document of a term.
	 *
	 * @since 2.6.1
	 * @param int $term_id Term id.
	 * @return void
	 */
	public static function flush_term( $term_id ) {
		delete_transient( self::cache_key( (int) $term_id, 'term' ) );
	}

	/**
	 * Drop every cached document at once, by bumping the key salt.
	 *
	 * @since 2.6.1
	 * @return void
	 */
	public static function flush_all() {
		update_option( self::CACHE_SALT_OPTION, (int) get_option( self::CACHE_SALT_OPTION, 0 ) + 1, false );
	}

	/**
	 * Shared response writer used by both post and term markdown responses.
	 *
	 * Handles user-agent based blocking, analytics tracking, headers and the
	 * actual body output. Exits on completion.
	 *
	 * @since 2.6.7 The `$shared` parameter, required on purpose: a call that forgot
	 *              it would otherwise let a document built for one visitor be reused.
	 *
	 * @param string $markdown      Markdown body to serve.
	 * @param string $canonical_url Canonical URL for the Link header.
	 * @param bool   $shared        Whether the document is the copy of everybody. One
	 *                              built for a request with cookies is not (see store()).
	 */
	private static function send_markdown_response( $markdown, $canonical_url, $shared ) {
		// A blocked crawler was turned away before the document was built: see
		// deny_blocked_crawler(). The list is read again only to decide how widely
		// the response may be reused.
		$blocks = class_exists( 'VigIA_Blocker' ) ? VigIA_Blocker::get_all_blocks() : array();

		self::maybe_track_request();

		$token_count = (int) ceil( mb_strlen( $markdown, 'UTF-8' ) / 4 );

		status_header( 200 );

		if ( $shared ) {
			// The copy of everybody is the same document for whoever asks and can be
			// reused. What decides how widely: a blocked crawler is turned away here,
			// at the origin, and a shared cache in front of the site never asks, so
			// with blocks configured the response is kept out of those and only the
			// client it was served to may reuse it.
			$reuse = empty( $blocks ) ? '' : 'private, ';
			header( 'Cache-Control: ' . $reuse . 'max-age=' . (int) self::HTTP_MAX_AGE );
			// WordPress sends the nocache set on requests from a logged-in user, and
			// its Expires date is in the past. Cache-Control takes precedence over it,
			// but leaving both in a response that may now be reused is contradictory.
			header_remove( 'Expires' );
		} else {
			// A document built for a request that carried cookies, or a logged-in
			// user, is for that request alone: neither a proxy nor a CDN nor the
			// client may keep it for the next visitor.
			header( 'Cache-Control: private, no-store' );
		}

		header( 'Content-Type: text/markdown; charset=utf-8' );
		// The body is plain Markdown, never HTML, and the generator strips script
		// and event-handler markup out of post content on the way in. nosniff keeps
		// a browser from second-guessing that and rendering the response as HTML in
		// the site's own origin.
		header( 'X-Content-Type-Options: nosniff' );
		self::merge_vary_header( 'Accept' );
		header( 'X-Markdown-Tokens: ' . $token_count );
		header( 'Link: <' . self::header_url( $canonical_url ) . '>; rel="canonical"' );

		// A Markdown document has no head to carry link relations, which is the
		// case the spec singles out for the header form. Only when we are the one
		// serving an llms.txt: when the Visibility sibling serves it, its own
		// Markdown endpoint answers this URL and adds the header itself.
		if ( class_exists( 'VigIA_LLMS_Generator' ) && VigIA_LLMS_Generator::serves_llms() ) {
			header( 'Link: <' . esc_url( home_url( '/llms.txt' ) ) . '>; rel="describedby"', false );
		}

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

		// esc_url_raw(), not sanitize_text_field(): a non-Latin path is stored
		// percent-encoded, all %XX octets, so sanitize_text_field() (which
		// strips every one of them) recorded it with the whole segment gone
		// instead of the page actually visited.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

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
		$output .= '# ' . self::decode_entities( get_the_title( $the_post ) ) . "\n\n";
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
		// The two addresses of the document must build from the same object, and
		// they did not hand over the same one. The `.md` address finds the term
		// with get_terms(); the page address passes the queried object, read with
		// get_term(). Plugins rewrite what get_terms() returns, and only that:
		// WooCommerce replaces `count` with the number of products the catalog
		// shows (`wc_change_term_counts()`, `includes/wc-term-functions.php:592-634`
		// in 11.1.2), so a product category said `count: 9` at one address and
		// `count: 0` at the other. Read again here, the same way for both, which is
		// also the way the list of child terms below reads theirs.
		$listed = get_terms(
			array(
				'taxonomy'   => $term->taxonomy,
				'include'    => array( (int) $term->term_id ),
				'hide_empty' => false,
			)
		);
		// Only the same term read again: get_terms() is filterable, and if what
		// comes back is another term, or none, the one handed over stays.
		$listed = ( is_wp_error( $listed ) || empty( $listed ) ) ? null : reset( $listed );
		if ( $listed instanceof WP_Term && (int) $listed->term_id === (int) $term->term_id ) {
			$term = $listed;
		}

		$output  = self::build_term_frontmatter( $term );
		$output .= '# ' . self::decode_entities( $term->name ) . "\n\n";

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

		$fm .= 'title: "' . self::escape_yaml( self::decode_entities( get_the_title( $the_post ) ) ) . '"' . "\n";

		$excerpt = self::get_clean_excerpt( $the_post );
		if ( $excerpt ) {
			$fm .= 'description: "' . self::escape_yaml( $excerpt ) . '"' . "\n";
		}

		$fm .= 'url: ' . get_permalink( $the_post ) . "\n";
		$fm .= 'date: ' . get_the_date( 'Y-m-d', $the_post ) . "\n";
		$fm .= 'modified: ' . get_the_modified_date( 'Y-m-d', $the_post ) . "\n";

		$author_name = get_the_author_meta( 'display_name', $the_post->post_author );
		if ( $author_name ) {
			$fm .= 'author: "' . self::escape_yaml( self::decode_entities( $author_name ) ) . '"' . "\n";
		}

		$thumbnail_url = get_the_post_thumbnail_url( $the_post, 'full' );
		if ( $thumbnail_url ) {
			$fm .= 'image: ' . $thumbnail_url . "\n";
		}

		$categories = get_the_category( $the_post->ID );
		if ( ! empty( $categories ) ) {
			$cat_names = array_map(
				function ( $cat ) {
					return '"' . self::escape_yaml( self::decode_entities( $cat->name ) ) . '"';
				},
				$categories
			);
			$fm .= 'categories: [' . implode( ', ', $cat_names ) . "]\n";
		}

		$tags = get_the_tags( $the_post->ID );
		if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
			$tag_names = array_map(
				function ( $tag ) {
					return '"' . self::escape_yaml( self::decode_entities( $tag->name ) ) . '"';
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

		$fm .= 'title: "' . self::escape_yaml( self::decode_entities( $term->name ) ) . '"' . "\n";

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
			$fm .= 'taxonomy_label: "' . self::escape_yaml( self::decode_entities( $tax_object->labels->singular_name ) ) . '"' . "\n";
		}

		if ( $term->parent ) {
			$parent = get_term( $term->parent, $term->taxonomy );
			if ( $parent && ! is_wp_error( $parent ) ) {
				$fm .= 'parent: "' . self::escape_yaml( self::decode_entities( $parent->name ) ) . '"' . "\n";
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
			$lines[] = sprintf( '- [%s](%s) (%d)', self::decode_entities( $child->name ), $link, (int) $child->count );
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
			// The query above is `post_type => any`, so a term shared with a gated
			// type lists its entries too. Their excerpts would be the opening of a
			// body nobody outside the membership is entitled to read.
			if ( ! VigIA_Content_Access::is_public( $entry )
				|| VigIA_Content_Access::is_gated_type( $entry->post_type ) ) {
				continue;
			}

			$permalink = get_permalink( $entry );
			$title     = self::decode_entities( get_the_title( $entry ) );
			$excerpt   = self::get_clean_excerpt( $entry );

			$line = sprintf( '- [%s](%s)', $title, $permalink );
			if ( $excerpt ) {
				$line .= ' — ' . $excerpt;
			}
			$line   .= self::product_summary_inline( $entry );
			$lines[] = $line;
		}

		// Nothing survived the access check: no heading for an empty list.
		if ( count( $lines ) < 3 ) {
			wp_reset_postdata();
			return '';
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
	 * Collapse any run of whitespace into single spaces.
	 *
	 * @param string $string Text.
	 * @return string
	 */
	private static function one_line( $string ) {
		return trim( self::keep_replace( '/\s+/', ' ', $string ) );
	}

	/**
	 * Does this text already end in punctuation that joins the next block?
	 *
	 * Twin of VigIA_LLMS_Generator::ends_in_punctuation(), and the reason it takes
	 * the block and never the line built so far.
	 *
	 * @param string $text Text to test.
	 * @return bool
	 */
	private static function ends_in_punctuation( $text ) {
		$last = mb_substr( (string) $text, -1, 1, 'UTF-8' );

		return '' !== $last && 1 === preg_match( '/^[.!?:;,\x{2026}\x{00BB}\x{201D}\x{2019})\]"\']$/u', $last );
	}

	/**
	 * HTML down to one readable line of prose, for the summaries that are not the
	 * document body (the frontmatter description).
	 *
	 * Stripping tags on their own glues together text the markup kept apart, so a
	 * pricing table reads as `PlanPriceBasic10 EURPro20 EUR`; a space in place of
	 * each tag keeps the words separated.
	 *
	 * A space is not enough where the tag was a block boundary, though: a heading
	 * and the paragraph under it come out as `Politica editorial de capitancapo
	 * capitancapo es un proyecto`, two sentences with nothing between them. The
	 * markup was carrying that full stop, so we put it back, unless the block
	 * already ends in punctuation of its own. A newline cannot do the job here,
	 * because the description is a single YAML line. Twin of
	 * class-llms-generator.php, blocks_to_line().
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	private static function plain_text( $html ) {
		$text = self::keep_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html );

		// Boundaries first, as newlines, so the loop below can tell the end of a
		// block from a space inside a sentence.
		$text = self::keep_replace( '#<(?:br|hr)\b[^>]*>#i', "\n", $text );
		$text = self::keep_replace(
			'#</(?:p|div|section|article|aside|header|footer|main|nav|h[1-6]|li|ul|ol|dl|dt|dd|blockquote|pre|figure|figcaption|table|thead|tbody|tfoot|tr|td|th|address|form|fieldset|details|summary)\s*>#i',
			"\n",
			$text
		);

		// Whatever tags are left still separate two words.
		$text = str_replace( '<', ' <', $text );
		$text = wp_strip_all_tags( $text );

		// $previous holds the block just appended, so the punctuation test reads one
		// short string instead of the whole line so far: matching with `/u` over a
		// growing subject revalidates all of it every time, which makes this loop
		// quadratic. Twin of class-llms-generator.php, ends_in_punctuation().
		$line     = '';
		$previous = '';

		foreach ( preg_split( '/\R+/', $text ) as $block ) {
			$block = self::one_line( $block );
			if ( '' === $block ) {
				continue;
			}

			if ( '' === $line ) {
				$line     = $block;
				$previous = $block;
				continue;
			}

			$line    .= ( self::ends_in_punctuation( $previous ) ? ' ' : '. ' ) . $block;
			$previous = $block;
		}

		return $line;
	}

	/**
	 * Remove the shortcode tags a page builder leaves behind when its own
	 * shortcodes are not registered in this request, keeping the text between
	 * them.
	 *
	 * Deliberately narrower than dropping every `[word]`: bracketed prose ([sic],
	 * a [1] footnote, [updated]) reads the same as a bare shortcode, so a bare tag
	 * is only removed when the document also carried its closing tag. Markdown
	 * link and image labels are left alone by the lookahead, which would otherwise
	 * delete the anchor text and leave a bare "(url)".
	 *
	 * @param string $markdown Markdown.
	 * @return string
	 */
	private static function strip_shortcode_leftovers( $markdown ) {
		if ( false === strpos( $markdown, '[' ) ) {
			return $markdown;
		}

		$paired = array();
		if ( preg_match_all( '#\[/([a-z][a-z0-9_-]*)\]#i', $markdown, $found ) ) {
			$paired = array_unique( array_map( 'strtolower', $found[1] ) );
		}

		// Closing tags: nobody writes [/something] in prose.
		$markdown = self::keep_replace( '#\[/[a-z][a-z0-9_-]*\]#i', '', $markdown );

		// Opening tags carrying attributes: [et_pb_section fb_built="1"].
		$markdown = self::keep_replace( '#\[[a-z][a-z0-9_-]*\s[^\]]*\](?!\()#i', '', $markdown );

		// Bare opening tags, only for names seen closing above.
		foreach ( $paired as $name ) {
			$markdown = self::keep_replace( '#\[' . preg_quote( $name, '#' ) . '\](?!\()#i', '', $markdown );
		}

		return $markdown;
	}

	/**
	 * Decode entities in a summary, then take the tags out again.
	 *
	 * Decoding is what turns a stored `&#8217;` into the apostrophe the author
	 * wrote. Cleaning up afterwards is not redundant: an entity-encoded
	 * `&lt;script&gt;` survives the first pass untouched and decoding would put
	 * a real tag back into the document. These summaries reach a Markdown list
	 * line with no further escaping, and Markdown passes inline HTML straight
	 * through to whatever renders it.
	 *
	 * Not wp_strip_all_tags() for that second pass, though: strip_tags() drops
	 * everything from a `<` that never finds its `>`, so a decoded `5<10` or
	 * `<5 minutes` swallowed the rest of the summary without a word, where the
	 * entity at least used to survive as text. Removing what is tag-shaped covers
	 * the `&lt;script&gt;` case and leaves a lone `<` as the character the author
	 * typed. Found in the cross review of 2.6.5; present since this helper existed.
	 *
	 * @param string $text Summary text.
	 * @return string
	 */
	private static function decode_entities( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return self::one_line( self::remove_tag_shapes( $text ) );
	}

	/**
	 * Take out everything shaped like an HTML tag, leaving a lone `<` alone.
	 *
	 * Not wp_strip_all_tags(): strip_tags() drops everything from a `<` that never
	 * finds its `>`, so a `5<10` or a `<5 minutes` swallows the rest of the text.
	 *
	 * To a fixed point, and this is the part that bites: one pass can weld two
	 * leftovers into a new tag. `<scr<b></b>ipt>` loses the `<b></b>` and becomes a
	 * working `<script>`, and `<i<b></b>mg src=x on<b></b>error=1>` rebuilds a live
	 * `<img onerror>` out of something strip_tags() emptied. The quoted-attribute
	 * alternatives keep a `>` inside an attribute from closing the match early.
	 * Measured on adversarial input of several MB: under 4 ms, no backtracking.
	 *
	 * Twin of VigIA_LLMS_Generator::decode_entities(). Both findings, and the
	 * reconstruction that the first fix introduced, come from the two cross-review
	 * rounds of 2.6.5.
	 *
	 * A tag-shaped construct with an unpaired quote (`<a href="javascript:alert(1)>click`)
	 * never finds the closing `>` the attribute-aware alternatives look for, so the
	 * loop above leaves the opening `<a` in place; measured with the AyudaWP
	 * Visibility sibling's fuzz harness at 3.108 surviving tags per 5.000 adversarial
	 * inputs without this net. Visibility's own fix (2.7.1): a final pass, after the
	 * loop reaches its fixed point, for any `<` (or run of them, so trimming one from
	 * `<<a` cannot weld the rest to the following letter) immediately before the four
	 * characters that open an HTML tag. `5<10`, `<5 minutes` and `a < b` keep their
	 * `<`, same as `x<y` already lost it to strip_tags().
	 *
	 * @param string $text Text that may carry decoded markup.
	 * @return string
	 */
	private static function remove_tag_shapes( $text ) {
		$text = (string) $text;

		for ( $pass = 0; $pass < 10; $pass++ ) {
			$before = $text;
			$text   = preg_replace( '#<!--.*?-->#s', '', $text );
			$text   = null === $text ? null : preg_replace( '#</?[a-z](?:[^<>"\']|"[^"]*"|\'[^\']*\')*>#i', '', $text );

			// This is the one step that must not keep what it had when the engine
			// gives up (see keep_replace()): what it had may carry a live tag. The
			// plain strip loses the text after a lone `<`, which is the lesser harm.
			if ( null === $text ) {
				return self::strip_when_filter_fails( $before );
			}

			if ( $text === $before ) {
				break;
			}
		}

		$clean = preg_replace( '#<+(?=[a-z/!?])#i', '', $text );

		return null === $clean ? self::strip_when_filter_fails( $text ) : $clean;
	}

	/**
	 * What remove_tag_shapes() falls back to when its expression cannot run.
	 *
	 * The plain strip is the very thing that function avoids, because it drops
	 * the text after a lone `<`. Here that loss is the lesser harm: the other
	 * choice is to hand over text nobody filtered. Kept apart so that the only
	 * call to it is this error path.
	 *
	 * @since 2.6.7
	 * @param string $text Text the filter could not process.
	 * @return string
	 */
	private static function strip_when_filter_fails( $text ) {
		return wp_strip_all_tags( $text );
	}

	/**
	 * Get clean post excerpt
	 *
	 * @param WP_Post $the_post Post object.
	 * @return string
	 */
	private static function get_clean_excerpt( $the_post ) {
		if ( ! empty( $the_post->post_excerpt ) ) {
			return self::decode_entities( self::plain_text( $the_post->post_excerpt ) );
		}

		// With no hand-written excerpt the description would be the opening of the
		// body, which must not be published for an entry the visitor cannot read:
		// a membership-gated page would otherwise have its first 200 characters
		// sitting in the frontmatter, and in the term listings, for anyone to
		// read. A manual excerpt above is different: the author wrote it to be
		// shown.
		if ( ! VigIA_Content_Access::is_public( $the_post ) ) {
			return '';
		}

		// strip_shortcodes() only knows the ones registered in this request, so a
		// page builder's tags survive it and would otherwise be the description an
		// agent reads as the summary of the page.
		$content = self::strip_shortcode_leftovers( strip_shortcodes( $the_post->post_content ) );
		$content = self::decode_entities( self::plain_text( $content ) );

		// Trimmed by characters, not bytes: strlen()/substr() cut an accented
		// character in half, and when the first 200 bytes held no space at all the
		// strrpos() fallback returned false and the description came out empty.
		if ( mb_strlen( $content ) > 200 ) {
			$content = rtrim( wp_trim_words( $content, 40, '' ) );

			if ( mb_strlen( $content ) > 200 ) {
				$content = rtrim( mb_substr( $content, 0, 200 ) );
			}

			$content .= '...';
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
		$content = $the_post->post_content;

		// Save and set up post context for shortcodes. This is also what lets the
		// membership plugins that gate by filtering `the_content` recognise which
		// entry they are being asked about: without it they see no post, conclude
		// there is nothing to protect and hand over the whole body.
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

		// Fall back to text extraction if shortcodes remain unprocessed, which
		// happens with page builders in a context where their plugin never
		// registered them.
		//
		// Extraction runs on the filtered content rather than on the raw body: a
		// membership plugin that replaced the body has no shortcodes left for this
		// branch to catch, but one that left a teaser does, and reading the raw
		// body there would hand over the very text it just withheld. In a context
		// with no filters at all the two are the same string anyway.
		if ( preg_match( '/\[[a-z][a-z0-9_-]*[\s\]]/i', $content ) ) {
			$content = self::extract_text_from_shortcodes( $content );
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
		// Remove self-closing shortcodes, with or without the space: [gallery /].
		$content = self::keep_replace( '#\[[a-z][a-z0-9_-]*[^\]]*/\]#is', '', $content );

		// Everything else goes through the same conservative cleanup the Markdown
		// pass uses. Deleting every [word] here would take bracketed prose with it,
		// and this is the branch that runs on exactly the pages where it matters:
		// the ones a page builder left full of its own tags.
		return self::strip_shortcode_leftovers( $content );
	}

	/**
	 * preg_replace() that keeps the subject when the engine gives up.
	 *
	 * A PCRE call that runs out of backtracking or of stack returns null, not the
	 * subject, and every step of the converter used to assign its result straight
	 * back: one failed step and the document went out as its frontmatter and an
	 * empty body, stored for twelve hours. Measured: 1,500 KB inside a link, a
	 * heading, a paragraph, a bold run, a code span or a table cell emptied it,
	 * with the PCRE JIT and without it, and without the JIT an image address of
	 * 1,000 KB did too. A step that fails now leaves the document as it was,
	 * which loses that one conversion and not the text.
	 *
	 * @since 2.6.7
	 * @param string $pattern     Pattern.
	 * @param string $replacement Replacement.
	 * @param mixed  $subject     Subject.
	 * @return string
	 */
	private static function keep_replace( $pattern, $replacement, $subject ) {
		$subject = (string) $subject;
		$result  = preg_replace( $pattern, $replacement, $subject );

		return null === $result ? $subject : $result;
	}

	/**
	 * preg_replace_callback() on the same terms as keep_replace().
	 *
	 * @since 2.6.7
	 * @param string   $pattern  Pattern.
	 * @param callable $callback Callback.
	 * @param mixed    $subject  Subject.
	 * @return string
	 */
	private static function keep_replace_callback( $pattern, $callback, $subject ) {
		$subject = (string) $subject;
		$result  = preg_replace_callback( $pattern, $callback, $subject );

		return null === $result ? $subject : $result;
	}

	/**
	 * A code sample as a fenced block, with a fence longer than any run of
	 * backticks the sample carries.
	 *
	 * @since 2.6.7
	 * @param string $code     The sample, already decoded.
	 * @param string $language Language for the info string, or ''.
	 * @return string
	 */
	private static function fenced( $code, $language = '' ) {
		$fence = '```';
		if ( preg_match_all( '/`{3,}/', $code, $runs ) ) {
			$fence = str_repeat( '`', max( array_map( 'strlen', $runs[0] ) ) + 1 );
		}

		return $fence . $language . "\n" . $code . "\n" . $fence;
	}

	/**
	 * The text of a code sample as it sits between its <pre> tags: line breaks
	 * written as <br> back to real ones, whatever markup a highlighter added
	 * taken out, and the entities decoded.
	 *
	 * @since 2.6.7
	 * @param string $inner Inner HTML of the <pre> or of its <code>.
	 * @return string
	 */
	private static function code_text( $inner ) {
		$inner = self::keep_replace( '#<br\b[^>]*>#i', "\n", $inner );

		return trim( html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES, 'UTF-8' ), "\r\n" );
	}

	/**
	 * Convert HTML to markdown
	 *
	 * @param string $html HTML content.
	 * @return string Markdown content.
	 */
	private static function html_to_markdown( $html ) {
		// A placeholder nobody can write in advance. See self::$marker.
		$outer_marker = self::$marker;
		self::$marker = 'VIGIAPLACEHOLDER' . self::marker_nonce() . 'X';

		// Remove script and style tags.
		$html = self::keep_replace( '/<script[^>]*>.*?<\/script>/is', '', $html );
		$html = self::keep_replace( '/<style[^>]*>.*?<\/style>/is', '', $html );

		// Remove empty page builder wrappers.
		$html = self::keep_replace( '/<(div|section|article|aside|header|footer|nav|main)[^>]*>\s*<\/\1>/is', '', $html );

		// The counter of Elementor is printed at the number it starts from and
		// counts up to the one in `data-to-value` once it is on screen: «250+
		// clients» came out as «0+». The number it ends at is the one to read.
		$html = self::keep_replace( '#(<span\b[^>]*\belementor-counter-number\b[^>]*\bdata-to-value="([^"]*)"[^>]*>)[^<]*(</span>)#i', '$1$2$3', $html );

		// Store for everything that has to reach the end untouched: the address
		// of every link and image, the code blocks and spans, and the rendered
		// lists. Each goes in as a placeholder and comes back verbatim after the
		// last pass. A block is parked as `…END` and what stays inside a line as
		// `…INL`, which is how the list walker tells a code block from a link.
		$protected = array();
		$park      = static function ( $block ) use ( &$protected ) {
			$protected[] = $block;
			return self::$marker . ( count( $protected ) - 1 ) . 'END';
		};

		// The address of a link or an image, parked (2.6.7). It used to sit in
		// the text, and a Markdown reader takes what is between the parentheses
		// of a link as it comes: a backtick in an `href` was never the start of a
		// code span for it, a quote opened a title that ran on to the next line,
		// and whatever span had been parked around that came back as text. Parked
		// and with those characters encoded, the address ends where it was closed.
		$address = static function ( $url ) use ( &$protected ) {
			$protected[] = self::link_address( $url );
			return self::$marker . ( count( $protected ) - 1 ) . 'INL';
		};

		// The address of an image is never a `data:` one (2.6.7). A picture pasted
		// into the content travels inside its own `src`, and the document carried
		// it: one of 200 KB put some 51,000 tokens of base64 into a file made to be
		// read. And a lazy-loading plugin keeps the real address out of `src`, in
		// `data-src`, `data-lazy-src` or `data-original`, with a placeholder in its
		// place: a `data:` one, a blank image file or nothing. So when one of those
		// three carries an address, that is the image, whatever `src` says; and an
		// image whose only address is a `data:` one gives its place to its alt text.
		// Every other image is left for the expressions below.
		$html = self::keep_replace_callback(
			'#<img\b[^>]*>#i',
			static function ( $matches ) use ( $address ) {
				$tag  = $matches[0];
				$lazy = '';
				if ( preg_match( '#(?<![\w-])data-(?:lazy-)?(?:src|original)=(?|"([^"]*)"|\'([^\']*)\')#i', $tag, $found ) ) {
					$lazy = trim( $found[1] );
					if ( 0 === stripos( $lazy, 'data:' ) ) {
						$lazy = '';
					}
				}

				$is_data = 1 === preg_match( '#(?<![\w-])src=(?:"\s*data:|\'\s*data:)#i', $tag );
				if ( '' === $lazy && ! $is_data ) {
					return $tag;
				}

				$alt = preg_match( '#(?<![\w-])alt=(?|"([^"]*)"|\'([^\']*)\')#i', $tag, $found ) ? $found[1] : '';

				return '' !== $lazy ? '![' . $alt . '](' . $address( $lazy ) . ')' : $alt;
			},
			$html
		);

		// Images (before links to avoid conflicts). Both attribute orders and both
		// quote styles, each value ending at its own quote: one character class per
		// quote style, so an apostrophe inside a double-quoted alt no longer cuts it
		// short (`It's here` came out as `It`). `src` and `alt` are the attributes of
		// that name and not the tail of another: unanchored and matched greedily,
		// the last `src=` of the tag won, and `<img src="real.jpg" data-src="data:x">`
		// came out as `data:x`. Never a lookahead repeated for every character: that
		// form runs out of stack on a long value, which the Visibility sibling
		// measured on these same expressions.
		$html = self::keep_replace_callback(
			'#<img\b[^>]*?(?<![\w-])alt=(?|"([^"]*)"|\'([^\']*)\')[^>]*?(?<![\w-])src=(?|"([^"]*)"|\'([^\']*)\')[^>]*>#i',
			static function ( $matches ) use ( $address ) {
				return '![' . $matches[1] . '](' . $address( $matches[2] ) . ')';
			},
			$html
		);
		$html = self::keep_replace_callback(
			'#<img\b[^>]*?(?<![\w-])src=(?|"([^"]*)"|\'([^\']*)\')[^>]*?(?<![\w-])alt=(?|"([^"]*)"|\'([^\']*)\')[^>]*>#i',
			static function ( $matches ) use ( $address ) {
				return '![' . $matches[2] . '](' . $address( $matches[1] ) . ')';
			},
			$html
		);
		$html = self::keep_replace_callback(
			'#<img\b[^>]*?(?<![\w-])src=(?|"([^"]*)"|\'([^\']*)\')[^>]*>#i',
			static function ( $matches ) use ( $address ) {
				return '![](' . $address( $matches[1] ) . ')';
			},
			$html
		);

		// Links.
		$html = self::keep_replace_callback(
			'/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
			static function ( $matches ) use ( $address ) {
				return '[' . self::one_block_line( $matches[2] ) . '](' . $address( $matches[1] ) . ')';
			},
			$html
		);

		// Code blocks (pre > code). Capture the attributes of both <pre> and
		// <code> so a `language-xxx` class on either one is detected. The old
		// single greedy `<code[^>]*` swallowed the class before the optional
		// group could capture it, so fences always came out without a language.
		//
		// A block is parked the moment it is built (2.6.7). Its content comes out
		// decoded (a <pre><code> carries its HTML sample as entities), and it used
		// to stay in the text for every pass below to read as markup: the strip
		// further down took `<div class="card"><p>Hola</p></div>` for tags and left
		// `Hola`, the heading and emphasis passes rewrote an `<h2>` or a `<strong>`
		// being taught, and the list walker turned a sample `<ul>` into a list.
		$html = self::keep_replace_callback(
			'/<pre([^>]*)>\s*<code([^>]*)>(.*?)<\/code>\s*<\/pre>/is',
			static function ( $matches ) use ( $park ) {
				$lang = '';
				if ( preg_match( '/language-([a-z0-9_+#-]+)/i', $matches[1] . ' ' . $matches[2], $lang_match ) ) {
					$lang = strtolower( $lang_match[1] );
				}
				return "\n\n" . $park( self::fenced( self::code_text( $matches[3] ), $lang ) ) . "\n\n";
			},
			$html
		);

		// A <pre> with no <code> inside is still preformatted text (the
		// Preformatted and Verse blocks, code pasted into older content): fenced
		// too, or the whitespace pass below flattens the layout that makes it
		// readable.
		$html = self::keep_replace_callback(
			'/<pre[^>]*>(.*?)<\/pre>/is',
			static function ( $matches ) use ( $park ) {
				return "\n\n" . $park( self::fenced( self::code_text( $matches[1] ) ) ) . "\n\n";
			},
			$html
		);

		// Inline code, on one line: a code span reads a line ending as a space
		// anyway, and only a span that sits on a single line is taken for code
		// further down.
		$html = self::keep_replace_callback(
			'/<code[^>]*>(.*?)<\/code>/is',
			static function ( $matches ) {
				return '`' . self::keep_replace( '#(?:<br\b[^>]*>|\R)\s*#i', ' ', $matches[1] ) . '`';
			},
			$html
		);

		// Blockquotes.
		$html = self::keep_replace_callback(
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

		// Headings. On one line, which is all a Markdown heading is (2.6.7): a
		// page builder writes the text inside nested elements, each on its line,
		// and the `###` came out alone with its title as a paragraph under it. A
		// heading with nothing to read is dropped.
		$html = self::keep_replace_callback(
			'/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is',
			static function ( $matches ) {
				$text = self::one_block_line( $matches[2] );

				return '' === trim( wp_strip_all_tags( $text ) ) ? '' : "\n\n" . str_repeat( '#', (int) $matches[1] ) . ' ' . $text . "\n\n";
			},
			$html
		);

		// Horizontal rules.
		$html = self::keep_replace( '/<hr[^>]*\/?>/is', "\n\n---\n\n", $html );

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
		// An empty element gets dropped rather than marked up: a themed icon is an
		// empty <i> (the Font Awesome `<i class="fa fa-star"></i>` that countless
		// themes and page builders emit), and wrapping nothing in markers leaves a
		// stray ** or * sitting mid-sentence.
		$emphasis = static function ( $pattern, $marker, $subject ) {
			return self::keep_replace_callback(
				$pattern,
				static function ( $matches ) use ( $marker ) {
					return '' === trim( wp_strip_all_tags( $matches[2] ) ) ? '' : $marker . $matches[2] . $marker;
				},
				$subject
			);
		};

		$html = $emphasis( '/<(strong|b)\b[^>]*>(.*?)<\/(strong|b)>/is', '**', $html );
		$html = $emphasis( '/<(em|i)\b[^>]*>(.*?)<\/(em|i)>/is', '*', $html );
		$html = $emphasis( '/<(del|s|strike)\b[^>]*>(.*?)<\/(del|s|strike)>/is', '~~', $html );

		// Lists (<ul>/<ol>, including nested and mixed). Walked with DOMDocument
		// so nesting, ordered/unordered markers and indentation survive; the old
		// flat regexes dropped the first nested item's bullet and all indent.
		// Each rendered block is parked as a placeholder to shield its per-line
		// indentation from the whitespace pass further down, in the same store as
		// the code blocks above. A code block inside an item is by now its
		// placeholder, and the walker sets it on a line of its own under the item.
		$html = self::convert_lists_to_markdown( $html, $protected );

		// Paragraphs and line breaks.
		$html = self::keep_replace( '/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $html );
		$html = self::keep_replace( '/<br[^>]*\/?>/is', "  \n", $html );

		// Tables. The store goes along because a cell is a single line: a code
		// block inside one is turned into a code span there.
		$html = self::keep_replace_callback(
			'/<table[^>]*>(.*?)<\/table>/is',
			static function ( $matches ) use ( &$protected ) {
				return self::convert_table_to_markdown( $matches, $protected );
			},
			$html
		);

		// Figure/figcaption.
		$html = self::keep_replace( '/<\/?figure[^>]*>/is', "\n", $html );
		$html = self::keep_replace( '/<figcaption[^>]*>(.*?)<\/figcaption>/is', "*$1*\n", $html );

		// Strip remaining wrappers (keep content).
		$html = self::keep_replace( '/<(div|section|article|aside|header|footer|nav|main|span)[^>]*>/is', '', $html );
		$html = self::keep_replace( '/<\/(div|section|article|aside|header|footer|nav|main|span)>/is', '', $html );

		// Strip any remaining HTML tags.
		$html = wp_strip_all_tags( $html );
		$html = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );

		// Protect the code that is still text from the cleanup and whitespace
		// passes below: a fence the author typed instead of using a <pre>, and the
		// inline code spans, whose content was entity-encoded until the decode
		// above. Without this, bracketed text inside code (e.g. $arr[key]) is
		// stripped as if it were a shortcode. The blocks built from markup are in
		// the store already. Only what a Markdown reader will take for code is set
		// aside (2.6.7): see park_typed_code().
		$spans = array();
		$html  = self::park_typed_code( $html, $protected, $spans );

		// The body kept whatever markup the entities carried: a stored
		// `&lt;script&gt;` came out of the decode above as a real `<script>` in a
		// document a Markdown renderer passes straight through as inline HTML. It
		// only took an author to write it. Now it goes through the same tag-shape
		// filter as the summaries.
		//
		// Deliberately AFTER the code is parked: inside a fenced block or an
		// inline code span, `<div>` is the subject of the page, not markup, and
		// CommonMark does not interpret HTML there. Those are placeholders by now
		// and come back verbatim at the end. Decided 17 sep 2026.
		$html = self::remove_tag_shapes( $html );

		// Clean up artifacts left by unregistered shortcodes.
		$html = self::strip_shortcode_leftovers( $html );

		// The filter once more (2.6.7): what the cleanup above takes out can be
		// the only thing that kept a `<` and a tag name apart. An author who wrote
		// `<[x y]img src=x onerror=alert(1)>` as text got the tag back, whole.
		$html = self::remove_tag_shapes( $html );

		// Clean up whitespace.
		$html  = self::keep_replace( '/\n{3,}/', "\n\n", $html );
		$html  = self::keep_replace( '/[ \t]+/', ' ', $html );
		$lines = explode( "\n", $html );
		$lines = array_map( 'trim', $lines );
		$html  = implode( "\n", $lines );
		$html  = self::keep_replace( '/\n{3,}/', "\n\n", $html );

		// What is left of the text can no longer open or close code of its own
		// (2.6.7): see settle_code_marks().
		$html = self::settle_code_marks( $html, $protected, $spans );

		// Restore the protected list blocks and code verbatim, now that the
		// whitespace pass is done, so nested indentation and inner brackets stay.
		// More than one pass, because a block can travel inside another: a code
		// block inside a list item is parked first, and its placeholder is part of
		// the list block parked after it. A placeholder alone on a line behind an
		// indentation or a quote marker (that same code block, set under its item,
		// or one inside a blockquote) passes that prefix on to every line of the
		// block, which is what keeps it inside the item or the quote: restored as
		// it stood, only its first line would carry it.
		$restore = '/^((?:[ \t]*>[ \t]?)+|[ \t]+)' . self::$marker . '(\d+)(?:END|INL)$|' . self::$marker . '(\d+)(?:END|INL)/m';
		for ( $pass = 0; $pass < 5 && false !== strpos( $html, self::$marker ); $pass++ ) {
			$html = self::keep_replace_callback(
				$restore,
				static function ( $matches ) use ( $protected ) {
					if ( isset( $matches[3] ) && '' !== $matches[3] ) {
						$index = (int) $matches[3];
						return isset( $protected[ $index ] ) ? $protected[ $index ] : '';
					}

					$index = (int) $matches[2];
					if ( ! isset( $protected[ $index ] ) ) {
						return '';
					}

					// An empty line keeps the quote marker and drops the spaces.
					$prefixed = array();
					foreach ( explode( "\n", $protected[ $index ] ) as $line ) {
						$prefixed[] = '' === $line ? rtrim( $matches[1] ) : $matches[1] . $line;
					}

					return implode( "\n", $prefixed );
				},
				$html
			);
		}

		self::$marker = $outer_marker;

		return trim( $html );
	}

	/**
	 * What sits inside a link or a heading, on a single line.
	 *
	 * Neither can hold a block: the text of a link ends at the first blank line
	 * and a heading is one line. Page builders nest that text in elements of
	 * their own, each on its line (the button of Elementor is an `<a>` around
	 * two `<span>`), and the document carried the line breaks: `[`, a blank
	 * line, the text, another blank line and `](address)`, which no Markdown
	 * reader takes for a link. The tags that would start a block further down
	 * the converter give way to a space, the wrappers go, and the whitespace is
	 * collapsed. A code block inside is left as it is, its lines being what it
	 * shows.
	 *
	 * @since 2.6.7
	 * @param string $inner Inner HTML of the link or the heading.
	 * @return string
	 */
	private static function one_block_line( $inner ) {
		if ( false !== stripos( $inner, '<pre' ) || 1 === preg_match( '/' . self::$marker . '\d+END/', $inner ) ) {
			return $inner;
		}

		$inner = self::keep_replace( '#</?(?:h[1-6]|p|div|section|article|aside|header|footer|figure|figcaption|ul|ol|li|dl|dt|dd|blockquote|table|thead|tbody|tfoot|tr|td|th|br|hr)\b[^>]*>#i', ' ', $inner );

		// Every other tag but the ones turned into Markdown further down: left
		// in, a wrapper keeps the space around it and the text comes out padded.
		$inner = self::keep_replace( '#</?(?!(?:strong|b|em|i|del|s|strike|code)\b)[a-z][^>]*>#i', '', $inner );

		return self::one_line( $inner );
	}

	/**
	 * A value for the placeholders of one call that nobody can write in advance.
	 *
	 * @since 2.6.7
	 * @return string Sixteen hexadecimal characters.
	 */
	private static function marker_nonce() {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $e ) {
			return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 16 );
		}
	}

	/**
	 * Set aside the code an author typed as text, and only what is code.
	 *
	 * The content of a parked block comes back verbatim at the end, markup and
	 * all, so parking is only safe for what a Markdown reader will then read as
	 * code. The two expressions this replaces (2.6.7) parked more than that, and
	 * what was left over came out as live markup. Measured, each from a body an
	 * author can write without `unfiltered_html`:
	 *
	 * - two backticks, a tag written as text and one backtick: CommonMark closes
	 *   a span only with as many backticks as opened it, so that is no span;
	 * - a span left open on one line and closed on the next, with a second one
	 *   after it: the reader pairs the first backtick with the second, and what
	 *   was parked as the content of a span is the text between two of them;
	 * - three backticks in the middle of a line, a tag in the next paragraph and
	 *   three more in the one after: a fence only opens at the start of a line.
	 *
	 * A fence counts when it is one for CommonMark: alone on its line, with an
	 * info string that is a plain word, closed by a line of its own, and behind
	 * the same quote markers on every line when it sits in a blockquote. It is
	 * parked with those lines whole, so it comes back as it was typed. A span
	 * counts when it opens and closes on the same line with runs of the same
	 * length, and on a table row when it stays inside one cell, because a table
	 * is cut into cells before its code spans are read.
	 *
	 * @since 2.6.7
	 * @param string $text  Text with the tags already stripped and the entities decoded.
	 * @param array  $store The placeholder store, by reference.
	 * @param array  $spans Indexes of the store that are code spans, by reference.
	 * @return string
	 */
	private static function park_typed_code( $text, &$store, &$spans ) {
		if ( false === strpos( $text, '`' ) ) {
			return $text;
		}

		$lines   = explode( "\n", $text );
		$count   = count( $lines );
		$tabular = self::table_lines( $lines );
		$out     = array();

		for ( $number = 0; $number < $count; $number++ ) {
			$line = $lines[ $number ];

			if ( false === strpos( $line, '`' ) ) {
				$out[] = $line;
				continue;
			}

			$in_table = isset( $tabular[ $number ] );
			$end      = ( $in_table && 2 === $tabular[ $number ] ) ? 0 : self::typed_fence_end( $lines, $number );

			if ( $end > $number ) {
				$block    = array_slice( $lines, $number, $end - $number + 1 );
				$last     = count( $block ) - 1;
				$block[0] = self::one_line( $block[0] );

				$block[ $last ] = self::one_line( $block[ $last ] );

				// Behind a blank line, so the line above cannot take the opening
				// fence for its own (a link reference definition reads the next
				// line as its destination).
				$before = empty( $out ) ? '' : trim( ltrim( end( $out ), "> \t" ) );
				if ( '' !== $before ) {
					$out[] = rtrim( substr( $block[0], 0, strcspn( $block[0], '`' ) ) );
				}

				$store[] = implode( "\n", $block );
				$out[]   = self::$marker . ( count( $store ) - 1 ) . 'END';
				$number  = $end;
				continue;
			}

			$out[] = self::park_spans( $line, $in_table, $store, $spans );
		}

		return implode( "\n", $out );
	}

	/**
	 * Where the fence opened on a line ends, or 0 when that line opens none.
	 *
	 * @since 2.6.7
	 * @param array $lines Lines of the text.
	 * @param int   $start Index of the candidate opening line.
	 * @return int Index of the closing line, or 0.
	 */
	private static function typed_fence_end( $lines, $start ) {
		if ( ! preg_match( '/^((?:>[ \t]*)*)(`{3,})[ \t]*[a-z0-9_+#.-]*$/i', trim( $lines[ $start ] ), $open ) ) {
			return 0;
		}

		$depth  = substr_count( $open[1], '>' );
		$length = strlen( $open[2] );
		$count  = count( $lines );

		for ( $number = $start + 1; $number < $count; $number++ ) {
			$line = trim( $lines[ $number ] );

			// A block parked earlier brings its own fence, which would close
			// this one and leave the rest of it outside.
			if ( 1 === preg_match( '/' . self::$marker . '\d+END/', $line ) ) {
				return 0;
			}

			if ( $depth > 0 ) {
				if ( ! preg_match( '/^(?:>[ \t]?){' . $depth . '}(.*)$/', $line, $inner ) ) {
					return 0;
				}
				$line = $inner[1];
				if ( strspn( $line, ' ' ) > 3 ) {
					continue;
				}
				$line = ltrim( $line, ' ' );
			}

			if ( strlen( $line ) >= $length && strlen( $line ) === strspn( $line, '`' ) ) {
				return $number;
			}
		}

		return 0;
	}

	/**
	 * The lines that a Markdown reader may read as rows of a table.
	 *
	 * A delimiter row (`| --- | --- |`) makes a table of the line above it and
	 * of every line under it up to the next blank one. Wider than any reader
	 * draws it, on purpose: the only effect of a line being listed here is that
	 * its code spans are looked for cell by cell.
	 *
	 * @since 2.6.7
	 * @param array $lines Lines of the text.
	 * @return array Line index => 1 for the row above a delimiter, 2 from the delimiter on.
	 */
	private static function table_lines( $lines ) {
		$tabular = array();
		$inside  = false;

		foreach ( $lines as $number => $line ) {
			$line = trim( ltrim( $line, "> \t" ) );

			if ( '' === $line ) {
				$inside = false;
				continue;
			}

			if ( false !== strpos( $line, '-' ) && strlen( $line ) === strspn( $line, "|:- \t" ) ) {
				$inside = true;
				if ( $number > 0 && ! isset( $tabular[ $number - 1 ] ) ) {
					$tabular[ $number - 1 ] = 1;
				}
			}

			if ( $inside ) {
				$tabular[ $number ] = 2;
			}
		}

		return $tabular;
	}

	/**
	 * A line cut where a table would cut it: at every pipe that an even number
	 * of backslashes, none included, comes before.
	 *
	 * The readers disagree on a pipe behind two backslashes (cmark and micromark
	 * cut there, markdown-it does not), so it counts as a cut.
	 *
	 * @since 2.6.7
	 * @param string $line Line.
	 * @return array The pieces, without the pipes they were cut at.
	 */
	private static function split_cells( $line ) {
		$cells = array();
		$from  = 0;
		$at    = strpos( $line, '|' );

		while ( false !== $at ) {
			if ( 0 === self::backslashes_before( $line, $at ) % 2 ) {
				$cells[] = substr( $line, $from, $at - $from );
				$from    = $at + 1;
			}
			$at = strpos( $line, '|', $at + 1 );
		}

		$cells[] = substr( $line, $from );

		return $cells;
	}

	/**
	 * How many backslashes come right before a position.
	 *
	 * @since 2.6.7
	 * @param string $text Text.
	 * @param int    $at   Position.
	 * @return int
	 */
	private static function backslashes_before( $text, $at ) {
		$total = 0;
		while ( $at - $total > 0 && '\\' === $text[ $at - $total - 1 ] ) {
			++$total;
		}

		return $total;
	}

	/**
	 * Every run of backticks of a text, as array( position, length ).
	 *
	 * @since 2.6.7
	 * @param string $text Text.
	 * @return array
	 */
	private static function backtick_runs( $text ) {
		$runs   = array();
		$length = strlen( $text );
		$at     = strpos( $text, '`' );

		while ( false !== $at ) {
			$size   = strspn( $text, '`', $at );
			$runs[] = array( $at, $size );
			$at     = $at + $size < $length ? strpos( $text, '`', $at + $size ) : false;
		}

		return $runs;
	}

	/**
	 * The code spans of one line, the way CommonMark pairs them.
	 *
	 * Left to right: a run of backticks opens a span that the next run of the
	 * same length closes, and a run with no such partner on the line is text. A
	 * backslash before the opening run takes its first backtick as an escaped
	 * character; inside a span a backslash is only a backslash. A span holding
	 * the placeholder of a code block is left alone: what comes back there
	 * brings backticks of its own. The address of a link does not.
	 *
	 * @since 2.6.7
	 * @param string $text One line, or one cell of a table row.
	 * @return array Spans as array( start, end ), the end exclusive.
	 */
	private static function code_spans( $text ) {
		$runs  = self::backtick_runs( $text );
		$total = count( $runs );
		$found = array();

		for ( $open = 0; $open < $total; $open++ ) {
			list( $start, $size ) = $runs[ $open ];

			if ( 1 === self::backslashes_before( $text, $start ) % 2 ) {
				++$start;
				--$size;
			}
			if ( $size < 1 ) {
				continue;
			}

			for ( $close = $open + 1; $close < $total; $close++ ) {
				if ( $runs[ $close ][1] === $size ) {
					break;
				}
			}
			if ( $close >= $total ) {
				continue;
			}

			$stop = $runs[ $close ][0] + $size;
			if ( 1 === preg_match( '/' . self::$marker . '\d+END/', substr( $text, $start, $stop - $start ) ) ) {
				continue;
			}

			$found[] = array( $start, $stop );
			$open    = $close;
		}

		return $found;
	}

	/**
	 * Park the code spans of a line.
	 *
	 * @since 2.6.7
	 * @param string $line     Line.
	 * @param bool   $in_table Whether the line may be a table row.
	 * @param array  $store    The placeholder store, by reference.
	 * @param array  $spans    Indexes of the store that are code spans, by reference.
	 * @return string
	 */
	private static function park_spans( $line, $in_table, &$store, &$spans ) {
		$cells = $in_table ? self::split_cells( $line ) : array( $line );

		foreach ( $cells as $key => $cell ) {
			$out  = '';
			$from = 0;

			foreach ( self::code_spans( $cell ) as $span ) {
				$store[] = substr( $cell, $span[0], $span[1] - $span[0] );
				$index   = count( $store ) - 1;
				$out    .= substr( $cell, $from, $span[0] - $from ) . self::$marker . $index . 'INL';
				$from    = $span[1];

				$spans[ $index ] = true;
			}

			$cells[ $key ] = $out . substr( $cell, $from );
		}

		return implode( '|', $cells );
	}

	/**
	 * Every backtick of a text made a literal one.
	 *
	 * With a backslash, which Markdown reads as the character itself and never
	 * as the start of code. A run that already has an odd number of backslashes
	 * before it has its first backtick escaped by the last of them.
	 *
	 * @since 2.6.7
	 * @param string $text Text with no code in it.
	 * @return string
	 */
	private static function escape_backticks( $text ) {
		$out  = '';
		$from = 0;

		foreach ( self::backtick_runs( $text ) as $run ) {
			list( $at, $size ) = $run;

			$bare = self::backslashes_before( $text, $at ) % 2;
			$out .= substr( $text, $from, $at - $from ) . str_repeat( '`', $bare ) . str_repeat( '\\`', $size - $bare );
			$from = $at + $size;
		}

		return $out . substr( $text, $from );
	}

	/**
	 * A line of text that cannot open a code block: its code spans stay, every
	 * other backtick becomes a literal one, and a run of tildes at its start
	 * (the other fence of CommonMark) gets a backslash.
	 *
	 * For the text of a list item, which is rendered and parked apart from the
	 * body and never meets settle_code_marks().
	 *
	 * @since 2.6.7
	 * @param string $text One line.
	 * @return string
	 */
	private static function settle_line( $text ) {
		if ( false !== strpos( $text, '`' ) ) {
			$out  = '';
			$from = 0;

			foreach ( self::code_spans( $text ) as $span ) {
				$out .= self::escape_backticks( substr( $text, $from, $span[0] - $from ) ) . substr( $text, $span[0], $span[1] - $span[0] );
				$from = $span[1];
			}

			$text = $out . self::escape_backticks( substr( $text, $from ) );
		}

		return strspn( $text, '~' ) >= 3 ? '\\' . $text : $text;
	}

	/**
	 * Leave the text unable to open or close code, once everything else is done.
	 *
	 * What a Markdown reader takes for code is decided by the text around it as
	 * much as by the code itself, and every block that comes back verbatim
	 * relies on that reading. So this runs last, on the text as it will stay,
	 * and line by line:
	 *
	 * - a code span that a table would cut in two goes back to being text. It
	 *   was parked on a line that was no table row yet: the cleanup of tags and
	 *   shortcode leftovers can be what completes the delimiter row;
	 * - every backtick still in the text is a stray one, the spans being
	 *   placeholders by now, and becomes a literal backtick. Left as it was, it
	 *   paired with the first backtick of the next span, or opened a fence that
	 *   the fence of a real code block then closed;
	 * - a placeholder is kept apart from the next one and from a backslash, two
	 *   neighbours the cleanup can leave it with and that undo its backticks;
	 * - a line starting with three tildes is a fence as well: `<del>~x</del>`
	 *   came out as `~~~x~~`, and the code block under it as text.
	 *
	 * @since 2.6.7
	 * @param string $text  Text, placeholders in place.
	 * @param array  $store The placeholder store.
	 * @param array  $spans Indexes of the store that are code spans.
	 * @return string
	 */
	private static function settle_code_marks( $text, $store, $spans ) {
		$marker  = self::$marker;
		$token   = '/' . $marker . '(\d+)(?:END|INL)/';
		$lines   = explode( "\n", $text );
		$tabular = self::table_lines( $lines );

		foreach ( $lines as $number => $line ) {
			if ( '' === $line ) {
				continue;
			}

			$holds = false !== strpos( $line, $marker );

			if ( $holds && isset( $tabular[ $number ] ) ) {
				$line = self::keep_replace_callback(
					$token,
					static function ( $matches ) use ( $store, $spans ) {
						$index = (int) $matches[1];
						if ( empty( $spans[ $index ] ) || ! isset( $store[ $index ] ) || count( self::split_cells( $store[ $index ] ) ) < 2 ) {
							return $matches[0];
						}

						return self::remove_tag_shapes( $store[ $index ] );
					},
					$line
				);
			}

			if ( false !== strpos( $line, '`' ) ) {
				$line = self::escape_backticks( $line );
			}

			// Link syntax the author typed: `](`, `][` and `]:` open what a reader
			// takes as it comes, backticks included. The links of the converter
			// are told apart by the placeholder their address is.
			if ( false !== strpos( $line, ']' ) ) {
				$plain = preg_replace( '/\](?=[\[:]|\((?!' . $marker . '))/', ']\\\\', $line );
				$line  = null === $plain ? str_replace( array( '](', '][', ']:' ), array( ']\\(', ']\\[', ']\\:' ), $line ) : $plain;
			}

			$quote = strspn( $line, "> \t" );
			if ( strspn( $line, '~', $quote ) >= 3 ) {
				$line = substr( $line, 0, $quote ) . '\\' . substr( $line, $quote );
			}

			if ( $holds && false !== strpos( $line, $marker ) ) {
				$line = self::settle_placeholders( $line, $store );
			}

			$lines[ $number ] = $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Give every placeholder of a line the surroundings its content needs.
	 *
	 * The passes between parking and here take text out, across lines too, and
	 * what they leave next to a placeholder is not what was there when it was
	 * parked. A tag shape that started on one line and found its `>` in the
	 * quote marker of the next one took the line break with it, and the code
	 * block of that next line came back in the middle of a sentence.
	 *
	 * - A block (anything of more than one line) gets a line of its own again,
	 *   behind the quote markers of the line it was found on.
	 * - Code inside a line is kept apart from what would take its first
	 *   backtick: another placeholder, a backslash, and a bare address
	 *   (`https://…`, `www.…`), which GFM reads as a link up to the next space.
	 *
	 * @since 2.6.7
	 * @param string $line  One line holding placeholders.
	 * @param array  $store The placeholder store.
	 * @return string One line, or several when a block was set apart.
	 */
	private static function settle_placeholders( $line, $store ) {
		$marker = self::$marker;
		$quote  = substr( $line, 0, strspn( $line, "> \t" ) );
		$body   = substr( $line, strlen( $quote ) );
		$pieces = preg_split( '/(' . $marker . '\d+(?:END|INL))/', $body, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $pieces ) ) {
			return $line;
		}

		$out     = array();
		$current = '';

		foreach ( $pieces as $piece ) {
			if ( 0 !== strpos( $piece, $marker ) ) {
				$current .= $piece;
				continue;
			}

			$index = (int) substr( $piece, strlen( $marker ) );
			$value = isset( $store[ $index ] ) ? $store[ $index ] : '';

			if ( false !== strpos( $value, "\n" ) ) {
				if ( '' !== trim( $current ) ) {
					$out[] = $quote . trim( $current );
					$out[] = rtrim( $quote );
				}
				$out[]   = $quote . $piece;
				$out[]   = rtrim( $quote );
				$current = '';
				continue;
			}

			if ( '' !== $value && '`' === $value[0] && '' !== $current ) {
				// The word it would be glued to, with the addresses parked in it
				// read back: a link whose text does not hold is plain text to a
				// reader, and its address a bare one.
				$word = substr( $current, (int) strrpos( ' ' . $current, ' ' ) );
				$word = (string) preg_replace_callback(
					'/' . $marker . '(\d+)INL/',
					static function ( $matches ) use ( $store ) {
						return isset( $store[ (int) $matches[1] ] ) ? $store[ (int) $matches[1] ] : '';
					},
					$word
				);

				if ( 1 === preg_match( '/' . $marker . '\d+(?:END|INL)$/', $current ) ) {
					$current .= ' ';
				} elseif ( 1 === self::backslashes_before( $current, strlen( $current ) ) % 2 ) {
					$current .= '\\';
				} elseif ( false !== stripos( $word, '://' ) || false !== stripos( $word, 'www.' ) ) {
					$current .= ' ';
				}
			}

			$current .= $piece;
		}

		if ( '' !== trim( $current ) || empty( $out ) ) {
			$out[] = $quote . ( empty( $out ) ? $current : trim( $current ) );
		} elseif ( rtrim( $quote ) === end( $out ) ) {
			array_pop( $out );
		}

		return implode( "\n", $out );
	}

	/**
	 * The address of a link or an image, fit to sit between its parentheses.
	 *
	 * Decoded once, and then every character that ends an address early or
	 * lets it run on is percent-encoded: whitespace and control characters,
	 * the backtick, the angle brackets, both quotes, the backslash, the
	 * parentheses and the pipe.
	 *
	 * @since 2.6.7
	 * @param string $url Address as the attribute carried it.
	 * @return string
	 */
	private static function link_address( $url ) {
		static $map = null;

		if ( null === $map ) {
			$map = array();
			foreach ( array_merge( range( 0, 32 ), array( 127 ), array_map( 'ord', str_split( '`<>"\'\\()|' ) ) ) as $code ) {
				$map[ chr( $code ) ] = sprintf( '%%%02X', $code );
			}
		}

		return strtr( trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) ), $map );
	}

	/**
	 * The pipes of a table cell, each behind an odd number of backslashes.
	 *
	 * A table is cut into cells at every pipe that is not escaped, code spans
	 * included, and `\\|` is an escaped backslash followed by a pipe that cuts.
	 * One backslash was added whatever came before, so a cell or a code sample
	 * that already read `\|` was cut there, and what was left of the sample
	 * landed in the next cell as text.
	 *
	 * @since 2.6.7
	 * @param string $text Cell text.
	 * @return string
	 */
	private static function escape_pipes( $text ) {
		$out  = '';
		$from = 0;
		$at   = strpos( $text, '|' );

		while ( false !== $at ) {
			$out .= substr( $text, $from, $at - $from ) . ( self::backslashes_before( $text, $at ) % 2 ? '\\\\|' : '\\|' );
			$from = $at + 1;
			$at   = strpos( $text, '|', $from );
		}

		return $out . substr( $text, $from );
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
				// render_html_list() reads items with DOM textContent, which decodes
				// entities, and the block is parked here and restored verbatim at the
				// very end, so it never meets the filter applied to the body. A
				// `&lt;script&gt;` inside an <li> reached the document through this
				// door, which is why the filter is applied here too.
				$markdown = self::remove_tag_shapes( self::render_html_list( $matches[0] ) );
				if ( '' === trim( $markdown ) ) {
					return '';
				}
				$store[] = $markdown;
				return "\n\n" . self::$marker . ( count( $store ) - 1 ) . "END\n\n";
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
			$flat = self::keep_replace( '/<li[^>]*>/i', "\n- ", $list_html );
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

			// What is tag-shaped goes here, before the backticks are read (2.6.7):
			// taken out afterwards, it left `` `<b>` `` as two backticks in a row,
			// which open code instead of closing it.
			$own_text = trim( self::remove_tag_shapes( self::keep_replace( '/\s+/', ' ', $own_text ) ) );
			$marker   = $ordered ? ( $counter . '. ' ) : '- ';

			// Align sublists with the start of this item's text so CommonMark
			// keeps them nested (ordered markers need more than two spaces).
			$child_indent = $indent . str_repeat( ' ', strlen( $marker ) );

			// A code block inside the item is its placeholder by now (2.6.7), and
			// it goes on a line of its own under the item, at the indentation of
			// the item's text and between blank lines: where html_to_markdown()
			// puts the block back with that same indentation on every line. Left in
			// the middle of the sentence, the fence would open half-way through a
			// line, which no Markdown reader takes for a code block.
			//
			// The text around it cannot open a fence of its own (2.6.7): an item
			// that read ``` had the fence of the block under it for its closing
			// one, and the sample came out as markup. See settle_line().
			$pieces = preg_split( '/\s*(' . self::$marker . '\d+END)\s*/', $own_text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
			$pieces = is_array( $pieces ) ? $pieces : array( $own_text );
			foreach ( $pieces as $key => $piece ) {
				if ( 0 !== strpos( $piece, self::$marker ) ) {
					$pieces[ $key ] = self::settle_line( $piece );
				}
			}
			$first   = ( isset( $pieces[0] ) && 0 !== strpos( $pieces[0], self::$marker ) ) ? array_shift( $pieces ) : '';
			$lines[] = rtrim( $indent . $marker . $first );

			foreach ( $pieces as $piece ) {
				$lines[] = '';
				$lines[] = $child_indent . $piece;
			}
			if ( ! empty( $pieces ) ) {
				$lines[] = '';
			}
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
	 * @since 2.6.7 The `$store` parameter.
	 *
	 * @param array      $matches Regex matches.
	 * @param array|null $store   The placeholder store of html_to_markdown(), by
	 *                            reference, so a code block inside a cell can be
	 *                            turned into a code span.
	 * @return string
	 */
	private static function convert_table_to_markdown( $matches, &$store = null ) {
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
					$text    = self::escape_pipes( str_replace( array( "\r\n", "\n", "\r" ), ' ', $text ) );
					$cells[] = $text;

					// A code block in the cell is a placeholder that would come back
					// as a fence of several lines, and a row is one line: it is turned
					// into a code span in the store, where the cell keeps pointing.
					if ( is_array( $store ) && preg_match_all( '/' . self::$marker . '(\d+)END/', $text, $parked ) ) {
						foreach ( $parked[1] as $index ) {
							$index = (int) $index;
							if ( isset( $store[ $index ] ) && 0 === strpos( $store[ $index ], '`' ) ) {
								$store[ $index ] = self::fence_to_span( $store[ $index ] );
							}
						}
					}
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

	/**
	 * A fenced block as a code span on one line, for where a block cannot go
	 * (a table cell).
	 *
	 * The fences and the language go, the lines are joined with spaces, and the
	 * delimiter is longer than any run of backticks inside. A pipe is escaped: in
	 * a table it splits the cell even inside a code span. See escape_pipes().
	 *
	 * @since 2.6.7
	 * @param string $block Fenced block as fenced() builds it.
	 * @return string
	 */
	private static function fence_to_span( $block ) {
		$lines = explode( "\n", $block );
		if ( count( $lines ) >= 2 ) {
			array_shift( $lines );
			array_pop( $lines );
		}

		$code = trim( self::keep_replace( '/\s+/', ' ', implode( ' ', $lines ) ) );
		if ( '' === $code ) {
			return '';
		}

		$delimiter = '`';
		if ( preg_match_all( '/`+/', $code, $runs ) ) {
			$delimiter = str_repeat( '`', max( array_map( 'strlen', $runs[0] ) ) + 1 );
		}
		$pad = ( '`' === $code[0] || '`' === substr( $code, -1 ) ) ? ' ' : '';

		return $delimiter . $pad . self::escape_pipes( $code ) . $pad . $delimiter;
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
			header( 'Link: <' . self::header_url( $md_url ) . '>; rel="alternate"; type="text/markdown"', false );
		}
	}

	/**
	 * A URL as it goes into an HTTP header: ASCII only.
	 *
	 * A link under a base that is not ASCII carries it as raw UTF-8
	 * (`/categoría/news.md`), and a header is not the place for that: a client
	 * that reads headers as Latin-1 gets another address. Every byte outside
	 * visible ASCII is percent-encoded, which is the same address. And
	 * esc_url_raw(), not esc_url(): the latter is for HTML and writes `&` as
	 * `&#038;`.
	 *
	 * @since 2.6.7
	 * @param string $url URL.
	 * @return string
	 */
	private static function header_url( $url ) {
		return self::keep_replace_callback(
			'/[^\x21-\x7E]/',
			static function ( $matches ) {
				return rawurlencode( $matches[0] );
			},
			esc_url_raw( (string) $url )
		);
	}

	/**
	 * Announce that this URL answers differently depending on Accept.
	 *
	 * The Vary belongs on every response subject to negotiation, not only on the
	 * negotiated variant (RFC 9110 section 12.5.5). Without it on the HTML side, a
	 * shared cache in front of the site stores the page with no idea the address
	 * has a second form, and later hands that HTML to an agent asking for
	 * markdown.
	 *
	 * Two limits worth knowing. It covers the HTML WordPress generates, not what a
	 * page cache serves straight from disk, because those requests end before
	 * these hooks run. And Cloudflare ignores Vary on HTML except for
	 * Accept-Encoding, so the fix there is a Cache Rule, not this header.
	 *
	 * @since 2.6.1
	 * @return void
	 */
	public static function add_vary_header() {
		if ( ! self::current_request_is_negotiable() ) {
			return;
		}

		self::merge_vary_header( 'Accept' );
	}

	/**
	 * Does the current request answer to content negotiation?
	 *
	 * Unlike resolve_current_markdown_url(), this does not care whether .md URLs
	 * are on: negotiation and .md addresses are separate settings, and a URL that
	 * answers markdown on Accept varies whether or not it also has an address of
	 * its own.
	 *
	 * @since 2.6.1
	 * @return bool
	 */
	private static function current_request_is_negotiable() {
		if ( is_singular() ) {
			$the_post = get_queried_object();
			return ( $the_post instanceof WP_Post && self::is_post_eligible( $the_post ) );
		}

		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			return ( $term instanceof WP_Term && self::is_term_eligible( $term ) );
		}

		return false;
	}

	/**
	 * Add a field name to the Vary header without dropping what is already there.
	 *
	 * Vary is a list, and other plugins put their own fields in it. Replacing the
	 * header outright would discard theirs, and appending a second Vary header
	 * gets folded into the same list anyway, so the values are merged instead.
	 *
	 * @since 2.6.1
	 * @param string $field Field name to announce, e.g. 'Accept'.
	 * @return void
	 */
	private static function merge_vary_header( $field ) {
		if ( headers_sent() ) {
			return;
		}

		$fields = array();

		foreach ( headers_list() as $sent ) {
			if ( 0 !== stripos( $sent, 'vary:' ) ) {
				continue;
			}

			foreach ( explode( ',', substr( $sent, 5 ) ) as $value ) {
				$value = trim( $value );
				if ( '' === $value ) {
					continue;
				}
				// A Vary of * already says the response is uncacheable by anything
				// other than the origin, and nothing can be added to that.
				if ( '*' === $value ) {
					return;
				}
				if ( 0 === strcasecmp( $value, $field ) ) {
					return;
				}
				// Anything that is not a field name (RFC 9110 token) is not ours to
				// rewrite: the header is left exactly as whoever sent it wrote it,
				// and ours is appended as a second one, which means the same list.
				if ( ! preg_match( '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $value ) ) {
					header( 'Vary: ' . $field, false );
					return;
				}
				$fields[] = $value;
			}
		}

		$fields[] = $field;

		header( 'Vary: ' . implode( ', ', $fields ) );
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

		$the_post = get_post( $the_post );

		if ( ! $the_post instanceof WP_Post ) {
			return false;
		}

		$permalink = get_permalink( $the_post );
		$home_url  = home_url( '/' );
		$path      = str_replace( $home_url, '', $permalink );
		$path      = trim( $path, '/' );

		if ( '' === $path ) {
			// A static front page has no path of its own: its permalink IS the home
			// URL, so there is nothing to suffix with .md. Fall back to the page
			// slug, an URL that serve_markdown_by_path() already resolves through
			// get_page_by_path(). The markdown response declares the home URL as
			// its canonical, so the agent still knows this is the front page.
			if ( self::is_front_page_post( $the_post ) && '' !== $the_post->post_name ) {
				return home_url( '/' . $the_post->post_name . '.md' );
			}

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
	 * Answer a `.md` request that is not a read with a plain 405.
	 *
	 * A `.md` is a document to be read: GET and HEAD, as the `Allow` header says.
	 *
	 * @since 2.6.7
	 */
	private static function send_405() {
		status_header( 405 );
		header( 'Allow: GET, HEAD' );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo 'Method not allowed';
		exit;
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
		header( 'X-Content-Type-Options: nosniff' );
		echo 'Not found';
		exit;
	}
}