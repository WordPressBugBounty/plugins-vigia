<?php
/**
 * AI Visibility Analyzer
 *
 * Analyzes the WordPress site for AI visibility signals.
 * Scores the site across 5 categories (100 points total) and generates
 * actionable recommendations based on the current site state.
 *
 * @package VigIA
 * @since   1.8.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visibility Analyzer class
 */
class VigIA_Visibility_Analyzer {

	/**
	 * Priority AI bot names to check in robots.txt
	 *
	 * @var array
	 */
	private static $priority_bots = array(
		'GPTBot',
		'ClaudeBot',
		'Google-Extended',
		'PerplexityBot',
		'CCBot',
		'Bytespider',
	);

	/**
	 * All known AI bot names (extended list)
	 *
	 * @var array
	 */
	private static $all_ai_bots = array(
		'GPTBot',
		'ChatGPT-User',
		'ClaudeBot',
		'Claude-Web',
		'Google-Extended',
		'PerplexityBot',
		'CCBot',
		'Bytespider',
		'Amazonbot',
		'FacebookBot',
		'Meta-ExternalAgent',
		'cohere-ai',
		'Diffbot',
		'Applebot-Extended',
	);

	/**
	 * Known SEO plugins and their main class/function identifiers
	 *
	 * @var array
	 */
	private static $seo_plugins = array(
		'yoast'    => 'wordpress-seo/wp-seo.php',
		'rankmath' => 'seo-by-rank-math/rank-math.php',
		'seopress' => 'wp-seopress/seopress.php',
		'tsf'      => 'autodescription/autodescription.php',
		'aioseo'   => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
	);

	/**
	 * AyudaWP ecosystem plugins
	 *
	 * @var array
	 */
	private static $ayudawp_plugins = array(
		'ai-content-signals'       => 'ai-content-signals/ai-content-signals.php',
		'ai-share-summarize'       => 'ai-share-summarize/ai-share-summarize.php',
		'native-sitemap-customizer' => 'native-sitemap-customizer/native-sitemap-customizer.php',
		'noindexer'                => 'noindexer/noindexer.php',
	);

	/**
	 * Run the full analysis for a given URL.
	 *
	 * @param string $url The URL to analyze (defaults to homepage).
	 * @return array Full analysis results with categories, scores, and recommendations.
	 */
	public static function analyze( $url = '' ) {
		if ( empty( $url ) ) {
			$url = home_url( '/' );
		}

		// Determine if this is the homepage.
		$home_url    = untrailingslashit( home_url() );
		$analyze_url = untrailingslashit( $url );
		$is_homepage = ( $analyze_url === $home_url || trailingslashit( $analyze_url ) === trailingslashit( $home_url ) );

		// 1. Page HTML: use cache if available (expensive HTTP fetch).
		$page_cache_key = self::get_page_cache_key( $url );
		$page_data      = get_transient( $page_cache_key );

		if ( false === $page_data ) {
			$page_data = self::fetch_and_parse_html( $url, $is_homepage );
			if ( is_wp_error( $page_data ) ) {
				return array(
					'success' => false,
					'error'   => $page_data->get_error_message(),
				);
			}
			set_transient( $page_cache_key, $page_data, DAY_IN_SECONDS );
		}

		// 2. WP internal data: always fresh (plugin states, features, files).
		$wp_data = self::gather_wp_internal_data();

		// 3. Scoring: always fresh (uses cached page data + fresh wp_data).
		$categories = array();
		$categories['access']      = self::analyze_access( $wp_data, $page_data );
		$categories['structured']  = self::analyze_structured_data( $page_data );
		$categories['content']     = self::analyze_content_structure( $page_data );
		$categories['interaction'] = self::analyze_ai_interaction( $page_data, $wp_data );
		$categories['performance'] = self::analyze_performance( $page_data );

		// Calculate totals.
		$total_points = 0;
		$total_max    = 0;
		foreach ( $categories as $cat ) {
			$total_points += $cat['points'];
			$total_max    += $cat['maxPoints'];
		}
		$total_points = round( $total_points, 1 );

		// Determine grade.
		$grade   = self::calculate_grade( $total_points );
		$message = self::get_grade_message( $grade );

		// 4. Recommendations: always fresh.
		$recommendations = self::build_recommendations( $categories, $wp_data );

		return array(
			'success'         => true,
			'url'             => $url,
			'is_homepage'     => $is_homepage,
			'points'          => $total_points,
			'maxPoints'       => $total_max,
			'grade'           => $grade,
			'message'         => $message,
			'categories'      => $categories,
			'recommendations' => $recommendations,
			'ttfb'            => isset( $page_data['ttfb'] ) ? $page_data['ttfb'] : 0,
			'timestamp'       => current_time( 'timestamp' ), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		);
	}

