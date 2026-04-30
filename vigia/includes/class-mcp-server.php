<?php
/**
 * MCP server registration.
 *
 * Exposes VigIA abilities as Model Context Protocol tools through the
 * official WordPress MCP Adapter so any MCP-compatible client (Claude
 * Code, Cursor, Claude Desktop) can discover and invoke them on the
 * site where VigIA is installed.
 *
 * Since 1.12.0 the adapter and its php-mcp-schema dependency ship
 * bundled inside the plugin under vendor/, so no Composer step is
 * required on the target site.
 *
 * @package VigIA
 * @since 1.11.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MCP server class
 */
class VigIA_MCP_Server {

	/**
	 * REST API namespace for the MCP endpoint.
	 */
	const ROUTE_NAMESPACE = 'vigia/v1';

	/**
	 * REST API route for the MCP endpoint.
	 */
	const ROUTE = 'mcp';

	/**
	 * Internal server identifier.
	 */
	const SERVER_ID = 'vigia';

	/**
	 * Server protocol version exposed to MCP clients.
	 */
	const SERVER_VERSION = 'v1';

	/**
	 * Hook registration.
	 */
	public static function init() {
		// Server registration runs only when the adapter fires its init hook.
		add_action( 'mcp_adapter_init', array( __CLASS__, 'register_server' ) );

		// Apply the user-facing read-only toggle (option vigia_mcp_read_only)
		// to the canonical filter that gates write abilities. We register
		// at priority 5 so a mu-plugin using `__return_false` at the
		// default priority 10 still wins; conversely, when the toggle is
		// off we leave the upstream value untouched.
		add_filter( 'vigia_can_write_via_abilities', array( __CLASS__, 'apply_read_only_option' ), 5 );
	}

	/**
	 * Force the write filter to false when the read-only option is on.
	 *
	 * @param bool $allowed Current decision from upstream filters.
	 * @return bool
	 */
	public static function apply_read_only_option( $allowed ) {
		if ( get_option( 'vigia_mcp_read_only', false ) ) {
			return false;
		}
		return $allowed;
	}

	/**
	 * Whether the WordPress MCP Adapter is loaded.
	 *
	 * Only checks that the class is autoloaded. The adapter will additionally
	 * bail out at runtime if the Abilities API is not available, so a true
	 * here does not guarantee the MCP routes are actually registered. Use
	 * is_mcp_active() for the full readiness check.
	 *
	 * @return bool
	 */
	public static function is_adapter_available() {
		return class_exists( '\\WP\\MCP\\Core\\McpAdapter' );
	}

	/**
	 * Whether the Abilities API is loaded.
	 *
	 * The MCP Adapter's bootstrap requires `wp_register_ability` and bails
	 * silently when missing. Until the Abilities API ships in WordPress
	 * core, users must install the canonical Abilities API plugin
	 * (https://github.com/WordPress/abilities-api).
	 *
	 * @return bool
	 */
	public static function is_abilities_api_available() {
		return function_exists( 'wp_register_ability' );
	}

	/**
	 * Whether the MCP server is fully operational.
	 *
	 * True only when both the adapter and the Abilities API are loaded,
	 * which is the actual condition for the REST routes to be registered.
	 *
	 * @return bool
	 */
	public static function is_mcp_active() {
		return self::is_adapter_available() && self::is_abilities_api_available();
	}

	/**
	 * Register the VigIA MCP server.
	 *
	 * Called on the mcp_adapter_init action. Receives the adapter
	 * instance from the action arguments.
	 *
	 * @param object $adapter Adapter instance provided by the action.
	 */
	public static function register_server( $adapter ) {
		if ( ! self::is_adapter_available() ) {
			return;
		}

		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$tools = array(
			'vigia/get-crawler-stats',
			'vigia/get-top-crawlers',
			'vigia/get-top-pages',
			'vigia/get-blocked-items',
			'vigia/get-robots-rules',
			'vigia/block-crawler',
			'vigia/unblock-crawler',
			'vigia/add-robots-disallow',
			'vigia/remove-robots-rule',
		);

		$adapter->create_server(
			self::SERVER_ID,
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			__( 'VigIA AI Crawler Control', 'vigia' ),
			__( 'Monitor and control AI crawler activity on this WordPress site.', 'vigia' ),
			self::SERVER_VERSION,
			array(
				'\\WP\\MCP\\Transport\\HttpTransport',
			),
			'\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler',
			'\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler',
			$tools,
			array(),
			array()
		);
	}
}
