<?php
/**
 * VigIA — Content access gate for the AI surfaces.
 *
 * Markdown for Agents, llms.txt and the excerpts derived from a body all
 * rebuild a post's content outside the normal template. That is a second
 * representation of the same resource, so it has to answer to the same access
 * rules the HTML page does, exactly as core's REST API controllers do for
 * theirs.
 *
 * WordPress has no authorisation API separate from presentation: `post_password`
 * and the core capabilities are all it offers, and every LMS or membership
 * plugin enforces its own rules by filtering `the_content` or by swapping the
 * template. Anything that rebuilds the content by itself bypasses both unless it
 * asks on purpose, which is what this class is for. Four layers, cheapest first:
 *
 * 1. Post status and password, the two core rules.
 * 2. Post type: the entry types of the known LMS and membership plugins are
 *    withheld unless the site owner opts them in, because their gating lives in
 *    the template and no generic check can see it.
 * 3. Explicit checks against the plugins that expose an access API.
 * 4. `vigia_content_is_public`, so anything unknown can veto.
 *
 * The render helper is the other half: it sets the post context before running
 * `the_content`, which is what lets the many plugins that gate by filtering it
 * do their job at all. Without that, they ask about a post that is not there,
 * conclude nothing needs protecting and hand over the whole body.
 *
 * This mirrors `Native_AEO_Pack_Content_Access` in the Visibility sibling on
 * purpose: the two plugins serve the same surfaces on the same sites, and a
 * visitor should not be able to read something through one that the other
 * withholds. Keep the two contracts in step when either changes.
 *
 * @package VigIA
 * @since 2.4.4
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Access gate shared by every surface that rebuilds post content.
 */
class VigIA_Content_Access {

	/**
	 * Entry post types withheld while their plugin is running, keyed by a symbol
	 * that plugin defines.
	 *
	 * These belong to the LMS and membership plugins whose gating is enforced in
	 * the template rather than in `the_content`, and which expose no access API
	 * this codebase has been able to verify against their source. Rebuilding a
	 * body from the database never goes through a template, so there is no way to
	 * tell one of their free lessons from a paid one: they are withheld whole.
	 *
	 * Sensei is deliberately absent. It gates in the template too, but it does
	 * publish `sensei_can_user_view_lesson()`, verified in `passes_plugin_checks()`,
	 * so its lessons are served or withheld one by one on their own merits and an
	 * open course keeps its Markdown.
	 *
	 * Keying on the plugin matters because the slugs collide: `lesson` and
	 * `course` belong to Sensei and to LifterLMS alike, and only one of the two
	 * can be checked.
	 *
	 * @return array<string,array<int,string>>
	 */
	private static function gated_types_map() {
		return array(
			// Sensei's private messages between student and teacher. Its lessons
			// and quizzes are checked one by one instead (see passes_plugin_checks),
			// but `sensei_can_user_view_lesson()` says nothing about a message, and
			// a message is correspondence, not content anybody asked us to publish.
			'sensei_can_user_view_lesson'               => array( 'sensei_message' ),
			// LearnDash (commercial; not verifiable here).
			'sfwd_lms_has_access'                       => array( 'sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz', 'sfwd-assignment', 'sfwd-essays' ),
			// LifterLMS.
			'llms_page_restricted'                      => array( 'course', 'lesson', 'llms_quiz', 'llms_membership', 'llms_access_plan', 'llms_my_certificate', 'llms_certificate' ),
			// Tutor LMS.
			'tutor_utils'                               => array( 'courses', 'lesson', 'tutor_quiz', 'tutor_assignments' ),
			// Paid Memberships Pro.
			'pmpro_has_membership_access'               => array( 'pmpro_membership_level' ),
			// WooCommerce Memberships (commercial).
			'wc_memberships_is_post_content_restricted' => array( 'wc_membership_plan', 'wc_user_membership' ),
		);
	}

	/**
	 * Is this entry safe to publish on an AI surface?
	 *
	 * Always answered as a logged-out visitor, never as whoever happens to be
	 * making the request. Everything these surfaces produce is public output: a
	 * `.md` document served to whoever asks for the URL, and an llms.txt that
	 * ends up as a file on disk. "Can this visitor read it?" is the wrong
	 * question for all of them, because the answer is handed to everybody; "can
	 * anybody read it?" is the right one.
	 *
	 * Sensei makes the difference concrete: `sensei_all_access()` grants
	 * administrators every lesson, so asking the other way handed an admin the
	 * full body of a paid lesson at its `.md` URL while the HTML page was still
	 * showing them the not-enrolled notice.
	 *
	 * This is the per-entry gate. The post-type one is separate (`is_gated_type()`),
	 * because it belongs to whichever types a surface offers at all, not to
	 * whether one given entry is readable.
	 *
	 * @param WP_Post|int $the_post Post or post ID.
	 * @return bool
	 */
	public static function is_public( $the_post ) {
		$the_post = get_post( $the_post );
		if ( ! $the_post instanceof WP_Post ) {
			return false;
		}

		$is_public = ( 'publish' === $the_post->post_status && '' === $the_post->post_password );

		if ( $is_public ) {
			// Nesting is counted, so a caller that already opened the context (the
			// llms build, which asks this once per entry) pays for it once.
			self::begin_anonymous_context();
			try {
				$is_public = self::passes_plugin_checks( $the_post );
			} finally {
				self::end_anonymous_context();
			}
		}

		/**
		 * Filters whether an entry may be published on the AI surfaces (Markdown
		 * for Agents, llms.txt, llms-full.txt and the excerpts derived from the
		 * content).
		 *
		 * Returning false withholds it everywhere at once. Use this to teach VigIA
		 * about a membership, LMS or paywall plugin it does not know: these
		 * surfaces rebuild the content outside the template, so gating that lives
		 * in a template or in a conditional `the_content` filter cannot be seen
		 * from here.
		 *
		 * @since 2.4.4
		 *
		 * @param bool    $is_public Whether the entry may be published.
		 * @param WP_Post $the_post  The entry.
		 */
		return (bool) apply_filters( 'vigia_content_is_public', $is_public, $the_post );
	}

