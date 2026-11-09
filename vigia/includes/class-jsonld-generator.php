<?php
/**
 * JSON-LD Generator class
 *
 * Generates structured data (JSON-LD) for AI discovery and site identity.
 * Includes WebSite, Organization/Person schemas and AI-specific discovery properties.
 *
 * @package VigIA
 * @since 1.7.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON-LD Generator class
 */
class VigIA_JsonLD_Generator {

	/**
	 * Option name for JSON-LD settings
	 */
	const OPTION_NAME = 'vigia_jsonld_settings';

	/**
	 * Default settings
	 *
	 * @var array
	 */
	private static $defaults = array(
		// Site Identity section.
		'site_identity_enabled' => false,
		'entity_type'           => 'Organization',
		'entity_name'           => '',
		'entity_description'    => '',
		'entity_logo'           => '',
		'entity_url'            => '',
		'search_action'         => false,
		'same_as'               => '',
		// AI Discovery section.
		'ai_discovery_enabled'  => false,
		'ai_discovery_llms'     => true,
		'ai_discovery_llms_full' => true,
		'ai_discovery_markdown' => true,
		// Output page.
		'output_page'           => 'front_page',
	);

	/**
	 * SEO plugins that generate WebSite/Organization schema
	 *
	 * @var array
	 */
	private static $schema_seo_plugins = array(
		'yoast'        => array(
			'name'   => 'Yoast SEO',
			'file'   => 'wordpress-seo/wp-seo.php',
			'schema' => array( 'WebSite', 'Organization', 'Person' ),
		),
		'rankmath'     => array(
			'name'   => 'Rank Math',
			'file'   => 'seo-by-rank-math/rank-math.php',
			'schema' => array( 'WebSite', 'Organization', 'Person' ),
		),
		'aioseo'       => array(
			'name'   => 'All in One SEO',
			'file'   => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
			'schema' => array( 'WebSite', 'Organization', 'Person' ),
		),
		'seopress'     => array(
			'name'   => 'SEOPress',
			'file'   => 'wp-seopress/seopress.php',
			'schema' => array( 'WebSite', 'Organization', 'Person' ),
		),
		'seoframework' => array(
			'name'   => 'The SEO Framework',
			'file'   => 'autodescription/autodescription.php',
			'schema' => array( 'WebSite', 'Organization', 'Person' ),
		),
	);

	/**
	 * Initialize hooks
	 */
	public static function init() {
		$settings = self::get_settings();

		// Only hook output if at least one section is enabled.
		if ( $settings['site_identity_enabled'] || $settings['ai_discovery_enabled'] ) {
			add_action( 'wp_head', array( __CLASS__, 'output_jsonld' ), 99 );
		}

		// When the Visibility sibling prints the WebSite node, the AI Discovery
		// pointers go into that node instead of one of our own. Its graph filter
		// runs in its wp_head (priority 20), after this hook on `wp`.
		if ( $settings['ai_discovery_enabled'] ) {
			add_filter( 'native_aeo_pack_schema_graph', array( __CLASS__, 'add_to_sibling_website' ), 10, 2 );
		}
	}

	/**
	 * Get all settings with defaults
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
		return update_option( self::OPTION_NAME, $settings );
	}

	/**
	 * Get default settings
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return self::$defaults;
	}

	/**
	 * Detect if an active SEO plugin generates conflicting schema
	 *
	 * @return array|false Plugin info array or false if no conflict.
	 */
	public static function detect_schema_conflict() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$conflicts = array();

		foreach ( self::$schema_seo_plugins as $slug => $plugin ) {
			if ( is_plugin_active( $plugin['file'] ) ) {
				$conflicts[] = array(
					'slug'   => $slug,
					'name'   => $plugin['name'],
					'schema' => $plugin['schema'],
				);
			}
		}

