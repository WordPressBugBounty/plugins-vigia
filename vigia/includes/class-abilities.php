<?php
/**
 * Abilities API integration
 *
 * Registers VigIA abilities for WordPress 6.9+ Abilities API.
 * Enables AI agents and automation tools to discover and use VigIA features.
 *
 * @package VigIA
 * @since 1.4.1
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abilities class
 */
class VigIA_Abilities {

	/**
	 * Ability namespace
	 */
	const NAMESPACE = 'vigia';

	/**
	 * Category slug
	 */
	const CATEGORY = 'ai-crawler-analytics';

	/**
	 * Initialize abilities registration
	 *
	 * Hooks into the correct actions for categories and abilities.
	 */
	public static function init() {
		// Register category on categories_init hook (runs first).
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );

		// Register abilities on api_init hook (runs after categories).
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Register ability category
	 *
	 * Called on wp_abilities_api_categories_init hook.
	 */
	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'AI Crawler Analytics', 'vigia' ),
				'description' => __( 'Monitor, analyze and control AI crawler activity on your WordPress site.', 'vigia' ),
			)
		);
	}

	/**
	 * Register all VigIA abilities
	 *
	 * Called on wp_abilities_api_init hook.
	 */
	public static function register_abilities() {
		// Verify API is available.
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// Register abilities.
		self::register_get_crawler_stats();
		self::register_get_top_crawlers();
		self::register_get_top_pages();
		self::register_get_blocked_items();
		self::register_block_crawler();
		self::register_unblock_crawler();
		self::register_get_robots_rules();
		self::register_add_robots_disallow();
		self::register_remove_robots_rule();
	}

	/**
	 * Permission callback for read abilities.
	 *
	 * @return bool
	 */
	public static function check_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission callback for write abilities.
	 *
	 * Adds a kill-switch filter on top of the manage_options check so a
	 * site owner can disable mutating abilities (block, unblock, robots
	 * changes) while keeping read-only abilities working. Useful when
	 * exposing the abilities through MCP clients with Application
	 * Passwords and the admin wants read-only access.
	 *
	 * @since 1.11.0
	 *
	 * @return bool
	 */
	public static function check_write_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		/**
		 * Filter to disable write operations exposed through the Abilities API.
		 *
		 * When set to false, the four write abilities (block-crawler,
		 * unblock-crawler, add-robots-disallow, remove-robots-rule) reject
		 * all calls, including those coming from MCP clients authenticated
		 * with Application Passwords. Read-only abilities are not affected.
		 *
		 * @since 1.11.0
		 *
		 * @param bool $allowed Whether write abilities are allowed. Default true.
		 */
		return (bool) apply_filters( 'vigia_can_write_via_abilities', true );
	}

	/**
	 * Build a transient cache key for an ability call.
	 *
	 * @since 1.11.0
	 *
	 * @param string $slug  Ability slug (without namespace).
	 * @param array  $input Sanitized input parameters.
	 * @return string
	 */
	private static function cache_key_for( $slug, $input ) {
		return 'vigia_ability_' . md5( $slug . '|' . wp_json_encode( $input ) );
	}

	/**
	 * Return a cached value or generate, store and return a new one.
	 *
	 * Used by stats abilities that aggregate over the visits table to
	 * keep MCP clients from hammering the database with repeated calls.
	 *
	 * @since 1.11.0
	 *
	 * @param string   $cache_key Transient key.
	 * @param callable $generator Callable that returns the value to cache.
	 * @return mixed
	 */
	private static function get_cached_or_generate( $cache_key, $generator ) {
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$result = call_user_func( $generator );
		set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * Register get-crawler-stats ability
	 *
	 * Returns general statistics about AI crawler activity.
	 */
	private static function register_get_crawler_stats() {
		wp_register_ability(
			self::NAMESPACE . '/get-crawler-stats',
			array(
				'label'               => __( 'Get Crawler Statistics', 'vigia' ),
				'description'         => __( 'Returns statistics about AI crawler visits including total visits, unique crawlers, and unique pages crawled.', 'vigia' ),
				'category'            => self::CATEGORY,
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'days' => array(
							'type'        => 'integer',
							'description' => __( 'Number of days to analyze. Default 30.', 'vigia' ),
							'default'     => 30,
							'minimum'     => 1,
							'maximum'     => 365,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'total_visits'    => array(
							'type'        => 'integer',
							'description' => __( 'Total number of crawler visits.', 'vigia' ),
						),
						'unique_crawlers' => array(
							'type'        => 'integer',
							'description' => __( 'Number of unique crawlers detected.', 'vigia' ),
						),
						'unique_pages'    => array(
							'type'        => 'integer',
							'description' => __( 'Number of unique pages crawled.', 'vigia' ),
						),
						'period_start'    => array(
							'type'        => 'string',
							'description' => __( 'Start date of the analysis period.', 'vigia' ),
						),
						'period_end'      => array(
							'type'        => 'string',
							'description' => __( 'End date of the analysis period.', 'vigia' ),
						),
					),
				),
				'execute_callback'            => array( __CLASS__, 'execute_get_crawler_stats' ),
			)
		);
	}

	/**
	 * Execute get-crawler-stats ability
	 *
	 * @param array $input Input parameters.
	 * @return array Statistics data.
	 */
	public static function execute_get_crawler_stats( $input = array() ) {
		$days = isset( $input['days'] ) ? absint( $input['days'] ) : 30;

		$cache_key = self::cache_key_for( 'get-crawler-stats', array( 'days' => $days ) );

		return self::get_cached_or_generate(
			$cache_key,
			static function () use ( $days ) {
				$end_date   = gmdate( 'Y-m-d' );
				$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

				$stats = VigIA_Database::get_stats( $start_date, $end_date );

				return array(
					'total_visits'    => $stats['total_visits'],
					'unique_crawlers' => $stats['unique_crawlers'],
					'unique_pages'    => $stats['unique_pages'],
					'period_start'    => $start_date,
					'period_end'      => $end_date,
				);
			}
		);
	}

	/**
	 * Register get-top-crawlers ability
	 *
	 * Returns list of most active crawlers.
	 */
	private static function register_get_top_crawlers() {
		wp_register_ability(
			self::NAMESPACE . '/get-top-crawlers',
			array(
				'label'               => __( 'Get Top Crawlers', 'vigia' ),
				'description'         => __( 'Returns a list of the most active AI crawlers sorted by visit count.', 'vigia' ),
				'category'            => self::CATEGORY,
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'days'  => array(
							'type'        => 'integer',
							'description' => __( 'Number of days to analyze. Default 30.', 'vigia' ),
							'default'     => 30,
							'minimum'     => 1,
							'maximum'     => 365,
						),
						'limit' => array(
							'type'        => 'integer',
							'description' => __( 'Maximum number of crawlers to return. Default 10.', 'vigia' ),
							'default'     => 10,
							'minimum'     => 1,
							'maximum'     => 100,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'crawlers' => array(
							'type'        => 'array',
							'description' => __( 'List of crawlers with visit counts.', 'vigia' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'name'        => array(
										'type'        => 'string',
										'description' => __( 'Crawler name.', 'vigia' ),
									),
									'category'    => array(
										'type'        => 'string',
										'description' => __( 'Crawler category.', 'vigia' ),
									),
									'visit_count' => array(
										'type'        => 'integer',
										'description' => __( 'Number of visits.', 'vigia' ),
									),
								),
							),
						),
						'total'    => array(
							'type'        => 'integer',
							'description' => __( 'Total number of unique crawlers in period.', 'vigia' ),
						),
					),
				),
				'execute_callback'            => array( __CLASS__, 'execute_get_top_crawlers' ),
			)
		);
	}

	/**
	 * Execute get-top-crawlers ability
	 *
	 * @param array $input Input parameters.
	 * @return array Crawlers data.
	 */
	public static function execute_get_top_crawlers( $input = array() ) {
		$days  = isset( $input['days'] ) ? absint( $input['days'] ) : 30;
		$limit = isset( $input['limit'] ) ? absint( $input['limit'] ) : 10;

		$cache_key = self::cache_key_for(
			'get-top-crawlers',
			array(
				'days'  => $days,
				'limit' => $limit,
			)
		);

		return self::get_cached_or_generate(
			$cache_key,
			static function () use ( $days, $limit ) {
				$end_date   = gmdate( 'Y-m-d' );
				$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

				$crawlers = VigIA_Database::get_visits_by_crawler( $start_date, $end_date, $limit, 0 );
				$total    = VigIA_Database::get_crawlers_count( $start_date, $end_date );

				$formatted = array();
				foreach ( $crawlers as $crawler ) {
					$formatted[] = array(
						'name'        => $crawler['crawler_name'],
						'category'    => $crawler['crawler_category'],
						'visit_count' => (int) $crawler['visit_count'],
					);
				}

				return array(
					'crawlers' => $formatted,
					'total'    => $total,
				);
			}
		);
	}

	/**
	 * Register get-top-pages ability
	 *
	 * Returns most crawled pages.
	 */
	private static function register_get_top_pages() {
		wp_register_ability(
			self::NAMESPACE . '/get-top-pages',
			array(
				'label'               => __( 'Get Top Crawled Pages', 'vigia' ),
				'description'         => __( 'Returns a list of the most crawled pages on your site.', 'vigia' ),
				'category'            => self::CATEGORY,
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'days'  => array(
							'type'        => 'integer',
							'description' => __( 'Number of days to analyze. Default 30.', 'vigia' ),
							'default'     => 30,
							'minimum'     => 1,
							'maximum'     => 365,
						),
						'limit' => array(
							'type'        => 'integer',
							'description' => __( 'Maximum number of pages to return. Default 10.', 'vigia' ),
							'default'     => 10,
							'minimum'     => 1,
							'maximum'     => 100,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'pages' => array(
							'type'        => 'array',
							'description' => __( 'List of pages with visit counts.', 'vigia' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'url'           => array(
										'type'        => 'string',
										'description' => __( 'Page URL (request path).', 'vigia' ),
									),
									'visit_count'   => array(
										'type'        => 'integer',
										'description' => __( 'Number of crawler visits.', 'vigia' ),
									),
									'crawler_count' => array(
										'type'        => 'integer',
										'description' => __( 'Number of distinct crawlers that visited this page.', 'vigia' ),
									),
								),
							),
						),
						'total' => array(
							'type'        => 'integer',
							'description' => __( 'Total number of unique pages crawled in period.', 'vigia' ),
						),
					),
				),
				'execute_callback'            => array( __CLASS__, 'execute_get_top_pages' ),
			)
		);
	}

	/**
	 * Execute get-top-pages ability
	 *
	 * @param array $input Input parameters.
	 * @return array Pages data.
	 */
	public static function execute_get_top_pages( $input = array() ) {
		$days  = isset( $input['days'] ) ? absint( $input['days'] ) : 30;
		$limit = isset( $input['limit'] ) ? absint( $input['limit'] ) : 10;

		$cache_key = self::cache_key_for(
			'get-top-pages',
			array(
				'days'  => $days,
				'limit' => $limit,
			)
		);

		return self::get_cached_or_generate(
			$cache_key,
			static function () use ( $days, $limit ) {
				$end_date   = gmdate( 'Y-m-d' );
				$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

				$pages = VigIA_Database::get_top_pages( $start_date, $end_date, $limit, 0 );
				$total = VigIA_Database::get_pages_count( $start_date, $end_date );

				// The DB layer returns rows keyed by `request_path` (the
				// physical column name); the MCP output schema exposes
				// it as `url`, friendlier for clients that don't know
				// our internal column names. We also surface
				// `crawler_count`, which the DB query already returns
				// and is genuinely useful info.
				$formatted = array();
				foreach ( $pages as $page ) {
					$formatted[] = array(
						'url'           => isset( $page['request_path'] ) ? $page['request_path'] : '',
						'visit_count'   => isset( $page['visit_count'] ) ? (int) $page['visit_count'] : 0,
						'crawler_count' => isset( $page['crawler_count'] ) ? (int) $page['crawler_count'] : 0,
					);
				}

				return array(
					'pages' => $formatted,
					'total' => $total,
				);
			}
		);
	}

	/**
	 * Register get-blocked-items ability
	 *
	 * Returns current blocked crawlers and IPs.
	 */
	private static function register_get_blocked_items() {
		wp_register_ability(
			self::NAMESPACE . '/get-blocked-items',
			array(
				'label'               => __( 'Get Blocked Items', 'vigia' ),
				'description'         => __( 'Returns the list of currently blocked crawlers and IP addresses.', 'vigia' ),
				'category'            => self::CATEGORY,
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'input_schema'        => array(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'blocked_crawlers' => array(
							'type'        => 'array',
							'description' => __( 'List of blocked crawler User-Agents.', 'vigia' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'id'         => array(
										'type'        => 'string',
										'description' => __( 'Block ID.', 'vigia' ),
									),
									'name'       => array(
										'type'        => 'string',
										'description' => __( 'Display name for the block.', 'vigia' ),
									),
									'value'      => array(
										'type'        => 'string',
										'description' => __( 'Blocked User-Agent pattern.', 'vigia' ),
									),
									'created_at' => array(
										'type'        => 'string',
										'description' => __( 'When the block was created.', 'vigia' ),
									),
								),
							),
						),
						'blocked_ips'      => array(
							'type'        => 'array',
							'description' => __( 'List of blocked IP addresses.', 'vigia' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'id'         => array(
										'type'        => 'string',
										'description' => __( 'Block ID.', 'vigia' ),
									),
									'name'       => array(
										'type'        => 'string',
										'description' => __( 'Display name for the block.', 'vigia' ),
									),
									'value'      => array(
										'type'        => 'string',
										'description' => __( 'Blocked IP address.', 'vigia' ),
									),
									'created_at' => array(
										'type'        => 'string',
										'description' => __( 'When the block was created.', 'vigia' ),
									),
								),
							),
						),
						'total_blocks'     => array(
							'type'        => 'integer',
							'description' => __( 'Total number of active blocks.', 'vigia' ),
						),
					),
				),
				'execute_callback'            => array( __CLASS__, 'execute_get_blocked_items' ),
			)
		);
	}

	/**
	 * Execute get-blocked-items ability
	 *
	 * @param array $input Input parameters (unused).
	 * @return array Blocked items data.
	 */
	public static function execute_get_blocked_items( $input = array() ) {
		$blocks = VigIA_Blocker::get_all_blocks();

		$blocked_crawlers = array();
		$blocked_ips      = array();

		foreach ( $blocks as $block ) {
			// Internally blocks are stored with 'pattern' and
			// 'blocked_at'; the MCP output schema exposes them as
			// 'value' and 'created_at' (more generic, friendlier to
			// clients that don't know our internal column names).
			$item = array(
				'id'         => isset( $block['id'] ) ? $block['id'] : '',
				'name'       => isset( $block['name'] ) ? $block['name'] : '',
				'value'      => isset( $block['pattern'] ) ? $block['pattern'] : '',
				'created_at' => isset( $block['blocked_at'] ) ? $block['blocked_at'] : '',
			);

			$type = isset( $block['type'] ) ? $block['type'] : '';
			if ( 'useragent' === $type ) {
				$blocked_crawlers[] = $item;
			} elseif ( 'ip' === $type ) {
				$blocked_ips[] = $item;
			}
		}

		return array(
			'blocked_crawlers' => $blocked_crawlers,
			'blocked_ips'      => $blocked_ips,
			'total_blocks'     => count( $blocks ),
		);
	}

	/**
	 * Register block-crawler ability
	 *
	 * Blocks a crawler by User-Agent or IP.
	 */
	private static function register_block_crawler() {
		wp_register_ability(
			self::NAMESPACE . '/block-crawler',
			array(
				'label'               => __( 'Block Crawler', 'vigia' ),
				'description'         => __( 'Blocks an AI crawler by User-Agent pattern or IP address. Blocked crawlers receive a 403 Forbidden response.', 'vigia' ),
				'category'            => self::CATEGORY,
				'permission_callback' => array( __CLASS__, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'value', 'type' ),
					'properties' => array(
						'value' => array(
							'type'        => 'string',
							'description' => __( 'User-Agent pattern or IP address to block.', 'vigia' ),
						),
						'type'  => array(
							'type'        => 'string',
							'description' => __( 'Block type: "user_agent" or "ip".', 'vigia' ),
							'enum'        => array( 'user_agent', 'ip' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'  => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the block was created successfully.', 'vigia' ),
						),
						'block_id' => array(
							'type'        => 'string',
							'description' => __( 'ID of the created block (for removal).', 'vigia' ),
						),
						'message'  => array(
							'type'        => 'string',
							'description' => __( 'Result message.', 'vigia' ),
						),
					),
				),
				'execute_callback'            => array( __CLASS__, 'execute_block_crawler' ),
			)
		);
	}

	/**
	 * Execute block-crawler ability
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function execute_block_crawler( $input = array() ) {
		if ( empty( $input['value'] ) || empty( $input['type'] ) ) {
			return array(
				'success'  => false,
				'block_id' => '',
				'message'  => __( 'Both value and type are required.', 'vigia' ),
			);
		}

		$value = sanitize_text_field( $input['value'] );
		$type  = sanitize_text_field( $input['type'] );

		if ( ! in_array( $type, array( 'user_agent', 'ip' ), true ) ) {
			return array(
				'success'  => false,
				'block_id' => '',
				'message'  => __( 'Type must be "user_agent" or "ip".', 'vigia' ),
			);
		}

		if ( 'ip' === $type && ! filter_var( $value, FILTER_VALIDATE_IP ) ) {
			return array(
				'success'  => false,
				'block_id' => '',
				'message'  => __( 'Invalid IP address.', 'vigia' ),
			);
		}

		// VigIA_Blocker exposes type-specific methods (add_useragent_block
		// and add_ip_block) and returns a bool, not the block ID. We map
		// the public-facing `user_agent` / `ip` enum to the right method
		// and then recover the generated ID from the freshly stored entry.
		if ( 'ip' === $type ) {
			$created = VigIA_Blocker::add_ip_block( '', $value );
		} else {
			$created = VigIA_Blocker::add_useragent_block( $value, $value );
		}

		if ( ! $created ) {
			return array(
				'success'  => false,
				'block_id' => '',
				'message'  => __( 'Failed to create block. It may already exist.', 'vigia' ),
			);
		}

		$block_id               = '';
		$expected_internal_type = ( 'ip' === $type ) ? 'ip' : 'useragent';
		$blocks                 = VigIA_Blocker::get_all_blocks();
		foreach ( array_reverse( $blocks ) as $block ) {
			if ( isset( $block['type'], $block['pattern'] )
				&& $expected_internal_type === $block['type']
				&& $value === $block['pattern']
			) {
				$block_id = isset( $block['id'] ) ? $block['id'] : '';
				break;
			}
		}

		return array(
			'success'  => true,
			'block_id' => $block_id,
			'message'  => sprintf(
				/* translators: %s: blocked value */
				__( 'Successfully blocked "%s".', 'vigia' ),
				$value
			),
		);
	}

	/**
	 * Register unblock-crawler ability
	 *
	 * Removes a block by ID.
	 */
	private static function register_unblock_crawler() {
		wp_register_ability(
			self::NAMESPACE . '/unblock-crawler',
			array(
				'label'               => __( 'Unblock Crawler', 'vigia' ),
				'description'         => __( 'Removes a block by its ID. Use get-blocked-items to find block IDs.', 'vigia' ),
				'category'            => self::CATEGORY,
				'permission_callback' => array( __CLASS__, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'block_id' ),
					'properties' => array(
						'block_id' => array(
							'type'        => 'string',
							'description' => __( 'ID of the block to remove.', 'vigia' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the block was removed successfully.', 'vigia' ),
						),
						'message' => array(
							'type'        => 'string',
							'description' => __( 'Result message.', 'vigia' ),
						),
					),
				),
				'execute_callback'            => array( __CLASS__, 'execute_unblock_crawler' ),
			)
		);
	}

	/**
	 * Execute unblock-crawler ability
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function execute_unblock_crawler( $input = array() ) {
		if ( empty( $input['block_id'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Block ID is required.', 'vigia' ),
			);
		}

		$block_id = sanitize_text_field( $input['block_id'] );
		$result   = VigIA_Blocker::remove_block( $block_id );

		if ( $result ) {
			return array(
				'success' => true,
				'message' => __( 'Block has been removed.', 'vigia' ),
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Failed to remove block. ID may not exist.', 'vigia' ),
		);
	}

	/**
	 * Register get-robots-rules ability
	 *
	 * Returns current robots.txt AI crawler rules.
	 */
	private static function register_get_robots_rules() {
		wp_register_ability(
			self::NAMESPACE . '/get-robots-rules',
			array(
				'label'               => __( 'Get Robots.txt Rules', 'vigia' ),
				'description'         => __( 'Returns the current AI crawler rules configured in robots.txt (Disallow and Allow directives).', 'vigia' ),
				'category'            => self::CATEGORY,
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'input_schema'        => array(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'disallow'    => array(
							'type'        => 'array',
							'description' => __( 'List of crawlers with Disallow directive.', 'vigia' ),
							'items'       => array(
								'type' => 'string',
							),
						),
						'allow'       => array(
							'type'        => 'array',
							'description' => __( 'List of crawlers with explicit Allow directive.', 'vigia' ),
							'items'       => array(
								'type' => 'string',
							),
						),
						'total_rules' => array(
							'type'        => 'integer',
							'description' => __( 'Total number of AI crawler rules.', 'vigia' ),
						),
					),
				),
				'execute_callback'            => array( __CLASS__, 'execute_get_robots_rules' ),
			)
		);
	}

	/**
	 * Execute get-robots-rules ability
	 *
	 * @param array $input Input parameters (unused).
	 * @return array Rules data.
	 */
	public static function execute_get_robots_rules( $input = array() ) {
		$rules = VigIA_Robots_Manager::get_ai_rules();

		return array(
			'disallow'    => array_values( $rules['disallow'] ),
			'allow'       => array_values( $rules['allow'] ),
			'total_rules' => count( $rules['disallow'] ) + count( $rules['allow'] ),
		);
	}

	/**
	 * Register add-robots-disallow ability
	 *
	 * Adds a Disallow rule for a crawler in robots.txt.
	 */
	private static function register_add_robots_disallow() {
		wp_register_ability(
			self::NAMESPACE . '/add-robots-disallow',
			array(
				'label'               => __( 'Add Robots.txt Disallow Rule', 'vigia' ),
				'description'         => __( 'Adds a Disallow directive for an AI crawler in robots.txt. Well-behaved crawlers will respect this and stop crawling.', 'vigia' ),
				'category'            => self::CATEGORY,
				'permission_callback' => array( __CLASS__, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'crawler' ),
					'properties' => array(
						'crawler' => array(
							'type'        => 'string',
							'description' => __( 'Crawler User-Agent name (e.g., GPTBot, ClaudeBot, PerplexityBot).', 'vigia' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the rule was added successfully.', 'vigia' ),
						),
						'message' => array(
							'type'        => 'string',
							'description' => __( 'Result message.', 'vigia' ),
						),
					),
				),
				'execute_callback'            => array( __CLASS__, 'execute_add_robots_disallow' ),
			)
		);
	}

	/**
	 * Execute add-robots-disallow ability
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function execute_add_robots_disallow( $input = array() ) {
		if ( empty( $input['crawler'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Crawler name is required.', 'vigia' ),
			);
		}

		$crawler = sanitize_text_field( $input['crawler'] );

		// Check if already disallowed.
		if ( VigIA_Robots_Manager::is_disallowed( $crawler ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: crawler name */
					__( '"%s" is already disallowed in robots.txt.', 'vigia' ),
					$crawler
				),
			);
		}

		$result = VigIA_Robots_Manager::add_disallow( $crawler );

		if ( $result ) {
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: crawler name */
					__( 'Disallow rule added for "%s" in robots.txt.', 'vigia' ),
					$crawler
				),
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Failed to add robots.txt rule.', 'vigia' ),
		);
	}

	/**
	 * Register remove-robots-rule ability
	 *
	 * Removes a crawler rule from robots.txt.
	 */
	private static function register_remove_robots_rule() {
		wp_register_ability(
			self::NAMESPACE . '/remove-robots-rule',
			array(
				'label'               => __( 'Remove Robots.txt Rule', 'vigia' ),
				'description'         => __( 'Removes a Disallow or Allow rule for a crawler from robots.txt.', 'vigia' ),
				'category'            => self::CATEGORY,
				'permission_callback' => array( __CLASS__, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'crawler' ),
					'properties' => array(
						'crawler' => array(
							'type'        => 'string',
							'description' => __( 'Crawler User-Agent name to remove from rules.', 'vigia' ),
						),
						'type'    => array(
							'type'        => 'string',
							'description' => __( 'Rule type to remove: "disallow" or "allow". Default "disallow".', 'vigia' ),
							'default'     => 'disallow',
							'enum'        => array( 'disallow', 'allow' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the rule was removed successfully.', 'vigia' ),
						),
						'message' => array(
							'type'        => 'string',
							'description' => __( 'Result message.', 'vigia' ),
						),
					),
				),
				'execute_callback'            => array( __CLASS__, 'execute_remove_robots_rule' ),
			)
		);
	}

	/**
	 * Execute remove-robots-rule ability
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function execute_remove_robots_rule( $input = array() ) {
		if ( empty( $input['crawler'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Crawler name is required.', 'vigia' ),
			);
		}

		$crawler = sanitize_text_field( $input['crawler'] );
		$type    = isset( $input['type'] ) ? sanitize_text_field( $input['type'] ) : 'disallow';

		if ( 'allow' === $type ) {
			$result = VigIA_Robots_Manager::remove_allow( $crawler );
		} else {
			$result = VigIA_Robots_Manager::remove_disallow( $crawler );
		}

		if ( $result ) {
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: 1: rule type, 2: crawler name */
					__( '%1$s rule for "%2$s" has been removed from robots.txt.', 'vigia' ),
					ucfirst( $type ),
					$crawler
				),
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Failed to remove robots.txt rule.', 'vigia' ),
		);
	}
}