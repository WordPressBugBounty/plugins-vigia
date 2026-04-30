/**
 * VigIA AI Visibility Scripts
 *
 * Handles AJAX analysis requests, URL autocomplete, result rendering
 * with accordions, and smart recommendation display.
 *
 * @package VigIA
 * @since   1.8.0
 */

/* global jQuery, vigiaVisData */
(function( $ ) {
	'use strict';

	var searchTimer = null;
	var isAnalyzing = false;

	// ------------------------------------------------------------------
	// Initialization
	// ------------------------------------------------------------------

	$( document ).ready( function() {
		var $analyzeBtn = $( '#vigia-vis-analyze-btn' );
		var $homeBtn    = $( '#vigia-vis-home-btn' );
		var $urlInput   = $( '#vigia-vis-url-input' );
		var $refToggle  = $( '#vigia-vis-reference-toggle' );

		if ( ! $analyzeBtn.length ) {
			return;
		}

		// Analyze button.
		$analyzeBtn.on( 'click', function() {
			runAnalysis();
		});

		// Home button: reset to homepage and analyze.
		$homeBtn.on( 'click', function() {
			$urlInput.val( vigiaVisData.homeUrl );
			runAnalysis();
		});

		// Enter key in input.
		$urlInput.on( 'keydown', function( e ) {
			if ( 13 === e.keyCode ) {
				e.preventDefault();
				$( '#vigia-vis-autocomplete' ).hide();
				runAnalysis();
			}
		});

		// URL autocomplete.
		$urlInput.on( 'input', function() {
			var val = $( this ).val().trim();

			// If it looks like a URL, skip autocomplete.
			if ( val.indexOf( 'http' ) === 0 || val.indexOf( '/' ) === 0 ) {
				$( '#vigia-vis-autocomplete' ).hide();
				return;
			}

			clearTimeout( searchTimer );
			if ( val.length < 2 ) {
				$( '#vigia-vis-autocomplete' ).hide();
				return;
			}

			searchTimer = setTimeout( function() {
				searchUrls( val );
			}, 300 );
		});

		// Close autocomplete on outside click.
		$( document ).on( 'click', function( e ) {
			if ( ! $( e.target ).closest( '.vigia-vis-url-wrapper' ).length ) {
				$( '#vigia-vis-autocomplete' ).hide();
			}
		});

		// Scoring reference toggle.
		$refToggle.on( 'click', function() {
			var $content = $( '#vigia-vis-reference-content' );
			var isOpen   = $content.is( ':visible' );
			if ( isOpen ) {
				$content.hide();
			} else {
				$content.css( 'display', 'flex' );
			}
			$( this ).attr( 'aria-expanded', ! isOpen );
			$( this ).find( '.vigia-vis-toggle-icon' ).text( isOpen ? '\u25B6' : '\u25BC' );
		});

		// Auto-analyze on page load.
		runAnalysis();
	});

	// ------------------------------------------------------------------
	// URL Autocomplete
	// ------------------------------------------------------------------

	/**
	 * Search for internal URLs via AJAX.
	 *
	 * @param {string} term Search term.
	 */
	function searchUrls( term ) {
		$.ajax({
			url:  vigiaVisData.ajaxUrl,
			type: 'POST',
			data: {
				action: 'vigia_search_visibility_urls',
				nonce:  vigiaVisData.ajaxNonce,
				search: term
			},
			success: function( response ) {
				if ( response.success && response.data.length > 0 ) {
					renderAutocomplete( response.data );
				} else {
					$( '#vigia-vis-autocomplete' ).hide();
				}
			}
		});
	}

	/**
	 * Render autocomplete dropdown.
	 *
	 * @param {Array} results Array of {id, title, url, type}.
	 */
	function renderAutocomplete( results ) {
		var $dropdown = $( '#vigia-vis-autocomplete' );
		var html      = '';

		$.each( results, function( i, item ) {
			html += '<div class="vigia-vis-ac-item" data-url="' + escHtml( item.url ) + '">';
			html += '<span class="vigia-vis-ac-title">' + escHtml( item.title ) + '</span>';
			html += '<span class="vigia-vis-ac-type">' + escHtml( item.type ) + '</span>';
			html += '</div>';
		});

		$dropdown.html( html ).show();

		// Click handler for autocomplete items.
		$dropdown.find( '.vigia-vis-ac-item' ).on( 'click', function() {
			$( '#vigia-vis-url-input' ).val( $( this ).data( 'url' ) );
			$dropdown.hide();
			runAnalysis();
		});
	}

	// ------------------------------------------------------------------
	// Analysis
	// ------------------------------------------------------------------

	/**
	 * Run the visibility analysis via AJAX.
	 */
	function runAnalysis() {
		if ( isAnalyzing ) {
			return;
		}

		var url = $( '#vigia-vis-url-input' ).val().trim();
		if ( ! url ) {
			url = vigiaVisData.homeUrl;
			$( '#vigia-vis-url-input' ).val( url );
		}

		isAnalyzing = true;
		var $btn     = $( '#vigia-vis-analyze-btn' );
		var $results = $( '#vigia-vis-results' );

		$btn.prop( 'disabled', true ).text( vigiaVisData.strings.analyzing );
		$results.show().html(
			'<div class="vigia-vis-loading">' +
				'<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>' +
				vigiaVisData.strings.analyzingDetail +
			'</div>'
		);

		$.ajax({
			url:  vigiaVisData.ajaxUrl,
			type: 'POST',
			data: {
				action: 'vigia_run_visibility_analysis',
				nonce:  vigiaVisData.ajaxNonce,
				url:    url
			},
			success: function( response ) {
				if ( response.success ) {
					renderResults( response.data );
				} else {
					renderError( response.data || vigiaVisData.strings.errorGeneric );
				}
			},
			error: function() {
				renderError( vigiaVisData.strings.errorGeneric );
			},
			complete: function() {
				isAnalyzing = false;
				$btn.prop( 'disabled', false ).text( vigiaVisData.strings.analyze );
			}
		});
	}

	// ------------------------------------------------------------------
	// Result Rendering
	// ------------------------------------------------------------------

	/**
	 * Render analysis results.
	 *
	 * @param {Object} data Analysis data from PHP.
	 */
	function renderResults( data ) {
		var $results = $( '#vigia-vis-results' );
		var html     = '';

		// Grade class.
		var gradeClass = getGradeClass( data.grade );

		// Page type badge.
		var pageType = data.is_homepage ? vigiaVisData.strings.homepage : vigiaVisData.strings.contentPage;
		var ttfb     = data.ttfb > 0 ? data.ttfb + ' ms' : 'N/A';

		// Banner.
		html += '<div class="vigia-vis-banner ' + gradeClass + '">';
		html += '<div class="vigia-vis-banner-hero">';
		html += '<div class="vigia-vis-hero-circle">';
		html += '<div class="vigia-vis-hero-grade">' + escHtml( data.grade ) + '</div>';
		html += '<div class="vigia-vis-hero-score">' + data.points + '<small>/' + data.maxPoints + '</small></div>';
		html += '</div>';
		html += '<div class="vigia-vis-hero-message">' + escHtml( data.message ) + '</div>';
		html += '</div>';
		html += '<div class="vigia-vis-banner-meta">';
		html += '<span>' + escHtml( pageType ) + '</span>';
		html += '<span class="vigia-vis-meta-sep">|</span>';
		html += '<span>TTFB: ' + ttfb + '</span>';
		html += '</div>';
		html += '</div>';

		// Re-analyze button.
		html += '<div class="vigia-vis-reanalyze">';
		html += '<button type="button" id="vigia-vis-reanalyze-btn" class="button">';
		html += '<span class="dashicons dashicons-update"></span>';
		html += vigiaVisData.strings.reanalyze;
		html += '</button>';
		html += '<span class="vigia-vis-cache-info">' + vigiaVisData.strings.cachedInfo + '</span>';
		html += '</div>';

		// Category accordions.
		var catKeys = [ 'access', 'structured', 'content', 'interaction', 'performance' ];
		var isFirst = false;

		$.each( catKeys, function( i, key ) {
			var cat = data.categories[ key ];
			if ( ! cat ) {
				return;
			}
			html += renderCategoryAccordion( key, cat, isFirst );
			isFirst = false;
		});

		// Smart recommendations.
		if ( data.recommendations && data.recommendations.length > 0 ) {
			html += renderRecommendations( data.recommendations );
		}

		$results.html( html );

		// Bind accordion click events.
		$results.find( '.vigia-vis-accordion-header' ).on( 'click', function() {
			var $acc   = $( this ).closest( '.vigia-vis-accordion' );
			var $body  = $acc.find( '.vigia-vis-accordion-body' );
			var $arrow = $acc.find( '.vigia-vis-accordion-arrow' );
			var isOpen = $acc.hasClass( 'vigia-vis-accordion-open' );

			if ( isOpen ) {
				$acc.removeClass( 'vigia-vis-accordion-open' );
				$body.slideUp( 200 );
				$arrow.text( '\u25B6' );
				$( this ).attr( 'aria-expanded', 'false' );
			} else {
				$acc.addClass( 'vigia-vis-accordion-open' );
				$body.slideDown( 200 );
				$arrow.text( '\u25BC' );
				$( this ).attr( 'aria-expanded', 'true' );
			}
		});

		// Re-analyze button (clears cache).
		$results.find( '#vigia-vis-reanalyze-btn' ).on( 'click', function() {
			var url2 = $( '#vigia-vis-url-input' ).val().trim();
			if ( ! url2 ) {
				url2 = vigiaVisData.homeUrl;
			}

			var $btn2 = $( this );
			$btn2.prop( 'disabled', true );
			isAnalyzing = false;

			$.ajax({
				url:  vigiaVisData.ajaxUrl,
				type: 'POST',
				data: {
					action:      'vigia_run_visibility_analysis',
					nonce:       vigiaVisData.ajaxNonce,
					url:         url2,
					clear_cache: '1'
				},
				success: function( response ) {
					if ( response.success ) {
						renderResults( response.data );
					} else {
						renderError( response.data || vigiaVisData.strings.errorGeneric );
					}
				},
				error: function() {
					renderError( vigiaVisData.strings.errorGeneric );
				},
				complete: function() {
					$btn2.prop( 'disabled', false );
				}
			});
		});
	}

	/**
	 * Render a category accordion.
	 *
	 * @param {string}  key            Category key.
	 * @param {Object}  cat            Category data.
	 * @param {boolean} openByDefault  Whether to open by default.
	 * @return {string} HTML.
	 */
	function renderCategoryAccordion( key, cat, openByDefault ) {
		var statusObj = getCatStatus( cat.points, cat.maxPoints );
		var openClass = openByDefault ? ' vigia-vis-accordion-open' : '';
		var arrow     = openByDefault ? '\u25BC' : '\u25B6';
		var bodyStyle = openByDefault ? '' : ' style="display:none;"';

		var html = '<div class="vigia-vis-accordion' + openClass + '" data-accordion="' + key + '">';
		html += '<button type="button" class="vigia-vis-accordion-header" aria-expanded="' + ( openByDefault ? 'true' : 'false' ) + '">';
		html += '<div class="vigia-vis-accordion-title">';
		html += '<span class="vigia-vis-accordion-arrow">' + arrow + '</span>';
		html += '<span class="vigia-vis-accordion-name">' + escHtml( cat.name ) + '</span>';
		html += '<span class="vigia-vis-status-badge ' + statusObj.cssClass + '">' + escHtml( statusObj.label ) + '</span>';
		html += '</div>';
		html += '<div class="vigia-vis-accordion-summary">' + cat.points + '/' + cat.maxPoints + ' ' + vigiaVisData.strings.points + '</div>';
		html += '</button>';
		html += '<div class="vigia-vis-accordion-body"' + bodyStyle + '>';

		// Individual checks.
		$.each( cat.checks, function( i, check ) {
			html += '<div class="vigia-vis-check vigia-vis-check-' + check.status + '">';
			html += '<div class="vigia-vis-check-header">';
			html += '<span class="vigia-vis-check-icon">' + getCheckIcon( check.status ) + '</span>';
			html += '<span class="vigia-vis-check-label">' + escHtml( check.label ) + '</span>';
			html += '<span class="vigia-vis-check-points">' + check.points + '/' + check.max + '</span>';
			html += '</div>';
			html += '<div class="vigia-vis-check-detail">' + escHtml( check.detail ) + '</div>';
			html += '</div>';
		});

		html += '</div>';
		html += '</div>';

		return html;
	}

	/**
	 * Render smart recommendations section.
	 *
	 * @param {Array} recs Recommendations array.
	 * @return {string} HTML.
	 */
	function renderRecommendations( recs ) {
		var html = '<div class="vigia-vis-recommendations">';
		html += '<h3>' + vigiaVisData.strings.recommendationsTitle + '</h3>';

		$.each( recs, function( i, rec ) {
			html += '<div class="vigia-vis-rec vigia-vis-rec-tier-' + rec.tier + '">';
			html += '<p class="vigia-vis-rec-text">' + escHtml( rec.text ) + '</p>';

			if ( 'internal_link' === rec.action ) {
				html += '<a href="' + escHtml( rec.url ) + '" class="button button-secondary">' + escHtml( rec.label ) + '</a>';
			} else if ( 'thickbox' === rec.action ) {
				var tbUrl = vigiaVisData.pluginInstallUrl + '?tab=plugin-information&plugin=' + rec.slug + '&TB_iframe=true&width=772&height=500';
				html += '<a href="' + escHtml( tbUrl ) + '" class="button button-secondary thickbox">' + escHtml( rec.label ) + '</a>';
			} else if ( 'thickbox_pair' === rec.action && rec.plugins ) {
				$.each( rec.plugins, function( j, plugin ) {
					var tbUrl2 = vigiaVisData.pluginInstallUrl + '?tab=plugin-information&plugin=' + plugin.slug + '&TB_iframe=true&width=772&height=500';
					html += '<a href="' + escHtml( tbUrl2 ) + '" class="button button-secondary thickbox" style="margin-right:8px;">' + escHtml( plugin.label ) + '</a>';
				});
			} else if ( 'plugins_page' === rec.action ) {
				html += '<a href="' + escHtml( vigiaVisData.pluginsUrl ) + '" class="button button-secondary">' + escHtml( rec.label ) + '</a>';
			}

			html += '</div>';
		});

		html += '</div>';
		return html;
	}

	/**
	 * Render error message.
	 *
	 * @param {string} message Error message.
	 */
	function renderError( message ) {
		var $results = $( '#vigia-vis-results' );
		$results.show().html(
			'<div class="vigia-vis-banner vigia-vis-danger">' +
				'<div class="vigia-vis-banner-verdict">' +
					'<span class="vigia-vis-banner-icon">' + getBannerIcon( 'vigia-vis-danger' ) + '</span>' +
					'<div class="vigia-vis-banner-text">' +
						'<strong>' + vigiaVisData.strings.errorTitle + '</strong>' +
						'<p>' + escHtml( message ) + '</p>' +
					'</div>' +
				'</div>' +
			'</div>'
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	function getGradeClass( grade ) {
		if ( grade === 'A+' ) {
			return 'vigia-vis-excellent';
		}
		if ( grade === 'A' ) {
			return 'vigia-vis-ok';
		}
		if ( grade === 'B' || grade === 'C' ) {
			return 'vigia-vis-warning';
		}
		return 'vigia-vis-danger';
	}

	function getCatStatus( points, maxPoints ) {
		if ( maxPoints > 0 && points >= maxPoints ) {
			return { label: vigiaVisData.strings.statusExcellent, cssClass: 'vigia-vis-excellent' };
		}
		var ratio = maxPoints > 0 ? points / maxPoints : 0;
		if ( ratio >= 0.7 ) {
			return { label: vigiaVisData.strings.statusGood, cssClass: 'vigia-vis-ok' };
		}
		if ( ratio >= 0.4 ) {
			return { label: vigiaVisData.strings.statusFair, cssClass: 'vigia-vis-warning' };
		}
		return { label: vigiaVisData.strings.statusPoor, cssClass: 'vigia-vis-danger' };
	}

	function getCheckIcon( status ) {
		if ( 'pass' === status ) {
			return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#4ec9b0" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>';
		}
		if ( 'info' === status ) {
			return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#a7aaad" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/></svg>';
		}
		if ( 'partial' === status ) {
			return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#f0ad4e" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="13"/><circle cx="12" cy="16.5" r="1" fill="#f0ad4e" stroke="none"/></svg>';
		}
		return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#e74c3c" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
	}

	function getBannerIcon( cssClass ) {
		if ( cssClass === 'vigia-vis-ok' ) {
			return '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
		}
		if ( cssClass === 'vigia-vis-warning' ) {
			return '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><circle cx="12" cy="17" r="1" fill="currentColor" stroke="none"/></svg>';
		}
		return '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
	}

	function escHtml( str ) {
		if ( typeof str !== 'string' ) {
			return String( str );
		}
		var div = document.createElement( 'div' );
		div.textContent = str;
		return div.innerHTML;
	}

})( jQuery );