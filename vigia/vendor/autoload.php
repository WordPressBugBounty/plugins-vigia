<?php
/**
 * Minimal PSR-4 autoloader for VigIA bundled dependencies.
 *
 * Replaces Composer's generated autoload.php so the plugin can be
 * shipped self-contained without requiring `composer install` on the
 * target site. Maps the two namespaces VigIA actually consumes:
 *
 *   - WP\MCP\        → vendor/wordpress/mcp-adapter/includes/
 *   - WP\McpSchema\  → vendor/wordpress/php-mcp-schema/src/
 *
 * The bundled MCP Adapter ships its own Autoloader.php; vigia.php
 * disables it via WP_MCP_AUTOLOAD=false so this loader is the single
 * source of truth.
 *
 * @package VigIA
 * @since 1.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( $class ) {
		static $prefixes = array(
			'WP\\MCP\\'       => __DIR__ . '/wordpress/mcp-adapter/includes/',
			'WP\\McpSchema\\' => __DIR__ . '/wordpress/php-mcp-schema/src/',
		);

		foreach ( $prefixes as $prefix => $base_dir ) {
			$len = strlen( $prefix );
			if ( 0 !== strncmp( $prefix, $class, $len ) ) {
				continue;
			}

			$relative = substr( $class, $len );
			$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_file( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
);

return true;
