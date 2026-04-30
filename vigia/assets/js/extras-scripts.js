/**
 * VigIA - Extras Page Scripts
 *
 * Handles robots.txt management, blocking, email alerts, and LLMs generator.
 *
 * @package VigIA
 * @since 1.2.0
 */

/* global vigiaData, jQuery */

(function($) {
    'use strict';

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Only run on extras page
        if ($('.vigia-extras-wrap').length === 0) {
            return;
        }

        initRobotsTab();
        initEmailTab();
        initLlmsTab();
        initMarkdownTab();
        initJsonldTab();
        initMcpTab();

        // Initialize paginators for Disallow & Blocking tab tables
        initExtrasPaginators();
    });

    /**
     * Initialize client-side paginators for all extras tables.
     * Uses VigiaPaginator from admin-scripts.js (loaded as dependency).
     */
    function initExtrasPaginators() {
        if (typeof VigiaPaginator === 'undefined') {
            return;
        }

        // Disallow rules (5 per page)
        if ($('#vigia-disallow-table').length) {
            new VigiaPaginator({
                table: '#vigia-disallow-table',
                pageSize: 5,
                pager: '#vigia-disallow-pager'
            });
        }

        // Compliance check (5 per page)
        if ($('#vigia-compliance-table').length) {
            new VigiaPaginator({
                table: '#vigia-compliance-table',
                pageSize: 5,
                pager: '#vigia-compliance-pager'
            });
        }

        // User-Agent blocks (5 per page)
        if ($('#vigia-ua-blocks-table').length) {
            new VigiaPaginator({
                table: '#vigia-ua-blocks-table',
                pageSize: 5,
                pager: '#vigia-ua-blocks-pager'
            });
        }

        // IP blocks (5 per page)
        if ($('#vigia-ip-blocks-table').length) {
            new VigiaPaginator({
                table: '#vigia-ip-blocks-table',
                pageSize: 5,
                pager: '#vigia-ip-blocks-pager'
            });
        }
    }

    // ==========================================================================
    // Robots.txt & Blocking Tab
    // ==========================================================================

    /**
     * Initialize robots tab functionality
     */
    function initRobotsTab() {
        // Add disallow rule
        $('#vigia-add-disallow').on('click', function() {
            var crawlerName = $('#vigia-robots-crawler').val();
            if (!crawlerName) {
                alert(vigiaData.strings.selectCrawler || 'Please select a crawler');
                return;
            }
            addRobotsRule(crawlerName, 'disallow');
        });

        // Remove robots rule
        $(document).on('click', '.vigia-remove-robots-rule', function() {
            var $btn = $(this);
            var crawlerName = $btn.data('crawler');
            var actionType = $btn.data('action');
            removeRobotsRule(crawlerName, actionType, $btn);
        });

        // Block via PHP from compliance panel (User-Agent)
        $(document).on('click', '.vigia-block-php', function() {
            var $btn = $(this);
            var crawlerName = $btn.data('crawler');
            blockFromCompliance(crawlerName, $btn);
        });

        // Add User-Agent block from selector
        $('#vigia-add-block-ua').on('click', function() {
            var $select = $('#vigia-block-crawler');
            var crawlerName = $select.val();
            var userAgent = $select.find(':selected').data('useragent') || crawlerName;
            
            if (!crawlerName) {
                alert(vigiaData.strings.selectCrawler || 'Please select a crawler');
                return;
            }
            
            blockUserAgent(crawlerName, userAgent);
        });

        // Add custom User-Agent block
        $('#vigia-add-custom-block-ua').on('click', function() {
            var name = $('#vigia-custom-ua-name').val();
            var pattern = $('#vigia-custom-ua-pattern').val();
            
            if (!name || !pattern) {
                alert(vigiaData.strings.enterBothFields || 'Please enter both name and pattern');
                return;
            }
            
            blockUserAgent(name, pattern);
        });

        // Add IP block
        $('#vigia-add-block-ip').on('click', function() {
            var name = $('#vigia-block-ip-name').val();
            var ip = $('#vigia-block-ip').val();
            
            if (!ip) {
                alert(vigiaData.strings.enterIP || 'Please enter an IP address');
                return;
            }
            
            blockIP(name || ip, ip);
        });

        // Unblock by ID (generic)
        $(document).on('click', '.vigia-unblock', function() {
            var $btn = $(this);
            var blockId = $btn.data('id');
            unblockById(blockId, $btn);
        });
    }

    /**
     * Add robots.txt rule (DOM update, no reload)
     */
    function addRobotsRule(crawlerName, actionType) {
        $.ajax({
            url: vigiaData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'vigia_add_robots_rule',
                nonce: vigiaData.ajaxNonce,
                crawler_name: crawlerName,
                action_type: actionType
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', vigiaData.strings.robotsRuleAdded);

                    // Update robots.txt preview
                    if (response.data && response.data.preview) {
                        $('#vigia-robots-preview').text(response.data.preview);
                    }

                    // Add row to table or create table if first rule
                    var $container = $('.vigia-robots-rules');
                    var $table = $container.find('#vigia-disallow-table');

                    if ($table.length === 0) {
                        // First rule: replace "no rules" message with table
                        $container.find('.vigia-no-rules').remove();
                        var tableHtml = '<table class="wp-list-table widefat fixed striped" id="vigia-disallow-table">' +
                            '<thead><tr>' +
                            '<th>' + escapeHtml(vigiaData.strings.crawler || 'Crawler') + '</th>' +
                            '<th>' + escapeHtml(vigiaData.strings.status || 'Status') + '</th>' +
                            '<th>' + escapeHtml(vigiaData.strings.actions || 'Actions') + '</th>' +
                            '</tr></thead><tbody></tbody></table>';
                        $container.find('.vigia-add-rule-form').before(tableHtml);
                        $table = $container.find('#vigia-disallow-table');

                        // Init paginator
                        new VigiaPaginator({
                            table: '#vigia-disallow-table',
                            pageSize: 5,
                            pager: '#vigia-disallow-pager'
                        });
                    }

                    var rowHtml = '<tr>' +
                        '<td>' + escapeHtml(crawlerName) + '</td>' +
                        '<td><span class="vigia-status vigia-status-disallow">' +
                        escapeHtml(vigiaData.strings.disallow || 'Disallow') + '</span></td>' +
                        '<td><button type="button" class="button button-small vigia-remove-robots-rule" ' +
                        'data-crawler="' + escapeHtml(crawlerName) + '" data-action="disallow">' +
                        escapeHtml(vigiaData.strings.remove || 'Remove') + '</button></td>' +
                        '</tr>';
                    $table.find('tbody').append(rowHtml);

                    // Refresh paginator and go to last page
                    var pager = $table.data('vigiaPaginator');
                    if (pager) {
                        pager.goToPage(pager.totalPages());
                    }

                    // Remove crawler from selector
                    $('#vigia-robots-crawler option[value="' + crawlerName + '"]').remove();
                    $('#vigia-robots-crawler').val('');
                } else {
                    alert(response.data || vigiaData.strings.error);
                }
            },
            error: function() {
                alert(vigiaData.strings.error);
            }
        });
    }

    /**
     * Remove robots.txt rule (DOM update, no reload)
     */
    function removeRobotsRule(crawlerName, actionType, $btn) {
        $.ajax({
            url: vigiaData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'vigia_remove_robots_rule',
                nonce: vigiaData.ajaxNonce,
                crawler_name: crawlerName,
                action_type: actionType
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', vigiaData.strings.robotsRuleRemoved);

                    // Update robots.txt preview
                    if (response.data && response.data.preview) {
                        $('#vigia-robots-preview').text(response.data.preview);
                    }

                    // Remove row from table
                    var $row = $btn.closest('tr');
                    var $table = $row.closest('table');
                    $row.fadeOut(300, function() {
                        $(this).remove();
                        // Refresh paginator
                        var pager = $table.data('vigiaPaginator');
                        if (pager) {
                            pager.refresh();
                        }
                        // If table is now empty, show placeholder
                        if ($table.find('tbody tr').length === 0) {
                            $table.after('<p class="vigia-no-rules">' +
                                (vigiaData.strings.noRulesConfigured || 'No robots.txt rules configured for AI crawlers.') +
                                '</p>');
                            $table.remove();
                        }
                    });
                } else {
                    alert(response.data || vigiaData.strings.error);
                }
            },
            error: function() {
                alert(vigiaData.strings.error);
            }
        });
    }

    /**
     * Block User-Agent via PHP (from form — reloads to show new row with block ID)
     */
    function blockUserAgent(name, pattern) {
        $.ajax({
            url: vigiaData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'vigia_block_crawler',
                nonce: vigiaData.ajaxNonce,
                crawler_name: name,
                user_agent: pattern,
                block_type: 'useragent'
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', vigiaData.strings.blocked);
                    location.reload();
                } else {
                    alert(response.data || vigiaData.strings.error);
                }
            },
            error: function() {
                alert(vigiaData.strings.error);
            }
        });
    }

    /**
     * Block User-Agent from compliance panel (updates button in-place, no reload)
     */
    function blockFromCompliance(crawlerName, $btn) {
        $.ajax({
            url: vigiaData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'vigia_block_crawler',
                nonce: vigiaData.ajaxNonce,
                crawler_name: crawlerName,
                user_agent: crawlerName,
                block_type: 'useragent'
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', vigiaData.strings.blocked);
                    // Replace button with "Already blocked" text
                    $btn.replaceWith('<span class="vigia-already-blocked">' +
                        (vigiaData.strings.alreadyBlockedPhp || 'Already blocked via PHP') + '</span>');
                } else {
                    alert(response.data || vigiaData.strings.error);
                }
            },
            error: function() {
                alert(vigiaData.strings.error);
            }
        });
    }

    /**
     * Block IP address via PHP (reloads to show new row with block ID)
     */
    function blockIP(name, ip) {
        $.ajax({
            url: vigiaData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'vigia_block_crawler',
                nonce: vigiaData.ajaxNonce,
                name: name,
                ip: ip,
                block_type: 'ip'
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', vigiaData.strings.blocked);
                    location.reload();
                } else {
                    alert(response.data || vigiaData.strings.error);
                }
            },
            error: function() {
                alert(vigiaData.strings.error);
            }
        });
    }

    /**
     * Unblock by ID (DOM update, no reload)
     */
    function unblockById(blockId, $btn) {
        $.ajax({
            url: vigiaData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'vigia_unblock_crawler',
                nonce: vigiaData.ajaxNonce,
                block_id: blockId
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', vigiaData.strings.unblocked);

                    var $row = $btn.closest('tr');
                    var $table = $row.closest('table');
                    var blockType = $btn.data('type');

                    $row.fadeOut(300, function() {
                        $(this).remove();
                        // Refresh paginator
                        var pager = $table.data('vigiaPaginator');
                        if (pager) {
                            pager.refresh();
                        }
                        // If table is now empty, show placeholder
                        if ($table.find('tbody tr').length === 0) {
                            var msg = blockType === 'ip'
                                ? (vigiaData.strings.noIpBlocks || 'No IP blocks configured.')
                                : (vigiaData.strings.noUaBlocks || 'No User-Agent blocks configured.');
                            $table.after('<p class="vigia-no-blocked">' + msg + '</p>');
                            $table.remove();
                        }
                    });
                } else {
                    alert(response.data || vigiaData.strings.error);
                }
            },
            error: function() {
                alert(vigiaData.strings.error);
            }
        });
    }

    // ==========================================================================
    // Email Alerts Tab
    // ==========================================================================

    /**
     * Initialize email tab functionality
     */
    function initEmailTab() {
        // Save email settings
        $('#vigia-save-email-settings').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true);

            $.ajax({
                url: vigiaData.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'vigia_save_email_settings',
                    nonce: vigiaData.ajaxNonce,
                    enabled: $('#vigia-email-enabled').is(':checked') ? 'true' : 'false',
                    frequency: $('#vigia-email-frequency').val(),
                    level: $('#vigia-email-level').val(),
                    email: $('#vigia-email-address').val()
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('success', vigiaData.strings.settingsSaved);
                    } else {
                        alert(response.data || vigiaData.strings.error);
                    }
                },
                error: function() {
                    alert(vigiaData.strings.error);
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        });

        // Test email
        $('#vigia-test-email').on('click', function() {
            var $btn = $(this);
            var originalText = $btn.text();
            $btn.prop('disabled', true).text(vigiaData.strings.sending || 'Sending...');

            $.ajax({
                url: vigiaData.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'vigia_test_email',
                    nonce: vigiaData.ajaxNonce
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('success', vigiaData.strings.testEmailSent);
                    } else {
                        alert(response.data || vigiaData.strings.error);
                    }
                },
                error: function() {
                    alert(vigiaData.strings.error);
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        });
    }

    // ==========================================================================
    // LLMs.txt Tab (v1.2.0 - completely rewritten)
    // ==========================================================================

    var llmsSearchTimeout = null;
    var taxonomyCache = {};

    /**
     * Initialize LLMs tab functionality
     */
    function initLlmsTab() {
        // Post type selection
        $(document).on('change', 'input[name="vigia_post_types[]"]', function() {
            updateTaxonomyFilters();
            updateContentSummary();
        });

        // Toggle full options
        $('#vigia-generate-full').on('change', function() {
            $('#vigia-full-options').toggle(this.checked);
        });

        // Include search with debounce
        $('#vigia-include-search').on('input', function() {
            var $input = $(this);
            var search = $input.val();
            
            clearTimeout(llmsSearchTimeout);
            
            if (search.length < 2) {
                $('#vigia-include-results').hide().empty();
                return;
            }
            
            llmsSearchTimeout = setTimeout(function() {
                searchPosts(search, 'include');
            }, 300);
        });

        // Exclude search with debounce
        $('#vigia-exclude-search').on('input', function() {
            var $input = $(this);
            var search = $input.val();
            
            clearTimeout(llmsSearchTimeout);
            
            if (search.length < 2) {
                $('#vigia-exclude-results').hide().empty();
                return;
            }
            
            llmsSearchTimeout = setTimeout(function() {
                searchPosts(search, 'exclude');
            }, 300);
        });

        // Click on search result - FIXED: correct target ID
        $(document).on('click', '.vigia-search-result-item', function() {
            var $item = $(this);
            var id = $item.data('id');
            var title = $item.data('title');
            var type = $item.attr('data-type-label') || '';
            
            // Get target from results container ID: vigia-include-results or vigia-exclude-results
            var resultsId = $item.closest('.vigia-search-results').attr('id');
            var targetType = resultsId.replace('vigia-', '').replace('-results', ''); // 'include' or 'exclude'
            var targetContainer = '#vigia-manual-' + targetType + 's'; // #vigia-manual-includes or #vigia-manual-excludes
            
            // Check if already selected
            if ($(targetContainer).find('[data-id="' + id + '"]').length > 0) {
                return;
            }
            
            // Add to selected items
            var $selected = $('<span class="vigia-selected-item" data-id="' + id + '">' +
                escapeHtml(title) + (type ? ' <small>(' + escapeHtml(type) + ')</small>' : '') +
                '<button type="button" class="vigia-remove-item">&times;</button></span>');
            
            $(targetContainer).append($selected);
            
            // Clear search
            $item.closest('.vigia-manual-selector').find('.vigia-ajax-search').val('');
            $item.closest('.vigia-search-results').hide().empty();
            
            updateContentSummary();
        });

        // Remove selected item
        $(document).on('click', '.vigia-remove-item', function() {
            $(this).closest('.vigia-selected-item').remove();
            updateContentSummary();
        });

        // Hide search results on click outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.vigia-manual-selector').length) {
                $('.vigia-search-results').hide();
            }
        });

        // Focus on search input shows results if they exist
        $('.vigia-ajax-search').on('focus', function() {
            var $results = $(this).siblings('.vigia-search-results');
            if ($results.children().length > 0) {
                $results.show();
            }
        });

        // Generate files
        $('#vigia-generate-llms').on('click', function() {
            generateLlmsFiles();
        });

        // Delete individual file
        $(document).on('click', '.vigia-delete-llms-file', function() {
            var filename = $(this).data('file');
            if (confirm(vigiaData.strings.confirmDeleteLlms || 'Are you sure you want to delete this file?')) {
                deleteLlmsFile(filename, $(this));
            }
        });

        // Taxonomy checkbox changes
        $(document).on('change', '.vigia-tax-checkbox', function() {
            updateTaxonomySelectionInfo($(this).closest('.vigia-taxonomy-accordion'));
            updateContentSummary();
        });
        
        // Accordion toggle
        $(document).on('click', '.vigia-accordion-header', function(e) {
            // Don't toggle if clicking on buttons inside
            if ($(e.target).is('button')) {
                return;
            }
            var $accordion = $(this).closest('.vigia-taxonomy-accordion');
            var $content = $accordion.find('.vigia-accordion-content');
            var $toggle = $accordion.find('.vigia-accordion-toggle');
            
            $content.slideToggle(200);
            $toggle.toggleClass('dashicons-arrow-right-alt2 dashicons-arrow-down-alt2');
            $accordion.toggleClass('is-open');
        });
        
        // Select all terms in taxonomy
        $(document).on('click', '.vigia-select-all-tax', function(e) {
            e.preventDefault();
            var taxonomy = $(this).data('taxonomy');
            var $accordion = $(this).closest('.vigia-taxonomy-accordion');
            $accordion.find('.vigia-tax-checkbox').prop('checked', true);
            updateTaxonomySelectionInfo($accordion);
            updateContentSummary();
        });
        
        // Select none terms in taxonomy
        $(document).on('click', '.vigia-select-none-tax', function(e) {
            e.preventDefault();
            var taxonomy = $(this).data('taxonomy');
            var $accordion = $(this).closest('.vigia-taxonomy-accordion');
            $accordion.find('.vigia-tax-checkbox').prop('checked', false);
            updateTaxonomySelectionInfo($accordion);
            updateContentSummary();
        });

        // Initialize taxonomy filters on load
        updateTaxonomyFilters();
        updateContentSummary();
    }

    /**
     * Search posts via AJAX
     */
    function searchPosts(search, type) {
        var excludeIds = [];
        
        // Get already selected IDs to exclude from results
        $('#vigia-manual-includes .vigia-selected-item, #vigia-manual-excludes .vigia-selected-item').each(function() {
            excludeIds.push($(this).data('id'));
        });
        
        // Also exclude posts from selected post types (for include search only)
        // This is handled server-side for performance

        $.ajax({
            url: vigiaData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'vigia_search_posts',
                nonce: vigiaData.ajaxNonce,
                search: search,
                exclude_ids: excludeIds
            },
            success: function(response) {
                if (response.success && response.data.length > 0) {
                    var html = '';
                    $.each(response.data, function(i, post) {
                        html += '<div class="vigia-search-result-item" data-id="' + post.id + '" ' +
                                'data-title="' + escapeHtml(post.title) + '" data-type-label="' + escapeHtml(post.type) + '">' +
                                '<span class="vigia-result-title">' + escapeHtml(post.title) + '</span>' +
                                '<span class="vigia-result-type">' + escapeHtml(post.type) + '</span>' +
                                '</div>';
                    });
                    $('#vigia-' + type + '-results').html(html).show();
                } else {
                    $('#vigia-' + type + '-results').html('<div class="vigia-no-results">' + 
                        (vigiaData.strings.noResults || 'No results found') + '</div>').show();
                }
            }
        });
    }

    /**
     * Update taxonomy filters based on selected post types
     * Uses collapsible accordions with Select all/None
     * Checkboxes CHECKED by default - unchecking = exclude
     * Restores saved state from vigiaData.llmsSettings.taxonomy_filters
     */
    function updateTaxonomyFilters() {
        var selectedTypes = [];
        $('input[name="vigia_post_types[]"]:checked').each(function() {
            selectedTypes.push($(this).val());
        });

        if (selectedTypes.length === 0) {
            $('#vigia-taxonomy-filters').hide();
            $('#vigia-taxonomy-selectors').empty();
            return;
        }

        // Get saved taxonomy filters to restore state
        var savedFilters = (typeof vigiaSavedTaxonomyFilters !== 'undefined') ? vigiaSavedTaxonomyFilters : {};

        // Fetch taxonomies for selected post types
        var promises = selectedTypes.map(function(postType) {
            if (taxonomyCache[postType]) {
                return $.Deferred().resolve(taxonomyCache[postType]).promise();
            }
            
            return $.ajax({
                url: vigiaData.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'vigia_get_taxonomies',
                    nonce: vigiaData.ajaxNonce,
                    post_type: postType
                }
            }).then(function(response) {
                if (response.success) {
                    taxonomyCache[postType] = response.data;
                    return response.data;
                }
                return {};
            });
        });

        $.when.apply($, promises).done(function() {
            var allTaxonomies = {};
            var taxPostTypeMap = {}; // Track which post_types have each taxonomy
            var results = arguments;
            
            // Merge taxonomies from all selected post types AND track ownership
            $.each(selectedTypes, function(i, postType) {
                var taxonomies = results[i] || taxonomyCache[postType] || {};
                $.each(taxonomies, function(taxName, taxData) {
                    if (!allTaxonomies[taxName]) {
                        allTaxonomies[taxName] = taxData;
                        taxPostTypeMap[taxName] = [];
                    }
                    taxPostTypeMap[taxName].push(postType);
                });
            });
            
            // Store the map globally for getLlmsSettings to use
            window.vigiaTaxPostTypeMap = taxPostTypeMap;

            if ($.isEmptyObject(allTaxonomies)) {
                $('#vigia-taxonomy-filters').hide();
                return;
            }

            // Collect saved term IDs for each taxonomy (from any post type)
            var savedTermsByTax = {};
            $.each(savedFilters, function(postType, taxonomies) {
                if (typeof taxonomies === 'object') {
                    $.each(taxonomies, function(taxName, termIds) {
                        if (!savedTermsByTax[taxName]) {
                            savedTermsByTax[taxName] = [];
                        }
                        if (Array.isArray(termIds)) {
                            $.each(termIds, function(i, termId) {
                                if (savedTermsByTax[taxName].indexOf(String(termId)) === -1) {
                                    savedTermsByTax[taxName].push(String(termId));
                                }
                            });
                        }
                    });
                }
            });

            // Build collapsible taxonomy accordions
            var html = '';
            $.each(allTaxonomies, function(taxName, taxData) {
                var termCount = taxData.terms ? taxData.terms.length : 0;
                var hasSavedFilter = savedTermsByTax[taxName] && savedTermsByTax[taxName].length > 0;
                
                html += '<div class="vigia-taxonomy-accordion" data-taxonomy="' + taxName + '">';
                
                // Accordion header (collapsed by default)
                html += '<div class="vigia-accordion-header">';
                html += '<span class="vigia-accordion-toggle dashicons dashicons-arrow-right-alt2"></span>';
                html += '<strong class="vigia-tax-label">' + escapeHtml(taxData.label) + '</strong>';
                html += '<span class="vigia-tax-term-count">(' + termCount + ')</span>';
                html += '<span class="vigia-tax-selection-info all-included">' + (vigiaData.strings.allIncluded || 'All included') + '</span>';
                html += '</div>';
                
                // Accordion content (hidden by default)
                html += '<div class="vigia-accordion-content" style="display: none;">';
                
                // Select all / None controls
                html += '<div class="vigia-tax-bulk-actions">';
                html += '<button type="button" class="button button-small vigia-select-all-tax" data-taxonomy="' + taxName + '">' + (vigiaData.strings.includeAll || 'Include all') + '</button>';
                html += '<button type="button" class="button button-small vigia-select-none-tax" data-taxonomy="' + taxName + '">' + (vigiaData.strings.excludeAll || 'Exclude all') + '</button>';
                html += '<span class="vigia-tax-hint">' + (vigiaData.strings.uncheckToExclude || 'Uncheck to exclude specific terms') + '</span>';
                html += '</div>';
                
                // Checkboxes grid - restore saved state or check all by default
                html += '<div class="vigia-tax-checkboxes">';
                $.each(taxData.terms, function(j, term) {
                    // If saved filter exists: only check if term is in saved list
                    // If no saved filter: check all (all included by default)
                    var isChecked = true;
                    if (hasSavedFilter) {
                        isChecked = savedTermsByTax[taxName].indexOf(String(term.id)) !== -1;
                    }
                    
                    html += '<label class="vigia-tax-checkbox-label">';
                    html += '<input type="checkbox" class="vigia-tax-checkbox" ' +
                            'name="vigia_tax_' + taxName + '[]" ' +
                            'value="' + term.id + '" ' +
                            'data-taxonomy="' + taxName + '"' + (isChecked ? ' checked' : '') + '>';
                    html += ' ' + escapeHtml(term.name) + ' <span class="vigia-term-count">(' + term.count + ')</span>';
                    html += '</label>';
                });
                html += '</div>';
                
                html += '</div>'; // .vigia-accordion-content
                html += '</div>'; // .vigia-taxonomy-accordion
            });

            $('#vigia-taxonomy-selectors').html(html);
            $('#vigia-taxonomy-filters').show();
            
            // Update selection info on all accordions
            updateAllTaxonomySelectionInfo();
        });
    }
    
    /**
     * Update selection info text for a taxonomy accordion
     */
    function updateTaxonomySelectionInfo($accordion) {
        var $checkboxes = $accordion.find('.vigia-tax-checkbox');
        var total = $checkboxes.length;
        var checked = $checkboxes.filter(':checked').length;
        var excluded = total - checked;
        var $info = $accordion.find('.vigia-tax-selection-info');
        
        if (checked === total) {
            $info.text(vigiaData.strings.allIncluded || 'All included')
                 .removeClass('has-exclusions')
                 .addClass('all-included');
        } else if (checked === 0) {
            $info.text(vigiaData.strings.allExcluded || 'All excluded')
                 .removeClass('all-included')
                 .addClass('has-exclusions');
        } else {
            $info.text((vigiaData.strings.excludedCount || '%d excluded').replace('%d', excluded))
                 .removeClass('all-included')
                 .addClass('has-exclusions');
        }
    }
    
    /**
     * Update all taxonomy selection info
     */
    function updateAllTaxonomySelectionInfo() {
        $('.vigia-taxonomy-accordion').each(function() {
            updateTaxonomySelectionInfo($(this));
        });
    }

    /**
     * Update content summary
     */
    function updateContentSummary() {
        var count = 0;
        var details = [];

        // Count from post types
        $('input[name="vigia_post_types[]"]:checked').each(function() {
            var ptCount = parseInt($(this).data('count'), 10) || 0;
            var ptLabel = $(this).siblings('.vigia-pt-label').text();
            count += ptCount;
            details.push(ptLabel + ': ' + ptCount);
        });

        // Count manual includes
        var manualIncludes = $('#vigia-manual-includes .vigia-selected-item').length;
        if (manualIncludes > 0) {
            count += manualIncludes;
            details.push((vigiaData.strings.manuallyAdded || 'Manually added') + ': +' + manualIncludes);
        }

        // Count manual excludes
        var manualExcludes = $('#vigia-manual-excludes .vigia-selected-item').length;
        if (manualExcludes > 0) {
            count -= manualExcludes;
            details.push((vigiaData.strings.excluded || 'Excluded') + ': -' + manualExcludes);
        }

        // Update summary
        if (count > 0) {
            var summaryText = (vigiaData.strings.estimatedContent || 'Estimated content: %1$d items (%2$s)')
                .replace('%1$d', count)
                .replace('%2$s', details.join(', '));
            $('#vigia-summary-text').html(summaryText);
            $('#vigia-content-summary').addClass('has-content');
        } else {
            $('#vigia-summary-text').text(vigiaData.strings.selectContentTypes || 'Select content types to see estimated count.');
            $('#vigia-content-summary').removeClass('has-content');
        }
    }

    /**
     * Get all LLMs settings from form
     */
    function getLlmsSettings() {
        var settings = {
            site_name: $('#vigia-llms-site-name').val(),
            site_description: $('#vigia-llms-description').val(),
            post_types: [],
            taxonomy_filters: {},
            manual_includes: [],
            manual_excludes: [],
            exclude_patterns: $('#vigia-exclude-patterns').val(),
            exclude_noindex: $('#vigia-exclude-noindex').is(':checked') ? 'true' : 'false',
            generate_full: $('#vigia-generate-full').is(':checked') ? 'true' : 'false',
            full_mode: $('input[name="vigia_full_mode"]:checked').val() || 'full',
            auto_regenerate: $('input[name="vigia_auto_regenerate"]:checked').val() || 'manual',
            robots_llms: $('#vigia-robots-llms').is(':checked') ? 'true' : 'false',
            robots_llms_full: $('#vigia-robots-llms-full').is(':checked') ? 'true' : 'false'
        };

        // Get selected post types
        $('input[name="vigia_post_types[]"]:checked').each(function() {
            settings.post_types.push($(this).val());
        });

        // Get taxonomy filters - only send if some are UNCHECKED (filtering)
        // If all are checked, don't send anything (include all)
        // IMPORTANT: Only apply filter to post_types that actually have this taxonomy
        var taxPostTypeMap = window.vigiaTaxPostTypeMap || {};
        
        $('.vigia-taxonomy-accordion').each(function() {
            var $accordion = $(this);
            var taxonomy = $accordion.data('taxonomy');
            var $checkboxes = $accordion.find('.vigia-tax-checkbox');
            var $checked = $checkboxes.filter(':checked');
            
            // Only add filter if some are unchecked (user wants to exclude some)
            if ($checked.length > 0 && $checked.length < $checkboxes.length) {
                // Get the post_types that actually have this taxonomy
                var postTypesWithTax = taxPostTypeMap[taxonomy] || [];
                
                // Only apply to post_types that have this taxonomy AND are selected
                $.each(postTypesWithTax, function(i, postType) {
                    if (settings.post_types.indexOf(postType) !== -1) {
                        if (!settings.taxonomy_filters[postType]) {
                            settings.taxonomy_filters[postType] = {};
                        }
                        settings.taxonomy_filters[postType][taxonomy] = [];
                        $checked.each(function() {
                            settings.taxonomy_filters[postType][taxonomy].push($(this).val());
                        });
                    }
                });
            }
            // If all checked or all unchecked, don't add filter (include all or none based on post type selection)
        });

        // Get manual includes
        $('#vigia-manual-includes .vigia-selected-item').each(function() {
            settings.manual_includes.push($(this).data('id'));
        });

        // Get manual excludes
        $('#vigia-manual-excludes .vigia-selected-item').each(function() {
            settings.manual_excludes.push($(this).data('id'));
        });

        return settings;
    }

    /**
     * Generate LLMs files
     */
    function generateLlmsFiles() {
        var settings = getLlmsSettings();

        // Validation
        if (!settings.site_name) {
            alert(vigiaData.strings.siteNameRequired || 'Site name is required');
            return;
        }

        if (settings.post_types.length === 0 && settings.manual_includes.length === 0) {
            alert(vigiaData.strings.selectContent || 'Please select at least one content type or add content manually');
            return;
        }

        var $btn = $('#vigia-generate-llms');
        var originalHtml = $btn.html();
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> ' + 
            (vigiaData.strings.generating || 'Generating...'));

        $.ajax({
            url: vigiaData.ajaxUrl,
            method: 'POST',
            data: $.extend({
                action: 'vigia_generate_llms',
                nonce: vigiaData.ajaxNonce
            }, settings),
            success: function(response) {
                if (response.success) {
                    showNotice('success', vigiaData.strings.llmsGenerated);
                    location.reload();
                } else {
                    alert(response.data || vigiaData.strings.error);
                }
            },
            error: function() {
                alert(vigiaData.strings.error);
            },
            complete: function() {
                $btn.prop('disabled', false).html(originalHtml);
            }
        });
    }

    /**
     * Delete a single LLMs file
     */
    function deleteLlmsFile(filename, $btn) {
        $btn.prop('disabled', true);

        $.ajax({
            url: vigiaData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'vigia_delete_llms_files',
                nonce: vigiaData.ajaxNonce,
                file: filename
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', vigiaData.strings.llmsDeleted);
                    location.reload();
                } else {
                    alert(response.data || vigiaData.strings.error);
                }
            },
            error: function() {
                alert(vigiaData.strings.error);
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    }

    // ==========================================================================
    // Markdown for Agents Tab (v1.5.0)
    // ==========================================================================

    /**
     * Initialize Markdown tab functionality
     */
    function initMarkdownTab() {
        // Save markdown settings
        $('#vigia-save-markdown-settings').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true);

            // Collect selected post types
            var postTypes = [];
            $('input[name="vigia_md_post_types[]"]').filter(':checked').each(function() {
                postTypes.push($(this).val());
            });

            $.ajax({
                url: vigiaData.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'vigia_save_markdown_settings',
                    nonce: vigiaData.ajaxNonce,
                    enabled: $('#vigia-md-enabled').is(':checked') ? 'true' : 'false',
                    enable_md_urls: $('#vigia-md-urls').is(':checked') ? 'true' : 'false',
                    enable_negotiation: $('#vigia-md-negotiation').is(':checked') ? 'true' : 'false',
                    enable_link_header: $('#vigia-md-link-header').is(':checked') ? 'true' : 'false',
                    enable_link_tag: $('#vigia-md-link-tag').is(':checked') ? 'true' : 'false',
                    respect_llms_filters: $('#vigia-md-respect-llms').is(':checked') ? 'true' : 'false',
                    post_types: postTypes
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('success', vigiaData.strings.markdownSaved || 'Settings saved');
                    } else {
                        alert(response.data || vigiaData.strings.error);
                    }
                },
                error: function() {
                    alert(vigiaData.strings.error);
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        });
    }

    // ==========================================================================
    // JSON-LD Tab (v1.7.0)
    // ==========================================================================

    /**
     * Initialize JSON-LD tab functionality
     */
    function initJsonldTab() {
        // Toggle Site Identity fields visibility.
        $('#vigia-jsonld-identity-enabled').on('change', function() {
            $('#vigia-jsonld-identity-fields').toggle($(this).is(':checked'));
            toggleOutputSection();
            updateJsonldPreview();
        });

        // Toggle AI Discovery fields visibility.
        $('#vigia-jsonld-ai-enabled').on('change', function() {
            $('#vigia-jsonld-ai-fields').toggle($(this).is(':checked'));
            toggleOutputSection();
            updateJsonldPreview();
        });

        // Toggle preview panel.
        $('#vigia-jsonld-toggle-preview').on('click', function() {
            $('#vigia-jsonld-preview-wrap').slideToggle(200);
        });

        // Media library picker for logo.
        $('#vigia-jsonld-logo-btn').on('click', function(e) {
            e.preventDefault();
            var frame = wp.media({
                title: vigiaData.strings.selectImage || 'Select image',
                button: { text: vigiaData.strings.useImage || 'Use this image' },
                multiple: false,
                library: { type: 'image' }
            });
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                $('#vigia-jsonld-logo').val(attachment.url);
                updateJsonldPreview();
            });
            frame.open();
        });

        // Live preview: update on any field change.
        $(document).on('change keyup',
            '#vigia-jsonld-identity-enabled, #vigia-jsonld-ai-enabled, ' +
            'input[name="vigia_entity_type"], #vigia-jsonld-name, ' +
            '#vigia-jsonld-description, #vigia-jsonld-logo, #vigia-jsonld-url, ' +
            '#vigia-jsonld-search-action, #vigia-jsonld-sameas, ' +
            '#vigia-jsonld-ai-llms, #vigia-jsonld-ai-llms-full, #vigia-jsonld-ai-markdown, ' +
            '#vigia-jsonld-output-page',
            function() {
                updateJsonldPreview();
            }
        );

        // Save JSON-LD settings.
        $('#vigia-save-jsonld-settings').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true);

            $.ajax({
                url: vigiaData.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'vigia_save_jsonld_settings',
                    nonce: vigiaData.ajaxNonce,
                    site_identity_enabled: $('#vigia-jsonld-identity-enabled').is(':checked') ? 'true' : 'false',
                    entity_type: $('input[name="vigia_entity_type"]:checked').val() || 'Organization',
                    entity_name: $('#vigia-jsonld-name').val(),
                    entity_description: $('#vigia-jsonld-description').val(),
                    entity_logo: $('#vigia-jsonld-logo').val(),
                    entity_url: $('#vigia-jsonld-url').val(),
                    search_action: $('#vigia-jsonld-search-action').is(':checked') ? 'true' : 'false',
                    same_as: $('#vigia-jsonld-sameas').val(),
                    ai_discovery_enabled: $('#vigia-jsonld-ai-enabled').is(':checked') ? 'true' : 'false',
                    ai_discovery_llms: $('#vigia-jsonld-ai-llms').is(':checked') ? 'true' : 'false',
                    ai_discovery_llms_full: $('#vigia-jsonld-ai-llms-full').is(':checked') ? 'true' : 'false',
                    ai_discovery_markdown: $('#vigia-jsonld-ai-markdown').is(':checked') ? 'true' : 'false',
                    output_page: $('#vigia-jsonld-output-page').val()
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('success', vigiaData.strings.jsonldSaved || 'JSON-LD settings saved');
                    } else {
                        alert(response.data || vigiaData.strings.error);
                    }
                },
                error: function() {
                    alert(vigiaData.strings.error);
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        });
    }

    /**
     * Show/hide output page selector based on active sections
     */
    function toggleOutputSection() {
        var hasActive = $('#vigia-jsonld-identity-enabled').is(':checked') ||
                        $('#vigia-jsonld-ai-enabled').is(':checked');
        $('#vigia-jsonld-output-section').toggle(hasActive);
    }

    /**
     * Build and update JSON-LD preview
     */
    function updateJsonldPreview() {
        if (typeof vigiaJsonldData === 'undefined') {
            return;
        }

        var siteUrl = vigiaJsonldData.siteUrl;
        var siteName = vigiaJsonldData.siteName;
        var aiFeatures = vigiaJsonldData.aiFeatures;
        var identityEnabled = $('#vigia-jsonld-identity-enabled').is(':checked');
        var aiEnabled = $('#vigia-jsonld-ai-enabled').is(':checked');

        if (!identityEnabled && !aiEnabled) {
            $('#vigia-jsonld-preview').text('');
            $('#vigia-jsonld-preview-empty').show();
            return;
        }

        var graph = [];

        // Build Site Identity nodes.
        if (identityEnabled) {
            var entityName = $('#vigia-jsonld-name').val() || siteName;
            var entityDesc = $('#vigia-jsonld-description').val();
            var entityType = $('input[name="vigia_entity_type"]:checked').val() || 'Organization';
            var entityLogo = $('#vigia-jsonld-logo').val();
            var entityUrl = $('#vigia-jsonld-url').val() || siteUrl + '/';
            var searchAction = $('#vigia-jsonld-search-action').is(':checked');

            // WebSite node.
            var website = {
                '@type': 'WebSite',
                '@id': siteUrl + '/#website',
                'url': siteUrl + '/',
                'name': entityName
            };

            if (entityDesc) {
                website.description = entityDesc;
            }

            if (searchAction) {
                website.potentialAction = [{
                    '@type': 'SearchAction',
                    'target': {
                        '@type': 'EntryPoint',
                        'urlTemplate': siteUrl + '/?s={search_term_string}'
                    },
                    'query-input': 'required name=search_term_string'
                }];
            }

            website.publisher = { '@id': siteUrl + '/#identity' };
            graph.push(website);

            // Entity node (Organization/Person).
            var entity = {
                '@type': entityType,
                '@id': siteUrl + '/#identity',
                'name': entityName,
                'url': entityUrl
            };

            if (entityDesc) {
                entity.description = entityDesc;
            }

            if (entityLogo) {
                entity.logo = {
                    '@type': 'ImageObject',
                    '@id': siteUrl + '/#logo',
                    'url': entityLogo
                };
                if (entityType === 'Person') {
                    entity.image = { '@id': siteUrl + '/#logo' };
                }
            }

            // Parse sameAs.
            var sameAsText = $('#vigia-jsonld-sameas').val();
            if (sameAsText) {
                var sameAs = sameAsText.split('\n').map(function(line) {
                    return line.trim();
                }).filter(function(url) {
                    return url.length > 0 && url.indexOf('http') === 0;
                });
                if (sameAs.length > 0) {
                    entity.sameAs = sameAs;
                }
            }

            graph.push(entity);
        }

        // Build AI Discovery actions.
        if (aiEnabled) {
            var aiActions = [];

            if ($('#vigia-jsonld-ai-llms').is(':checked') && aiFeatures.llms_txt) {
                aiActions.push({
                    '@type': 'ReadAction',
                    'target': siteUrl + '/llms.txt',
                    'name': 'LLMs.txt',
                    'description': 'Machine-readable content index for LLMs'
                });
            }
            if ($('#vigia-jsonld-ai-llms-full').is(':checked') && aiFeatures.llms_full_txt) {
                aiActions.push({
                    '@type': 'ReadAction',
                    'target': siteUrl + '/llms-full.txt',
                    'name': 'LLMs-full.txt',
                    'description': 'Full content index for LLMs'
                });
            }
            if ($('#vigia-jsonld-ai-markdown').is(':checked') && aiFeatures.markdown) {
                aiActions.push({
                    '@type': 'ReadAction',
                    'target': siteUrl + '/{slug}.md',
                    'name': 'Markdown for Agents',
                    'description': 'Individual posts served as optimized markdown via .md URL endpoints'
                });
            }

            if (aiActions.length > 0) {
                if (identityEnabled && graph.length > 0) {
                    // Merge into WebSite node.
                    var wsNode = graph[0];
                    if (!wsNode.potentialAction) {
                        wsNode.potentialAction = [];
                    }
                    wsNode.potentialAction = wsNode.potentialAction.concat(aiActions);
                } else {
                    // Standalone WebSite for AI Discovery.
                    graph.push({
                        '@type': 'WebSite',
                        '@id': siteUrl + '/#website',
                        'url': siteUrl + '/',
                        'name': siteName,
                        'potentialAction': aiActions
                    });
                }
            }
        }

        if (graph.length === 0) {
            $('#vigia-jsonld-preview').text('');
            $('#vigia-jsonld-preview-empty').show();
            return;
        }

        var jsonld = {
            '@context': 'https://schema.org',
            '@graph': graph
        };

        $('#vigia-jsonld-preview').text(JSON.stringify(jsonld, null, 2));
        $('#vigia-jsonld-preview-empty').hide();
    }

    // ==========================================================================
    // Helpers
    // ==========================================================================

    /**
     * Show admin notice
     */
    function showNotice(type, message) {
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible vigia-notice-js">' +
                        '<p>' + message + '</p>' +
                        '<button type="button" class="notice-dismiss"></button></div>');
        
        // Remove existing notices
        $('.vigia-notice-js').remove();
        
        // Add new notice
        $('.vigia-extras-wrap h1').after($notice);
        
        // Handle dismiss
        $notice.on('click', '.notice-dismiss', function() {
            $notice.fadeOut(function() { $(this).remove(); });
        });
        
        // Scroll to top
        $('html, body').animate({ scrollTop: 0 }, 300);
    }

    /**
     * Escape HTML entities
     */
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ==========================================================================
    // MCP TAB (v1.12.0)
    // ==========================================================================

    /**
     * Initialize the MCP tab: one-click password generation, revocation,
     * copy-to-clipboard for the connection commands and read-only toggle.
     */
    function initMcpTab() {
        if ($('.vigia-mcp-quick-connect, .vigia-mcp-readonly').length === 0) {
            return;
        }
        var $panel = $('.vigia-mcp-quick-connect');

        // Read-only toggle (auto-save on change)
        $(document).on('change', '#vigia-mcp-readonly-checkbox', function() {
            var $checkbox = $(this);
            var $status = $('.vigia-mcp-readonly-status');
            var enabled = $checkbox.is(':checked');

            $checkbox.prop('disabled', true);
            $status.text(vigiaData.strings.saving || 'Saving...').css('color', '');

            $.ajax({
                url: vigiaData.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'vigia_save_mcp_readonly',
                    nonce: vigiaData.ajaxNonce,
                    enabled: enabled ? 'true' : 'false'
                }
            }).done(function(response) {
                if (response && response.success) {
                    $status.text(vigiaData.strings.saved || 'Saved').css('color', '#1d6f42');
                    // Reload so the abilities pill and the prompt hint
                    // mirror the new state.
                    setTimeout(function() { window.location.reload(); }, 600);
                } else {
                    $checkbox.prop('checked', !enabled);
                    var errMsg = response && response.data ? response.data : (vigiaData.strings.error || 'Error');
                    $status.text(errMsg).css('color', '#b32d2e');
                    $checkbox.prop('disabled', false);
                }
            }).fail(function() {
                $checkbox.prop('checked', !enabled);
                $status.text(vigiaData.strings.error || 'Error').css('color', '#b32d2e');
                $checkbox.prop('disabled', false);
            });
        });

        if ($panel.length === 0) {
            return;
        }

        // Generate Application Password and connection commands
        $panel.on('click', '.vigia-mcp-generate', function(e) {
            e.preventDefault();

            var $btn = $(this);
            var $status = $panel.find('.vigia-mcp-generate-status');

            $btn.prop('disabled', true);
            $status.text(vigiaData.strings.loading || 'Loading...');

            $.ajax({
                url: vigiaData.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'vigia_create_mcp_app_password',
                    nonce: vigiaData.ajaxNonce
                }
            }).done(function(response) {
                if (!response || !response.success) {
                    var msg = response && response.data ? response.data : (vigiaData.strings.error || 'Error');
                    $status.text(msg).css('color', '#b32d2e');
                    $btn.prop('disabled', false);
                    return;
                }

                renderMcpResult(response.data);
                $status.text('').css('color', '');
                $btn.closest('p').hide();
            }).fail(function() {
                $status.text(vigiaData.strings.error || 'Error').css('color', '#b32d2e');
                $btn.prop('disabled', false);
            });
        });

        // Revoke existing Application Password
        $panel.on('click', '.vigia-mcp-revoke', function(e) {
            e.preventDefault();

            if (!window.confirm(vigiaData.strings.confirmRevokeMcp || 'Revoke the current VigIA MCP password and generate a new one?')) {
                return;
            }

            var $btn = $(this);
            var uuid = $btn.data('uuid') || '';
            var $status = $panel.find('.vigia-mcp-revoke-status');

            $btn.prop('disabled', true);
            $status.text(vigiaData.strings.loading || 'Loading...');

            $.ajax({
                url: vigiaData.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'vigia_revoke_mcp_app_password',
                    nonce: vigiaData.ajaxNonce,
                    uuid: uuid
                }
            }).done(function(response) {
                if (!response || !response.success) {
                    var msg = response && response.data ? response.data : (vigiaData.strings.error || 'Error');
                    $status.text(msg).css('color', '#b32d2e');
                    $btn.prop('disabled', false);
                    return;
                }
                // Reload to refresh the UI state into the "no existing password" branch.
                window.location.reload();
            }).fail(function() {
                $status.text(vigiaData.strings.error || 'Error').css('color', '#b32d2e');
                $btn.prop('disabled', false);
            });
        });

        // Copy to clipboard
        $panel.on('click', '.vigia-mcp-copy', function(e) {
            e.preventDefault();

            var $btn = $(this);
            var target = $btn.data('target');
            var text = $panel.find(target).text();

            copyToClipboard(text).then(function() {
                var original = $btn.text();
                $btn.text(vigiaData.strings.copied || 'Copied!').prop('disabled', true);
                setTimeout(function() {
                    $btn.text(original).prop('disabled', false);
                }, 1500);
            }, function() {
                window.alert(vigiaData.strings.copyFailed || 'Could not copy to clipboard. Select the text manually.');
            });
        });

        // Safe JSON merger — keeps the user from breaking their config by
        // hand. They paste their current file, we parse it, splice in the
        // VigIA server entry preserving everything else, and return a
        // valid full file ready to save back.
        $panel.on('click', '.vigia-mcp-merger-go', function(e) {
            e.preventDefault();

            var $btn = $(this);
            var $details = $btn.closest('details.vigia-mcp-merger');
            var client = $details.data('client');
            var $input = $details.find('.vigia-mcp-merger-input');
            var $status = $details.find('.vigia-mcp-merger-status');
            var $output = $details.find('.vigia-mcp-merger-output');
            var $result = $details.find('.vigia-mcp-merger-result');

            $status.text('').css('color', '');

            var currentJson = $input.val();
            if (!currentJson || !currentJson.trim()) {
                $status.text(vigiaData.strings.mergerEmpty || 'Paste your current config first.').css('color', '#b32d2e');
                $output.hide();
                return;
            }

            try {
                var merged = mergeMcpConfig(client, currentJson);
                $result.text(merged);
                $output.show();
                $status.text(vigiaData.strings.mergerOk || 'Merged. Copy the result and save it as the config file.').css('color', '#1d6f42');
            } catch (err) {
                $status.text(err.message).css('color', '#b32d2e');
                $output.hide();
            }
        });

        // Copy-merged button — same UX as the regular Copy button but
        // sourced from the merger output panel rather than a static block.
        $panel.on('click', '.vigia-mcp-copy-merged', function(e) {
            e.preventDefault();

            var $btn = $(this);
            var text = $btn.closest('.vigia-mcp-merger-output').find('.vigia-mcp-merger-result').text();

            copyToClipboard(text).then(function() {
                var original = $btn.text();
                $btn.text(vigiaData.strings.copied || 'Copied!').prop('disabled', true);
                setTimeout(function() {
                    $btn.text(original).prop('disabled', false);
                }, 1500);
            }, function() {
                window.alert(vigiaData.strings.copyFailed || 'Could not copy to clipboard. Select the text manually.');
            });
        });
    }

    /**
     * Merge VigIA into a user-supplied MCP client config without breaking
     * anything else in the file.
     *
     * @param {string} client       'cursor' or 'claudedesktop'
     * @param {string} currentJson  Raw text of the user's current config
     * @returns {string}            Pretty-printed merged JSON
     * @throws {Error} if the input is not a valid JSON object, or if the
     *                 quick-connect block has not been generated yet.
     */
    function mergeMcpConfig(client, currentJson) {
        // 1. Parse user input
        var current;
        try {
            current = JSON.parse(currentJson);
        } catch (e) {
            throw new Error(
                (vigiaData.strings.mergerInvalidJson || 'Your file is not valid JSON. Make sure to copy the entire file.') +
                ' (' + e.message + ')'
            );
        }
        if (!current || typeof current !== 'object' || Array.isArray(current)) {
            throw new Error(vigiaData.strings.mergerNotObject || 'The root of the file must be a JSON object.');
        }

        // 2. Pull the vigia entry from the already-rendered full block
        var $panel = $('.vigia-mcp-quick-connect');
        var fullJson = $panel.find('.vigia-mcp-cmd-' + client).text();
        if (!fullJson) {
            throw new Error(vigiaData.strings.mergerNoCreds || 'Generate the password first, then come back here.');
        }
        var fullParsed;
        try {
            fullParsed = JSON.parse(fullJson);
        } catch (e) {
            throw new Error('Internal error: cannot parse generated config. Reload the page.');
        }
        var vigiaBlock = fullParsed && fullParsed.mcpServers && fullParsed.mcpServers.vigia;
        if (!vigiaBlock) {
            throw new Error('Internal error: vigia entry not found in generated config.');
        }

        // 3. Splice it in, creating mcpServers if it's missing
        if (!current.mcpServers || typeof current.mcpServers !== 'object' || Array.isArray(current.mcpServers)) {
            current.mcpServers = {};
        }
        current.mcpServers.vigia = vigiaBlock;

        // 4. Return pretty-printed with 2-space indentation (matches the
        // convention every MCP client's config file uses)
        return JSON.stringify(current, null, 2);
    }

    /**
     * Render the result block with the connection commands for each client.
     *
     * @param {object} data Server response payload.
     */
    function renderMcpResult(data) {
        var $panel = $('.vigia-mcp-quick-connect');
        $panel.find('.vigia-mcp-cmd-claudecode').text(data.claudecode_cmd || '');
        $panel.find('.vigia-mcp-cmd-cursor').text(data.cursor_full || '');
        $panel.find('.vigia-mcp-cmd-claudedesktop').text(data.claudedesktop_full || '');
        $panel.find('.vigia-mcp-cmd-generic-url').text(data.generic_url || '');
        $panel.find('.vigia-mcp-cmd-generic-header').text(data.generic_header || '');
        $panel.find('.vigia-mcp-quick-result').show();
    }

    /**
     * Copy text to clipboard with a graceful fallback for non-secure
     * contexts (where navigator.clipboard is unavailable).
     *
     * @param {string} text Text to copy.
     * @returns {Promise}
     */
    function copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function(resolve, reject) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try {
                var ok = document.execCommand('copy');
                document.body.removeChild(ta);
                ok ? resolve() : reject();
            } catch (err) {
                document.body.removeChild(ta);
                reject(err);
            }
        });
    }

})(jQuery);