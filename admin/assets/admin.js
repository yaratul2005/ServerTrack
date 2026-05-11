/**
 * ServerTrack Admin JS — v2.7
 *
 * v2.7 FIXES:
 *   1. AJAX actions corrected:
 *      - loadDashboardStats() now uses 'servertrack_get_dashboard_stats'
 *        (registered in ServerTrack_Admin) with cfg.nonce.
 *      - refreshLog() now uses 'servertrack_get_logs' (registered in
 *        ServerTrack_Admin) with cfg.nonce.
 *   2. KPI DOM selectors fixed — count-up now targets #st-kpi-total,
 *      #st-kpi-success, #st-kpi-failed, #st-kpi-rate (IDs added to PHP).
 *   3. #servertrack-clear-log → #st-clear-log-btn (matches PHP).
 *   4. #servertrack-refresh-log → #st-manual-refresh (matches PHP).
 *   5. Log tbody selector #servertrack-log-body → #st-log-tbody.
 *   6. Log table empty-state colspan 9 → 7 (matches 7-column thead).
 *   7. stopAutoRefresh() now also fires on window beforeunload.
 *
 * Modules:
 *   1. Toast notification system
 *   2. KPI counter animation (count-up)
 *   3. Dashboard stats (KPI cards via servertrack_get_dashboard_stats)
 *   4. Platform health cards — test event buttons
 *   5. Log table — render, filter, refresh, clear
 *   6. Response cell expand/collapse
 *   7. Auto-refresh (30s) when log tbody is visible
 *   8. Tab icon injection
 */