	/**
	 * Is this entry a page that shows whoever visits it their own session?
	 *
	 * The cart, the checkout and My Account of a store are not content. What they
	 * show belongs to the visitor (their basket, their order, their details), and a
	 * Markdown document is built once and kept for twelve hours for everybody, so
	 * there is no document to make of them: the one built for the first visitor
	 * would be handed to the next. WooCommerce treats them the same way itself: for
	 * these three pages `WC_Cache_Helper::prevent_caching()`, on `wp_headers`,
	 * defines `DONOTCACHEPAGE` and adds core's no-cache headers
	 * (`includes/class-wc-cache-helper.php:30`, `:62-67`, `:75-82` and `:276` in
	 * 11.1.2).
	 *
	 * They are recognised by the ID WooCommerce has assigned to each and also by
	 * what the page carries: the shortcodes `woocommerce_cart`,
	 * `woocommerce_checkout` and `woocommerce_my_account`, and the blocks
	 * `woocommerce/cart`, `woocommerce/checkout` and `woocommerce/classic-shortcode`.
	 * The ID alone is not enough. In a translated store the page of the other
	 * language is not the one `wc_get_page_id()` returns, and a second checkout
	 * page made with the shortcode is a checkout all the same.
	 *
	 * Only WooCommerce is known here. For any other store, membership or account
	 * page, `vigia_content_is_public` is the filter that says no.
	 *
	 * Twin of `Native_AEO_Pack_Content_Access::is_visitor_page()` in the Visibility
	 * sibling, where each of the three traps below was measured.
	 *
	 * @since 2.6.7
	 *
	 * @param WP_Post|int $the_post Post or post ID.
	 * @return bool
	 */
	public static function is_visitor_page( $the_post ) {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return false;
		}

		$the_post = get_post( $the_post );
		if ( ! $the_post instanceof WP_Post ) {
			return false;
		}

