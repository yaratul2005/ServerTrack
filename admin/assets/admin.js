/**
 * ServerTrack Admin JS — vanilla, under 200 lines.
 * Handles: tab state, test event AJAX, log refresh, log clear.
 *
 * Bug fixes:
 *   - test_code field value now read from #servertrack-meta-test-code input
 *     and sent in the AJAX POST (was always empty — Meta never received it).
 *   - Graceful fallback when the input element does not exist (non-meta platforms).
 *   - renderLogRows() now renders all 9 columns in the correct order:
 *     Time, Platform, Event, Event ID, Order ID, Status, HTTP, Message, Response.
 *     Previously only 5 columns were rendered in the wrong order, causing
 *     event_name / event_id / order_id / http_code to disappear after every Refresh.
 */
(function ($) {
    'use strict';

    var cfg = window.servertrack_admin || {};

    // ── Escape HTML helper ─────────────────────────────────────────────────
    function esc(str) {
        if (!str && str !== 0) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ── Test Event Buttons ─────────────────────────────────────────────────
    function bindTestButtons() {
        $(document).on('click', '.servertrack-test-btn', function () {
            var $btn      = $(this);
            var platform  = $btn.data('platform');
            var $response = $('#servertrack-test-response-' + platform);

            // BUG FIX: read test_code from the saved input field in the Meta tab.
            // Previously this was never sent → Meta ignored the event in Test Events.
            var testCode = '';
            var $codeInput = $('#servertrack-meta-test-code');
            if ( $codeInput.length ) {
                testCode = $.trim( $codeInput.val() );
            }

            $btn.prop('disabled', true).text('Sending…');
            $response.removeClass('is-visible is-error').text('');

            $.post(cfg.ajax_url, {
                action:     'servertrack_test_event',
                nonce:      cfg.nonce,
                platform:   platform,
                test_code:  testCode
            }, function (res) {
                $btn.prop('disabled', false).text('Send Test Event → ' + platform.charAt(0).toUpperCase() + platform.slice(1));
                $response.addClass('is-visible');
                if (res.success) {
                    $response.text(JSON.stringify(res.data, null, 2));
                } else {
                    $response.addClass('is-error').text('Error: ' + (res.data || 'Unknown error'));
                }
                // Refresh log table after test so new entry appears immediately
                refreshLog();
            }).fail(function () {
                $btn.prop('disabled', false);
                $response.addClass('is-visible is-error').text('Request failed. Check your network.');
            });
        });
    }

    // ── Log Renderer ───────────────────────────────────────────────────────
    // BUG FIX: was rendering only 5 columns in wrong order.
    // Now renders all 9 columns matching the PHP table header:
    // Time | Platform | Event | Event ID | Order ID | Status | HTTP | Message | Response
    function renderLogRows(logs) {
        var $tbody = $('#servertrack-log-body');
        if (!$tbody.length) return;

        if (!logs || !logs.length) {
            $tbody.html('<tr><td colspan="9">No log entries yet.</td></tr>');
            return;
        }

        var rows = '';
        $.each(logs, function (i, entry) {
            var status   = entry.status   || '';
            var httpCode = entry.http_code ? String(entry.http_code) : '';
            var orderId  = entry.order_id  ? String(entry.order_id)  : '';

            rows += '<tr class="servertrack-log-row servertrack-status-' + esc(status) + '">'
                + '<td>'                                    + esc(entry.timestamp)  + '</td>'
                + '<td><strong>'                            + esc((entry.platform || '').toUpperCase()) + '</strong></td>'
                + '<td>'                                    + esc(entry.event_name) + '</td>'
                + '<td>'                                    + esc(entry.event_id)   + '</td>'
                + '<td>'                                    + esc(orderId)          + '</td>'
                + '<td class="servertrack-status-' + esc(status) + '">' + esc(status) + '</td>'
                + '<td>'                                    + esc(httpCode)         + '</td>'
                + '<td>'                                    + esc(entry.message)    + '</td>'
                + '<td class="servertrack-response-cell">' + esc(entry.response)   + '</td>'
                + '</tr>';
        });
        $tbody.html(rows);
    }

    // ── Log Refresh ────────────────────────────────────────────────────────
    function refreshLog() {
        $.post(cfg.ajax_url, {
            action: 'servertrack_get_logs',
            nonce:  cfg.nonce
        }, function (res) {
            if (res.success) {
                renderLogRows(res.data);
            }
        });
    }

    function bindLogRefresh() {
        $(document).on('click', '#servertrack-refresh-log', function () {
            var $btn = $(this);
            $btn.prop('disabled', true);
            $.post(cfg.ajax_url, {
                action: 'servertrack_get_logs',
                nonce:  cfg.nonce
            }, function (res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    renderLogRows(res.data);
                }
            }).fail(function () {
                $btn.prop('disabled', false);
            });
        });
    }

    // ── Log Clear ─────────────────────────────────────────────────────────
    function bindLogClear() {
        $(document).on('click', '#servertrack-clear-log', function () {
            if (!window.confirm('Clear all log entries?')) return;
            var $btn = $(this);
            $btn.prop('disabled', true);
            $.post(cfg.ajax_url, {
                action: 'servertrack_clear_log',
                nonce:  cfg.nonce
            }, function (res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    renderLogRows([]);
                }
            }).fail(function () {
                $btn.prop('disabled', false);
            });
        });
    }

    // ── Init ───────────────────────────────────────────────────────────────
    $(function () {
        bindTestButtons();
        bindLogRefresh();
        bindLogClear();
    });

}(jQuery));
