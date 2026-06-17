/**
 * Command Palette integration for VigIA.
 *
 * Registers VigIA actions in the WordPress Command Palette (Cmd/Ctrl+K), which
 * works across the whole admin since WordPress 6.9: regenerate llms.txt and
 * regenerate llms-full.txt from the saved configuration.
 *
 * Navigation commands are intentionally omitted: WordPress already auto-adds a
 * palette command for every admin menu page, so ours would only duplicate them.
 *
 * Uses the imperative store API (wp.data.dispatch) so the commands work on all
 * admin screens, the same pattern as Multiple Sale Prices Scheduler. Labels come
 * from PHP via wp_localize_script(). No build tools required.
 *
 * @since 2.1.0
 */
( function () {
	'use strict';

	var dispatch      = wp.data.dispatch;
	var commandsStore = wp.commands.store;
	var createElement = wp.element.createElement;
	var SVG           = wp.primitives.SVG;
	var Path          = wp.primitives.Path;

	// Labels and data from PHP.
	var config = vigiaCommandPalette;

	/* -------------------------------------------
	 * Icon
	 * ----------------------------------------- */

	var refreshIcon = createElement(
		SVG, { xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24' },
		createElement( Path, {
			d: 'M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 ' +
			   '8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 ' +
			   '4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 ' +
			   '1.78L13 11h7V4l-2.35 2.35z'
		} )
	);

	/* -------------------------------------------
	 * Helpers
	 * ----------------------------------------- */

	/**
	 * Self-dismissing status toast. Admin screens don't all render the
	 * core/notices snackbar, so the palette injects its own element.
	 *
	 * @param {string} message Text to show.
	 */
	function toast( message ) {
		if ( ! message ) {
			return;
		}
		var node = document.createElement( 'div' );
		node.textContent = message;
		node.setAttribute( 'role', 'status' );
		node.style.cssText = 'position:fixed;left:50%;bottom:32px;transform:translateX(-50%);' +
			'background:#1e1e1e;color:#fff;padding:12px 20px;border-radius:4px;font-size:13px;' +
			'line-height:1.4;max-width:90vw;z-index:160000;box-shadow:0 4px 16px rgba(0,0,0,.35)';
		document.body.appendChild( node );
		window.setTimeout( function () {
			if ( node.parentNode ) {
				node.parentNode.removeChild( node );
			}
		}, 4000 );
	}

	/**
	 * POST to admin-ajax to regenerate one of the llms files.
	 *
	 * @param {string} which 'llms' or 'full'.
	 */
	function regenerate( which ) {
		toast( config.labels.running );

		var body = new window.URLSearchParams();
		body.set( 'action', 'vigia_command_regenerate_llms' );
		body.set( 'nonce', config.nonce );
		body.set( 'which', which );

		window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( result ) {
			if ( result && result.success ) {
				toast( config.labels.done );
			} else {
				toast( ( result && result.data ) ? result.data : config.labels.error );
			}
		} ).catch( function () {
			toast( config.labels.error );
		} );
	}

	/* -------------------------------------------
	 * Commands
	 * ----------------------------------------- */

	dispatch( commandsStore ).registerCommand( {
		name: 'vigia/regenerate-llms',
		label: config.labels.regenerateLlms,
		icon: refreshIcon,
		callback: function ( options ) {
			options.close();
			regenerate( 'llms' );
		}
	} );

	dispatch( commandsStore ).registerCommand( {
		name: 'vigia/regenerate-llms-full',
		label: config.labels.regenerateFull,
		icon: refreshIcon,
		callback: function ( options ) {
			options.close();
			regenerate( 'full' );
		}
	} );

} )();
