/**
 * VigIA - Admin Scripts
 *
 * Handles data loading, chart rendering, and user interactions.
 *
 * @package VigIA
 */

/* global vigiaData, vigiaDataCategories, Chart */

/**
 * VigIA Table Paginator
 *
 * Reusable client-side pagination for tables.
 * Shows/hides <tbody> rows and renders navigation controls.
 *
 * Usage:
 *   var pager = new VigiaPaginator({
 *       table: '#my-table',
 *       pageSize: 10,
 *       pager: '#my-pager'
 *   });
 *   pager.refresh();          // recalculate after DOM changes
 *   pager.goToPage(2);        // jump to page
 *   pager.currentPage = 1;    // reset before refresh
 *
 * @param {Object} options
 * @param {string} options.table       - Table selector.
 * @param {number} options.pageSize    - Rows per page.
 * @param {string} options.pager       - Container selector for controls.
 * @param {string} [options.pagerBottom] - Optional bottom pager container (synced).
 */
window.VigiaPaginator = (function($) {
    'use strict';

    function Paginator(options) {
        this.$table   = $(options.table);
        this.pageSize = options.pageSize || 10;
        this.$pager   = $(options.pager);
        this.$pagerBottom = options.pagerBottom ? $(options.pagerBottom) : null;
        this.currentPage = 1;

        if (this.$table.length === 0 || this.$pager.length === 0) {
            return;
        }

        this._buildControls(this.$pager);
        if (this.$pagerBottom && this.$pagerBottom.length) {
            this._buildControls(this.$pagerBottom);
        }

        // Merged collection for bulk UI updates in _render
        this.$allPagers = (this.$pagerBottom && this.$pagerBottom.length)
            ? this.$pager.add(this.$pagerBottom)
            : this.$pager;

        this.$table.data('vigiaPaginator', this);
        this.refresh();
    }

    /**
     * Build control buttons inside a pager container.
     *
     * @param {jQuery} $container Pager element to populate.
     */
    Paginator.prototype._buildControls = function($container) {
        var html = '<button type="button" class="vigia-pager-btn vigia-pager-first" title="First">&laquo;</button>' +
            '<button type="button" class="vigia-pager-btn vigia-pager-prev" title="Previous">&lsaquo;</button>' +
            '<span class="vigia-pager-info"></span>' +
            '<button type="button" class="vigia-pager-btn vigia-pager-next" title="Next">&rsaquo;</button>' +
            '<button type="button" class="vigia-pager-btn vigia-pager-last" title="Last">&raquo;</button>';

        $container.html(html);

        var self = this;
        $container.on('click', '.vigia-pager-first', function() { self.goToPage(1); });
        $container.on('click', '.vigia-pager-prev',  function() { self.goToPage(self.currentPage - 1); });
        $container.on('click', '.vigia-pager-next',  function() { self.goToPage(self.currentPage + 1); });
        $container.on('click', '.vigia-pager-last',  function() { self.goToPage(self.totalPages()); });
    };

    /**
     * Get visible data rows (excludes no-data placeholders).
     */
    Paginator.prototype._getRows = function() {
        return this.$table.find('tbody tr').not('.vigia-no-data');
    };

    /**
     * Total number of pages.
     */
    Paginator.prototype.totalPages = function() {
        return Math.max(1, Math.ceil(this._getRows().length / this.pageSize));
    };

    /**
     * Navigate to a specific page.
     */
    Paginator.prototype.goToPage = function(page) {
        page = Math.max(1, Math.min(page, this.totalPages()));
        this.currentPage = page;
        this._render();
    };

    /**
     * Recalculate after rows added/removed.
     */
    Paginator.prototype.refresh = function() {
        if (this.currentPage > this.totalPages()) {
            this.currentPage = this.totalPages();
        }
        this._render();
    };

    /**
     * Apply show/hide and update controls.
     */
    Paginator.prototype._render = function() {
        var $rows  = this._getRows();
        var total  = $rows.length;
        var pages  = this.totalPages();
        var start  = (this.currentPage - 1) * this.pageSize;
        var end    = Math.min(start + this.pageSize, total);

        // Show/hide rows
        $rows.each(function(i) {
            $(this).toggle(i >= start && i < end);
        });

        // Range text: "1–10 of 85"
        var ofStr = (typeof vigiaData !== 'undefined' && vigiaData.strings && vigiaData.strings.pagerRange)
            ? vigiaData.strings.pagerRange
            : '%1$s\u2013%2$s of %3$s';
        var info = total > 0
            ? ofStr.replace('%1$s', start + 1).replace('%2$s', end).replace('%3$s', total)
            : '';

        // Update all pager containers (top + optional bottom)
        this.$allPagers.find('.vigia-pager-info').text(info);

        // Toggle arrow buttons (hide if single page)
        var multi = pages > 1;
        this.$allPagers.find('.vigia-pager-btn').toggle(multi);

        // Disable at boundaries
        this.$allPagers.find('.vigia-pager-first, .vigia-pager-prev').prop('disabled', this.currentPage <= 1);
        this.$allPagers.find('.vigia-pager-next, .vigia-pager-last').prop('disabled', this.currentPage >= pages);

        // Show pagers only when there are rows
        this.$allPagers.toggle(total > 0);
    };

    return Paginator;

})(jQuery);

