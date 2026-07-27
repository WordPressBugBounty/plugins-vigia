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
	 * Note there is deliberately no "may this be cached?" helper: every document
	 * is built inside the anonymous context instead, so what comes out is the
	 * same for every visitor and is always safe to cache or to write to disk.
	 * One rule rather than two.
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