		return ! empty( $conflicts ) ? $conflicts : false;
	}

	/**
	 * Check if the current page should output JSON-LD
	 *
	 * @return bool
	 */
	private static function should_output() {
		// Cede the whole JSON-LD output to the Visibility sibling when it emits
		// the Site Identity schema. Both build a #website / #identity node on the
		// same page, so emitting ours too would duplicate the @id graph. The AI
		// Discovery pointers are not lost with it: add_to_sibling_website() puts
		// them into Visibility's WebSite. See VigIA_Sibling_Visibility for the
		// emit/observe split.
		if ( VigIA_Sibling_Visibility::should_defer( 'identity' ) ) {
			return false;
		}

		$settings = self::get_settings();

		// With the WebSite ceded (see build_jsonld()), AI Discovery alone leaves
		// nothing of our own to print: its pointers travel in Visibility's node.
		if ( ! $settings['site_identity_enabled'] && ( ! $settings['ai_discovery_enabled'] || self::website_ceded() ) ) {
			return false;
		}

		$output_page = $settings['output_page'];

		if ( 'front_page' === $output_page ) {
			return is_front_page();
		}

		// Specific page ID.
		$page_id = absint( $output_page );
		if ( $page_id > 0 ) {
			return is_page( $page_id );
		}

		return false;
	}

	/**
	 * Build the JSON-LD data array
	 *
	 * @param array|null $settings Optional settings override (for preview).
	 * @return array JSON-LD graph array.
	 */
	public static function build_jsonld( $settings = null ) {
		if ( null === $settings ) {
			$settings = self::get_settings();
		}

		$site_url = untrailingslashit( home_url() );
		$graph    = array();

		// The WebSite node is the Visibility sibling's when it prints one (from
		// Visibility 3.2.4, on its home page and with its own "Site name"). Ours
		// had the same @id and the site title as its name, so two names for one
		// node. Left out here, and not only when printing, because Visibility
		// decides whether to step back from its own WebSite by building this graph
		// (Native_AEO_Pack_Conflicts::vigia_website_on_home()).
		$website_ceded = self::website_ceded();

		// Site Identity: WebSite schema.
		if ( $settings['site_identity_enabled'] && ! $website_ceded ) {
			$website = array(
				'@type' => 'WebSite',
				'@id'   => $site_url . '/#website',
				'url'   => $site_url . '/',
				'name'  => ! empty( $settings['entity_name'] ) ? $settings['entity_name'] : vigia_get_site_name(),
			);

			if ( ! empty( $settings['entity_description'] ) ) {
				$website['description'] = $settings['entity_description'];
			}

			// SearchAction for site search.
			if ( $settings['search_action'] ) {
				$website['potentialAction'] = array(
					array(
						'@type'       => 'SearchAction',
						'target'      => array(
							'@type'       => 'EntryPoint',
							'urlTemplate' => $site_url . '/?s={search_term_string}',
						),
						'query-input' => 'required name=search_term_string',
					),
				);
			}

			// Publisher reference.
			$website['publisher'] = array(
				'@id' => $site_url . '/#identity',
			);

			$graph[] = $website;
		}

		// Site Identity: the entity, Organization or Person. Printed with the
		// WebSite ceded too: Visibility's WebSite points at it as its publisher.
		if ( $settings['site_identity_enabled'] ) {
			$entity = array(
				'@type' => $settings['entity_type'],
				'@id'   => $site_url . '/#identity',
				'name'  => ! empty( $settings['entity_name'] ) ? $settings['entity_name'] : vigia_get_site_name(),
				'url'   => ! empty( $settings['entity_url'] ) ? $settings['entity_url'] : $site_url . '/',
			);

			if ( ! empty( $settings['entity_description'] ) ) {
				$entity['description'] = $settings['entity_description'];
			}

			if ( ! empty( $settings['entity_logo'] ) ) {
				$entity['logo'] = array(
					'@type' => 'ImageObject',
					'@id'   => $site_url . '/#logo',
					'url'   => $settings['entity_logo'],
				);
				// Also set image for Person type.
				if ( 'Person' === $settings['entity_type'] ) {
					$entity['image'] = array( '@id' => $site_url . '/#logo' );
				}
			}

			// sameAs URLs.
			$same_as = self::parse_same_as( $settings['same_as'] );
			if ( ! empty( $same_as ) ) {
				$entity['sameAs'] = $same_as;
			}

			$graph[] = $entity;
		}

		// AI Discovery: machine-readable content pointers, in our own WebSite.
		// With the WebSite ceded they go into Visibility's instead, through
		// add_to_sibling_website().
		if ( $settings['ai_discovery_enabled'] && ! $website_ceded ) {
			$ai_actions = self::ai_read_actions( $settings, $site_url, false );

			if ( ! empty( $ai_actions ) ) {
				// If site identity is enabled, add to WebSite node.
				if ( $settings['site_identity_enabled'] && ! empty( $graph ) ) {
					// Find WebSite node and add/merge potentialAction.
					foreach ( $graph as &$node ) {
						if ( isset( $node['@type'] ) && 'WebSite' === $node['@type'] ) {
							if ( ! isset( $node['potentialAction'] ) ) {
								$node['potentialAction'] = array();
							}
							$node['potentialAction'] = array_merge( $node['potentialAction'], $ai_actions );
							break;
						}
					}
					unset( $node );
				} else {
					// Standalone WebSite node for AI Discovery only.
					$graph[] = array(
						'@type'           => 'WebSite',
						'@id'             => $site_url . '/#website',
						'url'             => $site_url . '/',
						'name'            => vigia_get_site_name(),
						'potentialAction' => $ai_actions,
					);
				}
			}
		}

		if ( empty( $graph ) ) {
			return array();
		}

		return array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);
	}

	/**
	 * Has the WebSite node been handed over to the Visibility sibling?
	 *
	 * @since 2.8.5
	 *
	 * @return bool
	 */
	public static function website_ceded() {
		return VigIA_Sibling_Visibility::should_defer( 'website' );
	}

	/**
	 * The AI Discovery ReadAction pointers.
	 *
	 * @since 2.8.5 Taken out of build_jsonld(), to be shared with
	 *              add_to_sibling_website().
	 *
	 * @param array  $settings JSON-LD settings.
	 * @param string $site_url Untrailingslashed home URL.
	 * @param bool   $own_only Only what VigIA itself serves, leaving out what it
	 *                         has ceded to Visibility: for Visibility's WebSite,
	 *                         which already announces what Visibility serves.
	 * @return array<int,array<string,mixed>>
	 */
	private static function ai_read_actions( $settings, $site_url, $own_only ) {
		$actions = array();

		// VigIA cedes the two files together, under a single signal.
		$llms_ours = ! $own_only || ! VigIA_Sibling_Visibility::should_defer( 'llms' );

		// LLMs.txt pointer.
		if ( $settings['ai_discovery_llms'] && $llms_ours && VigIA_LLMS_Generator::is_served( 'llms.txt' ) ) {
			$actions[] = array(
				'@type'       => 'ReadAction',
				'target'      => $site_url . '/llms.txt',
				'name'        => 'LLMs.txt',
				'description' => 'Machine-readable content index for LLMs',
			);
		}

		// LLMs-full.txt pointer.
		if ( $settings['ai_discovery_llms_full'] && $llms_ours && VigIA_LLMS_Generator::is_served( 'llms-full.txt' ) ) {
			$actions[] = array(
				'@type'       => 'ReadAction',
				'target'      => $site_url . '/llms-full.txt',
				'name'        => 'LLMs-full.txt',
				'description' => 'Full content index for LLMs',
			);
		}

		// Markdown endpoints pointer. The target is a URL pattern, not a real
		// URL, so it must go inside an EntryPoint as an RFC 6570 urlTemplate
		// (same shape as the SearchAction above). As a bare string target,
		// consumers treat "/{slug}.md" as a literal crawlable URL and report
		// it as a 404 (seen in Google Search Console).
		if ( $settings['ai_discovery_markdown'] ) {
			if ( $own_only ) {
				// Module on, its .md addresses on, and not ceded to Visibility.
				$markdown = VigIA_Markdown_Endpoints::serves_markdown();
			} else {
				$md_settings = get_option( 'vigia_markdown_settings', array() );
				$markdown    = ! empty( $md_settings['enabled'] );
			}

			if ( $markdown ) {
				$actions[] = array(
					'@type'       => 'ReadAction',
					'target'      => array(
						'@type'       => 'EntryPoint',
						'urlTemplate' => $site_url . '/{slug}.md',
						'contentType' => 'text/markdown',
					),
					// Same wording as the Visibility sibling's own ReadAction
					// (class-frontend-schema-identity.php), so an agent reading
					// either plugin's JSON-LD sees one consistent claim.
					'name'        => 'Markdown for agents',
					'description' => 'Each page is also available as clean Markdown at its URL with a .md suffix.',
				);
			}
		}

		return $actions;
	}

	/**
	 * Add the AI Discovery pointers to the Visibility sibling's WebSite node,
	 * when VigIA does not print a WebSite of its own because Visibility does.
	 *
	 * Hooked to `native_aeo_pack_schema_graph`, and only for the graphs where
	 * Visibility prints its site-wide WebSite: 'website' (the home page, with
	 * its identity off or printed on another page) and 'identity' (the page that
	 * carries its identity). The entries and profiles carry a minimal WebSite of
	 * their own when nobody prints the real one, which is not where these go.
	 *
	 * Only what VigIA itself serves goes in, and nothing whose target the node
	 * already announces: Visibility lists what it serves, and running this twice
	 * must not add them twice.
	 *
	 * @since 2.8.5
	 *
	 * @param mixed  $graph   The graph Visibility is about to print.
	 * @param string $context Which graph: website, identity, article, person.
	 * @return mixed The graph, with the pointers added to its WebSite node.
	 */
	public static function add_to_sibling_website( $graph, $context = '' ) {
		if ( ! is_array( $graph ) || ! in_array( $context, array( 'website', 'identity' ), true ) ) {
			return $graph;
		}

		// Our own WebSite carries them when we print one. With the identity
		// ceded we print nothing (should_output()), and Visibility's WebSite
		// comes with its identity.
		if ( ! self::website_ceded() && ! VigIA_Sibling_Visibility::should_defer( 'identity' ) ) {
			return $graph;
		}

		$settings = self::get_settings();
		if ( empty( $settings['ai_discovery_enabled'] ) ) {
			return $graph;
		}

		$actions = self::ai_read_actions( $settings, untrailingslashit( home_url() ), true );
		if ( empty( $actions ) ) {
			return $graph;
		}

		foreach ( $graph as $i => $node ) {
			if ( ! self::is_website_node( $node ) ) {
				continue;
			}

			// A single action may be written as an object rather than a list.
			$existing = isset( $node['potentialAction'] ) ? $node['potentialAction'] : array();
			if ( ! is_array( $existing ) ) {
				$existing = empty( $existing ) ? array() : array( $existing );
			} elseif ( isset( $existing['@type'] ) ) {
				$existing = array( $existing );
			}

			$announced = array();
			foreach ( $existing as $action ) {
				$announced[ self::action_target( $action ) ] = true;
			}

			$added = false;
			foreach ( $actions as $action ) {
				$target = self::action_target( $action );
				if ( isset( $announced[ $target ] ) ) {
					continue;
				}
				$existing[]           = $action;
				$announced[ $target ] = true;
				$added                = true;
			}

			if ( $added ) {
				$graph[ $i ]['potentialAction'] = array_values( $existing );
			}

			// One WebSite per graph.
			break;
		}

		return $graph;
	}

	/**
	 * Is a graph node a WebSite?
	 *
	 * @param mixed $node Graph node.
	 * @return bool
	 */
	private static function is_website_node( $node ) {
		if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) {
			return false;
		}

		$type = $node['@type'];

		return 'WebSite' === $type || ( is_array( $type ) && in_array( 'WebSite', $type, true ) );
	}

	/**
	 * The address an action points at, for comparing two of them: a plain
	 * target, or the template or address inside an EntryPoint.
	 *
	 * @param mixed $action A potentialAction entry.
	 * @return string Empty when it has none that can be compared.
	 */
	private static function action_target( $action ) {
		if ( ! is_array( $action ) || ! isset( $action['target'] ) ) {
			return '';
		}

		$target = $action['target'];
		if ( is_array( $target ) ) {
			if ( isset( $target['urlTemplate'] ) ) {
				$target = $target['urlTemplate'];
			} elseif ( isset( $target['url'] ) ) {
				$target = $target['url'];
			}
		}

		return is_string( $target ) ? trim( $target ) : '';
	}

	/**
	 * Output JSON-LD in wp_head
	 */
	public static function output_jsonld() {
		if ( ! self::should_output() ) {
			return;
		}

		$jsonld = self::build_jsonld();

		if ( empty( $jsonld ) ) {
			return;
		}

		// JSON_HEX_TAG escapes < and > as < / > so a "</script>" that
		// slips into the data (e.g. a site or organization field) cannot break
		// out of the inline script. Slashes stay unescaped to keep URLs readable.
		$json = wp_json_encode( $jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG );

		if ( ! $json ) {
			return;
		}

		// wp_print_inline_script_tag() emits the JSON-LD verbatim inside a
		// <script type="application/ld+json"> with sanitized attributes (and a
		// CSP nonce when one applies), so no manual escaping is needed here and
		// the WordPress.Security.EscapeOutput exception is no longer required.
		wp_print_inline_script_tag( $json, array( 'type' => 'application/ld+json' ) );
	}

	/**
	 * Get JSON-LD preview string (formatted)
	 *
	 * @param array|null $settings Optional settings override.
	 * @return string Formatted JSON string.
	 */
	public static function get_preview( $settings = null ) {
		$jsonld = self::build_jsonld( $settings );

		if ( empty( $jsonld ) ) {
			return '';
		}

		return wp_json_encode( $jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}

	/**
	 * Parse sameAs textarea into array of URLs
	 *
	 * @param string $same_as Raw textarea content.
	 * @return array Validated URLs.
	 */
	public static function parse_same_as( $same_as ) {
		if ( empty( $same_as ) ) {
			return array();
		}

		$lines = explode( "\n", $same_as );
		$urls  = array();

		foreach ( $lines as $line ) {
			$url = trim( $line );
			if ( ! empty( $url ) && filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Check available AI Discovery features
	 *
	 * Returns which features exist and can be pointed to.
	 *
	 * @return array Feature availability status.
	 */
	public static function get_ai_discovery_features() {
		$md_settings = get_option( 'vigia_markdown_settings', array() );

		return array(
			'llms_txt'      => VigIA_LLMS_Generator::is_served( 'llms.txt' ),
			'llms_full_txt' => VigIA_LLMS_Generator::is_served( 'llms-full.txt' ),
			'markdown'      => ! empty( $md_settings['enabled'] ),
		);
	}

	/**
	 * Get published pages for output page selector
	 *
	 * @return array Array of page objects with ID and title.
	 */
	public static function get_pages_for_selector() {
		$pages = get_pages(
			array(
				'post_status' => 'publish',
				'sort_column' => 'post_title',
				'sort_order'  => 'ASC',
			)
		);

		$result = array();
		if ( $pages ) {
			foreach ( $pages as $page ) {
				$result[] = array(
					'id'    => $page->ID,
					'title' => $page->post_title,
				);
			}
		}

		return $result;
	}
}