(function($) {
    'use strict';

    // Chart instances
    let timelineChart = null;
    let categoryChart = null;

    // Current settings
    let currentDays = 30;
    let compareEnabled = false;
    let compareType = 'previous';

    // Custom date ranges
    let customDateFrom = null;
    let customDateTo = null;
    let customCompareDateFrom = null;
    let customCompareDateTo = null;

    // Timeline data storage
    let currentTimelineData = null;
    let compareTimelineData = null;

    // Paginator instances
    let crawlersPaginator = null;
    let pagesPaginator = null;
    let recentPaginator = null;
    let customCrawlersPaginator = null;

    // AI Share & Summarize integration
    let aissActive = false;

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Only run on plugin admin page
        if ($('.vigia-wrap').length === 0) {
            return;
        }

        // Cache selectors
        var $compareToggle = $('#vigia-compare-toggle');
        var $compareRange = $('#vigia-compare-range');
        var $dateRange = $('#vigia-date-range');
        var $customDates = $('#vigia-custom-dates');
        var $compareCustomDates = $('#vigia-compare-custom-dates');

        // Load initial data
        loadAllData();

        // Date range change handler
        $dateRange.on('change', function() {
            var value = $(this).val();
            
            if (value === 'custom') {
                $customDates.show();
                // Don't load data yet, wait for Apply button
            } else {
                $customDates.hide();
                customDateFrom = null;
                customDateTo = null;
                currentDays = parseInt(value, 10);
                loadAllData();
            }
        });

        // Custom date apply handler
        $('#vigia-apply-custom-dates').on('click', function() {
            customDateFrom = $('#vigia-date-from').val();
            customDateTo = $('#vigia-date-to').val();
            
            if (customDateFrom && customDateTo) {
                currentDays = 'custom';
                loadAllData();
            }
        });

        // Compare toggle handler
        $compareToggle.on('change', function() {
            compareEnabled = this.checked;
            
            if (compareEnabled) {
                $compareRange.removeAttr('disabled');
                loadCompareStats();
                loadCompareTimeline();
            } else {
                $compareRange.attr('disabled', 'disabled');
                $compareCustomDates.hide();
                clearCompareStats();
                clearCompareTimeline();
            }
        });

        // Compare range change handler
        $compareRange.on('change', function() {
            compareType = $(this).val();
            
            if (compareType === 'custom') {
                $compareCustomDates.show();
                // Don't load data yet, wait for Apply button
            } else {
                $compareCustomDates.hide();
                customCompareDateFrom = null;
                customCompareDateTo = null;
                if (compareEnabled) {
                    loadCompareStats();
                    loadCompareTimeline();
                }
            }
        });

        // Custom compare date apply handler
        $('#vigia-apply-compare-dates').on('click', function() {
            customCompareDateFrom = $('#vigia-compare-date-from').val();
            customCompareDateTo = $('#vigia-compare-date-to').val();
            
            if (customCompareDateFrom && customCompareDateTo && compareEnabled) {
                loadCompareStats();
                loadCompareTimeline();
            }
        });

        // Export dropdown toggle
        $('#vigia-export-csv').on('click', function(e) {
            e.stopPropagation();
            $('#vigia-export-menu').toggleClass('open');
        });

        // Close dropdown when clicking outside
        $(document).on('click', function() {
            $('#vigia-export-menu').removeClass('open');
        });

        // Export options handlers
        $('.vigia-export-option').on('click', function(e) {
            e.stopPropagation();
            var exportType = $(this).data('export');
            $('#vigia-export-menu').removeClass('open');
            exportCSV(exportType);
        });

        // Settings handlers
        $('#vigia-save-settings').on('click', saveSettings);
        $('#vigia-delete-all-data').on('click', deleteAllData);

        // Custom crawlers handlers
        $('#vigia-add-custom-crawler').on('click', addCustomCrawler);
        $(document).on('click', '.vigia-remove-custom-crawler', removeCustomCrawler);

        // Collapsible box handler
        $('.vigia-collapsible-header').on('click', toggleCollapsible);

        // Recent activity filter handlers
        $('#vigia-filter-apply').on('click', applyRecentFilters);
        $('#vigia-filter-clear').on('click', clearRecentFilters);
        $('#vigia-filter-export').on('click', exportFilteredRecent);

        // Apply filters on Enter key
        $('.vigia-recent-filters input, .vigia-recent-filters select').on('keypress', function(e) {
            if (e.which === 13) {
                applyRecentFilters();
            }
        });

        // Server-side pager controls (4 buttons mirroring VigiaPaginator).
        $(document).on('click', '.vigia-recent-pager-first', function() {
            fetchRecentPage(recentFilters, 1);
        });
        $(document).on('click', '.vigia-recent-pager-prev', function() {
            var p = Math.max(1, recentPagination.page - 1);
            fetchRecentPage(recentFilters, p);
        });
        $(document).on('click', '.vigia-recent-pager-next', function() {
            var p = Math.min(Math.max(1, recentPagination.total_pages), recentPagination.page + 1);
            fetchRecentPage(recentFilters, p);
        });
        $(document).on('click', '.vigia-recent-pager-last', function() {
            fetchRecentPage(recentFilters, Math.max(1, recentPagination.total_pages));
        });

        // Crawlers multiselect — open/close panel.
        $(document).on('click', '#vigia-filter-crawlers .vigia-multiselect-toggle', function(e) {
            e.stopPropagation();
            populateCrawlerOptions();
            var $multi = $('#vigia-filter-crawlers');
            var $panel = $multi.find('.vigia-multiselect-panel');
            var willOpen = $panel.prop('hidden');
            $panel.prop('hidden', !willOpen);
            $(this).attr('aria-expanded', willOpen ? 'true' : 'false');
        });

        // Close multiselect when clicking outside.
        $(document).on('click', function(e) {
            if (!$(e.target).closest('#vigia-filter-crawlers').length) {
                $('#vigia-filter-crawlers .vigia-multiselect-panel').prop('hidden', true);
                $('#vigia-filter-crawlers .vigia-multiselect-toggle').attr('aria-expanded', 'false');
            }
        });

        // Update label as user toggles crawler checkboxes (no fetch yet — wait for Apply).
        $(document).on('change', '#vigia-filter-crawlers .vigia-multiselect-options input[type=checkbox]', function() {
            updateCrawlerToggleLabel();
        });

        // Init paginator for custom crawlers table (PHP-rendered)
        if ($('#vigia-custom-crawlers-list table').length > 0) {
            customCrawlersPaginator = new VigiaPaginator({
                table: '#vigia-custom-crawlers-list table',
                pageSize: 5,
                pager: '#vigia-custom-crawlers-pager'
            });
        }

        // AI Share & Summarize tip dismiss handler
        $(document).on('click', '.vigia-aiss-tip .notice-dismiss', function() {
            $.ajax({
                url: vigiaData.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'vigia_dismiss_aiss_tip',
                    nonce: vigiaData.ajaxNonce
                }
            });
        });
    });

    /**
     * Get human-readable period text
     *
     * @return {string} Period text
     */
    function getPeriodText() {
        if (currentDays === 'custom' && customDateFrom && customDateTo) {
            // Format custom dates
            var fromDate = new Date(customDateFrom + 'T00:00:00');
            var toDate = new Date(customDateTo + 'T00:00:00');
            var options = { day: 'numeric', month: 'short', year: 'numeric' };
            return fromDate.toLocaleDateString(undefined, options) + ' - ' + toDate.toLocaleDateString(undefined, options);
        } else if (currentDays === 0) {
            return vigiaData.strings.allTime || 'All time';
        } else if (currentDays === 1) {
            return vigiaData.strings.today || 'Today';
        } else {
            // Use template string: "Last %d days"
            var template = vigiaData.strings.lastDays || 'Last %d days';
            return template.replace('%d', currentDays);
        }
    }

    /**
     * Update period indicators in section titles
     */
    function updatePeriodIndicators() {
        var periodText = getPeriodText();
        $('#vigia-timeline-period').text(periodText);
        $('#vigia-category-period').text(periodText);
        $('#vigia-crawlers-period').text(periodText);
        $('#vigia-pages-period').text(periodText);
    }

    /**
     * Save settings via AJAX
     */
    function saveSettings() {
        var $button = $('#vigia-save-settings');
        var originalText = $button.text();
        
        $button.prop('disabled', true).text(vigiaData.strings.loading);

        $.ajax({
            url: vigiaData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'vigia_save_settings',
                nonce: vigiaData.ajaxNonce,
                retention_days: $('#vigia-retention-days').val(),
                delete_on_uninstall: $('#vigia-delete-on-uninstall').is(':checked') ? 'true' : 'false'
            },
            success: function(response) {
                if (response.success) {
                    showNotice(vigiaData.strings.settingsSaved, 'success');
                } else {
                    showNotice(vigiaData.strings.error, 'error');
                }
            },
            error: function() {
                showNotice(vigiaData.strings.error, 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    }

    /**
     * Delete all data via AJAX
     */
    function deleteAllData() {
        if (!confirm(vigiaData.strings.confirmDelete)) {
            return;
        }

        var $button = $('#vigia-delete-all-data');
        var originalText = $button.text();
        
        $button.prop('disabled', true).text(vigiaData.strings.loading);

        $.ajax({
            url: vigiaData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'vigia_delete_all_data',
                nonce: vigiaData.ajaxNonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice(vigiaData.strings.dataDeleted, 'success');
                    // Reload data
                    loadAllData();
                } else {
                    showNotice(vigiaData.strings.error, 'error');
                }
            },
            error: function() {
                showNotice(vigiaData.strings.error, 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    }

    /**
     * Add custom crawler via AJAX
     */
    function addCustomCrawler() {
        var userAgent = $('#vigia-custom-useragent').val().trim();
        var name = $('#vigia-custom-name').val().trim();
        var company = $('#vigia-custom-company').val().trim();
        var category = $('#vigia-custom-category').val();

        if (!userAgent || !name) {
            showNotice('User-Agent and Name are required', 'error');
            return;
        }

        var $button = $('#vigia-add-custom-crawler');
        var originalText = $button.text();
        
        $button.prop('disabled', true).text(vigiaData.strings.loading);

        $.ajax({
            url: vigiaData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'vigia_add_custom_crawler',
                nonce: vigiaData.ajaxNonce,
                user_agent: userAgent,
                name: name,
                company: company,
                category: category
            },
            success: function(response) {
                if (response.success) {
                    showNotice(vigiaData.strings.crawlerAdded, 'success');
                    // Clear form
                    $('#vigia-custom-useragent, #vigia-custom-name, #vigia-custom-company').val('');
                    // Reload page to show updated list
                    location.reload();
                } else {
                    showNotice(response.data || vigiaData.strings.error, 'error');
                }
            },
            error: function() {
                showNotice(vigiaData.strings.error, 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    }

    /**
     * Remove custom crawler via AJAX
     */
    function removeCustomCrawler() {
        if (!confirm(vigiaData.strings.confirmRemove)) {
            return;
        }

        var $button = $(this);
        var crawlerId = $button.data('id');
        var $row = $button.closest('tr');

        $button.prop('disabled', true);

        $.ajax({
            url: vigiaData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'vigia_remove_custom_crawler',
                nonce: vigiaData.ajaxNonce,
                crawler_id: crawlerId
            },
            success: function(response) {
                if (response.success) {
                    showNotice(vigiaData.strings.crawlerRemoved, 'success');
                    $row.fadeOut(300, function() {
                        $(this).remove();
                        // Refresh paginator
                        if (customCrawlersPaginator) {
                            customCrawlersPaginator.refresh();
                        }
                        // Check if list is empty
                        if ($('#vigia-custom-crawlers-list tbody tr').length === 0) {
                            $('#vigia-custom-crawlers-list').html('<p class="vigia-no-custom-crawlers">No custom crawlers added yet.</p>');
                        }
                    });
                } else {
                    showNotice(response.data || vigiaData.strings.error, 'error');
                    $button.prop('disabled', false);
                }
            },
            error: function() {
                showNotice(vigiaData.strings.error, 'error');
                $button.prop('disabled', false);
            }
        });
    }

    /**
     * Toggle collapsible section
     */
    function toggleCollapsible() {
        var $container = $(this).closest('.vigia-collapsible');
        var $content = $container.find('.vigia-collapsible-content');
        var $icon = $(this).find('.dashicons');
        var isCollapsed = $container.hasClass('collapsed');

        if (isCollapsed) {
            $container.removeClass('collapsed');
            $content.slideDown(200);
            $icon.removeClass('dashicons-arrow-right-alt2').addClass('dashicons-arrow-down-alt2');
        } else {
            $container.addClass('collapsed');
            $content.slideUp(200);
            $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-right-alt2');
        }

        // Save state via AJAX
        $.ajax({
            url: vigiaData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'vigia_toggle_crawlers_box',
                nonce: vigiaData.ajaxNonce,
                collapsed: !isCollapsed ? 'true' : 'false'
            }
        });
    }

    /**
     * Show admin notice
     *
     * @param {string} message Notice message
     * @param {string} type    Notice type (success, error, warning)
     */
    function showNotice(message, type) {
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        
        // Remove existing notices
        $('.vigia-wrap > .notice').remove();
        
        // Add new notice after title
        $('.vigia-wrap h1').after($notice);
        
        // Auto dismiss after 3 seconds
        setTimeout(function() {
            $notice.fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
    }

    /**
     * Load all dashboard data
     */
    function loadAllData() {
        // Update period indicators
        updatePeriodIndicators();

        loadStats();
        loadTimeline();
        loadCategories();
        loadCrawlers();
        loadPages();
        loadRecent();

        if (compareEnabled) {
            loadCompareStats();
            loadCompareTimeline();
        }
    }

    /**
     * Make API request
     *
     * @param {string} endpoint API endpoint
     * @param {Object} params Additional parameters
     * @param {Function} callback Success callback
     */
    function apiRequest(endpoint, params, callback) {
        var data = params || {};
        
        // Handle date range
        if (currentDays === 'custom' && customDateFrom && customDateTo) {
            data.date_from = customDateFrom;
            data.date_to = customDateTo;
        } else {
            data.days = currentDays;
        }

        $.ajax({
            url: vigiaData.restUrl + endpoint,
            method: 'GET',
            data: data,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', vigiaData.nonce);
            },
            success: callback,
            error: function() {
                console.error('API request failed:', endpoint);
            }
        });
    }

    /**
     * Load statistics
     */
    function loadStats() {
        apiRequest('stats', {}, function(data) {
            $('#vigia-total-visits').text(formatNumber(data.total_visits));
            $('#vigia-unique-crawlers').text(formatNumber(data.unique_crawlers));
            $('#vigia-unique-pages').text(formatNumber(data.unique_pages));
        });
    }

    /**
     * Load comparison statistics
     */
    function loadCompareStats() {
        var params = { compare: compareType };
        
        // Handle custom compare dates
        if (compareType === 'custom' && customCompareDateFrom && customCompareDateTo) {
            params.compare_date_from = customCompareDateFrom;
            params.compare_date_to = customCompareDateTo;
        }
        
        apiRequest('stats/compare', params, function(data) {
            updateCompareDisplay('vigia-total-visits-compare', data.total_visits_change, data.total_visits_previous);
            updateCompareDisplay('vigia-unique-crawlers-compare', data.unique_crawlers_change, data.unique_crawlers_previous);
            updateCompareDisplay('vigia-unique-pages-compare', data.unique_pages_change, data.unique_pages_previous);
        });
    }

    /**
     * Update comparison display
     *
     * @param {string} elementId Element ID
     * @param {number} change Percentage change
     * @param {number} previousValue Previous period value
     */
    function updateCompareDisplay(elementId, change, previousValue) {
        var $element = $('#' + elementId);
        var changeNum = parseFloat(change);
        var text = '';
        var className = 'vigia-stat-compare ';

        if (changeNum > 0) {
            text = '\u2191 ' + changeNum.toFixed(1) + '% vs ' + formatNumber(previousValue);
            className += 'positive';
        } else if (changeNum < 0) {
            text = '\u2193 ' + Math.abs(changeNum).toFixed(1) + '% vs ' + formatNumber(previousValue);
            className += 'negative';
        } else {
            text = '= ' + formatNumber(previousValue);
            className += 'neutral';
        }

        $element.text(text).attr('class', className);
    }

    /**
     * Clear comparison displays
     */
    function clearCompareStats() {
        $('.vigia-stat-compare').text('').removeClass('positive negative neutral');
    }

    /**
     * Load and render timeline chart
     */
    function loadTimeline() {
        apiRequest('timeline', {}, function(data) {
            currentTimelineData = data;
            renderTimelineChart(data, compareEnabled ? compareTimelineData : null);
        });
    }

    /**
     * Load comparison timeline data
     */
    function loadCompareTimeline() {
        var params = { compare: compareType };
        
        // Handle custom compare dates
        if (compareType === 'custom' && customCompareDateFrom && customCompareDateTo) {
            params.compare_date_from = customCompareDateFrom;
            params.compare_date_to = customCompareDateTo;
        }
        
        apiRequest('timeline/compare', params, function(data) {
            compareTimelineData = data;
            if (currentTimelineData) {
                renderTimelineChart(currentTimelineData, compareTimelineData);
            }
        });
    }

    /**
     * Clear comparison timeline
     */
    function clearCompareTimeline() {
        compareTimelineData = null;
        if (currentTimelineData) {
            renderTimelineChart(currentTimelineData, null);
        }
    }

    /**
     * Load and render category chart
     */
    function loadCategories() {
        apiRequest('categories', {}, function(data) {
            renderCategoryChart(data);
        });
    }

    /**
     * Load crawlers table (all results, client-side pagination)
     */
    function loadCrawlers() {
        var $tbody = $('#vigia-crawlers-table tbody');
        $tbody.html('<tr class="vigia-no-data"><td colspan="3" class="vigia-loading">' + vigiaData.strings.loading + '</td></tr>');

        apiRequest('crawlers', { limit: 100, offset: 0 }, function(response) {
            var data = response.items || response;

            if (data.length === 0) {
                $tbody.html('<tr class="vigia-no-data"><td colspan="3" class="vigia-loading">' + vigiaData.strings.noData + '</td></tr>');
                if (crawlersPaginator) {
                    crawlersPaginator.refresh();
                }
                return;
            }

            var html = '';
            data.forEach(function(row) {
                html += renderCrawlerRow(row);
            });
            $tbody.html(html);

            if (!crawlersPaginator) {
                crawlersPaginator = new VigiaPaginator({
                    table: '#vigia-crawlers-table',
                    pageSize: 10,
                    pager: '#vigia-crawlers-pager'
                });
            }
            crawlersPaginator.currentPage = 1;
            crawlersPaginator.refresh();
        });
    }

    /**
     * Render single crawler row
     *
     * @param {Object} row Crawler data
     * @return {string} HTML
     */
    function renderCrawlerRow(row) {
        var categoryLabel = vigiaDataCategories.labels[row.crawler_category] || row.crawler_category;
        var categoryColor = vigiaDataCategories.colors[row.crawler_category] || '#95a5a6';

        var html = '<tr>';
        html += '<td><strong>' + escapeHtml(row.crawler_name) + '</strong></td>';
        html += '<td><span class="vigia-category-badge" style="background-color:' + categoryColor + '">' + escapeHtml(categoryLabel) + '</span></td>';
        html += '<td class="num">' + formatNumber(row.visit_count) + '</td>';
        html += '</tr>';

        return html;
    }

    /**
     * Load pages table (all results, client-side pagination)
     */
    function loadPages() {
        var colCount = aissActive ? 4 : 3;
        var $tbody = $('#vigia-pages-table tbody');
        $tbody.html('<tr class="vigia-no-data"><td colspan="' + colCount + '" class="vigia-loading">' + vigiaData.strings.loading + '</td></tr>');

        apiRequest('pages', { limit: 100, offset: 0 }, function(response) {
            var data = response.items || response;

            // Update AISS active state from response
            aissActive = response.aiss_active || false;
            var clickData = response.click_data || {};
            colCount = aissActive ? 4 : 3;

            // Update table header dynamically
            updatePagesHeader();

            if (data.length === 0) {
                $tbody.html('<tr class="vigia-no-data"><td colspan="' + colCount + '" class="vigia-loading">' + vigiaData.strings.noData + '</td></tr>');
                if (pagesPaginator) {
                    pagesPaginator.refresh();
                }
                return;
            }

            var html = '';
            data.forEach(function(row) {
                html += renderPageRow(row, clickData);
            });
            $tbody.html(html);

            if (!pagesPaginator) {
                pagesPaginator = new VigiaPaginator({
                    table: '#vigia-pages-table',
                    pageSize: 10,
                    pager: '#vigia-pages-pager'
                });
            }
            pagesPaginator.currentPage = 1;
            pagesPaginator.refresh();
        });
    }

    /**
     * Render single page row
     *
     * @param {Object} row       Page data.
     * @param {Object} clickData AISS click data keyed by request_path.
     * @return {string} HTML
     */
    function renderPageRow(row, clickData) {
        var path = row.request_path || '/';
        var truncatedPath = path.length > 50 ? path.substring(0, 50) + '...' : path;
        var fullUrl = vigiaData.siteUrl + path;

        var html = '<tr>';
        html += '<td title="' + escapeHtml(path) + '">';
        html += '<a href="' + escapeHtml(fullUrl) + '" target="_blank" rel="noopener noreferrer"><code>' + escapeHtml(truncatedPath) + '</code></a>';
        html += '</td>';
        html += '<td class="num">' + formatNumber(row.visit_count) + '</td>';
        html += '<td class="num">' + formatNumber(row.crawler_count) + '</td>';

        if (aissActive) {
            var clicks = (clickData && clickData[path]) || 0;
            html += '<td class="num">' + formatNumber(clicks) + '</td>';
        }

        html += '</tr>';

        return html;
    }

    /**
     * Update pages table header based on AISS active state
     */
    function updatePagesHeader() {
        var $thead = $('#vigia-pages-table thead tr');
        var hasClicksCol = $thead.find('th').length > 3;

        if (aissActive && !hasClicksCol) {
            $thead.append('<th class="num">' + (vigiaData.strings.clicks || 'Clicks') + '</th>');
        } else if (!aissActive && hasClicksCol) {
            $thead.find('th:last').remove();
        }
    }

    /**
     * Recent activity — server-side pagination and filters (v2.0.0)
     *
     * State:
     *  - recentFilters: object with the currently applied filters.
     *  - recentPagination: page/per_page/total/total_pages from the last response.
     *  - recentPageCache: cache of the last few rendered pages (key = filters+page).
     *  - recentRequestSeq: monotonic counter to discard stale AJAX responses.
     */
    var recentFilters = { crawlers: [], category: '', content_type: '', http_status: '', date_from: '', date_to: '' };
    var recentPagination = { page: 1, per_page: 20, total: 0, total_pages: 0 };
    var recentPageCache = {};
    var recentRequestSeq = 0;
    var crawlerOptionsLoaded = false;

    function filtersCacheKey(filters, page) {
        return [
            (filters.crawlers || []).slice().sort().join('|'),
            filters.category || '',
            filters.content_type || '',
            filters.http_status || '',
            filters.date_from || '',
            filters.date_to || '',
            page
        ].join('::');
    }

    function activeFilterCount() {
        var count = 0;
        if (recentFilters.crawlers && recentFilters.crawlers.length) count++;
        if (recentFilters.category) count++;
        if (recentFilters.content_type) count++;
        if (recentFilters.http_status) count++;
        if (recentFilters.date_from || recentFilters.date_to) count++;
        return count;
    }

    function updateActiveFilterBadge() {
        var $badge = $('#vigia-filter-badge');
        var n = activeFilterCount();
        if (n === 0) {
            $badge.prop('hidden', true).text('');
            $('#vigia-filter-export').prop('disabled', true);
            return;
        }
        var template = n === 1
            ? (vigiaData.strings.filterBadgeSingular || '%d active filter')
            : (vigiaData.strings.filterBadgePlural || '%d active filters');
        $badge.prop('hidden', false).text(template.replace('%d', n));
        $('#vigia-filter-export').prop('disabled', false);
    }

    function buildRecentRequestParams(filters, page) {
        var params = {
            page: page,
            per_page: recentPagination.per_page
        };
        if (filters.crawlers && filters.crawlers.length) {
            params.crawlers = filters.crawlers;
        }
        if (filters.category) params.category = filters.category;
        if (filters.content_type) params.content_type = filters.content_type;
        if (filters.http_status) params.http_status = filters.http_status;
        if (filters.date_from) params.date_from = filters.date_from;
        if (filters.date_to) params.date_to = filters.date_to;
        return params;
    }

    function loadRecent(preservePage) {
        var page = preservePage ? recentPagination.page : 1;
        fetchRecentPage(recentFilters, page);
    }

    function fetchRecentPage(filters, page) {
        var $tbody = $('#vigia-recent-table tbody');
        var cacheKey = filtersCacheKey(filters, page);

        if (recentPageCache[cacheKey]) {
            renderRecentTable(recentPageCache[cacheKey]);
            return;
        }

        $tbody.html('<tr class="vigia-no-data"><td colspan="6" class="vigia-loading">' + vigiaData.strings.loading + '</td></tr>');

        var mySeq = ++recentRequestSeq;
        apiRequest('recent', buildRecentRequestParams(filters, page), function(data) {
            // Discard stale responses if a newer request was fired meanwhile.
            if (mySeq !== recentRequestSeq) return;

            // Server returns either an array (legacy) or a paged object.
            if (Array.isArray(data)) {
                data = { items: data, total: data.length, page: 1, per_page: data.length, total_pages: 1 };
            }

            recentPagination = {
                page: data.page || 1,
                per_page: data.per_page || recentPagination.per_page,
                total: data.total || 0,
                total_pages: data.total_pages || 0
            };

            // Cache up to 3 page snapshots; drop oldest first.
            var keys = Object.keys(recentPageCache);
            if (keys.length >= 3) delete recentPageCache[keys[0]];
            recentPageCache[cacheKey] = data;

            renderRecentTable(data);
        });
    }

    function renderRecentTable(data) {
        var $tbody = $('#vigia-recent-table tbody');
        var items = (data && data.items) ? data.items : [];
        var typeLabels = (vigiaData.strings.contentTypeLabels) || {};

        if (items.length === 0) {
            $tbody.html('<tr class="vigia-no-data"><td colspan="8" class="vigia-loading">' + vigiaData.strings.noData + '</td></tr>');
            renderRecentPager();
            return;
        }

        var html = '';
        items.forEach(function(row) {
            var categoryLabel = vigiaDataCategories.labels[row.crawler_category] || row.crawler_category;
            var categoryColor = vigiaDataCategories.colors[row.crawler_category] || '#95a5a6';
            var path = row.request_path || '/';
            var truncatedPath = path.length > 30 ? path.substring(0, 30) + '...' : path;
            var ip = row.ip_address || '-';
            var actionsHtml = getActionsDropdownHTML(row.crawler_name, ip);

            var contentType = row.content_type || 'other';
            var contentTypeLabel = typeLabels[contentType] || contentType;
            var httpStatus = row.http_status || '';

            html += '<tr>';
            html += '<td><strong>' + escapeHtml(row.crawler_name) + '</strong></td>';
            html += '<td><span class="vigia-category-badge" style="background-color:' + categoryColor + '">' + escapeHtml(categoryLabel) + '</span></td>';
            var fullUrl = vigiaData.siteUrl + path;
            html += '<td title="' + escapeHtml(path) + '"><a href="' + escapeHtml(fullUrl) + '" target="_blank" rel="noopener noreferrer"><code>' + escapeHtml(truncatedPath) + '</code></a></td>';
            html += '<td class="vigia-content-type vigia-content-type-' + escapeHtml(contentType) + '">' + escapeHtml(contentTypeLabel) + '</td>';
            html += '<td class="vigia-http-status vigia-http-' + escapeHtml(String(httpStatus).charAt(0)) + 'xx">' + escapeHtml(String(httpStatus)) + '</td>';
            html += '<td><code>' + escapeHtml(ip) + '</code></td>';
            html += '<td>' + escapeHtml(row.visit_date) + '</td>';
            html += '<td class="vigia-actions-col">' + actionsHtml + '</td>';
            html += '</tr>';
        });

        $tbody.html(html);
        renderRecentPager();
    }

    function renderRecentPager() {
        var $pagers = $('#vigia-recent-pager, #vigia-recent-pager-bottom');

        var total = recentPagination.total;
        var page = recentPagination.page;
        var perPage = recentPagination.per_page;
        var totalPages = Math.max(1, recentPagination.total_pages);

        if (total === 0) {
            $pagers.empty();
            return;
        }

        var first = ((page - 1) * perPage) + 1;
        var last = Math.min(page * perPage, total);

        var rangeStr = (vigiaData.strings.pagerRange || '%1$s–%2$s of %3$s')
            .replace('%1$s', first)
            .replace('%2$s', last)
            .replace('%3$s', total);

        var atStart = page <= 1;
        var atEnd   = page >= totalPages;
        var singlePage = totalPages <= 1;

        // Mirror VigiaPaginator's markup so styling stays consistent across tables.
        var html = '<button type="button" class="vigia-pager-btn vigia-recent-pager-first" title="' + escapeHtml(vigiaData.strings.first || 'First') + '"' + (atStart ? ' disabled' : '') + (singlePage ? ' style="display:none"' : '') + '>&laquo;</button>' +
            '<button type="button" class="vigia-pager-btn vigia-recent-pager-prev" title="' + escapeHtml(vigiaData.strings.previous || 'Previous') + '"' + (atStart ? ' disabled' : '') + (singlePage ? ' style="display:none"' : '') + '>&lsaquo;</button>' +
            '<span class="vigia-pager-info">' + escapeHtml(rangeStr) + '</span>' +
            '<button type="button" class="vigia-pager-btn vigia-recent-pager-next" title="' + escapeHtml(vigiaData.strings.next || 'Next') + '"' + (atEnd ? ' disabled' : '') + (singlePage ? ' style="display:none"' : '') + '>&rsaquo;</button>' +
            '<button type="button" class="vigia-pager-btn vigia-recent-pager-last" title="' + escapeHtml(vigiaData.strings.last || 'Last') + '"' + (atEnd ? ' disabled' : '') + (singlePage ? ' style="display:none"' : '') + '>&raquo;</button>';

        $pagers.html(html);
    }

    function readRecentFiltersFromUI() {
        var crawlers = [];
        $('#vigia-filter-crawlers .vigia-multiselect-options input[type=checkbox]:checked').each(function() {
            crawlers.push($(this).val());
        });
        return {
            crawlers: crawlers,
            category: $('#vigia-filter-category').val() || '',
            content_type: $('#vigia-filter-content-type').val() || '',
            http_status: $('#vigia-filter-http-status').val() || '',
            date_from: $('#vigia-filter-date-from').val() || '',
            date_to: $('#vigia-filter-date-to').val() || ''
        };
    }

    function applyRecentFilters() {
        recentFilters = readRecentFiltersFromUI();
        recentPageCache = {}; // Invalidate cache on filter change.
        updateActiveFilterBadge();
        updateCrawlerToggleLabel();
        fetchRecentPage(recentFilters, 1);
    }

    function clearRecentFilters() {
        $('#vigia-filter-crawlers .vigia-multiselect-options input[type=checkbox]').prop('checked', false);
        $('#vigia-filter-category').val('');
        $('#vigia-filter-content-type').val('');
        $('#vigia-filter-http-status').val('');
        $('#vigia-filter-date-from').val('');
        $('#vigia-filter-date-to').val('');
        recentFilters = { crawlers: [], category: '', content_type: '', http_status: '', date_from: '', date_to: '' };
        recentPageCache = {};
        updateActiveFilterBadge();
        updateCrawlerToggleLabel();
        fetchRecentPage(recentFilters, 1);
    }

    function exportFilteredRecent() {
        if (activeFilterCount() === 0) return;
        var params = $.extend({}, buildRecentRequestParams(recentFilters, 1));
        delete params.page;
        delete params.per_page;

        // Signal to the server that this export came from the "Export filtered
        // CSV" button so it always uses the filtered filename and the
        // "Activity (filtered)" header label, even when only a date range
        // happens to be set (no other filters would still feel like a filter
        // to the user because they clicked the filtered-export button).
        params.mode = 'filtered';

        var $btn = $('#vigia-filter-export');
        $btn.prop('disabled', true);

        apiRequest('export', params, function(data) {
            $btn.prop('disabled', false);
            if (!data || !data.content) return;
            var blob = new Blob([data.content], { type: 'text/csv;charset=utf-8' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = data.filename || 'vigia-filtered.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });
    }

    function updateCrawlerToggleLabel() {
        var $label = $('#vigia-filter-crawlers .vigia-multiselect-label');
        var selected = $('#vigia-filter-crawlers .vigia-multiselect-options input[type=checkbox]:checked');
        var allLabel = vigiaData.strings.allCrawlers || 'All crawlers';
        if (selected.length === 0) {
            $label.text(allLabel);
        } else if (selected.length === 1) {
            $label.text(selected.val());
        } else {
            var template = vigiaData.strings.crawlersSelected || '%d crawlers selected';
            $label.text(template.replace('%d', selected.length));
        }
    }

    function populateCrawlerOptions() {
        if (crawlerOptionsLoaded) return;
        crawlerOptionsLoaded = true;
        apiRequest('crawlers', { limit: 100, offset: 0 }, function(response) {
            var $container = $('#vigia-filter-crawlers .vigia-multiselect-options');
            var items = (response && response.items) ? response.items : (Array.isArray(response) ? response : []);
            if (!items || items.length === 0) {
                $container.html('<p class="description">' + escapeHtml(vigiaData.strings.noData) + '</p>');
                return;
            }
            var html = '';
            items.forEach(function(row) {
                var name = row.crawler_name || row.name || '';
                if (!name) return;
                html += '<label class="vigia-multiselect-option">';
                html += '<input type="checkbox" value="' + escapeHtml(name) + '"> ';
                html += escapeHtml(name);
                html += '</label>';
            });
            $container.html(html);
        });
    }

    /**
     * Render timeline chart
     *
     * @param {Array} data Timeline data with crawler breakdown
     * @param {Array|null} compareData Comparison timeline data (optional)
     */
    function renderTimelineChart(data, compareData) {
        var ctx = document.getElementById('vigia-timeline-chart');
        if (!ctx) return;

        // Destroy existing chart
        if (timelineChart) {
            timelineChart.destroy();
        }

        var labels = data.map(function(item) {
            return item.date;
        });

        var values = data.map(function(item) {
            return parseInt(item.visit_count, 10);
        });

        // Store crawler breakdown for tooltip
        var crawlerBreakdown = data.map(function(item) {
            return item.crawlers || [];
        });

        // Store comparison dates for tooltip
        var compareDates = [];
        if (compareData && compareData.length > 0) {
            compareDates = compareData.map(function(item) {
                return item.date;
            });
        }

        // Build datasets array
        var datasets = [{
            label: vigiaData.strings.requests,
            data: values,
            borderColor: '#D97757',
            backgroundColor: 'rgba(217, 119, 87, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.3,
            pointRadius: 3,
            pointHoverRadius: 5
        }];

        // Add comparison dataset if available
        if (compareData && compareData.length > 0) {
            var compareValues = compareData.map(function(item) {
                return parseInt(item.visit_count, 10);
            });

            datasets.push({
                label: vigiaData.strings.previousPeriod || 'Previous period',
                data: compareValues,
                borderColor: '#999999',
                backgroundColor: 'transparent',
                borderWidth: 2,
                borderDash: [5, 5],
                fill: false,
                tension: 0.3,
                pointRadius: 2,
                pointHoverRadius: 4
            });
        }

        /**
         * Format date for display
         */
        function formatDateForTooltip(dateStr) {
            var dateObj = new Date(dateStr + 'T00:00:00');
            return dateObj.toLocaleDateString(undefined, { 
                weekday: 'short', 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric' 
            });
        }

        timelineChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: compareData && compareData.length > 0,
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 15
                        }
                    },
                    tooltip: {
                        callbacks: {
                            title: function(context) {
                                var index = context[0].dataIndex;
                                var currentDate = labels[index];
                                var title = formatDateForTooltip(currentDate);
                                
                                // If comparing and we have comparison dates, show both
                                if (compareDates.length > 0 && compareDates[index]) {
                                    var compareDate = compareDates[index];
                                    if (currentDate !== compareDate) {
                                        title += ' vs ' + formatDateForTooltip(compareDate);
                                    }
                                }
                                
                                return title;
                            },
                            label: function(context) {
                                var label = context.dataset.label || '';
                                var index = context.dataIndex;
                                var value = context.parsed.y;
                                
                                // For comparison dataset, show date in label
                                if (context.datasetIndex === 1 && compareDates.length > 0 && compareDates[index]) {
                                    var compareDate = new Date(compareDates[index] + 'T00:00:00');
                                    var shortDate = compareDate.toLocaleDateString(undefined, { 
                                        month: 'short', 
                                        day: 'numeric' 
                                    });
                                    return label + ' (' + shortDate + '): ' + value;
                                }
                                
                                return label + ': ' + value;
                            },
                            afterBody: function(context) {
                                // Only show crawler breakdown for current period (first dataset)
                                if (context[0].datasetIndex !== 0) {
                                    return [];
                                }
                                
                                var index = context[0].dataIndex;
                                var crawlers = crawlerBreakdown[index];
                                
                                if (!crawlers || crawlers.length === 0) {
                                    return [];
                                }

                                var lines = [''];
                                // Show top 5 crawlers max
                                var topCrawlers = crawlers.slice(0, 5);
                                topCrawlers.forEach(function(crawler) {
                                    lines.push('  ' + crawler.name + ': ' + crawler.count);
                                });

                                if (crawlers.length > 5) {
                                    var others = crawlers.slice(5).reduce(function(sum, c) {
                                        return sum + c.count;
                                    }, 0);
                                    lines.push('  ' + vigiaData.strings.others + ': ' + others);
                                }

                                return lines;
                            }
                        },
                        displayColors: true
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    }

    /**
     * Render category chart
     *
     * @param {Array} data Category data
     */
    function renderCategoryChart(data) {
        var ctx = document.getElementById('vigia-category-chart');
        if (!ctx) return;

        // Destroy existing chart
        if (categoryChart) {
            categoryChart.destroy();
        }

        var labels = data.map(function(item) {
            return vigiaDataCategories.labels[item.crawler_category] || item.crawler_category;
        });

        var values = data.map(function(item) {
            return parseInt(item.visit_count, 10);
        });

        var colors = data.map(function(item) {
            return vigiaDataCategories.colors[item.crawler_category] || '#95a5a6';
        });

        categoryChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                cutout: '60%'
            }
        });

        // Render custom legend
        renderCategoryLegend(data, colors);
    }

    /**
     * Render category legend
     *
     * @param {Array} data Category data
     * @param {Array} colors Category colors
     */
    function renderCategoryLegend(data, colors) {
        var $legend = $('#vigia-category-legend');
        var html = '';

        data.forEach(function(item, index) {
            var label = vigiaDataCategories.labels[item.crawler_category] || item.crawler_category;
            html += '<div class="vigia-category-legend-item">';
            html += '<span class="vigia-category-legend-color" style="background-color:' + colors[index] + '"></span>';
            html += '<span>' + escapeHtml(label) + ' (' + formatNumber(item.visit_count) + ')</span>';
            html += '</div>';
        });

        $legend.html(html);
    }

    /**
     * Export data to CSV
     * 
     * @param {string} exportType Type of export: 'current', 'comparison', or 'timeline'
     */
    function exportCSV(exportType) {
        var $button = $('#vigia-export-csv');
        var originalText = $button.html();
        
        $button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + vigiaData.strings.loading);

        // Build request data
        var requestData = {};
        if (currentDays === 'custom' && customDateFrom && customDateTo) {
            requestData.date_from = customDateFrom;
            requestData.date_to = customDateTo;
        } else {
            requestData.days = currentDays;
        }

        // Determine endpoint and add comparison params if needed
        var endpoint = 'export';
        if (exportType === 'timeline') {
            endpoint = 'export/timeline';
            if (compareEnabled) {
                requestData.compare = compareType;
                if (compareType === 'custom' && customCompareDateFrom && customCompareDateTo) {
                    requestData.compare_date_from = customCompareDateFrom;
                    requestData.compare_date_to = customCompareDateTo;
                }
            }
        }

        $.ajax({
            url: vigiaData.restUrl + endpoint,
            method: 'GET',
            data: requestData,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', vigiaData.nonce);
            },
            success: function(data) {
                // Create download link
                var blob = new Blob([data.content], { type: 'text/csv;charset=utf-8;' });
                var url = URL.createObjectURL(blob);
                var link = document.createElement('a');
                
                link.setAttribute('href', url);
                link.setAttribute('download', data.filename);
                link.style.visibility = 'hidden';
                
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                URL.revokeObjectURL(url);
            },
            error: function() {
                alert(vigiaData.strings.error);
            },
            complete: function() {
                $button.prop('disabled', false).html(originalText);
            }
        });
    }

    /**
     * Format number with locale
     *
     * @param {number} num Number to format
     * @return {string} Formatted number
     */
    function formatNumber(num) {
        return parseInt(num, 10).toLocaleString();
    }

    /**
     * Escape HTML entities
     *
     * @param {string} text Text to escape
     * @return {string} Escaped text
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ==========================================================================
    // Blocking functionality (v1.1.0)
    // ==========================================================================

    /**
     * Check if crawler is blocked via PHP (User-Agent)
     *
     * @param {string} crawlerName Crawler name
     * @return {boolean}
     */
    function isCrawlerBlockedPHP(crawlerName) {
        return typeof vigiaBlockedCrawlers !== 'undefined' && 
               vigiaBlockedCrawlers.indexOf(crawlerName) !== -1;
    }

    /**
     * Check if crawler is blocked via robots.txt
     *
     * @param {string} crawlerName Crawler name
     * @return {boolean}
     */
    function isCrawlerBlockedRobots(crawlerName) {
        return typeof vigiaRobotsDisallow !== 'undefined' && 
               vigiaRobotsDisallow.indexOf(crawlerName) !== -1;
    }

    /**
     * Check if IP is blocked
     *
     * @param {string} ip IP address
     * @return {boolean}
     */
    function isIPBlocked(ip) {
        return typeof vigiaBlockedIPs !== 'undefined' && 
               vigiaBlockedIPs.indexOf(ip) !== -1;
    }

    /**
     * Get actions dropdown HTML for Recent Activity table
     * Shows different icon/color based on applied rules
     *
     * @param {string} crawlerName Crawler name
     * @param {string} ip          IP address
     * @return {string} HTML
     */
    function getActionsDropdownHTML(crawlerName, ip) {
        var safeName = escapeHtml(crawlerName);
        var safeIP = escapeHtml(ip);
        
        // Check current block status
        var blockedUA = isCrawlerBlockedPHP(crawlerName);
        var blockedRobots = isCrawlerBlockedRobots(crawlerName);
        var blockedIP = (ip && ip !== '-') ? isIPBlocked(ip) : false;

        // Determine button state class and icon
        var btnClass = 'vigia-action-btn';
        var iconClass = 'dashicons-shield';
        var titleText = vigiaData.strings.blockActions || 'Block actions';

        if (blockedUA && blockedRobots && (blockedIP || !ip || ip === '-')) {
            // Fully blocked - all options applied
            btnClass += ' vigia-btn-full';
            iconClass = 'dashicons-lock';
            titleText = vigiaData.strings.fullyBlocked;
        } else if (blockedUA || blockedIP) {
            // PHP blocked (UA or IP) - red/danger
            btnClass += ' vigia-btn-blocked';
            iconClass = 'dashicons-dismiss';
            titleText = vigiaData.strings.phpBlocked || 'PHP blocked';
        } else if (blockedRobots) {
            // Only robots.txt disallow - orange/warning
            btnClass += ' vigia-btn-disallow';
            iconClass = 'dashicons-warning';
            titleText = vigiaData.strings.disallowedOnly || 'Disallowed in robots.txt';
        }

        var html = '<div class="vigia-block-dropdown">' +
                   '<button type="button" class="button button-small ' + btnClass + '" title="' + titleText + '">' +
                   '<span class="dashicons ' + iconClass + '"></span>' +
                   '<span class="dashicons dashicons-arrow-down-alt2"></span>' +
                   '</button>' +
                   '<div class="vigia-block-menu">';

        // Disallow option
        if (blockedRobots) {
            html += '<span class="vigia-menu-disabled"><span class="dashicons dashicons-yes"></span>' + vigiaData.strings.disallowed + '</span>';
        } else {
            html += '<button type="button" class="vigia-action-disallow" data-crawler="' + safeName + '">' +
                    '<span class="dashicons dashicons-admin-site-alt3"></span>' + vigiaData.strings.addDisallow + '</button>';
        }

        // Block User-Agent option
        if (blockedUA) {
            html += '<span class="vigia-menu-disabled"><span class="dashicons dashicons-yes"></span>' + vigiaData.strings.uaBlocked + '</span>';
        } else {
            html += '<button type="button" class="vigia-action-block-ua" data-crawler="' + safeName + '">' +
                    '<span class="dashicons dashicons-admin-users"></span>' + vigiaData.strings.blockUA + '</button>';
        }

        // Block IP option (only if IP is valid)
        if (ip && ip !== '-' && ip !== '0.0.0.0') {
            if (blockedIP) {
                html += '<span class="vigia-menu-disabled"><span class="dashicons dashicons-yes"></span>' + vigiaData.strings.ipBlocked + '</span>';
            } else {
                html += '<button type="button" class="vigia-action-block-ip" data-ip="' + safeIP + '" data-crawler="' + safeName + '">' +
                        '<span class="dashicons dashicons-admin-network"></span>' + vigiaData.strings.blockIP + '</button>';
            }
        }

        html += '</div></div>';
        return html;
    }

    // Block dropdown toggle
    $(document).on('click', '.vigia-action-btn', function(e) {
        e.stopPropagation();
        var $menu = $(this).siblings('.vigia-block-menu');
        $('.vigia-block-menu').not($menu).removeClass('open');
        $menu.toggleClass('open');
    });

    // Close block menu on outside click
    $(document).on('click', function() {
        $('.vigia-block-menu').removeClass('open');
    });

    // Action: Add Disallow to robots.txt
    $(document).on('click', '.vigia-action-disallow', function(e) {
        e.stopPropagation();
        var crawlerName = $(this).data('crawler');
        addDisallow(crawlerName);
    });

    // Action: Block User-Agent via PHP
    $(document).on('click', '.vigia-action-block-ua', function(e) {
        e.stopPropagation();
        var crawlerName = $(this).data('crawler');
        blockUserAgent(crawlerName);
    });

    // Action: Block IP via PHP
    $(document).on('click', '.vigia-action-block-ip', function(e) {
        e.stopPropagation();
        var ip = $(this).data('ip');
        var crawlerName = $(this).data('crawler');
        blockIP(ip, crawlerName);
    });

    /**
     * Add Disallow rule to robots.txt
     *
     * @param {string} crawlerName Crawler name
     */
    function addDisallow(crawlerName) {
        $('.vigia-block-menu').removeClass('open');
        
        $.ajax({
            url: vigiaData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'vigia_add_robots_rule',
                nonce: vigiaData.ajaxNonce,
                crawler_name: crawlerName,
                action_type: 'disallow'
            },
            success: function(response) {
                if (response.success) {
                    // Update local array
                    if (typeof vigiaRobotsDisallow === 'undefined') {
                        window.vigiaRobotsDisallow = [];
                    }
                    vigiaRobotsDisallow.push(crawlerName);
                    
                    // Reload recent activity table (preserve current page)
                    loadRecent(true);
                    
                    // Show success notice
                    showBlockNotice(crawlerName, 'disallow');
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
     * Block User-Agent via PHP
     *
     * @param {string} crawlerName Crawler name / User-Agent pattern
     */
    function blockUserAgent(crawlerName) {
        $('.vigia-block-menu').removeClass('open');
        
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
                    // Update local array
                    if (typeof vigiaBlockedCrawlers === 'undefined') {
                        window.vigiaBlockedCrawlers = [];
                    }
                    vigiaBlockedCrawlers.push(crawlerName);
                    
                    // Reload recent activity table (preserve current page)
                    loadRecent(true);
                    
                    // Show success notice
                    showBlockNotice(crawlerName, 'useragent');
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
     * Block IP address via PHP
     *
     * @param {string} ip          IP address
     * @param {string} crawlerName Associated crawler name (for note)
     */
    function blockIP(ip, crawlerName) {
        $('.vigia-block-menu').removeClass('open');
        
        $.ajax({
            url: vigiaData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'vigia_block_crawler',
                nonce: vigiaData.ajaxNonce,
                ip: ip,
                name: crawlerName || ip,
                block_type: 'ip'
            },
            success: function(response) {
                if (response.success) {
                    // Update local array
                    if (typeof vigiaBlockedIPs === 'undefined') {
                        window.vigiaBlockedIPs = [];
                    }
                    vigiaBlockedIPs.push(ip);
                    
                    // Reload recent activity table (preserve current page)
                    loadRecent(true);
                    
                    // Show success notice
                    showBlockNotice(ip, 'ip');
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
     * Show block success notice with link to Extras
     *
     * @param {string} target Target (crawler name or IP)
     * @param {string} method Block method (disallow, useragent, ip)
     */
    function showBlockNotice(target, method) {
        var methodLabels = {
            'disallow': 'robots.txt Disallow',
            'useragent': 'User-Agent block',
            'ip': 'IP block'
        };
        var methodLabel = methodLabels[method] || method;
        
        var $notice = $('<div class="notice notice-success is-dismissible vigia-block-notice-js">' +
                        '<p><strong>' + escapeHtml(target) + '</strong> ' + vigiaData.strings.blockedVia + ' ' + methodLabel + '. ' +
                        '<a href="' + vigiaData.extrasUrl + '">' + vigiaData.strings.manageInExtras + '</a></p>' +
                        '<button type="button" class="notice-dismiss"></button></div>');
        
        // Remove existing notices
        $('.vigia-block-notice-js').remove();
        
        // Add new notice
        $('.vigia-wrap h1').after($notice);
        
        // Handle dismiss
        $notice.on('click', '.notice-dismiss', function() {
            $notice.fadeOut(function() { $(this).remove(); });
        });
        
        // Auto-dismiss after 8 seconds
        setTimeout(function() {
            $notice.fadeOut(function() { $(this).remove(); });
        }, 8000);
    }

})(jQuery);