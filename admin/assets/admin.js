/**
 * ServerTrack Admin JS — vanilla, under 200 lines.
 * Handles: tab state, test event AJAX, log refresh, log clear.
 */
(function ($) {
    'use strict';

    var cfg = window.servertrack_admin || {};

    // ── Test Event Buttons ─────────────────────────────────────────────────
    function bindTestButtons() {
        $(document).on('click', '.servertrack-test-btn', function () {
            var $btn      = $(this);
            var platform  = $btn.data('platform');
            var $response = $('#servertrack-test-response-' + platform);

            $btn.prop('disabled', true).text('Sending…');
            $response.removeClass('is-visible is-error').text('');

            $.post(cfg.ajax_url, {
                action:   'servertrack_test_event',
                nonce:    cfg.nonce,
                platform: platform
            }, function (res) {
                $btn.prop('disabled', false).text('Send Test Event → ' + platform.charAt(0).toUpperCase() + platform.slice(1));
                $response.addClass('is-visible');
                if (res.success) {
                    $response.text(JSON.stringify(res.data, null, 2));
                } else {
                    $response.addClass('is-error').text('Error: ' + (res.data || 'Unknown error'));
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                $response.addClass('is-visible is-error').text('Request failed. Check your network.');
            });
        });
    }

    // ── Log Refresh ────────────────────────────────────────────────────────
    function renderLogRows(logs) {
        var $tbody = $('#servertrack-log-body');
        if (!$tbody.length) return;

        if (!logs || !logs.length) {
            $tbody.html('<tr><td colspan="5">No log entries yet.</td></tr>');
            return;
        }

        var statusLabels = { success: 'success', error: 'error', skipped: 'skipped', dedup_blocked: 'dedup_blocked' };
        var rows = '';
        $.each(logs, function (i, entry) {
            var status = entry.status || '';
            rows += '<tr class="servertrack-log-row servertrack-status-' + status + '">'
                + '<td>' + (entry.timestamp || '') + '</td>'
                + '<td><strong>' + (entry.platform || '').toUpperCase() + '</strong></td>'
                + '<td>' + status + '</td>'
                + '<td>' + (entry.message || '') + '</td>'
                + '<td style="max-width:300px;word-break:break-all;">' + (entry['response'] || '') + '</td>'
                + '</tr>';
        });
        $tbody.html(rows);
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
