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
		// same page, so emitting ours too would duplicate the @id graph. This
		// suppresses the AI Discovery ReadAction pointers as well (they hang off a
		// #website node): an acceptable, documented loss, since when the user moves
		// emission to Visibility it owns the identity graph. See
		// VigIA_Sibling_Visibility for the emit/observe split.
		if ( VigIA_Sibling_Visibility::should_defer( 'identity' ) ) {
			return false;
		}

		$settings = self::get_settings();

		if ( ! $settings['site_identity_enabled'] && ! $settings['ai_discovery_enabled'] ) {
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

		// Site Identity: WebSite schema.
		if ( $settings['site_identity_enabled'] ) {
			$website = array(
				'@type' => 'WebSite',
				'@id'   => $site_url . '/#website',
				'url'   => $site_url . '/',
				'name'  => ! empty( $settings['entity_name'] ) ? $settings['entity_name'] : get_bloginfo( 'name' ),
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

			// Entity: Organization or Person.
			$entity = array(
				'@type' => $settings['entity_type'],
				'@id'   => $site_url . '/#identity',
				'name'  => ! empty( $settings['entity_name'] ) ? $settings['entity_name'] : get_bloginfo( 'name' ),
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

		// AI Discovery: machine-readable content pointers.
		if ( $settings['ai_discovery_enabled'] ) {
			$ai_actions = array();

			// LLMs.txt pointer.
			if ( $settings['ai_discovery_llms'] && file_exists( ABSPATH . 'llms.txt' ) ) {
				$ai_actions[] = array(
					'@type'       => 'ReadAction',
					'target'      => $site_url . '/llms.txt',
					'name'        => 'LLMs.txt',
					'description' => 'Machine-readable content index for LLMs',
				);
			}

			// LLMs-full.txt pointer.
			if ( $settings['ai_discovery_llms_full'] && file_exists( ABSPATH . 'llms-full.txt' ) ) {
				$ai_actions[] = array(
					'@type'       => 'ReadAction',
					'target'      => $site_url . '/llms-full.txt',
					'name'        => 'LLMs-full.txt',
					'description' => 'Full content index for LLMs',
				);
			}

			// Markdown endpoints pointer.
			if ( $settings['ai_discovery_markdown'] ) {
				$md_settings = get_option( 'vigia_markdown_settings', array() );
				if ( ! empty( $md_settings['enabled'] ) ) {
					$ai_actions[] = array(
						'@type'       => 'ReadAction',
						'target'      => $site_url . '/{slug}.md',
						'name'        => 'Markdown for Agents',
						'description' => 'Individual posts served as optimized markdown via .md URL endpoints',
					);
				}
			}

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
						'name'            => get_bloginfo( 'name' ),
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
			'llms_txt'      => file_exists( ABSPATH . 'llms.txt' ),
			'llms_full_txt' => file_exists( ABSPATH . 'llms-full.txt' ),
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