		// `wc_get_page_id()` gives -1 when no page is assigned: only a real ID counts.
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $vigia_page ) {
			$vigia_page_id = (int) wc_get_page_id( $vigia_page );
			if ( $vigia_page_id > 0 && $vigia_page_id === (int) $the_post->ID ) {
				return true;
			}
		}

		// Not has_shortcode(): it also counts a shortcode written between double
		// brackets, which is an example on show that WordPress prints and does not
		// run (`do_shortcode_tag()`, `wp-includes/shortcodes.php:396` in 7.1.2). A
		// tutorial about the cart is not a cart.
		$vigia_shortcodes = array_filter( array( 'woocommerce_cart', 'woocommerce_checkout', 'woocommerce_my_account' ), 'shortcode_exists' );
		if ( ! empty( $vigia_shortcodes )
			&& false !== strpos( $the_post->post_content, '[' )
			&& preg_match_all( '/' . get_shortcode_regex( $vigia_shortcodes ) . '/', $the_post->post_content, $vigia_found, PREG_SET_ORDER ) ) {
			foreach ( $vigia_found as $vigia_match ) {
				if ( '[' !== $vigia_match[1] || ']' !== $vigia_match[6] ) {
					return true;
				}
			}
		}

		// Not has_block(): it looks for the delimiter written with a single space
		// (`wp-includes/blocks.php:931` in 7.1.2), and the parser that renders the
		// block takes any white space there.
		if ( 1 === preg_match( '#<!--\s+wp:woocommerce/(?:cart|checkout|classic-shortcode)\s#', $the_post->post_content ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Is this post type withheld from the AI surfaces? See gated_types_map().
	 *
	 * Only while a known LMS or membership plugin is running: a site with a
	 * `course` type of its own, unrelated to any of them, is left alone.
	 *
	 * Surfaces use this to drop these types from what they serve, and the
	 * settings screen to explain why they are not on offer. A site that really
	 * wants them published can allow them back through `vigia_content_is_public`,
	 * which is a deliberate enough act to be the right escape hatch for it.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function is_gated_type( $post_type ) {
		if ( '' === (string) $post_type ) {
			return false;
		}

		foreach ( self::gated_types_map() as $vigia_symbol => $vigia_types ) {
			if ( function_exists( $vigia_symbol ) && in_array( $post_type, $vigia_types, true ) ) {
				return true;
			}
		}

		// MemberPress ships classes rather than helper functions.
		if ( class_exists( 'MeprRule' ) && in_array( $post_type, array( 'memberpressproduct', 'memberpressgroup' ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Post types an AI surface may offer at all.
	 *
	 * Public, addressable on the front end, not attachments, and not gated. A type
	 * registered public purely to get an admin UI, with no URL of its own, has no
	 * business here: serving its `.md` would invent a public address for something
	 * the site itself never shows.
	 *
	 * "Addressable" is computed here rather than taken from `is_post_type_viewable()`
	 * on purpose. That function ends in an `is_post_type_viewable` filter, and
	 * plugins use it to get the block editor to treat an internal type as visible:
	 * Sensei does exactly that for its email templates, so in an admin request the
	 * function answers true for a type with no front end at all. The expression
	 * below is core's own rule minus the filter, which is the question actually
	 * being asked.
	 *
	 * Both surfaces and the settings screens read the list from here, so the
	 * checkboxes on offer and the types actually served cannot drift apart.
	 *
	 * @return array<int,string>
	 */
	public static function servable_post_types() {
		$objects = get_post_types( array( 'public' => true ), 'objects' );
		unset( $objects['attachment'] );

		$servable = array();
		foreach ( $objects as $vigia_type => $vigia_object ) {
			$addressable = $vigia_object->publicly_queryable
				|| ( $vigia_object->_builtin && $vigia_object->public );

			if ( ! $addressable || self::is_gated_type( $vigia_type ) ) {
				continue;
			}
			$servable[] = $vigia_type;
		}

		return $servable;
	}

	/**
	 * The inverse of `is_gated_type()`, as a callable for array_filter().
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function is_servable_type( $post_type ) {
		return ! self::is_gated_type( $post_type );
	}

	/**
	 * The gated post types actually in play on this site, for the settings screens
	 * to explain why they are not on offer.
	 *
	 * @return array<int,string>
	 */
	public static function gated_types_in_use() {
		$types = array();

		foreach ( self::gated_types_map() as $vigia_symbol => $vigia_types ) {
			if ( ! function_exists( $vigia_symbol ) ) {
				continue;
			}
			foreach ( $vigia_types as $vigia_type ) {
				if ( post_type_exists( $vigia_type ) ) {
					$types[ $vigia_type ] = true;
				}
			}
		}

		if ( class_exists( 'MeprRule' ) ) {
			foreach ( array( 'memberpressproduct', 'memberpressgroup' ) as $vigia_mepr_type ) {
				if ( post_type_exists( $vigia_mepr_type ) ) {
					$types[ $vigia_mepr_type ] = true;
				}
			}
		}

		return array_keys( $types );
	}

	/**
	 * Human-readable labels for `gated_types_in_use()`, ready to print.
	 *
	 * @return array<int,string>
	 */
	public static function gated_type_labels() {
		$labels = array();

		foreach ( self::gated_types_in_use() as $vigia_type ) {
			$object = get_post_type_object( $vigia_type );
			$labels[] = ( $object && isset( $object->labels->name ) ) ? $object->labels->name : $vigia_type;
		}

		sort( $labels );

		return $labels;
	}

	/**
	 * Explicit checks against the plugins that expose an access API.
	 *
	 * Only the ones whose signature has been verified against their source are
	 * called here; everything else is covered by the post-type gate, by the post
	 * context the render helper sets up, or by the filter.
	 *
	 * @param WP_Post $the_post Post.
	 * @return bool
	 */
	private static function passes_plugin_checks( $the_post ) {
		// Sensei LMS. Its gating is in the Learning Mode templates, so nothing
		// else here would notice it. Verified against Sensei LMS 4.26.1.
		if ( function_exists( 'sensei_can_user_view_lesson' )
			&& in_array( $the_post->post_type, array( 'lesson', 'quiz' ), true )
			&& ! sensei_can_user_view_lesson( $the_post->ID, get_current_user_id() ) ) {
			return false;
		}

		// Members, by Justin Tadlock. It does filter `the_content`, so the render
		// context already covers the body, but asking outright also keeps the
		// entry out of the llms.txt listing and out of the derived excerpts.
		// Verified against Members 3.2.
		if ( function_exists( 'members_can_user_view_post' )
			&& ! members_can_user_view_post( get_current_user_id(), $the_post->ID ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Nesting depth of the anonymous context.
	 *
	 * The anonymous context covers the user, not the session a cookie brings: it
	 * takes the logged-in user away and leaves the cookies where they were, and a
	 * block that paints from one of them (the basket and the notices a store ties
	 * to its session cookie, the currency or the language another plugin
	 * remembers) paints it all the same. So "is what comes out the same for every
	 * visitor?" is not answered by this context alone, as this comment used to
	 * claim. That second question is `is_shareable_request()`: a Markdown document
	 * is built in the anonymous context always, and stored for everybody only when
	 * the request carried no cookies and nobody was logged in.
	 *
	 * @var int
	 */
	private static $anonymous_depth = 0;

	/**
	 * User ID to restore when the outermost anonymous context ends.
	 *
	 * @var int
	 */
	private static $anonymous_restore_id = 0;

	/**
	 * Evaluate everything from here on as a logged-out visitor.
	 *
	 * Every AI surface is built for an audience of one kind: whoever asks for the
	 * URL, and that is the same document for everybody. llms.txt and llms-full.txt
	 * are written to disk and then served by the web server without WordPress
	 * running at all, yet they are built by whoever pressed the button in wp-admin
	 * or by cron; and a `.md` URL is answered on the spot to whoever requests it.
	 * So if the gate were evaluated as "can the current user read this?", an
	 * administrator opening a `.md` URL, or the admin request that writes the
	 * physical file, would publish content the site itself withholds.
	 *
	 * Running as user 0 also covers the plugins this class does not know by name:
	 * a membership plugin filtering `the_content` sees an anonymous visitor and
	 * withholds the body by itself, without VigIA having to recognise it.
	 *
	 * Always pair with `end_anonymous_context()`; nesting is counted, so an inner
	 * pair does not restore the user early.
	 */
	public static function begin_anonymous_context() {
		if ( 0 === self::$anonymous_depth ) {
			self::$anonymous_restore_id = get_current_user_id();
			if ( 0 !== self::$anonymous_restore_id ) {
				wp_set_current_user( 0 );
			}
		}

		++self::$anonymous_depth;
	}

	/**
	 * Restore the user suspended by `begin_anonymous_context()`.
	 */
	public static function end_anonymous_context() {
		if ( 0 === self::$anonymous_depth ) {
			return;
		}

		--self::$anonymous_depth;

		if ( 0 === self::$anonymous_depth && 0 !== self::$anonymous_restore_id ) {
			wp_set_current_user( self::$anonymous_restore_id );
			self::$anonymous_restore_id = 0;
		}
	}

	/**
	 * Nesting depth of the neutral query context.
	 *
	 * @since 2.6.7
	 *
	 * @var int
	 */
	private static $neutral_depth = 0;

	/**
	 * What `begin_neutral_query()` set aside: the main query, its reference copy,
	 * the global post, the address of the request and the `WP` object, in that
	 * order. The address is null when it was left alone, and the object when
	 * there was none.
	 *
	 * @since 2.6.7
	 *
	 * @var array<int,mixed>
	 */
	private static $neutral_restore = array();

	/**
	 * Build what follows outside the page the request is for.
	 *
	 * A Markdown document answers at two addresses, and both keep it in the same
	 * transient, so the one asked first decided what the other served for the next
	 * twelve hours. They did not build the same thing. Both run on
	 * `template_redirect`, but with a different main query behind them: the page
	 * asked with `Accept: text/markdown` is a page view, where `is_singular()` is
	 * true, and the plugins that add their share buttons or a summary box to the
	 * content of a page took the build for one and added them to the document. The
	 * `.md` address is a rewrite rule whose two query variables `WP_Query` does not
	 * know, so its main query is the blog index (`is_home()`, with the latest posts
	 * in it: `wp-includes/class-wp-query.php:1051-1054` and, with a static front
	 * page, `:1058-1077` in 7.1.2), and a query block set to inherit listed those
	 * posts inside the document of a page. Measured on a test site: the same entry
	 * came out at 2,406 bytes built at one address and 5,369 at the other, and 78 of
	 * 131 documents differed.
	 *
	 * The document is the entry, not the page around it, so every one is built
	 * with an empty main query and no global post. Both query globals are swapped,
	 * the reference copy included. A shortcode that ends its own loop with
	 * `wp_reset_query()` copies that one back (`wp-includes/query.php:114-117`),
	 * and half-way through the content the page view would return.
	 *
	 * The address of the request goes the same way. Blocks build links to "this
	 * page" with `add_query_arg()`, which reads it
	 * (`wp-includes/functions.php:1147` and `:1153`): the add to cart link of a
	 * product listing, the next page of a query. Left alone, the document asked at
	 * `/my-courses.md` linked to `/my-courses.md?query-0-page=2` and the one asked
	 * at `/my-courses/` to `/my-courses/?query-0-page=2`. While the document is
	 * built the request is for the page it belongs to, whichever address was
	 * asked. A missing key is left missing, though WordPress makes sure there is
	 * one (`wp_fix_server_vars()`, `wp-includes/load.php:34`).
	 *
	 * The `WP` object goes too, but as a copy: the one the request has, with what
	 * the request resolved taken off (`query_vars`, `query_string`, `request`,
	 * `matched_rule`, `matched_query` and `did_permalink`, which `parse_request()`
	 * and `build_query_string()` write, `wp-includes/class-wp.php:136` and `:609`).
	 * A shortcode that decides with those painted a different thing at each
	 * address: WooCommerce picks the section of My Account from `$wp->query_vars`.
	 *
	 * A new object will not do, because `init` has registered query variables in
	 * the real one (`register_post_type()` adds its own,
	 * `wp-includes/class-wp-post-type.php:715`). `url_to_postid()` drops the
	 * variables it does not find there (`wp-includes/rewrite.php:639`), and core
	 * calls it for every link it embeds (`wp-includes/embed.php:684`, reached
	 * through `pre_oembed_result`, `wp-includes/default-filters.php:768`): inside a
	 * new object the address of a product resolved to the static front page. That
	 * was measured in the Visibility sibling, whose
	 * `Native_AEO_Pack_Content_Access::begin_neutral_query()` this is the twin of.
	 *
	 * Always pair with `end_neutral_query()`; nesting is counted.
	 *
	 * @since 2.6.7
	 *
	 * @param mixed $address URL of the page the document belongs to. Anything that
	 *                       is not a URL leaves the request address alone.
	 */
	public static function begin_neutral_query( $address = '' ) {
		if ( 0 === self::$neutral_depth ) {
			// A copy, so the address can go back exactly as it came. It is kept and
			// put back, never used.
			$server = $_SERVER;

			self::$neutral_restore = array(
				isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null,
				isset( $GLOBALS['wp_the_query'] ) ? $GLOBALS['wp_the_query'] : null,
				isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null,
				null,
				null,
			);

			$blank = new WP_Query();

			$GLOBALS['wp_query']     = $blank; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored in end_neutral_query(); a document is not built as the page view of the request.
			$GLOBALS['wp_the_query'] = $blank; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored in end_neutral_query(); wp_reset_query() copies this one back.
			unset( $GLOBALS['post'] );

			// Not a `new WP()`: see above. A copy keeps the query variables `init`
			// registered and loses only what the request resolved, and what a shortcode
			// writes into it does not reach the real one. With no `$wp` there is nothing
			// to copy, and none is made.
			if ( isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof WP ) {
				self::$neutral_restore[4] = $GLOBALS['wp'];

				$unresolved = clone $GLOBALS['wp'];

				// Unset before assigning. If another plugin holds a PHP reference to one
				// of these properties of the real object, the clone shares that reference,
				// and assigning to it would write into the real one too; once unset, the
				// property of the clone is no longer bound to it. The class has no magic
				// methods, so the assignment below simply sets it again.
				unset( $unresolved->query_vars, $unresolved->query_string, $unresolved->request, $unresolved->matched_rule, $unresolved->matched_query, $unresolved->did_permalink );

				$unresolved->query_vars    = array();
				$unresolved->query_string  = '';
				$unresolved->request       = '';
				$unresolved->matched_rule  = '';
				$unresolved->matched_query = '';
				$unresolved->did_permalink = false;

				$GLOBALS['wp'] = $unresolved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored in end_neutral_query(); the two addresses of a document resolve different things, so both build with a copy that has none of what the request resolved.
			}

			$request_uri = self::request_uri_of( $address );
			if ( '' !== $request_uri && isset( $server['REQUEST_URI'] ) ) {
				self::$neutral_restore[3] = $server['REQUEST_URI'];
				// Slashed, as WordPress keeps every value of this array
				// (`wp_magic_quotes()`, `wp-includes/load.php:1285`).
				$_SERVER['REQUEST_URI'] = wp_slash( $request_uri );
			}
		}

		++self::$neutral_depth;
	}

	/**
	 * An address as a request for it would name it: its path and its query.
	 *
	 * @since 2.6.7
	 *
	 * @param mixed $address URL of a page: absolute, relative to the scheme
	 *                       (`//example.com/cart/`) or to the root (`/cart/`).
	 * @return string '' when it is not a URL.
	 */
	public static function request_uri_of( $address ) {
		if ( ! is_string( $address ) ) {
			return '';
		}

		// Cut by hand and not with wp_parse_url(): parse_url() turns some bytes of
		// raw UTF-8 into underscores, and the address of a term under a non-ASCII
		// base is exactly that (`/categoría/` came back as `/categor_a/`).
		$request_uri = preg_replace( '~^(?:[a-z][a-z0-9+.\-]*:)?//[^/?#]+~i', '', $address, 1, $count );
		if ( ! is_string( $request_uri ) ) {
			return '';
		}

		// A plugin that makes the permalinks relative to the root hands out `/cart/`
		// and not `https://example.com/cart/`: with no host to take off it is already
		// a path and a query, and the rest goes the same way. Anything else with no
		// host, `cart/` or a bare word, is not an address.
		if ( 0 === $count && 0 !== strpos( $address, '/' ) ) {
			return '';
		}

		// A request carries no fragment.
		$request_uri = substr( $request_uri, 0, strcspn( $request_uri, '#' ) );

		// One slash at the start, whatever came. `https://example.com` and
		// `https://example.com?p=1` have no path. A site whose home address is stored
		// with a slash at the end hands out `https://example.com//page/`, which is its
		// own address and has to stay one (rejecting it left such a site with no
		// Markdown on `Accept` at all). And a browser reads `//x` and `/\x` as another
		// domain, so what goes into `REQUEST_URI` starts with neither.
		$request_uri = '/' . ltrim( $request_uri, '/\\' );

		// Nothing that comes from a permalink carries a control character. The
		// callers hand over the address of the page itself, so this is a hardening.
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $request_uri ) ) {
			return '';
		}

		return $request_uri;
	}

	/**
	 * Put back what `begin_neutral_query()` set aside.
	 *
	 * @since 2.6.7
	 */
	public static function end_neutral_query() {
		if ( 0 === self::$neutral_depth ) {
			return;
		}

		--self::$neutral_depth;

		if ( 0 !== self::$neutral_depth ) {
			return;
		}

		list( $query, $the_query, $the_post, $request_uri, $wp_object ) = self::$neutral_restore;
		self::$neutral_restore                                          = array();

		$GLOBALS['wp_query']     = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the main query set aside in begin_neutral_query().
		$GLOBALS['wp_the_query'] = $the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the main query set aside in begin_neutral_query().

		if ( null !== $wp_object ) {
			$GLOBALS['wp'] = $wp_object; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the WP object set aside in begin_neutral_query().
		}

		if ( null !== $request_uri ) {
			$_SERVER['REQUEST_URI'] = $request_uri;
		}

		if ( $the_post instanceof WP_Post ) {
			$GLOBALS['post'] = $the_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the caller's context.
			setup_postdata( $the_post );
		} else {
			unset( $GLOBALS['post'] );
		}
	}

	/**
	 * Is the request one that reads, a GET or a HEAD?
	 *
	 * A document is built for a read and for nothing else. What a page answers to a
	 * submission (the form back with its errors, the thank you message, a notice
	 * the plugin that processed it left behind) is the answer to that one person
	 * and not the document, and it would be stored for everybody. A HEAD is the
	 * GET without its body.
	 *
	 * A request with no method counts as a GET, which is what WP-CLI and a script
	 * run from the command line have.
	 *
	 * @since 2.6.7
	 *
	 * @return bool
	 */
	public static function is_read_request() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		return 'GET' === $method || 'HEAD' === $method;
	}

	/**
	 * Is the request in course for this address, and for nothing else?
	 *
	 * The page of an entry answers at more addresses than its own, and every one
	 * of them reaches `template_redirect` with the same queried object: the page
	 * with a parameter that changes what it shows (`/my-courses/?query-0-page=2` is
	 * the second page of a query block, and with the cache empty that one was built
	 * and stored as the document of everybody, 2,079 bytes instead of the 3,090 of
	 * the first), the second page of an archive, `/feed/`, `/embed/`, an endpoint of
	 * My Account (`/my-account/orders/` is the page `/my-account/`). A parameter
	 * that changes nothing, `?utm_source=` and the like, stays out as well, because
	 * which ones matter is not something to guess. All of those get the web page,
	 * which announces its `.md` in the `Link` header, so nothing is lost.
	 *
	 * `is_shareable_request()` leans on this. A parameter can bring a session with
	 * no cookie at all (WooCommerce clones one from `?session=`, WooCommerce
	 * Multilingual from `?xdomain_data=` or from a POST, which `is_read_request()`
	 * turns away), so whoever lets a parameter through here lets the basket of a
	 * visitor into the copy everybody is served.
	 *
	 * Compared by path and by query, each cut by hand at the first `?` and not with
	 * `wp_parse_url()` (see `request_uri_of()`). The path is compared decoded and
	 * without its outer slashes: WordPress answers `/cart` and `/cart/` alike, and a
	 * browser writes the octets of `/categor%C3%ADa/` in upper case where a
	 * permalink may carry them in lower. Case is kept otherwise, so `/Cart/` is
	 * another request. The query is compared as the string it is: with pretty
	 * permalinks the address has none and the request cannot bring any, and with
	 * plain ones it is `p=12` and nothing else.
	 *
	 * @since 2.6.7
	 *
	 * @param mixed $address URL of the page the document belongs to. Anything that
	 *                       is not a URL is no address, and nothing is a request
	 *                       for it.
	 * @return bool
	 */
	public static function is_request_for( $address ) {
		$own = self::request_uri_of( $address );
		if ( '' === $own ) {
			return false;
		}

		// Read with esc_url_raw() and not with sanitize_text_field(), which drops
		// every `%XX` octet (`wp-includes/formatting.php:5736`).
		$asked = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $asked ) {
			return false;
		}

		$split = static function ( $uri ) {
			// A request carries no fragment, and its query starts at the first `?`.
			$uri = substr( $uri, 0, strcspn( $uri, '#' ) );
			$cut = strcspn( $uri, '?' );

			// The cast is for PHP 7, where substr() gives false past the end.
			return array( trim( rawurldecode( substr( $uri, 0, $cut ) ), '/' ), (string) substr( $uri, $cut + 1 ) );
		};

		list( $own_path, $own_query )     = $split( $own );
		list( $asked_path, $asked_query ) = $split( $asked );

		return $own_path === $asked_path && $own_query === $asked_query;
	}

	/**
	 * Is this a request whose document may be kept for everybody?
	 *
	 * Only when it carries no cookies at all and nobody is logged in. The anonymous
	 * context takes the user away and leaves the cookies where they were, and what
	 * a block paints from one of them belongs to the visitor: a store ties the
	 * basket and its notices to a session cookie and other plugins keep the
	 * currency or the language picked, so a mini-cart or a notices block set inside
	 * the content of an entry painted them into the document, and the document is
	 * stored for twelve hours and handed to everybody. Emptying the WooCommerce
	 * session while the document is built is not safe either: anything that
	 * recalculates the totals in between would save the empty basket into the
	 * session of the visitor who asked. So the rule goes the other way round. A
	 * request with cookies still gets its document, built for it alone and not
	 * stored, and only one without them writes the copy everybody is served.
	 *
	 * The cookies are read from the `Cookie` header the request sent, not from
	 * `$_COOKIE`: a plugin can write into that array on its own (WPML keeps the
	 * current language there on every request, cookie or not), and then no request
	 * would ever look free of them and the document would never be stored. Whoever
	 * asks decides whether to send the header, and neither answer gets a visitor's
	 * data into the copy: with it nothing is stored, and without it the only other
	 * way a session arrives is the address, which never builds a document: see
	 * `is_request_for()`.
	 *
	 * And nobody logged in, because a user can be identified with no cookie at all
	 * (single sign-on by a server header), and a store then loads that customer's
	 * saved basket all the same. The user asked about is the one behind the
	 * request: inside the anonymous context, the one it set aside.
	 *
	 * What this does not cover is whatever changes with no cookie to give it away:
	 * a price that follows the country read from the IP, content chosen by
	 * `Accept-Language`. The copy is then the one the first request without cookies
	 * built. A script or WP-CLI carries none and counts as shareable, unless it runs
	 * as a user.
	 *
	 * @since 2.6.7
	 *
	 * @return bool
	 */
	public static function is_shareable_request() {
		if ( isset( $_SERVER['HTTP_COOKIE'] ) && '' !== $_SERVER['HTTP_COOKIE'] ) {
			return false;
		}

		$user_id = self::$anonymous_depth > 0 ? self::$anonymous_restore_id : get_current_user_id();

		return 0 === $user_id;
	}

	/**
	 * Nesting depth of the session-free render.
	 *
	 * @since 2.6.7
	 *
	 * @var int
	 */
	private static $sessionless_depth = 0;

	/**
	 * Blocks that paint the session of whoever makes the request.
	 *
	 * The ones WooCommerce renders on the server from the basket or the notices
	 * of the session (`WC()->cart` and `wc_print_notices()` in
	 * `src/Blocks/BlockTypes/` of 11.1.2: `MiniCart.php`, its two inner blocks,
	 * `ProductButton.php`, which writes how many of a product are in the basket
	 * into its add to cart button, `StoreNotices.php` and `ClassicShortcode.php`),
	 * plus the cart and the checkout themselves, for when they sit inside a
	 * synced pattern that `is_visitor_page()` cannot see.
	 *
	 * @since 2.6.7
	 *
	 * @return array<int,string>
	 */
	public static function session_blocks() {
		/**
		 * Filters the blocks left out of whatever is built for everybody from a
		 * request that carries a session (llms.txt and llms-full.txt, built from
		 * wp-admin). Add the block of any other plugin that paints the basket, the
		 * notices or the name of the visitor.
		 *
		 * @since 2.6.7
		 *
		 * @param array<int,string> $blocks Block names, with their namespace.
		 */
		return (array) apply_filters(
			'vigia_session_blocks',
			array(
				'woocommerce/mini-cart',
				'woocommerce/mini-cart-footer-block',
				'woocommerce/mini-cart-title-items-counter-block',
				'woocommerce/product-button',
				'woocommerce/store-notices',
				'woocommerce/classic-shortcode',
				'woocommerce/cart',
				'woocommerce/checkout',
			)
		);
	}

	/**
	 * Shortcodes that paint the session of whoever makes the request.
	 *
	 * `shop_messages` prints the notices of the session, and `woocommerce_messages`
	 * is its older name (`includes/class-wc-shortcodes.php:38` and `:50` in
	 * WooCommerce 11.1.2). The other three are the cart, the checkout and My
	 * Account, for when they sit inside a synced pattern.
	 *
	 * @since 2.6.7
	 *
	 * @return array<int,string>
	 */
	public static function session_shortcodes() {
		/**
		 * Filters the shortcodes left out of whatever is built for everybody from a
		 * request that carries a session. See `vigia_session_blocks`.
		 *
		 * @since 2.6.7
		 *
		 * @param array<int,string> $shortcodes Shortcode tags.
		 */
		return (array) apply_filters(
			'vigia_session_shortcodes',
			array(
				'woocommerce_cart',
				'woocommerce_checkout',
				'woocommerce_my_account',
				'shop_messages',
				'woocommerce_messages',
			)
		);
	}

	/**
	 * Render what follows without the pieces that paint a session.
	 *
	 * llms.txt and llms-full.txt are one copy for everybody, written to disk, and
	 * the rule that protects the Markdown documents cannot protect them:
	 * `is_shareable_request()` asks for a request with no cookies, and these files
	 * are built by whoever pressed the button in wp-admin, who always has them.
	 * That request goes through admin-ajax, which WooCommerce counts as a
	 * front-end one and loads the basket for (`includes/class-woocommerce.php:709`
	 * and `:1008-1010` in 11.1.2), so a mini-cart block or a `[shop_messages]` set
	 * inside the content of any entry painted the basket and the notices of that
	 * administrator into the file. Measured: the notice of the session and the
	 * number of items in the basket came out in llms-full.txt, and the notice in
	 * the summary of the entry in llms.txt.
	 *
	 * Emptying the session is not safe (anything that recalculates the totals in
	 * between would save the empty basket for the person who asked), so the pieces
	 * are not rendered instead: the blocks through `pre_render_block`, which core
	 * asks for top-level and inner blocks alike (`wp-includes/blocks.php:2436` and
	 * `wp-includes/class-wp-block.php:605` in 7.1.2), and the shortcodes through
	 * `pre_do_shortcode_tag` (`wp-includes/shortcodes.php:427`). That also reaches
	 * what sits inside a synced pattern, which is rendered the same way.
	 *
	 * Only WooCommerce is known here; `vigia_session_blocks` and
	 * `vigia_session_shortcodes` are there for anything else. What this cannot
	 * cover is a piece nobody listed, or a price that follows the session.
	 *
	 * Always pair with `end_sessionless_render()`; nesting is counted.
	 *
	 * @since 2.6.7
	 */
	public static function begin_sessionless_render() {
		if ( 0 === self::$sessionless_depth ) {
			add_filter( 'pre_render_block', array( __CLASS__, 'skip_session_block' ), 10, 2 );
			add_filter( 'pre_do_shortcode_tag', array( __CLASS__, 'skip_session_shortcode' ), 10, 2 );
		}

		++self::$sessionless_depth;
	}

	/**
	 * Stop leaving out the pieces `begin_sessionless_render()` set aside.
	 *
	 * @since 2.6.7
	 */
	public static function end_sessionless_render() {
		if ( 0 === self::$sessionless_depth ) {
			return;
		}

		--self::$sessionless_depth;

		if ( 0 === self::$sessionless_depth ) {
			remove_filter( 'pre_render_block', array( __CLASS__, 'skip_session_block' ), 10 );
			remove_filter( 'pre_do_shortcode_tag', array( __CLASS__, 'skip_session_shortcode' ), 10 );
		}
	}

	/**
	 * `pre_render_block` callback: render nothing for a block that paints a session.
	 *
	 * @since 2.6.7
	 *
	 * @param string|null $pre_render   What an earlier callback decided, null for none.
	 * @param mixed       $parsed_block The block about to be rendered.
	 * @return string|null
	 */
	public static function skip_session_block( $pre_render, $parsed_block ) {
		if ( is_array( $parsed_block )
			&& isset( $parsed_block['blockName'] )
			&& in_array( $parsed_block['blockName'], self::session_blocks(), true ) ) {
			return '';
		}

		return $pre_render;
	}

	/**
	 * `pre_do_shortcode_tag` callback: render nothing for a shortcode that paints
	 * a session.
	 *
	 * @since 2.6.7
	 *
	 * @param string|false $output What an earlier callback decided, false for none.
	 * @param mixed        $tag    The shortcode tag about to run.
	 * @return string|false
	 */
	public static function skip_session_shortcode( $output, $tag ) {
		if ( is_string( $tag ) && in_array( $tag, self::session_shortcodes(), true ) ) {
			return '';
		}

		return $output;
	}

	/**
	 * Run `the_content` with the post context set up, and restore it afterwards.
	 *
	 * This is the half of the fix that needs no knowledge of any plugin. Most
	 * membership plugins gate by filtering `the_content` and asking `get_the_ID()`
	 * which post they are looking at. Called outside the loop, that returns
	 * nothing, they conclude there is nothing to protect and let the whole body
	 * through. Setting `$GLOBALS['post']` is what makes them work; `setup_postdata()`
	 * alone is not enough, since it prepares the loop variables without assigning
	 * the global.
	 *
	 * @param WP_Post $the_post Post whose content to render.
	 * @return string Rendered HTML.
	 */
	public static function render_content( $the_post ) {
		$the_post = get_post( $the_post );
		if ( ! $the_post instanceof WP_Post ) {
			return '';
		}

		$previous_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

		$GLOBALS['post'] = $the_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below; the point is to give the_content filters the right post.
		setup_postdata( $the_post );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying WordPress core's the_content filter, exactly as the_content() does.
		$html = (string) apply_filters( 'the_content', $the_post->post_content );

		wp_reset_postdata();

		if ( $previous_post instanceof WP_Post ) {
			$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the caller's context.
			setup_postdata( $previous_post );
		} else {
			unset( $GLOBALS['post'] );
		}

		return $html;
	}
}
