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
	 * Zero-based position of create_server()'s transport permission callback.
	 *
	 * The callback is passed positionally, so this slot is load-bearing for
	 * access control. See adapter_accepts_permission_callback().
	 *
	 * @var int
	 */
	const PERMISSION_CALLBACK_ARG = 12;

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
	 * Permission gate for the MCP transport endpoint itself.
	 *
	 * Passed to the adapter as the transport permission callback so the
	 * endpoint does not fall back to the adapter's own default, which is
	 * current_user_can( 'read' ) and therefore open to any subscriber.
	 * Every ability already requires manage_options on its own, so a
	 * subscriber could never invoke one; what this closes is the ability
	 * to reach the endpoint and enumerate the available tools.
	 *
	 * manage_options is a per-site capability, which is the right bar
	 * here: the abilities that write the files shared by a whole network
	 * carry their own network gate (see
	 * VigIA_Robots_Manager::current_user_can_manage_root_files()).
	 *
	 * @since 2.6.3
	 *
	 * @param WP_REST_Request $request Incoming request. Unused, kept for
	 *                                 the adapter's callback signature.
	 * @return bool
	 */
	public static function check_transport_permission( $request = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature required by the MCP Adapter.
		/**
		 * Filters the capability required to reach the VigIA MCP endpoint.
		 *
		 * Only gates the transport. Each ability keeps its own permission
		 * check, so relaxing this does not grant the right to run them.
		 *
		 * @since 2.6.3
		 *
		 * @param string $capability Capability required. Default 'manage_options'.
		 */
		$capability = apply_filters( 'vigia_mcp_transport_capability', 'manage_options' );

		if ( ! is_string( $capability ) || '' === $capability ) {
			$capability = 'manage_options';
		}

		return current_user_can( $capability ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Filtered capability, defaults to manage_options.
	}

	/**
	 * Whether a usable WordPress MCP Adapter is loaded.
	 *
	 * Checks that the class is autoloaded AND that its create_server() still
	 * takes our transport permission callback, because an adapter we cannot
	 * lock down is worse than no adapter at all. The adapter will additionally
	 * bail out at runtime if the Abilities API is not available, so a true
	 * here does not guarantee the MCP routes are actually registered. Use
	 * is_mcp_active() for the full readiness check.
	 *
	 * @return bool
	 */
	public static function is_adapter_available() {
		return class_exists( '\\WP\\MCP\\Core\\McpAdapter' )
			&& self::adapter_accepts_permission_callback();
	}

	/**
	 * Whether the loaded adapter still takes our permission callback.
	 *
	 * VigIA passes check_transport_permission() positionally, as the 13th
	 * argument of create_server(). VigIA bundles its own copy of the adapter,
	 * but WooCommerce, WP Rocket and Elementor bundle or consume it too, and
	 * whichever autoloader is registered first wins, so the signature we call
	 * is not necessarily the one we ship. If that slot ever stops being the
	 * permission callback, the argument is silently ignored, HttpTransport
	 * falls back to its own default of current_user_can( 'read' ), and every
	 * subscriber on the site reaches the MCP endpoint.
	 *
	 * Verified identical across adapter 0.3.0, 0.5.0, 0.6.1 and upstream trunk,
	 * so this never fires today. It exists so a future signature change downs
	 * the server instead of quietly opening it.
	 *
	 * @return bool
	 */
	public static function adapter_accepts_permission_callback() {
		static $accepts = null;

		if ( null !== $accepts ) {
			return $accepts;
		}

		// Answered without caching while the class is still unloaded, so an
		// early caller cannot freeze a false for the rest of the request.
		if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			return false;
		}

		try {
			$params = ( new \ReflectionMethod( '\\WP\\MCP\\Core\\McpAdapter', 'create_server' ) )->getParameters();
		} catch ( \ReflectionException $e ) {
			$accepts = false;

			return $accepts;
		}

		$accepts = isset( $params[ self::PERMISSION_CALLBACK_ARG ] )
			&& 'transport_permission_callback' === $params[ self::PERMISSION_CALLBACK_ARG ]->getName();

		return $accepts;
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

		// Fail closed. No MCP server at all beats one whose endpoint answers
		// to any logged-in subscriber. See adapter_accepts_permission_callback().
		if ( ! self::adapter_accepts_permission_callback() ) {
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
			array(),
			array( __CLASS__, 'check_transport_permission' )
		);
	}
}