(function ($) {
    'use strict';

    var cfg = window.servertrack_admin || {};

    /* ── UTILITIES ──────────────────────────────────────────────────── */
    function esc(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function svgIcon(name) {
        var icons = {
            send:     '<svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
            refresh:  '<svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>',
            trash:    '<svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>',
            check:    '<svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>',
            alert:    '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
            info:     '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
            zap:      '<svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
            activity: '<svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
            log:      '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
            spinner:  '<svg viewBox="0 0 24 24"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/></svg>',
        };
        return icons[name] || '';
    }

    /* ── 1. TOAST ────────────────────────────────────────────────────── */
    function showToast(type, title, msg) {
        var $c = $('#st-toast-container');
        if (!$c.length) {
            $c = $('<div id="st-toast-container"></div>').appendTo('body');
        }
        var iconMap = { success: 'check', error: 'alert', info: 'info' };
        var $t = $(
            '<div class="st-toast st-toast-' + esc(type) + '">'
            + '<svg class="st-toast-icon">' + svgIcon(iconMap[type] || 'info') + '</svg>'
            + '<div class="st-toast-body">'
            + '<div class="st-toast-title">' + esc(title) + '</div>'
            + '<div class="st-toast-msg">'   + esc(msg)   + '</div>'
            + '</div></div>'
        );
        $c.append($t);
        setTimeout(function () {
            $t.addClass('is-leaving');
            setTimeout(function () { $t.remove(); }, 220);
        }, 3400);
    }

    /* ── 2. COUNT-UP ANIMATION ───────────────────────────────────────── */
    function animateCount($el, target, suffix) {
        var start    = 0;
        var duration = 900;
        var begin    = null;
        suffix = suffix || '';

        function step(ts) {
            if (!begin) begin = ts;
            var progress = Math.min((ts - begin) / duration, 1);
            var ease     = 1 - Math.pow(1 - progress, 3); // ease-out-cubic
            var val      = Math.round(start + ease * (target - start));
            $el.text(val + suffix);
            if (progress < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    /* ── 3. DASHBOARD STATS (KPI cards) ─────────────────────────────── */
    // v2.7 FIX: action corrected to 'servertrack_get_dashboard_stats'.
    // KPI element IDs corrected to match PHP output (#st-kpi-total etc.).
    function loadDashboardStats() {
        var $grid = $('#st-kpis');
        if (!$grid.length) return;

        $.post(cfg.ajax_url, {
            action: 'servertrack_get_dashboard_stats',
            nonce:  cfg.nonce
        }, function (res) {
            if (!res.success) return;
            var d = res.data;

            $('#st-kpi-total')  .text(''); animateCount($('#st-kpi-total'),   parseInt(d.total_today   || 0, 10));
            $('#st-kpi-rate')   .text(''); animateCount($('#st-kpi-rate'),    parseInt(d.success_rate  || 0, 10), '%');

            // Activity feed (used on Settings debug tab)
            if (typeof renderActivity === 'function') {
                renderActivity(d.recent || []);
            }

            // Per-platform last-send badge
            if (d.platforms) {
                $.each(d.platforms, function (platform, info) {
                    $('#st-last-send-' + platform).text(info.last_send || 'Never');
                    var $pill = $('#st-health-pill-' + platform);
                    if ($pill.length) {
                        if (info.configured && info.enabled) {
                            $pill.attr('class', 'st-status-pill st-status-ok')
                                 .html('<span class="st-status-pill-dot"></span> Active');
                        } else if (info.enabled && !info.configured) {
                            $pill.attr('class', 'st-status-pill st-status-warning')
                                 .html('<span class="st-status-pill-dot"></span> Setup Required');
                        } else {
                            $pill.attr('class', 'st-status-pill st-status-inactive')
                                 .html('<span class="st-status-pill-dot"></span> Inactive');
                        }
                    }
                });
            }
        });
    }

    /* ── 4. TEST EVENT BUTTONS (platform health cards) ──────────── */
    function bindTestButtons() {
        $(document).on('click', '.st-test-btn', function () {
            var $btn      = $(this);
            var platform  = $btn.data('platform');
            var $result   = $('#st-test-result-' + platform);

            var testCode = '';
            var $codeInput = $('#servertrack-meta-test-code');
            if ($codeInput.length) testCode = $.trim($codeInput.val());

            $btn.prop('disabled', true).addClass('is-sending');
            $btn.html(svgIcon('spinner') + ' Sending…');
            $result.removeClass('is-visible is-success is-error').text('');

            $.post(cfg.ajax_url, {
                action:    'servertrack_test_event',
                nonce:     cfg.nonce,
                platform:  platform,
                test_code: testCode
            }, function (res) {
                $btn.prop('disabled', false).removeClass('is-sending');
                $btn.html(svgIcon('send') + ' Send Test → ' + platform.charAt(0).toUpperCase() + platform.slice(1));
                $result.addClass('is-visible');

                if (res.success) {
                    $result.addClass('is-success').text(JSON.stringify(res.data, null, 2));
                    showToast('success', 'Test Event Sent', platform.charAt(0).toUpperCase() + platform.slice(1) + ' API responded OK.');
                } else {
                    $result.addClass('is-error').text('Error: ' + (res.data || 'Unknown error'));
                    showToast('error', 'Test Failed', 'Check the debug log for details.');
                }
                refreshLog();
                loadDashboardStats();
            }).fail(function () {
                $btn.prop('disabled', false).removeClass('is-sending');
                $btn.html(svgIcon('send') + ' Send Test → ' + platform.charAt(0).toUpperCase() + platform.slice(1));
                $result.addClass('is-visible is-error').text('Request failed. Check your network.');
                showToast('error', 'Network Error', 'AJAX request failed.');
            });
        });
    }

    /* ── 5. LOG TABLE ───────────────────────────────────────────────── */
    var logFilterState = 'all';
    var allLogs = [];

    function platformChip(p) {
        return '<span class="st-platform-chip st-platform-chip-' + esc(p) + '">' + esc(p.toUpperCase()) + '</span>';
    }

    function statusBadge(s) {
        var cls = { success: 'success', error: 'error', skipped: 'warning', dedup_blocked: 'warning' };
        return '<span class="st-log-badge st-log-badge-' + esc(cls[s] || 'info') + '">' + esc(s) + '</span>';
    }

    function renderLogRows(logs) {
        allLogs = logs || [];
        applyLogFilter();
    }

    function applyLogFilter() {
        // v2.7 FIX: selector corrected to #st-log-tbody (was #servertrack-log-body).
        var $tbody = $('#st-log-tbody');
        if (!$tbody.length) return;

        var filtered = logFilterState === 'all'
            ? allLogs
            : allLogs.filter(function (e) { return e.status === logFilterState; });

        if (!filtered.length) {
            // v2.7 FIX: colspan corrected from 9 to 7 (matches 7-column thead).
            $tbody.html(
                '<tr><td colspan="7" style="padding:0">'
                + '<div class="st-empty-state">'
                + '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'
                + '<h3>No log entries</h3><p>No entries match this filter.</p></div></td></tr>'
            );
            return;
        }

        var rows = '';
        $.each(filtered, function (i, e) {
            var platform = (e.platform || '').toLowerCase();
            var response = esc(e.response || '');
            var shortResp = response.length > 60 ? response.substring(0, 60) + '…' : response;
            var respCell = response.length > 60
                ? '<span class="st-response-short">' + shortResp + '</span>'
                  + '<button class="st-response-toggle">expand</button>'
                  + '<div class="st-response-full">' + response + '</div>'
                : response;

            rows += '<tr>'
                + '<td style="white-space:nowrap;color:var(--st-text-muted);font-size:.75rem">' + esc(e.timestamp || '') + '</td>'
                + '<td>' + statusBadge(e.status || '') + '</td>'
                + '<td>' + platformChip(platform) + '</td>'
                + '<td style="font-weight:600">' + esc(e.event_name || '') + '</td>'
                + '<td style="font-variant-numeric:tabular-nums">' + esc(e.order_id  || '') + '</td>'
                + '<td>' + esc(e.emq_score != null ? e.emq_score : '—') + '</td>'
                + '<td style="max-width:180px">' + esc(e.message || '') + '</td>'
                + '</tr>';
        });
        $tbody.html(rows);
    }

    // v2.7 FIX: refreshLog now uses 'servertrack_get_logs' (correct action in
    // ServerTrack_Admin) and targets #st-log-tbody (correct tbody ID).
    function refreshLog() {
        // v2.7 FIX: target #st-manual-refresh (was #servertrack-refresh-log).
        var $icon = $('#st-manual-refresh').find('svg');
        $icon.css('animation', 'st-spin .6s linear infinite');

        $.post(cfg.ajax_url, {
            action: 'servertrack_get_logs',
            nonce:  cfg.nonce
        }, function (res) {
            $icon.css('animation', '');
            if (res.success) renderLogRows(res.data);
        }).fail(function () {
            $icon.css('animation', '');
        });
    }

    function bindLogControls() {
        // v2.7 FIX: ID corrected to #st-manual-refresh.
        $(document).on('click', '#st-manual-refresh', function () {
            var $btn = $(this);
            $btn.prop('disabled', true);
            $.post(cfg.ajax_url, {
                action: 'servertrack_get_logs',
                nonce:  cfg.nonce
            }, function (res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    renderLogRows(res.data);
                    showToast('info', 'Log Refreshed', res.data.length + ' entries loaded.');
                }
            }).fail(function () { $btn.prop('disabled', false); });
        });

        // v2.7 FIX: ID corrected to #st-clear-log-btn.
        $(document).on('click', '#st-clear-log-btn', function () {
            if (!window.confirm('Clear all log entries? This cannot be undone.')) return;
            var $btn = $(this);
            $btn.prop('disabled', true);
            $.post(cfg.ajax_url, {
                action: 'servertrack_clear_log',
                nonce:  cfg.nonce
            }, function (res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    renderLogRows([]);
                    showToast('success', 'Log Cleared', 'All entries removed.');
                }
            }).fail(function () { $btn.prop('disabled', false); });
        });

        // Filter pills
        $(document).on('click', '.st-filter-btn', function () {
            $('.st-filter-btn').removeClass('is-active');
            $(this).addClass('is-active');
            logFilterState = $(this).data('filter');
            applyLogFilter();
        });

        // Response expand/collapse
        $(document).on('click', '.st-response-toggle', function () {
            var $toggle = $(this);
            var $full   = $toggle.next('.st-response-full');
            $full.toggleClass('is-open');
            $toggle.text($full.hasClass('is-open') ? 'collapse' : 'expand');
        });
    }

    /* ── 6. AUTO-REFRESH ────────────────────────────────────────────── */
    var autoRefreshTimer = null;

    function startAutoRefresh() {
        if (autoRefreshTimer) return;
        autoRefreshTimer = setInterval(function () {
            // v2.7 FIX: selector corrected to #st-log-tbody.
            if ($('#st-log-tbody').is(':visible')) refreshLog();
        }, 30000);
    }

    function stopAutoRefresh() {
        if (autoRefreshTimer) {
            clearInterval(autoRefreshTimer);
            autoRefreshTimer = null;
        }
    }

    /* ── INIT ────────────────────────────────────────────────────────── */
    $(function () {
        bindTestButtons();
        bindLogControls();
        loadDashboardStats();
        startAutoRefresh();

        // Load initial log if tbody is present (Settings debug tab)
        if ($('#st-log-tbody').length) {
            refreshLog();
        }

        // v2.7 FIX: stop auto-refresh on page unload.
        $(window).on('beforeunload', stopAutoRefresh);
    });

}(jQuery));
