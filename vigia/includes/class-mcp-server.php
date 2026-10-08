<?php
/**
 * MCP server registration.
 *
 * Exposes VigIA abilities as Model Context Protocol tools through the
 * official WordPress MCP Adapter so any MCP-compatible client (Claude
 * Code, Cursor, Claude Desktop) can discover and invoke them on the
 * site where VigIA is installed.
 *
 * The adapter is not part of this plugin (2.8.0). From 1.12.0 to 2.7.0 a
 * copy shipped under vendor/ and VigIA booted it. Now the server is off
 * until the site owner turns it on, and it registers only when something
 * else boots the adapter: the MCP Adapter plugin, or another plugin that
 * carries a copy of its own.
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
	 * Option behind the on/off switch. '1' or '0', autoloaded, and present on
	 * every site that has been through VigIA::maybe_upgrade_version().
	 *
	 * @since 2.8.0
	 */
	const OPTION_ENABLED = 'vigia_mcp_enabled';

	/**
	 * Option behind the notice for a site that had the server in use and was
	 * left without an adapter by the update. '1' or '0', lowered and never
	 * deleted, so reading it costs no query.
	 *
	 * @since 2.8.0
	 */
	const OPTION_NOTICE = 'vigia_mcp_adapter_notice';

	/**
	 * Main file of the MCP Adapter plugin, relative to the plugins folder.
	 *
	 * @since 2.8.0
	 */
	const ADAPTER_PLUGIN = 'mcp-adapter/mcp-adapter.php';

	/**
	 * Whether register_server() got the server into the adapter on this request.
	 *
	 * @since 2.8.0
	 *
	 * @var bool
	 */
	private static $registered = false;

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
	 * here: the files shared by a whole network are only written from its
	 * main site (see VigIA_Robots_Manager::owns_root_files()).
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
	 * Name the MCP clients know this site's server by.
	 *
	 * It only lives in the client's own config (the `claude mcp add` command,
	 * the key under `mcpServers`), so it has to tell one site from another
	 * there: `vigia-example-com`, or `vigia-example-com-shop` for a site in a
	 * subdirectory. Lowercase letters, digits and hyphens, which every client
	 * takes and need no quoting on a command line.
	 *
	 * The address is cut by hand: parse_url() mangles raw UTF-8 on recent PHP.
	 *
	 * @since 2.8.0
	 *
	 * @return string
	 */
	public static function get_client_server_name() {
		$address = (string) preg_replace( '~^[a-z][a-z0-9+.\-]*://~i', '', (string) home_url() );
		$address = substr( $address, 0, strcspn( $address, '?#' ) );
		$address = (string) preg_replace( '/^www\./', '', strtolower( $address ) );
		$slug    = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $address ), '-' );
		$slug    = rtrim( substr( $slug, 0, 50 ), '-' );

		return '' === $slug ? 'vigia' : 'vigia-' . $slug;
	}

	/**
	 * Whether the site owner has turned the MCP server on.
	 *
	 * Off unless the switch in VigIA > Extras > MCP says otherwise. A site
	 * updated from a version that had no switch keeps the server on until
	 * VigIA::maybe_upgrade_version() decides, on the first admin page load, so
	 * an unattended update does not cut off a client that was connected.
	 *
	 * @since 2.8.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$value = get_option( self::OPTION_ENABLED, null );

		if ( null === $value ) {
			$stored = get_option( 'vigia_version', '0.0.0' );

			// A released version number and nothing else: version_compare()
			// takes an empty string, or anything it cannot read, as lower.
			return is_string( $stored )
				&& 1 === preg_match( '/^\d+\.\d+\.\d+$/', $stored )
				&& '0.0.0' !== $stored
				&& version_compare( $stored, '2.8.0', '<' );
		}

		// Only the string the switch writes turns it on. is_scalar() first: an
		// option somebody else left as an array would otherwise warn on a cast.
		return is_scalar( $value ) && '1' === (string) $value;
	}

	/**
	 * Whether VigIA's server made it into the adapter on this request.
	 *
	 * The adapter does its work on rest_api_init (on init under WP-CLI), and
	 * whoever boots it may do so that late as well, so nothing short of
	 * starting the REST server tells whether the endpoint exists. A loadable
	 * adapter class does not: WooCommerce carries a copy that it only boots
	 * behind a feature flag.
	 *
	 * Starts the REST server, so it is meant for the screens that report the
	 * status, not for every request.
	 *
	 * @since 2.8.0
	 *
	 * @return bool
	 */
	public static function is_server_registered() {
		if ( ! self::is_enabled() ) {
			return false;
		}

		if ( ! self::$registered && ! did_action( 'rest_api_init' ) ) {
			rest_get_server();
		}

		return self::$registered;
	}

	/**
	 * Whether the MCP Adapter plugin itself is active on this site.
	 *
	 * @since 2.8.0
	 *
	 * @return bool
	 */
	public static function is_adapter_plugin_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( self::ADAPTER_PLUGIN );
	}

	/**
	 * Which copy of the adapter would run, and where it comes from.
	 *
	 * Several plugins carry the adapter and the newest copy among them wins the
	 * autoload, so the version in use is a fact about the site, not about any
	 * one plugin. The MCP tab shows it.
	 *
	 * @since 2.8.0
	 *
	 * @return array{available:bool,version:string,folder:string,official:bool}
	 */
	public static function get_adapter_info() {
		$info = array(
			'available' => false,
			'version'   => '',
			'folder'    => '',
			'official'  => false,
		);

		if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			return $info;
		}

		$info['available'] = true;

		if ( defined( '\\WP\\MCP\\Core\\McpAdapter::VERSION' ) ) {
			$version = constant( '\\WP\\MCP\\Core\\McpAdapter::VERSION' );

			$info['version'] = is_scalar( $version ) ? (string) $version : '';
		}

		try {
			$file = ( new \ReflectionClass( '\\WP\\MCP\\Core\\McpAdapter' ) )->getFileName();
		} catch ( \ReflectionException $e ) {
			$file = false;
		}

		if ( is_string( $file ) ) {
			$file    = wp_normalize_path( $file );
			$plugins = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );

			if ( 0 === strpos( $file, $plugins ) ) {
				$info['folder'] = (string) strtok( substr( $file, strlen( $plugins ) ), '/' );
			}
		}

		$info['official'] = dirname( self::ADAPTER_PLUGIN ) === $info['folder'];

		// Where the plugins folder is a symlink the real path of the class does
		// not start with WP_PLUGIN_DIR. WP_MCP_DIR is set by the plugin itself,
		// from its own real path.
		if ( ! $info['official'] && is_string( $file ) && defined( 'WP_MCP_DIR' ) && is_string( WP_MCP_DIR )
			&& 0 === strpos( $file, trailingslashit( wp_normalize_path( WP_MCP_DIR ) ) )
			&& self::is_adapter_plugin_active() ) {
			$info['official'] = true;
			$info['folder']   = dirname( self::ADAPTER_PLUGIN );
		}

		return $info;
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
	 * argument of create_server(). VigIA no longer ships the adapter (2.8.0):
	 * the copy that runs is the MCP Adapter plugin's, or the one WooCommerce,
	 * Elementor or Rank Math carry, whichever is newest, so the signature we
	 * call is never one we chose. If that slot ever stops being the
	 * permission callback, the argument is silently ignored, HttpTransport
	 * falls back to its own default of current_user_can( 'read' ), and every
	 * subscriber on the site reaches the MCP endpoint.
	 *
	 * Verified identical across adapter 0.3.0, 0.5.0, 0.6.1 and 0.7.0, so this
	 * never fires today. It exists so a future signature change downs
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
	 * True only when the switch is on, the Abilities API is loaded and the
	 * server is in the adapter, which is the actual condition for the REST
	 * routes to exist. Until 2.7.0 a loadable adapter was enough, because
	 * VigIA booted it itself.
	 *
	 * @return bool
	 */
	public static function is_mcp_active() {
		return self::is_abilities_api_available() && self::is_server_registered();
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
		// Off until the site owner turns it on (2.8.0). Another plugin booting
		// the adapter must not bring VigIA's endpoint up with it.
		if ( ! self::is_enabled() ) {
			return;
		}

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

		$result = $adapter->create_server(
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

		// create_server() answers with a WP_Error when it refuses the server.
		// Asked back as well where the adapter allows it, so the status the MCP
		// tab reports is the adapter's and not ours.
		self::$registered = ! is_wp_error( $result )
			&& ( ! method_exists( $adapter, 'get_server' ) || null !== $adapter->get_server( self::SERVER_ID ) );
	}
}
