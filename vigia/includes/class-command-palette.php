<?php
/**
 * Command Palette integration class
 *
 * Registers VigIA actions in the WordPress Command Palette (Cmd/Ctrl+K), which
 * spans the whole admin since WordPress 6.9: regenerate llms.txt and
 * regenerate llms-full.txt from the saved configuration without leaving the
 * current screen.
 *
 * Navigation to VigIA's admin screens is intentionally NOT registered here:
 * WordPress already auto-adds a palette command for every admin menu page, so
 * registering our own would only duplicate them. We add the actions WordPress
 * can't generate on its own.
 *
 * Uses the imperative store API (wp.data.dispatch on wp.commands.store), the
 * same pattern as Multiple Sale Prices Scheduler. No build step: plain JS with
 * every label passed from PHP via wp_localize_script().
 *
 * @package VigIA
 * @since 2.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Command Palette class
 */
class VigIA_Command_Palette {

	/**
	 * Nonce action shared by the command palette AJAX endpoint.
	 */
	const NONCE_ACTION = 'vigia_command_palette';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_vigia_command_regenerate_llms', array( __CLASS__, 'ajax_regenerate_llms' ) );
	}

	/**
	 * Enqueue the command palette script for users who can manage the plugin.
	 *
	 * Labels travel from PHP so the script needs no JSON translation files.
	 */
	public static function enqueue_assets() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Both enqueue hooks can fire on block-editor screens; load only once.
		if ( wp_script_is( 'vigia-command-palette', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			'vigia-command-palette',
			VIGIA_PLUGIN_URL . 'assets/js/command-palette.js',
			array( 'wp-commands', 'wp-element', 'wp-primitives', 'wp-data' ),
			VIGIA_VERSION,
			true
		);

		wp_localize_script(
			'vigia-command-palette',
			'vigiaCommandPalette',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'labels'  => array(
					'regenerateLlms' => __( 'VigIA: Regenerate llms.txt now', 'vigia' ),
					'regenerateFull' => __( 'VigIA: Regenerate llms-full.txt now', 'vigia' ),
					'running'        => __( 'VigIA: regenerating…', 'vigia' ),
					'done'           => __( 'VigIA: llms file regenerated.', 'vigia' ),
					'error'          => __( 'VigIA: could not regenerate the llms file.', 'vigia' ),
				),
			)
		);
	}

	/**
	 * AJAX: regenerate one llms file from the currently saved configuration.
	 *
	 * The existing vigia_generate_llms handler rebuilds settings from the LLMs
	 * form POST, so it can't be reused from the palette. Here we regenerate the
	 * requested file (llms.txt or llms-full.txt) from the stored settings without
	 * touching them; an unconfigured site (or a disabled full file) gets the
	 * generator's own WP_Error surfaced to the toast.
	 */
	public static function ajax_regenerate_llms() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized', 'vigia' ) );
		}

		if ( ! class_exists( 'VigIA_LLMS_Generator' ) ) {
			wp_send_json_error( __( 'The LLMs.txt generator is not available.', 'vigia' ) );
		}

		$which  = ( isset( $_POST['which'] ) && 'full' === sanitize_key( wp_unslash( $_POST['which'] ) ) ) ? 'full' : 'llms';
		$result = VigIA_LLMS_Generator::regenerate_file( $which );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}
}