	/**
	 * Fetch a URL and parse its HTML for relevant signals.
	 *
	 * @param string $url         URL to fetch.
	 * @param bool   $is_homepage Whether this is the site homepage.
	 * @return array|WP_Error Parsed page data or error.
	 */
	private static function fetch_and_parse_html( $url, $is_homepage ) {
		$start_time = microtime( true );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => 'VigIA AI Visibility Analyzer/' . VIGIA_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')',
				'sslverify'  => false,
				'redirection' => 5,
				'headers'    => array(
					'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language' => 'en,es;q=0.5',
				),
			)
		);

		$ttfb = round( ( microtime( true ) - $start_time ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'fetch_failed',
				/* translators: %s: error message */
				sprintf( __( 'Could not connect to the site: %s', 'vigia' ), $response->get_error_message() )
			);
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		if ( $http_code >= 400 ) {
			return new WP_Error(
				'http_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'The site returned HTTP error %d', 'vigia' ), $http_code )
			);
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return new WP_Error( 'empty_response', __( 'The site returned an empty response', 'vigia' ) );
		}

		$data = array(
			'is_homepage' => $is_homepage,
			'ttfb'        => $ttfb,
			'html'        => $html,
		);

		// Parse all HTML signals.
		$data = array_merge( $data, self::parse_lang( $html ) );
		$data = array_merge( $data, self::parse_meta_description( $html ) );
		$data = array_merge( $data, self::parse_canonical( $html ) );
		$data = array_merge( $data, self::parse_meta_robots( $html ) );
		$data = array_merge( $data, self::parse_open_graph( $html ) );
		$data = array_merge( $data, self::parse_twitter_cards( $html ) );
		$data = array_merge( $data, self::parse_jsonld( $html ) );
		$data = array_merge( $data, self::parse_headings( $html ) );
		$data = array_merge( $data, self::parse_semantic_html( $html ) );
		$data = array_merge( $data, self::parse_images( $html ) );
		$data = array_merge( $data, self::parse_content_ratio( $html ) );
		$data = array_merge( $data, self::parse_js_dependency( $html ) );
		$data = array_merge( $data, self::parse_ai_share_links( $html ) );
		$data = array_merge( $data, self::parse_markdown_delivery( $html ) );

		return $data;
	}

	// ------------------------------------------------------------------
	// HTML Parsers
	// ------------------------------------------------------------------

	/**
	 * Parse lang attribute from HTML
	 *
	 * @param string $html HTML content.
	 * @return array
	 */
	private static function parse_lang( $html ) {
		$lang = '';
		if ( preg_match( '/<html[^>]+lang=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$lang = $m[1];
		}
		return array( 'lang' => $lang );
	}

	/**
	 * Parse meta description
	 *
	 * @param string $html HTML content.
	 * @return array
	 */
	private static function parse_meta_description( $html ) {
		$desc = '';
		if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/i', $html, $m ) ) {
			$desc = $m[1];
		} elseif ( preg_match( '/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']description["\'][^>]*>/i', $html, $m ) ) {
			$desc = $m[1];
		}
		return array(
			'meta_description'        => $desc,
			'meta_description_length' => mb_strlen( $desc ),
		);
	}

	/**
	 * Parse canonical URL
	 *
	 * @param string $html HTML content.
	 * @return array
	 */
	private static function parse_canonical( $html ) {
		$canonical = '';
		if ( preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$canonical = $m[1];
		} elseif ( preg_match( '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']canonical["\']/i', $html, $m ) ) {
			$canonical = $m[1];
		}
		return array( 'canonical' => $canonical );
	}

	/**
	 * Parse meta robots directives (noai, noimageai)
	 *
	 * @param string $html HTML content.
	 * @return array
	 */
	private static function parse_meta_robots( $html ) {
		$robots = '';
		if ( preg_match( '/<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/i', $html, $m ) ) {
			$robots = $m[1];
		} elseif ( preg_match( '/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']robots["\'][^>]*>/i', $html, $m ) ) {
			$robots = $m[1];
		}
		$lower = strtolower( $robots );
		return array(
			'meta_robots'    => $robots,
			'has_noai'       => ( false !== strpos( $lower, 'noai' ) ),
			'has_noimageai'  => ( false !== strpos( $lower, 'noimageai' ) ),
		);
	}

	/**
	 * Parse Open Graph tags
	 *
	 * @param string $html HTML content.
	 * @return array
	 */
	private static function parse_open_graph( $html ) {
		$tags   = array( 'og:title', 'og:description', 'og:image', 'og:type', 'og:url' );
		$result = array();
		foreach ( $tags as $tag ) {
			$result[ $tag ] = false;
			$escaped        = preg_quote( $tag, '/' );
			if ( preg_match( '/<meta[^>]+property=["\']' . $escaped . '["\'][^>]+content=["\']([^"\']+)["\']/i', $html ) ) {
				$result[ $tag ] = true;
			} elseif ( preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']' . $escaped . '["\']/i', $html ) ) {
				$result[ $tag ] = true;
			}
		}
		return array( 'og' => $result );
	}

	/**
	 * Parse Twitter Card tags
	 *
	 * @param string $html HTML content.
	 * @return array
	 */
	private static function parse_twitter_cards( $html ) {
		$tags   = array( 'twitter:card', 'twitter:title', 'twitter:description' );
		$result = array();
		foreach ( $tags as $tag ) {
			$result[ $tag ] = false;
			$escaped        = preg_quote( $tag, '/' );
			if ( preg_match( '/<meta[^>]+(?:name|property)=["\']' . $escaped . '["\'][^>]+content=["\']([^"\']+)["\']/i', $html ) ) {
				$result[ $tag ] = true;
			} elseif ( preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:name|property)=["\']' . $escaped . '["\']/i', $html ) ) {
				$result[ $tag ] = true;
			}
		}
		return array( 'twitter' => $result );
	}

	/**
	 * Parse JSON-LD structured data
	 *
	 * @param string $html HTML content.
	 * @return array
	 */
	private static function parse_jsonld( $html ) {
		$types = array();
		if ( preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $matches ) ) {
			foreach ( $matches[1] as $raw ) {
				$decoded = json_decode( trim( $raw ), true );
				if ( ! $decoded ) {
					continue;
				}
				if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
					foreach ( $decoded['@graph'] as $item ) {
						if ( isset( $item['@type'] ) ) {
							$item_types = is_array( $item['@type'] ) ? $item['@type'] : array( $item['@type'] );
							$types      = array_merge( $types, $item_types );
						}
					}
				} elseif ( isset( $decoded['@type'] ) ) {
					$item_types = is_array( $decoded['@type'] ) ? $decoded['@type'] : array( $decoded['@type'] );
					$types      = array_merge( $types, $item_types );
				}
			}
		}
		return array( 'jsonld_types' => array_values( array_unique( $types ) ) );
	}

	/**
	 * Parse heading structure
	 *
	 * @param string $html HTML content.
	 * @return array
	 */
	private static function parse_headings( $html ) {
		$headings = array( 'h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0 );
		$order    = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			if ( preg_match_all( '/<h' . $i . '[\s>]/i', $html, $hm ) ) {
				$headings[ 'h' . $i ] = count( $hm[0] );
				for ( $j = 0; $j < count( $hm[0] ); $j++ ) {
					$order[] = $i;
				}
			}
		}

		// Check hierarchy (no jumps like H1 -> H4).
		$hierarchy_ok = true;
		$prev_level   = 0;
		foreach ( $order as $level ) {
			if ( $prev_level > 0 && $level > $prev_level + 1 ) {
				$hierarchy_ok = false;
				break;
			}
			$prev_level = $level;
		}

		return array(
			'headings'             => $headings,
			'heading_hierarchy_ok' => $hierarchy_ok,
		);
	}

	/**
	 * Parse semantic HTML5 elements
	 *
	 * @param string $html HTML content.
	 * @return array
	 */
	private static function parse_semantic_html( $html ) {
		$tags   = array( 'article', 'main', 'nav', 'aside', 'section', 'header', 'footer' );
		$result = array();
		foreach ( $tags as $tag ) {
			$result[ $tag ] = (bool) preg_match( '/<' . $tag . '[\s>]/i', $html );
		}
		return array( 'semantic' => $result );
	}

	/**
	 * Parse images and alt text coverage
	 *
	 * @param string $html HTML content.
	 * @return array
	 */
	private static function parse_images( $html ) {
		$total    = 0;
		$with_alt = 0;
		if ( preg_match_all( '/<img\s[^>]*>/i', $html, $matches ) ) {
			$total = count( $matches[0] );
			foreach ( $matches[0] as $img_tag ) {
				if ( preg_match( '/\salt\s*=\s*["\'][^"\']*["\']/i', $img_tag ) ) {
					++$with_alt;
				}
			}
		}
		return array(
			'images_total'    => $total,
			'images_with_alt' => $with_alt,
		);
	}

	/**
	 * Parse content-to-HTML ratio
	 *
	 * @param string $html HTML content.
	 * @return array
	 */
	private static function parse_content_ratio( $html ) {
		$text        = wp_strip_all_tags( $html );
		$text        = preg_replace( '/\s+/', ' ', trim( $text ) );
		$text_len    = mb_strlen( $text );
		$html_len    = mb_strlen( $html );
		$ratio       = ( $html_len > 0 ) ? round( ( $text_len / $html_len ) * 100, 1 ) : 0;
		return array( 'content_ratio' => $ratio );
	}

	/**
	 * Check for JavaScript-dependent rendering (SPA)
	 *
	 * @param string $html HTML content.
	 * @return array
	 */
	private static function parse_js_dependency( $html ) {
		$body_text = '';
		if ( preg_match( '/<body[^>]*>(.*)<\/body>/si', $html, $m ) ) {
			$body_text = wp_strip_all_tags( $m[1] );
			$body_text = preg_replace( '/\s+/', ' ', trim( $body_text ) );
		}
		$is_spa = ( mb_strlen( $body_text ) < 200 && (
			false !== strpos( $html, 'id="root"' ) ||
			false !== strpos( $html, 'id="app"' ) ||
			false !== strpos( $html, 'id="__next"' ) ||
			false !== strpos( $html, 'id="__nuxt"' )
		) );
		return array( 'js_dependent' => $is_spa );
	}

	/**
	 * Detect AI share/summarize links
	 *
	 * @param string $html HTML content.
	 * @return array
	 */
	private static function parse_ai_share_links( $html ) {
		$platforms = array(
			'claude.ai', 'chatgpt.com', 'chat.openai.com',
			'gemini.google.com', 'perplexity.ai', 'grok.com',
			'deepseek.com', 'chat.mistral.ai', 'copilot.microsoft.com',
		);
		$count = 0;
		foreach ( $platforms as $platform ) {
			if ( false !== stripos( $html, $platform ) ) {
				++$count;
			}
		}
		return array( 'ai_share_links' => $count );
	}

	/**
	 * Check for Markdown delivery support
	 *
	 * @param string $html HTML content.
	 * @return array
	 */
	private static function parse_markdown_delivery( $html ) {
		$has_md = false;
		if ( preg_match( '/href=["\'][^"\']*\.md["\']/i', $html ) ||
			false !== stripos( $html, 'application/markdown' ) ||
			false !== stripos( $html, 'text/markdown' ) ) {
			$has_md = true;
		}
		return array( 'markdown_delivery' => $has_md );
	}

	// ------------------------------------------------------------------
	// WordPress Internal Data Gathering
	// ------------------------------------------------------------------

	/**
	 * Gather data from WordPress internals (global checks).
	 *
	 * @return array WordPress internal data.
	 */
	private static function gather_wp_internal_data() {
		$data = array();

		// robots.txt analysis.
		$data['robots'] = self::analyze_robots_txt();

		// llms.txt files. The Visibility sibling can serve these virtually with no
		// physical file, so "available" means a physical file OR Visibility
		// emitting it. Without this, the analyzer would flag llms.txt as missing
		// and recommend configuring it in VigIA, which has ceded it to Visibility.
		$visibility_llms      = class_exists( 'VigIA_Sibling_Visibility' ) && VigIA_Sibling_Visibility::emits_llms();
		$visibility_llms_full = class_exists( 'VigIA_Sibling_Visibility' ) && VigIA_Sibling_Visibility::emits_llms_full();

		$data['llms_txt_exists']      = file_exists( ABSPATH . 'llms.txt' ) || $visibility_llms;
		$data['llms_full_txt_exists'] = file_exists( ABSPATH . 'llms-full.txt' ) || $visibility_llms_full;

		// Check llms.txt format. A physical file is sniffed for Markdown; a
		// Visibility-served llms.txt is Markdown by construction.
		$data['llms_txt_is_markdown'] = false;
		if ( file_exists( ABSPATH . 'llms.txt' ) ) {
			$content = file_get_contents( ABSPATH . 'llms.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( $content && ( preg_match( '/^#\s/m', $content ) || preg_match( '/\[.*\]\(.*\)/', $content ) || preg_match( '/^[-*]\s/m', $content ) ) ) {
				$data['llms_txt_is_markdown'] = true;
			}
		} elseif ( $visibility_llms ) {
			$data['llms_txt_is_markdown'] = true;
		}

		// Sitemap detection.
		$data['sitemap'] = self::detect_sitemap();

		// RSS feed detection.
		$data['feed'] = self::detect_feed();

		// Active SEO plugin.
		$data['active_seo_plugin'] = self::detect_seo_plugin();

		// AyudaWP plugins status.
		$data['ayudawp_plugins'] = self::detect_ayudawp_plugins();

		// VigIA internal feature states.
		$data['vigia_features'] = self::detect_vigia_features();

		// Site language.
		$data['locale'] = get_locale();

		// Blog public setting.
		$data['blog_public'] = (bool) get_option( 'blog_public', true );

		return $data;
	}

	/**
	 * Analyze robots.txt using VigIA's own data + filesystem.
	 *
	 * @return array robots.txt analysis data.
	 */
	private static function analyze_robots_txt() {
		$result = array(
			'exists'          => false,
			'global_disallow' => false,
			'ai_bots'         => array(),
			'content_signals' => false,
			'signal_details'  => array(),
		);

		$robots_path = ABSPATH . 'robots.txt';
		$content     = '';

		// 1. Physical robots.txt.
		if ( file_exists( $robots_path ) ) {
			$content          = file_get_contents( $robots_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$result['exists'] = true;
		} else {
			// 2. WordPress virtual robots.txt (no HTTP, no loopback issues).
			$blog_public = (bool) get_option( 'blog_public', true );
			if ( $blog_public ) {
				$virtual  = "User-agent: *\n";
				$virtual .= "Disallow: /wp-admin/\n";
				$virtual .= "Allow: /wp-admin/admin-ajax.php\n";

				$site_url = wp_parse_url( site_url(), PHP_URL_PATH );
				if ( $site_url && '/' !== $site_url ) {
					$virtual .= "Disallow: $site_url/wp-admin/\n";
					$virtual .= "Allow: $site_url/wp-admin/admin-ajax.php\n";
				}

				/**
				 * Filter the virtual robots.txt content.
				 * This is the same filter WordPress applies, so plugins
				 * like VigIA that add rules will have their content included.
				 */
				$content = apply_filters( 'robots_txt', $virtual, $blog_public ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WP filter.;
				$result['exists'] = ! empty( $content );
			}
		}

		if ( ! $result['exists'] || empty( $content ) ) {
			return $result;
		}

		// Check for global Disallow: /.
		if ( preg_match( '/user-agent:\s*\*\s*\n(?:\s*#[^\n]*\n)*\s*disallow:\s*\/\s*$/mi', $content ) ) {
			$result['global_disallow'] = true;
		}

		// Check each AI bot.
		foreach ( self::$all_ai_bots as $bot ) {
			if ( false !== stripos( $content, $bot ) ) {
				$bot_lower = strtolower( $bot );
				$status    = 'allowed';

				if ( preg_match( '/user-agent:\s*' . preg_quote( $bot_lower, '/' ) . '\s*\n(?:\s*#[^\n]*\n)*\s*disallow:\s*\/\s*$/mi', $content ) ) {
					$status = 'blocked';
				} elseif ( preg_match( '/user-agent:\s*' . preg_quote( $bot_lower, '/' ) . '\s*\n\s*allow:\s*\//mi', $content ) ) {
					$status = 'allowed';
				}

				$result['ai_bots'][ $bot ] = $status;
			}
		}

		// Content Signals (Cloudflare standard).
		$signal_types = array( 'search', 'ai-input', 'ai-train' );
		foreach ( $signal_types as $signal ) {
			if ( preg_match( '/' . preg_quote( $signal, '/' ) . '\s*[:=]\s*(yes|no|true|false)/i', $content, $sm ) ) {
				$result['content_signals']           = true;
				$value_lower                         = strtolower( $sm[1] );
				$result['signal_details'][ $signal ] = in_array( $value_lower, array( 'yes', 'true' ), true );
			}
		}

		return $result;
	}

	/**
	 * Detect available XML sitemap.
	 *
	 * @return array Sitemap data.
	 */
	private static function detect_sitemap() {
		$urls = array(
			home_url( '/wp-sitemap.xml' ),
			home_url( '/sitemap.xml' ),
			home_url( '/sitemap_index.xml' ),
			home_url( '/sitemaps.xml' ),
		);

		foreach ( $urls as $url ) {
			$response = wp_remote_head(
				$url,
				array(
					'timeout'   => 5,
					'sslverify' => false,
				)
			);
			if ( ! is_wp_error( $response ) ) {
				$code = wp_remote_retrieve_response_code( $response );
				if ( $code >= 200 && $code < 400 ) {
					return array(
						'exists' => true,
						'url'    => $url,
					);
				}
			}
		}

		return array(
			'exists' => false,
			'url'    => '',
		);
	}

	/**
	 * Detect available RSS/Atom feed.
	 *
	 * @return array Feed data.
	 */
	private static function detect_feed() {
		$feed_url = get_feed_link();
		$response = wp_remote_head(
			$feed_url,
			array(
				'timeout'   => 5,
				'sslverify' => false,
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$code = wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 400 ) {
				return array(
					'exists' => true,
					'url'    => $feed_url,
				);
			}
		}

		return array(
			'exists' => false,
			'url'    => '',
		);
	}

	/**
	 * Detect active SEO plugin.
	 *
	 * Public so the Extras page can reuse the same detection for its
	 * Visibility-promotion notices, keeping a single source for the SEO list.
	 *
	 * @return string|false Plugin key or false.
	 */
	public static function detect_seo_plugin() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( self::$seo_plugins as $key => $file ) {
			if ( is_plugin_active( $file ) ) {
				return $key;
			}
		}
		return false;
	}

	/**
	 * Detect AyudaWP ecosystem plugin statuses.
	 *
	 * @return array Plugin statuses: 'active', 'installed', or 'not_installed'.
	 */
	private static function detect_ayudawp_plugins() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$result      = array();

		foreach ( self::$ayudawp_plugins as $slug => $file ) {
			if ( is_plugin_active( $file ) ) {
				$result[ $slug ] = 'active';
			} elseif ( isset( $all_plugins[ $file ] ) ) {
				$result[ $slug ] = 'installed';
			} else {
				$result[ $slug ] = 'not_installed';
			}
		}

		return $result;
	}

	/**
	 * Detect VigIA internal feature states.
	 *
	 * @return array Feature states.
	 */
	private static function detect_vigia_features() {
		$features = array();

		// LLMs.txt generation settings.
		if ( class_exists( 'VigIA_LLMS_Generator' ) ) {
			$llms_settings             = VigIA_LLMS_Generator::get_settings();
			$features['llms_enabled']  = ! empty( $llms_settings['site_name'] );
		} else {
			$features['llms_enabled'] = false;
		}

		// Markdown endpoints.
		if ( class_exists( 'VigIA_Markdown_Endpoints' ) ) {
			$md_settings                  = VigIA_Markdown_Endpoints::get_settings();
			$features['markdown_enabled'] = ! empty( $md_settings['enabled'] );
		} else {
			$features['markdown_enabled'] = false;
		}

		// JSON-LD.
		if ( class_exists( 'VigIA_JsonLD_Generator' ) ) {
			$jsonld_settings             = VigIA_JsonLD_Generator::get_settings();
			$features['jsonld_enabled']  = ! empty( $jsonld_settings['site_identity_enabled'] ) || ! empty( $jsonld_settings['ai_discovery_enabled'] );
		} else {
			$features['jsonld_enabled'] = false;
		}

		// Robots manager (VigIA manages robots.txt rules).
		$features['robots_managed'] = true; // Always available.

		return $features;
	}

	// ------------------------------------------------------------------
	// Category Analyzers (scoring logic)
	// ------------------------------------------------------------------

	/**
	 * Category 1: Access and AI Discovery (37 points)
	 *
	 * @param array $wp_data   WordPress internal data.
	 * @param array $page_data Parsed HTML data.
	 * @return array Category result.
	 */
	private static function analyze_access( $wp_data, $page_data ) {
		$cat = array(
			'name'      => __( 'Access and AI discovery', 'vigia' ),
			'maxPoints' => 37,
			'points'    => 0,
			'checks'    => array(),
		);

		$robots = $wp_data['robots'];

		// 1. robots.txt exists (2 pts).
		$robots_exists   = $robots['exists'];
		$global_disallow = $robots['global_disallow'];
		$points          = $robots_exists ? ( $global_disallow ? 1 : 2 ) : 0;
		$status          = $robots_exists ? ( $global_disallow ? 'partial' : 'pass' ) : 'fail';

		if ( $robots_exists && $global_disallow ) {
			$detail = __( 'robots.txt is accessible but has a global Disallow: / that blocks all bots by default', 'vigia' );
		} elseif ( $robots_exists ) {
			$detail = __( 'robots.txt is accessible', 'vigia' );
		} else {
			$detail = __( 'No robots.txt found at the domain root', 'vigia' );
		}

		$cat['checks'][] = array(
			'id'     => 'robots_exists',
			'label'  => __( 'robots.txt accessible', 'vigia' ),
			'points' => $points,
			'max'    => 2,
			'status' => $status,
			'detail' => $detail,
		);

		// 2. AI bot directives (8 pts).
		$bot_points  = 0;
		$bot_details = array();
		$bot_data    = $robots['ai_bots'];
		$ppb         = 8 / count( self::$priority_bots );

		foreach ( self::$priority_bots as $bot ) {
			$st = isset( $bot_data[ $bot ] ) ? $bot_data[ $bot ] : null;
			if ( 'blocked' === $st ) {
				/* translators: %s: bot name */
				$bot_details[] = sprintf( __( '%s: blocked', 'vigia' ), $bot );
			} elseif ( 'allowed' === $st ) {
				$bot_points += $ppb;
				/* translators: %s: bot name */
				$bot_details[] = sprintf( __( '%s: allowed', 'vigia' ), $bot );
			} elseif ( $global_disallow ) {
				/* translators: %s: bot name */
				$bot_details[] = sprintf( __( '%s: blocked by global Disallow: /', 'vigia' ), $bot );
			} else {
				$bot_points += $ppb;
				/* translators: %s: bot name */
				$bot_details[] = sprintf( __( '%s: no mention (allowed by default)', 'vigia' ), $bot );
			}
		}
		$bot_points = min( round( $bot_points, 1 ), 8 );

		$cat['checks'][] = array(
			'id'     => 'ai_bots',
			'label'  => __( 'AI bot directives in robots.txt', 'vigia' ),
			'points' => $bot_points,
			'max'    => 8,
			'status' => $bot_points >= 6 ? 'pass' : ( $bot_points >= 3 ? 'partial' : 'fail' ),
			'detail' => implode( ', ', $bot_details ),
		);

		// 3. Content Signals (7 pts).
		$has_signals    = $robots['content_signals'];
		$signal_details = $robots['signal_details'];
		$signal_count   = count( $signal_details );
		$signal_points  = 0;
		if ( $has_signals ) {
			$signal_points = min( round( $signal_count * 2.33, 1 ), 7 );
		}

		$signal_info = array();
		if ( $has_signals ) {
			foreach ( array( 'search', 'ai-input', 'ai-train' ) as $s ) {
				if ( isset( $signal_details[ $s ] ) ) {
					/* translators: 1: signal name, 2: allowed/denied */
					$signal_info[] = $s . ': ' . ( $signal_details[ $s ] ? __( 'allowed', 'vigia' ) : __( 'denied', 'vigia' ) );
				}
			}
		}

		$cat['checks'][] = array(
			'id'     => 'content_signals',
			'label'  => __( 'Content Signals in robots.txt', 'vigia' ),
			'points' => $signal_points,
			'max'    => 7,
			'status' => $signal_points >= 5 ? 'pass' : ( $signal_points > 0 ? 'partial' : 'fail' ),
			'detail' => $has_signals
				? implode( ', ', $signal_info )
				: __( 'No Content Signals found (search, ai-input, ai-train)', 'vigia' ),
		);

		// 4. llms.txt (6 pts).
		$llms_exists = $wp_data['llms_txt_exists'];
		$llms_md     = $wp_data['llms_txt_is_markdown'];
		if ( $llms_exists && $llms_md ) {
			$llms_pts    = 6;
			$llms_detail = __( 'llms.txt present with valid Markdown format', 'vigia' );
		} elseif ( $llms_exists ) {
			$llms_pts    = 3;
			$llms_detail = __( 'llms.txt present but without recognizable Markdown format', 'vigia' );
		} else {
			$llms_pts    = 0;
			$llms_detail = __( 'No llms.txt found at the domain root', 'vigia' );
		}

		$cat['checks'][] = array(
			'id'     => 'llms_txt',
			'label'  => 'llms.txt',
			'points' => $llms_pts,
			'max'    => 6,
			'status' => $llms_pts >= 5 ? 'pass' : ( $llms_pts > 0 ? 'partial' : 'fail' ),
			'detail' => $llms_detail,
		);

		// 5. llms-full.txt (4 pts).
		$llms_full = $wp_data['llms_full_txt_exists'];
		$cat['checks'][] = array(
			'id'     => 'llms_full_txt',
			'label'  => 'llms-full.txt',
			'points' => $llms_full ? 4 : 0,
			'max'    => 4,
			'status' => $llms_full ? 'pass' : 'fail',
			'detail' => $llms_full
				? __( 'llms-full.txt is accessible', 'vigia' )
				: __( 'No llms-full.txt found', 'vigia' ),
		);

		// 6. Sitemap (5 pts).
		$sitemap = $wp_data['sitemap'];
		$cat['checks'][] = array(
			'id'     => 'sitemap',
			'label'  => __( 'XML Sitemap accessible', 'vigia' ),
			'points' => $sitemap['exists'] ? 5 : 0,
			'max'    => 5,
			'status' => $sitemap['exists'] ? 'pass' : 'fail',
			'detail' => $sitemap['exists']
				/* translators: %s: sitemap URL */
				? sprintf( __( 'Sitemap found: %s', 'vigia' ), $sitemap['url'] )
				: __( 'No XML sitemap found', 'vigia' ),
		);

		// 7. RSS/Atom feed (3 pts).
		$feed = $wp_data['feed'];
		$cat['checks'][] = array(
			'id'     => 'feed',
			'label'  => __( 'RSS/Atom feed accessible', 'vigia' ),
			'points' => $feed['exists'] ? 3 : 0,
			'max'    => 3,
			'status' => $feed['exists'] ? 'pass' : 'fail',
			'detail' => $feed['exists']
				/* translators: %s: feed URL */
				? sprintf( __( 'Feed found: %s', 'vigia' ), $feed['url'] )
				: __( 'No RSS or Atom feed found', 'vigia' ),
		);

		// 8. No restrictive meta robots (2 pts).
		$no_restrict = ! $page_data['has_noai'] && ! $page_data['has_noimageai'];
		if ( $page_data['has_noai'] && $page_data['has_noimageai'] ) {
			$restrict_detail = __( 'Both noai and noimageai directives detected in meta robots', 'vigia' );
		} elseif ( $page_data['has_noai'] ) {
			$restrict_detail = __( 'noai directive detected in meta robots', 'vigia' );
		} elseif ( $page_data['has_noimageai'] ) {
			$restrict_detail = __( 'noimageai directive detected in meta robots', 'vigia' );
		} else {
			$restrict_detail = __( 'No restrictive AI directives in meta robots', 'vigia' );
		}

		$cat['checks'][] = array(
			'id'     => 'meta_robots_ai',
			'label'  => __( 'No noai/noimageai restrictions', 'vigia' ),
			'points' => $no_restrict ? 2 : 0,
			'max'    => 2,
			'status' => $no_restrict ? 'pass' : 'fail',
			'detail' => $restrict_detail,
		);

		// Sum category points.
		foreach ( $cat['checks'] as $c ) {
			$cat['points'] += $c['points'];
		}
		$cat['points'] = round( $cat['points'], 1 );

		return $cat;
	}

	/**
	 * Category 2: Structured Data and Semantic Context (25 points)
	 *
	 * @param array $page_data Parsed HTML data.
	 * @return array Category result.
	 */
	private static function analyze_structured_data( $page_data ) {
		$cat = array(
			'name'      => __( 'Structured data and semantic context', 'vigia' ),
			'maxPoints' => 25,
			'points'    => 0,
			'checks'    => array(),
		);

		$is_homepage = $page_data['is_homepage'];

		// 1. JSON-LD (3 base + 8 type pts = 11).
		$jsonld_types   = $page_data['jsonld_types'];
		$has_jsonld     = ! empty( $jsonld_types );
		$base_pts       = $has_jsonld ? 3 : 0;
		$homepage_types = array( 'Organization', 'WebSite', 'SearchAction', 'SiteNavigationElement' );
		$content_types  = array( 'Article', 'BlogPosting', 'Product', 'FAQPage', 'HowTo', 'BreadcrumbList', 'Review', 'NewsArticle', 'TechArticle' );
		$relevant       = $is_homepage ? $homepage_types : $content_types;
		$matched        = array_intersect( $jsonld_types, $relevant );
		$type_pts       = min( count( $matched ) * 2, 8 );
		$jsonld_total   = $base_pts + $type_pts;

		$cat['checks'][] = array(
			'id'     => 'jsonld',
			'label'  => __( 'JSON-LD structured data', 'vigia' ),
			'points' => $jsonld_total,
			'max'    => 11,
			'status' => $jsonld_total >= 7 ? 'pass' : ( $jsonld_total >= 3 ? 'partial' : 'fail' ),
			'detail' => $has_jsonld
				/* translators: %s: comma-separated list of schema types */
				? sprintf( __( 'Types found: %s', 'vigia' ), implode( ', ', $jsonld_types ) )
				: __( 'No JSON-LD structured data found', 'vigia' ),
		);

		// 2. Open Graph (4 pts).
		$og_tags    = $page_data['og'];
		$og_present = count( array_filter( $og_tags ) );
		$og_missing = array();
		foreach ( $og_tags as $tag => $found ) {
			if ( ! $found ) {
				$og_missing[] = $tag;
			}
		}
		$og_pts = round( ( $og_present / 5 ) * 4, 1 );

		$og_detail = sprintf(
			/* translators: 1: number present, 2: total */
			__( '%1$d of %2$d OG tags present', 'vigia' ),
			$og_present,
			5
		);
		if ( ! empty( $og_missing ) ) {
			/* translators: %s: comma-separated list of missing tags */
			$og_detail .= '. ' . sprintf( __( 'Missing: %s', 'vigia' ), implode( ', ', $og_missing ) );
		}

		$cat['checks'][] = array(
			'id'     => 'open_graph',
			'label'  => __( 'Open Graph tags', 'vigia' ),
			'points' => $og_pts,
			'max'    => 4,
			'status' => $og_present >= 4 ? 'pass' : ( $og_present >= 2 ? 'partial' : 'fail' ),
			'detail' => $og_detail,
		);

		// 3. Twitter Cards (3 pts).
		$tw_tags    = $page_data['twitter'];
		$tw_present = count( array_filter( $tw_tags ) );
		$tw_missing = array();
		foreach ( $tw_tags as $tag => $found ) {
			if ( ! $found ) {
				$tw_missing[] = $tag;
			}
		}
		$tw_pts = round( ( $tw_present / 3 ) * 3, 1 );

		$tw_detail = sprintf(
			/* translators: 1: number present, 2: total */
			__( '%1$d of %2$d tags present', 'vigia' ),
			$tw_present,
			3
		);
		if ( ! empty( $tw_missing ) ) {
			/* translators: %s: comma-separated list of missing tags */
			$tw_detail .= '. ' . sprintf( __( 'Missing: %s', 'vigia' ), implode( ', ', $tw_missing ) );
		}

		$cat['checks'][] = array(
			'id'     => 'twitter_cards',
			'label'  => __( 'Twitter/X Cards', 'vigia' ),
			'points' => $tw_pts,
			'max'    => 3,
			'status' => $tw_present >= 2 ? 'pass' : ( $tw_present >= 1 ? 'partial' : 'fail' ),
			'detail' => $tw_detail,
		);

		// 4. Meta description (3 pts).
		$meta_len = $page_data['meta_description_length'];
		if ( $meta_len >= 120 && $meta_len <= 160 ) {
			$meta_pts    = 3;
			/* translators: %d: character count */
			$meta_detail = sprintf( __( 'Meta description present with optimal length (%d characters)', 'vigia' ), $meta_len );
		} elseif ( $meta_len > 0 ) {
			$meta_pts    = 1.5;
			/* translators: %d: character count */
			$meta_detail = sprintf( __( 'Meta description present but non-optimal length (%d characters, recommended 120-160)', 'vigia' ), $meta_len );
		} else {
			$meta_pts    = 0;
			$meta_detail = __( 'No meta description found', 'vigia' );
		}

		$cat['checks'][] = array(
			'id'     => 'meta_description',
			'label'  => __( 'Meta description', 'vigia' ),
			'points' => $meta_pts,
			'max'    => 3,
			'status' => $meta_pts >= 2.5 ? 'pass' : ( $meta_pts > 0 ? 'partial' : 'fail' ),
			'detail' => $meta_detail,
		);

		// 5. Canonical URL (2 pts).
		$has_canonical = ! empty( $page_data['canonical'] );
		$cat['checks'][] = array(
			'id'     => 'canonical',
			'label'  => __( 'Canonical URL declared', 'vigia' ),
			'points' => $has_canonical ? 2 : 0,
			'max'    => 2,
			'status' => $has_canonical ? 'pass' : 'fail',
			'detail' => $has_canonical
				? 'Canonical: ' . $page_data['canonical']
				: __( 'No rel="canonical" tag found', 'vigia' ),
		);

		// 6. Language declared (2 pts).
		$has_lang = ! empty( $page_data['lang'] );
		$cat['checks'][] = array(
			'id'     => 'lang',
			'label'  => __( 'Language declared in HTML', 'vigia' ),
			'points' => $has_lang ? 2 : 0,
			'max'    => 2,
			'status' => $has_lang ? 'pass' : 'fail',
			'detail' => $has_lang
				? 'lang="' . $page_data['lang'] . '"'
				: __( 'No lang attribute found on <html>', 'vigia' ),
		);

		// Sum.
		foreach ( $cat['checks'] as $c ) {
			$cat['points'] += $c['points'];
		}
		$cat['points'] = round( $cat['points'], 1 );

		return $cat;
	}

	/**
	 * Category 3: Content Structure and Readability (20 points)
	 *
	 * @param array $page_data Parsed HTML data.
	 * @return array Category result.
	 */
	private static function analyze_content_structure( $page_data ) {
		$cat = array(
			'name'      => __( 'Content structure and readability', 'vigia' ),
			'maxPoints' => 20,
			'points'    => 0,
			'checks'    => array(),
		);

		// 1. Single H1 (3 pts).
		$h1_count = isset( $page_data['headings']['h1'] ) ? $page_data['headings']['h1'] : 0;
		if ( 1 === $h1_count ) {
			$h1_pts    = 3;
			$h1_detail = __( 'Page has exactly one H1', 'vigia' );
		} elseif ( $h1_count > 1 ) {
			$h1_pts    = 1;
			/* translators: %d: number of H1 tags */
			$h1_detail = sprintf( __( '%d H1 tags found (should be exactly one)', 'vigia' ), $h1_count );
		} else {
			$h1_pts    = 0;
			$h1_detail = __( 'No H1 tag found', 'vigia' );
		}

		$cat['checks'][] = array(
			'id'     => 'single_h1',
			'label'  => __( 'Single H1', 'vigia' ),
			'points' => $h1_pts,
			'max'    => 3,
			'status' => 3 === $h1_pts ? 'pass' : ( $h1_pts > 0 ? 'partial' : 'fail' ),
			'detail' => $h1_detail,
		);

		// 2. Heading hierarchy (3 pts).
		$hier_ok = $page_data['heading_hierarchy_ok'];
		$cat['checks'][] = array(
			'id'     => 'heading_hierarchy',
			'label'  => __( 'Consistent heading hierarchy', 'vigia' ),
			'points' => $hier_ok ? 3 : 0,
			'max'    => 3,
			'status' => $hier_ok ? 'pass' : 'fail',
			'detail' => $hier_ok
				? __( 'Headings follow a logical hierarchy without gaps', 'vigia' )
				: __( 'Gaps detected in heading hierarchy (e.g. H1 to H4)', 'vigia' ),
		);

		// 3. Semantic HTML5 (4 pts).
		$semantic     = $page_data['semantic'];
		$key_tags     = array( 'article', 'main', 'nav', 'aside' );
		$found_tags   = array();
		$missing_tags = array();
		foreach ( $key_tags as $tag ) {
			if ( ! empty( $semantic[ $tag ] ) ) {
				$found_tags[] = '<' . $tag . '>';
			} else {
				$missing_tags[] = '<' . $tag . '>';
			}
		}
		$sem_pts = round( ( count( $found_tags ) / count( $key_tags ) ) * 4, 1 );

		$sem_detail = '';
		if ( ! empty( $found_tags ) ) {
			/* translators: %s: comma-separated list of found tags */
			$sem_detail = sprintf( __( 'Found: %s', 'vigia' ), implode( ', ', $found_tags ) );
			if ( ! empty( $missing_tags ) ) {
				/* translators: %s: comma-separated list of missing tags */
				$sem_detail .= '. ' . sprintf( __( 'Missing: %s', 'vigia' ), implode( ', ', $missing_tags ) );
			}
		} else {
			$sem_detail = __( 'No semantic HTML5 tags found (article, main, nav, aside)', 'vigia' );
		}

		$cat['checks'][] = array(
			'id'     => 'semantic_html',
			'label'  => __( 'Semantic HTML5 tags', 'vigia' ),
			'points' => $sem_pts,
			'max'    => 4,
			'status' => $sem_pts >= 3 ? 'pass' : ( $sem_pts >= 1 ? 'partial' : 'fail' ),
			'detail' => $sem_detail,
		);

		// 4. Alt text on images (4 pts).
		$img_total = $page_data['images_total'];
		$img_alt   = $page_data['images_with_alt'];

		if ( 0 === $img_total ) {
			$alt_pts    = 4;
			$alt_status = 'info';
			$alt_detail = __( 'No images found on the page', 'vigia' );
		} else {
			$alt_ratio  = $img_alt / $img_total;
			$alt_pts    = round( $alt_ratio * 4, 1 );
			$alt_status = $alt_ratio >= 0.9 ? 'pass' : ( $alt_ratio >= 0.5 ? 'partial' : 'fail' );
			/* translators: 1: images with alt, 2: total images, 3: percentage */
			$alt_detail = sprintf( __( '%1$d of %2$d images with alt attribute (%3$d%%)', 'vigia' ), $img_alt, $img_total, round( $alt_ratio * 100 ) );
		}

		$cat['checks'][] = array(
			'id'     => 'alt_text',
			'label'  => __( 'Image alt text', 'vigia' ),
			'points' => $alt_pts,
			'max'    => 4,
			'status' => $alt_status,
			'detail' => $alt_detail,
		);

		// 5. Content ratio (3 pts).
		$ratio = $page_data['content_ratio'];
		if ( $ratio >= 25 ) {
			$ratio_pts = 3;
		} elseif ( $ratio >= 15 ) {
			$ratio_pts = 2;
		} elseif ( $ratio >= 8 ) {
			$ratio_pts = 1;
		} else {
			$ratio_pts = 0;
		}

		/* translators: %s: percentage */
		$ratio_detail = sprintf( __( '%s%% text vs total HTML', 'vigia' ), $ratio );
		if ( $ratio < 15 ) {
			$ratio_detail .= '. ' . __( 'A low ratio indicates too much code and not enough useful content', 'vigia' );
		}

		$cat['checks'][] = array(
			'id'     => 'content_ratio',
			'label'  => __( 'Content/HTML ratio', 'vigia' ),
			'points' => $ratio_pts,
			'max'    => 3,
			'status' => $ratio_pts >= 2.5 ? 'pass' : ( $ratio_pts >= 1 ? 'partial' : 'fail' ),
			'detail' => $ratio_detail,
		);

		// 6. JS-dependent content (3 pts).
		$not_js = ! $page_data['js_dependent'];
		$cat['checks'][] = array(
			'id'     => 'js_dependent',
			'label'  => __( 'Content accessible without JavaScript', 'vigia' ),
			'points' => $not_js ? 3 : 0,
			'max'    => 3,
			'status' => $not_js ? 'pass' : 'fail',
			'detail' => $not_js
				? __( 'Main content is accessible in the initial HTML', 'vigia' )
				: __( 'The page appears to depend on JavaScript to render content (SPA)', 'vigia' ),
		);

		// Sum.
		foreach ( $cat['checks'] as $c ) {
			$cat['points'] += $c['points'];
		}
		$cat['points'] = round( $cat['points'], 1 );

		return $cat;
	}

	/**
	 * Category 4: AI Interaction and Distribution (8 points)
	 *
	 * @param array $page_data Parsed HTML data.
	 * @param array $wp_data   WordPress internal data.
	 * @return array Category result.
	 */
	private static function analyze_ai_interaction( $page_data, $wp_data ) {
		$cat = array(
			'name'      => __( 'AI interaction and distribution', 'vigia' ),
			'maxPoints' => 8,
			'points'    => 0,
			'checks'    => array(),
		);

		$is_homepage = $page_data['is_homepage'];

		// 1. Markdown delivery (4 pts).
		$md_enabled = ! empty( $wp_data['vigia_features']['markdown_enabled'] );
		// The Visibility sibling can serve Markdown for agents at the same .md
		// URLs; when it does, VigIA cedes to it, so its delivery counts as
		// configured too (otherwise the analyzer would recommend setting up
		// Markdown in VigIA, which has handed it over).
		$md_sibling = class_exists( 'VigIA_Sibling_Visibility' ) && VigIA_Sibling_Visibility::emits_markdown();
		$md_site    = $md_enabled || $md_sibling;

		if ( $is_homepage && ( $md_site || $page_data['markdown_delivery'] ) ) {
			$md_pts    = 4;
			$md_status = 'pass';
			$md_detail = $md_site
				? __( 'Markdown for Agents is enabled site-wide (serves content pages)', 'vigia' )
				: __( 'Markdown delivery detected', 'vigia' );
		} elseif ( $is_homepage ) {
			$md_pts    = 0;
			$md_status = 'fail';
			$md_detail = __( 'No Markdown delivery configured for AI agents', 'vigia' );
		} elseif ( $page_data['markdown_delivery'] || $md_site ) {
			$md_pts    = 4;
			$md_status = 'pass';
			$md_detail = $page_data['markdown_delivery']
				? __( 'Markdown version or .md link detected', 'vigia' )
				: __( 'Markdown for Agents is enabled site-wide', 'vigia' );
		} else {
			$md_pts    = 0;
			$md_status = 'fail';
			$md_detail = __( 'No Markdown version of the content detected', 'vigia' );
		}

		$cat['checks'][] = array(
			'id'     => 'markdown_delivery',
			'label'  => __( 'Markdown version for AI agents', 'vigia' ),
			'points' => $md_pts,
			'max'    => 4,
			'status' => $md_status,
			'detail' => $md_detail,
		);

		// 2. AI share buttons (4 pts).
		if ( $is_homepage ) {
			$share_pts    = 4;
			$share_status = 'info';
			$share_detail = __( 'Not applicable for homepages (relevant for content pages)', 'vigia' );
		} elseif ( $page_data['ai_share_links'] > 0 ) {
			$links        = $page_data['ai_share_links'];
			$share_pts    = min( round( $links * 1.33, 1 ), 4 );
			$share_status = $share_pts >= 3 ? 'pass' : 'partial';
			/* translators: %d: number of AI platforms */
			$share_detail = sprintf( __( 'Links to %d AI platform(s) detected', 'vigia' ), $links );
		} else {
			$share_pts    = 0;
			$share_status = 'fail';
			$share_detail = __( 'No AI share/summarize buttons or links detected', 'vigia' );
		}

		$cat['checks'][] = array(
			'id'     => 'ai_share',
			'label'  => __( 'AI share/summarize buttons', 'vigia' ),
			'points' => $share_pts,
			'max'    => 4,
			'status' => $share_status,
			'detail' => $share_detail,
		);

		// Sum.
		foreach ( $cat['checks'] as $c ) {
			$cat['points'] += $c['points'];
		}
		$cat['points'] = round( $cat['points'], 1 );

		return $cat;
	}

	/**
	 * Category 5: Access Performance (10 points)
	 *
	 * @param array $page_data Parsed HTML data.
	 * @return array Category result.
	 */
	private static function analyze_performance( $page_data ) {
		$cat = array(
			'name'      => __( 'Access performance', 'vigia' ),
			'maxPoints' => 10,
			'points'    => 0,
			'checks'    => array(),
		);

		$ttfb = isset( $page_data['ttfb'] ) ? $page_data['ttfb'] : 0;

		if ( $ttfb > 0 && $ttfb < 200 ) {
			$pts    = 10;
			$status = 'pass';
			/* translators: %d: TTFB in milliseconds */
			$detail = sprintf( __( '%d ms — Excellent', 'vigia' ), $ttfb );
		} elseif ( $ttfb >= 200 && $ttfb < 500 ) {
			$pts    = 7;
			$status = 'pass';
			/* translators: %d: TTFB in milliseconds */
			$detail = sprintf( __( '%d ms — Good', 'vigia' ), $ttfb );
		} elseif ( $ttfb >= 500 && $ttfb < 1000 ) {
			$pts    = 4;
			$status = 'partial';
			/* translators: %d: TTFB in milliseconds */
			$detail = sprintf( __( '%d ms — Needs improvement', 'vigia' ), $ttfb );
		} elseif ( $ttfb >= 1000 ) {
			$pts    = 1;
			$status = 'fail';
			/* translators: %d: TTFB in milliseconds */
			$detail = sprintf( __( '%d ms — Slow', 'vigia' ), $ttfb );
		} else {
			$pts    = 0;
			$status = 'fail';
			$detail = __( 'Could not measure TTFB', 'vigia' );
		}

		$cat['checks'][] = array(
			'id'     => 'ttfb',
			'label'  => __( 'TTFB (Time to First Byte)', 'vigia' ),
			'points' => $pts,
			'max'    => 10,
			'status' => $status,
			'detail' => $detail,
		);

		$cat['points'] = $pts;

		return $cat;
	}

	// ------------------------------------------------------------------
	// Grading
	// ------------------------------------------------------------------

	/**
	 * Calculate grade from total points.
	 *
	 * @param float $points Total score.
	 * @return string Grade (A+, A, B, C, D, E, F).
	 */
	private static function calculate_grade( $points ) {
		if ( $points >= 90 ) {
			return 'A+';
		}
		if ( $points >= 80 ) {
			return 'A';
		}
		if ( $points >= 65 ) {
			return 'B';
		}
		if ( $points >= 50 ) {
			return 'C';
		}
		if ( $points >= 35 ) {
			return 'D';
		}
		if ( $points >= 20 ) {
			return 'E';
		}
		return 'F';
	}

	/**
	 * Get human-readable message for a grade.
	 *
	 * @param string $grade Grade letter.
	 * @return string Message.
	 */
	private static function get_grade_message( $grade ) {
		$messages = array(
			'A+' => __( 'Excellent. Your site is very well optimized for AI visibility.', 'vigia' ),
			'A'  => __( 'Very good. Your site has good AI visibility with minor improvements possible.', 'vigia' ),
			'B'  => __( 'Good. There is room for improvement in AI visibility.', 'vigia' ),
			'C'  => __( 'Fair. Several important AI visibility signals are missing.', 'vigia' ),
			'D'  => __( 'Poor. Your site needs significant improvements for AI visibility.', 'vigia' ),
			'E'  => __( 'Very poor. Very few AI visibility signals are configured.', 'vigia' ),
			'F'  => __( 'Critical. Your site lacks basic AI optimization.', 'vigia' ),
		);
		return isset( $messages[ $grade ] ) ? $messages[ $grade ] : $messages['F'];
	}

	// ------------------------------------------------------------------
	// Smart Recommendations
	// ------------------------------------------------------------------

	/**
	 * Build smart recommendations with contextual links.
	 *
	 * Generates three tiers of recommendations:
	 * - Tier 1: VigIA internal features (link to Extras tabs)
	 * - Tier 2: AyudaWP plugins not installed (Thickbox install modal)
	 * - Tier 3: AyudaWP plugins installed but inactive (link to plugins screen)
	 * - Tier 4: Third-party SEO plugins (Thickbox)
	 *
	 * @param array $categories Scored categories.
	 * @param array $wp_data    WordPress internal data.
	 * @return array Recommendations data for JS rendering.
	 */
	private static function build_recommendations( $categories, $wp_data ) {
		$recs     = array();
		$features = $wp_data['vigia_features'];
		$plugins  = $wp_data['ayudawp_plugins'];
		$seo      = $wp_data['active_seo_plugin'];

		// Collect failed/partial checks.
		$failed = array();
		foreach ( $categories as $cat ) {
			foreach ( $cat['checks'] as $check ) {
				if ( 'pass' !== $check['status'] ) {
					$failed[ $check['id'] ] = $check;
				}
			}
		}

		// Tier 1: VigIA features not configured.
		if ( isset( $failed['llms_txt'] ) ) {
			$llms_text = $features['llms_enabled']
				? __( 'Your llms.txt generator is configured but the llms.txt file is missing. Go to VigIA Extras to generate it.', 'vigia' )
				: __( 'Configure the llms.txt generator in VigIA Extras to create your llms.txt file automatically.', 'vigia' );
			$llms_label = $features['llms_enabled']
				? __( 'Generate llms.txt', 'vigia' )
				: __( 'Configure llms.txt', 'vigia' );
			$recs[] = array(
				'tier'   => 1,
				'check'  => 'llms_txt',
				'text'   => $llms_text,
				'action' => 'internal_link',
				'url'    => admin_url( 'admin.php?page=vigia-extras&tab=llms' ),
				'label'  => $llms_label,
			);
		}

		if ( isset( $failed['llms_full_txt'] ) && ! isset( $failed['llms_txt'] ) ) {
			$recs[] = array(
				'tier'   => 1,
				'check'  => 'llms_full_txt',
				'text'   => __( 'Your llms.txt exists but llms-full.txt is missing. Enable full content mode in VigIA Extras to generate it.', 'vigia' ),
				'action' => 'internal_link',
				'url'    => admin_url( 'admin.php?page=vigia-extras&tab=llms' ),
				'label'  => __( 'Configure llms-full.txt', 'vigia' ),
			);
		}

		if ( isset( $failed['jsonld'] ) ) {
			if ( ! $features['jsonld_enabled'] && ! $seo ) {
				// No SEO plugin and no VigIA JSON-LD: recommend full setup.
				$recs[] = array(
					'tier'   => 1,
					'check'  => 'jsonld',
					'text'   => __( 'Enable JSON-LD structured data in VigIA Extras to add Schema.org markup and AI Discovery signals to your site.', 'vigia' ),
					'action' => 'internal_link',
					'url'    => admin_url( 'admin.php?page=vigia-extras&tab=jsonld' ),
					'label'  => __( 'Configure JSON-LD', 'vigia' ),
				);
			} elseif ( $seo && ! $features['jsonld_enabled'] ) {
				// SEO plugin handles basic schema, but VigIA adds AI Discovery.
				$recs[] = array(
					'tier'   => 1,
					'check'  => 'jsonld',
					'text'   => __( 'Your SEO plugin generates basic schema, but VigIA can add AI Discovery signals (ReadAction pointers to llms.txt and Markdown endpoints). Enable AI Discovery in VigIA Extras.', 'vigia' ),
					'action' => 'internal_link',
					'url'    => admin_url( 'admin.php?page=vigia-extras&tab=jsonld' ),
					'label'  => __( 'Enable AI Discovery', 'vigia' ),
				);
			}
		}

		if ( isset( $failed['markdown_delivery'] ) ) {
			if ( ! $features['markdown_enabled'] ) {
				$recs[] = array(
					'tier'   => 1,
					'check'  => 'markdown_delivery',
					'text'   => __( 'Enable Markdown endpoints in VigIA Extras so AI agents can access your content in Markdown format.', 'vigia' ),
					'action' => 'internal_link',
					'url'    => admin_url( 'admin.php?page=vigia-extras&tab=markdown' ),
					'label'  => __( 'Configure Markdown', 'vigia' ),
				);
			}
		}

		// Tier 2 & 3: AyudaWP plugins.
		if ( isset( $failed['content_signals'] ) ) {
			$status = isset( $plugins['ai-content-signals'] ) ? $plugins['ai-content-signals'] : 'not_installed';
			if ( 'not_installed' === $status ) {
				$recs[] = array(
					'tier'   => 2,
					'check'  => 'content_signals',
					'text'   => __( 'Install AI Content Signals to automatically add Content Signals (search, ai-input, ai-train) to your robots.txt.', 'vigia' ),
					'action' => 'thickbox',
					'slug'   => 'ai-content-signals',
					'label'  => __( 'Install AI Content Signals', 'vigia' ),
				);
			} elseif ( 'installed' === $status ) {
				$recs[] = array(
					'tier'   => 3,
					'check'  => 'content_signals',
					'text'   => __( 'AI Content Signals is installed but not active. Activate it to add Content Signals to your robots.txt.', 'vigia' ),
					'action' => 'thickbox',
					'slug'   => 'ai-content-signals',
					'label'  => __( 'Activate plugin', 'vigia' ),
				);
			}
		}

		// AI Share & Summarize: recommend whenever plugin is not active.
		// On homepage the check is N/A (info) so it won't appear in $failed,
		// but users still benefit from having the plugin on content pages.
		$aiss_status = isset( $plugins['ai-share-summarize'] ) ? $plugins['ai-share-summarize'] : 'not_installed';
		if ( 'not_installed' === $aiss_status ) {
			$recs[] = array(
				'tier'   => 2,
				'check'  => 'ai_share',
				'text'   => __( 'Install AI Share & Summarize to add share/summarize buttons for major AI platforms to your content.', 'vigia' ),
				'action' => 'thickbox',
				'slug'   => 'ai-share-summarize',
				'label'  => __( 'Install AI Share & Summarize', 'vigia' ),
			);
		} elseif ( 'installed' === $aiss_status ) {
			$recs[] = array(
				'tier'   => 3,
				'check'  => 'ai_share',
				'text'   => __( 'AI Share & Summarize is installed but not active.', 'vigia' ),
				'action' => 'thickbox',
				'slug'   => 'ai-share-summarize',
				'label'  => __( 'Activate plugin', 'vigia' ),
			);
		}

		if ( isset( $failed['sitemap'] ) ) {
			$status = isset( $plugins['native-sitemap-customizer'] ) ? $plugins['native-sitemap-customizer'] : 'not_installed';
			if ( 'not_installed' === $status ) {
				$recs[] = array(
					'tier'   => 2,
					'check'  => 'sitemap',
					'text'   => __( 'Install Native Sitemap Customizer to customize WordPress native sitemap and ensure it is accessible.', 'vigia' ),
					'action' => 'thickbox',
					'slug'   => 'native-sitemap-customizer',
					'label'  => __( 'Install Native Sitemap Customizer', 'vigia' ),
				);
			} elseif ( 'installed' === $status ) {
				$recs[] = array(
					'tier'   => 3,
					'check'  => 'sitemap',
					'text'   => __( 'Native Sitemap Customizer is installed but not active.', 'vigia' ),
					'action' => 'thickbox',
					'slug'   => 'native-sitemap-customizer',
					'label'  => __( 'Activate plugin', 'vigia' ),
				);
			}
		}

		// NoIndexer: recommend whenever plugin is not active.
		// Helps control which content appears in search engines and AI outputs.
		$noindexer_status = isset( $plugins['noindexer'] ) ? $plugins['noindexer'] : 'not_installed';
		if ( 'not_installed' === $noindexer_status ) {
			$recs[] = array(
				'tier'   => 2,
				'check'  => 'noindexer',
				'text'   => __( 'Install NoIndexer to control which posts and pages are indexed by search engines. Works with VigIA to automatically exclude noindexed content from llms.txt and Markdown endpoints.', 'vigia' ),
				'action' => 'thickbox',
				'slug'   => 'noindexer',
				'label'  => __( 'Install NoIndexer', 'vigia' ),
			);
		} elseif ( 'installed' === $noindexer_status ) {
			$recs[] = array(
				'tier'   => 3,
				'check'  => 'noindexer',
				'text'   => __( 'NoIndexer is installed but not active. Activate it to control indexing and integrate with VigIA llms.txt and Markdown endpoints.', 'vigia' ),
				'action' => 'thickbox',
				'slug'   => 'noindexer',
				'label'  => __( 'Activate plugin', 'vigia' ),
			);
		}

		// Visibility (native-aeo-pack) is VigIA's sibling in the AyudaWP family:
		// Visibility emits the AI/search signals, VigIA measures and enforces them.
		// Promote that pairing based on the current setup, rather than recommending
		// a competing SEO plugin.
		$visibility_active = class_exists( 'VigIA_Sibling_Visibility' ) && VigIA_Sibling_Visibility::is_active();
		$needs_seo         = isset( $failed['jsonld'] ) || isset( $failed['open_graph'] ) || isset( $failed['meta_description'] );

		if ( $visibility_active ) {
			// Already paired: an informative complementarity note (no action button).
			$recs[] = array(
				'tier'   => 0,
				'check'  => 'visibility_complement',
				'text'   => __( 'Visibility and VigIA are working together. Visibility emits your AI and search signals (Site Identity schema, llms.txt, Markdown for agents, robots-for-AI) and VigIA measures and enforces them (crawler analytics, blocking and alerts). You have the complete SEO + AI stack.', 'vigia' ),
				'action' => 'info',
			);
		} elseif ( $seo ) {
			// Another SEO plugin is running but Visibility is not: the native SEO + AI
			// integration with VigIA is missing. Recommend switching to Visibility.
			$recs[] = array(
				'tier'   => 2,
				'check'  => 'visibility_promo',
				'text'   => __( 'You are running another SEO plugin, so you are missing the native SEO + AI integration with VigIA. Visibility is a lightweight, no-bloat SEO plugin built for AI visibility (llms.txt, Markdown for agents, Site Identity schema and a robots-for-AI editor) that pairs natively with VigIA: it emits the signals, VigIA measures and enforces them. Switch to Visibility for the full stack.', 'vigia' ),
				'action' => 'thickbox',
				'slug'   => 'native-aeo-pack',
				'label'  => __( 'Get Visibility', 'vigia' ),
			);
		} elseif ( $needs_seo && $features['jsonld_enabled'] === false ) {
			// No SEO plugin at all and structured data/OG/meta missing: recommend
			// Visibility, the sibling that also integrates with VigIA.
			$recs[] = array(
				'tier'   => 4,
				'check'  => 'seo_plugin',
				'text'   => __( 'No SEO plugin is generating your structured data, Open Graph and meta descriptions. Visibility is a lightweight SEO plugin built for AI visibility that pairs natively with VigIA: it emits the signals (schema, llms.txt, Markdown, robots-for-AI), VigIA measures and enforces them.', 'vigia' ),
				'action' => 'thickbox',
				'slug'   => 'native-aeo-pack',
				'label'  => __( 'Get Visibility', 'vigia' ),
			);
		}

		return $recs;
	}

	// ------------------------------------------------------------------
	// URL Search for Internal Pages
	// ------------------------------------------------------------------

	/**
	 * Search published content for the URL selector.
	 * Returns posts, pages, and public CPTs matching a search term.
	 *
	 * @param string $search Search term.
	 * @param int    $limit  Max results (default 10).
	 * @return array Array of results with id, title, url, type.
	 */
	public static function search_urls( $search = '', $limit = 10 ) {
		// Get all public post types.
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$type_slugs = array();
		foreach ( $post_types as $slug => $obj ) {
			if ( 'attachment' === $slug ) {
				continue;
			}
			$type_slugs[] = $slug;
		}

		$args = array(
			'post_type'      => $type_slugs,
			'post_status'    => 'publish',
			'posts_per_page' => absint( $limit ),
			'orderby'        => 'relevance',
			'order'          => 'DESC',
		);

		if ( ! empty( $search ) ) {
			$args['s'] = sanitize_text_field( $search );
		} else {
			$args['orderby'] = 'date';
		}

		$query   = new WP_Query( $args );
		$results = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$type_obj = get_post_type_object( get_post_type() );
				$results[] = array(
					'id'    => get_the_ID(),
					'title' => get_the_title(),
					'url'   => get_permalink(),
					'type'  => $type_obj ? $type_obj->labels->singular_name : get_post_type(),
				);
			}
			wp_reset_postdata();
		}

		return $results;
	}

	/**
	 * Get transient key for cached page data.
	 *
	 * @param string $url URL to generate key for.
	 * @return string Transient key.
	 */
	public static function get_page_cache_key( $url ) {
		return 'vigia_vis_page_' . md5( $url );
	}

	/**
	 * Clear cached page data for a URL.
	 *
	 * @param string $url URL to clear cache for.
	 */
	public static function clear_page_cache( $url ) {
		delete_transient( self::get_page_cache_key( $url ) );
	}
}