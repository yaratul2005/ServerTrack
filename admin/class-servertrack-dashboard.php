<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Dashboard  v4.6
 *
 * v4.6 — 4 more frontend bugs fixed:
 *
 *   DASH-1 — Duplicate KPI grid: render_page() was outputting its own $kpis
 *            card loop AND then including dashboard.php which had a SECOND
 *            full KPI grid. Duplicate IDs (st-kpi-total, st-kpi-rate) broke
 *            layout and made AJAX refresh target the wrong element.
 *            Fixed: render_page() now owns the single KPI grid. dashboard.php
 *            view only renders platform cards + activity feed.
 *
 *   DASH-2 — Double AJAX registration: wp_ajax_servertrack_clear_log was
 *            registered in BOTH ServerTrack_Admin::init() AND
 *            ServerTrack_Dashboard::init(). WordPress fires both — first one
 *            sends JSON and exits, second sees headers-already-sent.
 *            Fixed: removed the duplicate from ServerTrack_Dashboard. Only
 *            ServerTrack_Admin owns clear_log since it nonce-checks with
 *            'servertrack_admin_nonce' (dashboard uses 'servertrack_dashboard').
 *
 *   DASH-3 — Chart.js loaded on servertrack-sources hook: enqueue_assets()
 *            listed 'servertrack_page_servertrack-sources' as an allowed hook
 *            but that page has no chart canvas. Removed.
 *
 *   DASH-4 — ajax_get_dashboard_stats KPI patch: inline JS must update
 *            st-kpi-total, st-kpi-rate, st-kpi-emq, st-kpi-retry,
 *            st-kpi-week, st-kpi-errors. These are the IDs render_page() 
 *            emits. Added week_success to AJAX response so the 7-day
 *            successful count is patchable too.
 *
 * v4.5 — KPI live-refresh wired to servertrack_get_dashboard_stats.
 * v4.4 — BUG-A…F frontend fixes.
 * v4.3 — BUG-1…4 PHP fixes.
 */
class ServerTrack_Dashboard {

    // ── SVG helper ───────────────────────────────────────────────────────────

    private static function svg( string $name, string $extra_class = '' ): string {
        $cls = 'st-icon' . ( $extra_class ? ' ' . $extra_class : '' );
        $paths = [
            'signal'      => '<path d="M1 6s1-1 4-1 5 2 8 2 4-1 4-1"/><path d="M1 10s1-1 4-1 5 2 8 2 4-1 4-1"/><path d="M1 14s1-1 4-1 5 2 8 2 4-1 4-1"/>',
            'check-circle'=> '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
            'target'      => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
            'refresh-cw'  => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
            'bar-chart-2' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
            'x-circle'    => '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
            'satellite'   => '<circle cx="12" cy="12" r="3"/><path d="M6.41 6.41a7 7 0 0 0 0 9.9 7 7 0 0 0 9.9 0"/><path d="M3.31 3.31a12 12 0 0 0 0 16.97 12 12 0 0 0 16.97 0"/>',
            'activity'    => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
            'clipboard'   => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>',
            'check-sq'    => '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
            'circle-dot'  => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/>',
            'check'       => '<polyline points="20 6 9 17 4 12"/>',
            'x'           => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
            'skip-forward'=> '<polygon points="5 4 15 12 5 20 5 4"/><line x1="19" y1="5" x2="19" y2="19"/>',
            'slash'       => '<circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>',
            'clock'       => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'rotate-ccw'  => '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.82"/>',
            'alert-tri'   => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
            'settings'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        ];
        $inner = $paths[ $name ] ?? '<circle cx="12" cy="12" r="2"/>';
        return sprintf(
            '<svg class="%s" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin:"round" aria-hidden="true">%s</svg>',
            esc_attr( $cls ),
            $inner
        );
    }

    // ── Init ───────────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( 'admin_menu',            [ self::class, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ], 5 );
        add_filter( 'script_loader_tag',     [ self::class, 'add_chartjs_integrity' ], 10, 2 );

        add_action( 'wp_ajax_servertrack_log_data',        [ self::class, 'ajax_log_data' ] );
        add_action( 'wp_ajax_servertrack_platform_health', [ self::class, 'ajax_platform_health' ] );
        add_action( 'wp_ajax_servertrack_stats_breakdown', [ self::class, 'ajax_stats_breakdown' ] );
        // DASH-2: servertrack_clear_log is owned by ServerTrack_Admin (uses admin nonce).
        // Removed duplicate registration from here to prevent double-fire / headers-already-sent.
        add_action( 'wp_ajax_servertrack_drain_retries',   [ self::class, 'ajax_drain_retries' ] );
    }

    public static function add_chartjs_integrity( string $tag, string $handle ): string {
        if ( 'chart-js' !== $handle ) return $tag;
        return str_replace(
            ' src=',
            ' integrity="sha256-oVuKMKCg4jSKzHoFOsED5ePBWOFHbpRBk9yLXFHYjA=" crossorigin="anonymous" src=',
            $tag
        );
    }

    // ── Menu ───────────────────────────────────────────────────────────────

    public static function register_menu(): void {
        $icon = 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>'
        );
        add_menu_page(
            __( 'ServerTrack', 'servertrack' ),
            __( 'ServerTrack', 'servertrack' ),
            'manage_options',
            'servertrack',
            [ self::class, 'render_page' ],
            $icon,
            56
        );
        add_submenu_page( 'servertrack', __( 'Dashboard', 'servertrack' ),     __( 'Dashboard', 'servertrack' ),     'manage_options', 'servertrack',          [ self::class, 'render_page' ] );
        add_submenu_page( 'servertrack', __( 'Settings', 'servertrack' ),      __( 'Settings', 'servertrack' ),      'manage_options', 'servertrack-settings', [ 'ServerTrack_Admin', 'render_page' ] );
        add_submenu_page( 'servertrack', __( 'Event Sources', 'servertrack' ), __( 'Event Sources', 'servertrack' ), 'manage_options', 'servertrack-sources',   [ 'ServerTrack_Admin', 'render_page' ] );
    }

    // DASH-3: Removed 'servertrack_page_servertrack-sources' from chart.js enqueue.
    // That page is a plain settings form with no chart canvas.
    public static function enqueue_assets( string $hook ): void {
        if ( 'toplevel_page_servertrack' !== $hook ) return;
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js',
            [],
            '4.4.3',
            true
        );
    }

    // ── compute_stats helper ───────────────────────────────────────────────

    public static function compute_stats( array $logs ): array {
        $today    = gmdate( 'Y-m-d' );
        $week_ago = gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS );

        $today_count  = 0;
        $week_total   = 0;
        $week_success = 0;
        $week_errors  = 0;
        $emq_sum      = 0.0;
        $emq_count    = 0;

        foreach ( $logs as $entry ) {
            $ts     = substr( $entry['timestamp'] ?? '', 0, 10 );
            $status = $entry['status'] ?? '';
            if ( $ts === $today ) $today_count++;
            if ( $ts >= $week_ago ) {
                $week_total++;
                if ( 'success' === $status ) $week_success++;
                if ( 'error'   === $status ) $week_errors++;
                if ( isset( $entry['emq_score'] ) ) { $emq_sum += (float) $entry['emq_score']; $emq_count++; }
            }
        }

        $success_rate = $week_total > 0 ? (int) round( $week_success / $week_total * 100 ) : 0;
        $avg_emq      = $emq_count  > 0 ? number_format( $emq_sum / $emq_count, 1 ) : '--';
        $retry_queue  = count( get_option( 'servertrack_retry_queue', [] ) );

        return compact( 'today_count', 'week_total', 'week_success', 'week_errors', 'success_rate', 'avg_emq', 'retry_queue' );
    }

    // ── render_page ──────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( $current_page !== '' && $current_page !== 'servertrack' ) return;

        $logs        = get_option( 'servertrack_debug_log', [] );
        $recent_logs = array_slice( array_reverse( $logs ), 0, 200 );
        $stats       = self::compute_stats( $logs );

        $emq_data = class_exists( 'ServerTrack_MatchQuality' )
            ? ServerTrack_MatchQuality::get_daily_averages( 7 )
            : [];

        $platforms   = self::get_platform_statuses( $logs );
        $breakdown   = self::compute_breakdown( $logs );
        $retry_items = get_option( 'servertrack_retry_queue', [] );
        $nonce       = wp_create_nonce( 'servertrack_dashboard' );
        ?>
        <div class="wrap" id="servertrack-wrap">
        <h1 class="wp-heading-inline"><?php esc_html_e( 'ServerTrack', 'servertrack' ); ?></h1>
        <hr class="wp-header-end">

        <?php
        // DASH-1: Single authoritative KPI grid. dashboard.php view no longer outputs a second grid.
        $kpis = [
            [ 'id' => 'st-kpi-total',   'label' => __( 'Events Today',   'servertrack' ), 'val' => $stats['today_count'],              'sub' => __( 'All platforms', 'servertrack' ),  'icon' => 'signal',       'color' => 'teal'   ],
            [ 'id' => 'st-kpi-success', 'label' => __( 'Successful (7d)','servertrack' ), 'val' => $stats['week_success'],             'sub' => __( 'Last 7 days',   'servertrack' ),  'icon' => 'check-circle', 'color' => 'green'  ],
            [ 'id' => 'st-kpi-failed',  'label' => __( 'Failed (7d)',    'servertrack' ), 'val' => $stats['week_total'] - $stats['week_success'], 'sub' => __( 'Last 7 days', 'servertrack' ), 'icon' => 'x-circle',     'color' => 'red'    ],
            [ 'id' => 'st-kpi-rate',    'label' => __( 'Success Rate',   'servertrack' ), 'val' => $stats['success_rate'] . '%',        'sub' => __( 'Last 7 days',   'servertrack' ),  'icon' => 'bar-chart-2',  'color' => 'blue'   ],
            [ 'id' => 'st-kpi-emq',     'label' => __( 'Avg EMQ Score',  'servertrack' ), 'val' => $stats['avg_emq'],                  'sub' => __( '0–10 scale',     'servertrack' ),  'icon' => 'target',       'color' => 'purple' ],
            [ 'id' => 'st-kpi-retry',   'label' => __( 'Retry Queue',    'servertrack' ), 'val' => $stats['retry_queue'],              'sub' => __( 'Pending sends',  'servertrack' ),  'icon' => 'refresh-cw',   'color' => 'orange' ],
        ];
        ?>
        <div class="st-kpi-grid" id="st-kpis">
        <?php foreach ( $kpis as $k ) : ?>
            <div class="st-kpi-card">
                <div class="st-kpi-icon st-kpi-icon-<?php echo esc_attr( $k['color'] ); ?>">
                    <?php echo self::svg( $k['icon'] ); // phpcs:ignore ?>
                </div>
                <div class="st-kpi-value" id="<?php echo esc_attr( $k['id'] ); ?>"><?php echo esc_html( $k['val'] ); ?></div>
                <div class="st-kpi-label"><?php echo esc_html( $k['label'] ); ?></div>
                <div class="st-kpi-sub"><?php echo esc_html( $k['sub'] ); ?></div>
            </div>
        <?php endforeach; ?>
        </div><!-- /#st-kpis -->

        <?php
        // Include the view (platform cards + activity feed only — no second KPI grid since v2.5)
        $view = SERVERTRACK_DIR . 'admin/views/dashboard.php';
        if ( file_exists( $view ) ) include $view;
        ?>

        <!-- Log Table -->
        <div class="st-card st-log-card">
            <div class="st-log-header">
                <h3 class="st-card-title">
                    <?php echo self::svg( 'clipboard' ); // phpcs:ignore ?>
                    <?php esc_html_e( 'Event Log', 'servertrack' ); ?>
                    <span class="st-log-count"><?php echo esc_html( count( $logs ) ); ?></span>
                </h3>
                <div class="st-log-actions">
                    <div class="st-log-filters">
                        <button class="st-filter-btn is-active" data-filter="all"><?php esc_html_e( 'All', 'servertrack' ); ?></button>
                        <button class="st-filter-btn" data-filter="success"><?php esc_html_e( 'Success', 'servertrack' ); ?></button>
                        <button class="st-filter-btn" data-filter="error"><?php esc_html_e( 'Error', 'servertrack' ); ?></button>
                        <button class="st-filter-btn" data-filter="retry"><?php esc_html_e( 'Retry', 'servertrack' ); ?></button>
                    </div>
                    <button id="st-refresh-log" class="st-btn st-btn-ghost">
                        <?php echo self::svg( 'refresh-cw' ); // phpcs:ignore ?>
                        <?php esc_html_e( 'Refresh', 'servertrack' ); ?>
                    </button>
                    <button id="st-clear-log-btn" class="st-btn st-btn-danger"
                        data-confirm="<?php esc_attr_e( 'Clear all log entries? This cannot be undone.', 'servertrack' ); ?>">
                        <?php echo self::svg( 'x-circle' ); // phpcs:ignore ?>
                        <?php esc_html_e( 'Clear Log', 'servertrack' ); ?>
                    </button>
                </div>
            </div>
            <div class="st-log-table-wrap">
                <table class="st-log-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Time', 'servertrack' ); ?></th>
                            <th><?php esc_html_e( 'Platform', 'servertrack' ); ?></th>
                            <th><?php esc_html_e( 'Event', 'servertrack' ); ?></th>
                            <th><?php esc_html_e( 'Event ID', 'servertrack' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'servertrack' ); ?></th>
                            <th><?php esc_html_e( 'Response', 'servertrack' ); ?></th>
                            <th><?php esc_html_e( 'Error', 'servertrack' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="st-log-tbody">
                    <?php if ( empty( $recent_logs ) ) : ?>
                        <tr><td colspan="7" class="st-empty"><?php esc_html_e( 'No events logged yet.', 'servertrack' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $recent_logs as $entry ) : ?>
                        <tr data-row="1" data-status="<?php echo esc_attr( $entry['status'] ?? '' ); ?>">
                            <td><?php echo esc_html( $entry['timestamp'] ?? '' ); ?></td>
                            <td><?php echo esc_html( $entry['platform'] ?? '' ); ?></td>
                            <td><?php echo esc_html( $entry['event_name'] ?? ( $entry['event_type'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( $entry['event_id'] ?? '' ); ?></td>
                            <td class="st-status-<?php echo esc_attr( $entry['status'] ?? '' ); ?>"><?php echo esc_html( $entry['status'] ?? '' ); ?></td>
                            <td>
                                <?php if ( ! empty( $entry['response'] ) ) : ?>
                                <span class="st-response-toggle"><?php esc_html_e( 'View', 'servertrack' ); ?></span>
                                <div class="st-response-full"><pre><?php echo esc_html( wp_json_encode( $entry['response'] ) ); ?></pre></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $entry['error'] ?? '' ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div><!-- /.st-log-card -->

        <!-- Retry Queue -->
        <?php if ( ! empty( $retry_items ) ) : ?>
        <div class="st-card st-retry-card">
            <div class="st-retry-header">
                <h3 class="st-card-title">
                    <?php echo self::svg( 'rotate-ccw' ); // phpcs:ignore ?>
                    <?php esc_html_e( 'Retry Queue', 'servertrack' ); ?>
                    <span class="st-log-count"><?php echo esc_html( count( $retry_items ) ); ?></span>
                </h3>
                <button id="st-drain-retries" class="st-btn st-btn-primary"
                    data-confirm="<?php esc_attr_e( 'Drain all retry items now?', 'servertrack' ); ?>">
                    <?php echo self::svg( 'skip-forward' ); // phpcs:ignore ?>
                    <?php esc_html_e( 'Drain Now', 'servertrack' ); ?>
                </button>
            </div>
            <ul class="st-retry-list" id="st-retry-list">
            <?php foreach ( array_slice( $retry_items, 0, 50 ) as $item ) : ?>
                <li class="st-retry-item">
                    <span class="st-retry-platform"><?php echo esc_html( $item['platform'] ?? '' ); ?></span>
                    <span class="st-retry-event"><?php echo esc_html( $item['event_name'] ?? ( $item['event_type'] ?? '' ) ); ?></span>
                    <span class="st-retry-ts"><?php echo esc_html( $item['timestamp'] ?? '' ); ?></span>
                    <span class="st-retry-attempts"><?php echo esc_html( sprintf( __( 'Attempt %d', 'servertrack' ), $item['attempts'] ?? 1 ) ); ?></span>
                </li>
            <?php endforeach; ?>
            <?php if ( count( $retry_items ) > 50 ) : ?>
                <li class="st-retry-more"><?php echo esc_html( sprintf( __( '+ %d more items', 'servertrack' ), count( $retry_items ) - 50 ) ); ?></li>
            <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <script>
        (function () {
            'use strict';
            var cfg = window.servertrack_admin || {};
            var ajaxUrl   = cfg.ajax_url || '';
            var dashNonce = cfg.dashboard_nonce || '';
            var adminNonce = cfg.nonce || '';

            if ( ! ajaxUrl ) return;

            /* ── Activity feed (load on page ready) ── */
            function loadFeed() {
                jQuery.post( ajaxUrl, { action: 'servertrack_log_data', nonce: dashNonce }, function ( res ) {
                    var $feed = jQuery( '#st-activity-feed' );
                    if ( res && res.success && res.data && res.data.items && res.data.items.length ) {
                        var html = '';
                        jQuery.each( res.data.items.slice( 0, 20 ), function ( i, item ) {
                            var cls = item.status === 'success' ? 'st-feed-success' : ( item.status === 'error' ? 'st-feed-error' : 'st-feed-info' );
                            var node = document.createElement( 'div' );
                            node.textContent = '[' + ( item.platform || '' ) + '] ' + ( item.event_name || item.event_type || '' ) + ' — ' + ( item.timestamp || '' );
                            html += '<li class="st-feed-item ' + cls + '">' + node.innerHTML + '</li>';
                        } );
                        $feed.html( html );
                    } else {
                        $feed.html( '<li class="st-feed-item st-empty"><?php echo esc_js( __( 'No recent events.', 'servertrack' ) ); ?></li>' );
                    }
                } ).fail( function () {
                    jQuery( '#st-activity-feed' ).html( '<li class="st-feed-item st-feed-error"><?php echo esc_js( __( 'Failed to load feed.', 'servertrack' ) ); ?></li>' );
                } );
            }

            /* ── KPI live-patch after refresh ── */
            function refreshKPIs() {
                jQuery.post( ajaxUrl, { action: 'servertrack_get_dashboard_stats', nonce: dashNonce }, function ( res ) {
                    if ( ! res || ! res.success || ! res.data ) return;
                    var d = res.data;
                    function patch( id, val ) { var el = document.getElementById( id ); if ( el ) el.textContent = val; }
                    patch( 'st-kpi-total',   d.today_count   !== undefined ? d.today_count   : '' );
                    patch( 'st-kpi-success', d.week_success  !== undefined ? d.week_success  : '' );
                    patch( 'st-kpi-failed',  ( d.week_total !== undefined && d.week_success !== undefined ) ? ( d.week_total - d.week_success ) : '' );
                    patch( 'st-kpi-rate',    d.success_rate  !== undefined ? d.success_rate + '%' : '' );
                    patch( 'st-kpi-emq',     d.avg_emq       !== undefined ? d.avg_emq       : '' );
                    patch( 'st-kpi-retry',   d.retry_queue   !== undefined ? d.retry_queue   : '' );
                } );
            }

            /* ── Log refresh ── */
            jQuery( document ).on( 'click', '#st-refresh-log', function () {
                var $btn = jQuery( this ).addClass( 'st-spinning' ).prop( 'disabled', true );
                jQuery.post( ajaxUrl, { action: 'servertrack_get_logs', nonce: adminNonce }, function ( res ) {
                    $btn.removeClass( 'st-spinning' ).prop( 'disabled', false );
                    if ( res.success && res.data ) jQuery( '#st-log-tbody' ).html( res.data.html || '' );
                } ).fail( function () { $btn.removeClass( 'st-spinning' ).prop( 'disabled', false ); } );
            } );

            /* ── Dashboard clear log (― uses admin nonce, NOT dashboard nonce ―) ── */
            jQuery( document ).on( 'click', '#st-clear-log-btn', function () {
                if ( ! window.confirm( '<?php echo esc_js( __( 'Clear all log entries? This cannot be undone.', 'servertrack' ) ); ?>' ) ) return;
                var $btn = jQuery( this ).prop( 'disabled', true );
                jQuery.post( ajaxUrl, { action: 'servertrack_clear_log', nonce: adminNonce }, function ( res ) {
                    $btn.prop( 'disabled', false );
                    if ( res.success ) {
                        jQuery( '#st-log-tbody' ).html( '<tr><td colspan="7" class="st-empty"><?php echo esc_js( __( 'Log cleared.', 'servertrack' ) ); ?></td></tr>' );
                        jQuery( '#st-activity-feed' ).html( '<li class="st-feed-item st-empty"><?php echo esc_js( __( 'No recent events.', 'servertrack' ) ); ?></li>' );
                        refreshKPIs();
                    }
                } ).fail( function () { $btn.prop( 'disabled', false ); } );
            } );

            /* ── Drain retries ── */
            jQuery( document ).on( 'click', '#st-drain-retries', function () {
                if ( ! window.confirm( '<?php echo esc_js( __( 'Drain all retry items now?', 'servertrack' ) ); ?>' ) ) return;
                var $btn = jQuery( this ).prop( 'disabled', true );
                jQuery.post( ajaxUrl, { action: 'servertrack_drain_retries', nonce: dashNonce }, function ( res ) {
                    $btn.prop( 'disabled', false );
                    if ( res && res.success ) refreshKPIs();
                } ).fail( function () { $btn.prop( 'disabled', false ); } );
            } );

            /* ── Filter rows ── */
            jQuery( document ).on( 'click', '.st-filter-btn', function () {
                var $btn = jQuery( this );
                var filter = $btn.data( 'filter' );
                $btn.closest( '.st-log-filters' ).find( '.st-filter-btn' ).removeClass( 'is-active' );
                $btn.addClass( 'is-active' );
                jQuery( '#st-log-tbody tr[data-row]' ).each( function () {
                    var $r = jQuery( this );
                    $r.toggle( ! filter || filter === 'all' || $r.data( 'status' ) === filter );
                } );
            } );

            /* ── Response expand ── */
            jQuery( document ).on( 'click', '.st-response-toggle', function () {
                jQuery( this ).next( '.st-response-full' ).toggleClass( 'is-open' );
            } );

            /* ── Boot ── */
            jQuery( document ).ready( function () {
                loadFeed();
                refreshKPIs();
                // Auto-refresh every 60 s
                setInterval( function () { loadFeed(); refreshKPIs(); }, 60000 );
            } );
        }());
        </script>

        </div><!-- /#servertrack-wrap -->
        <?php
    }

    // ── AJAX handlers ─────────────────────────────────────────────────────

    public static function ajax_log_data(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $logs  = get_option( 'servertrack_debug_log', [] );
        $items = array_slice( array_reverse( $logs ), 0, 50 );

        // Return structured items array (not table-row HTML — inline JS builds <li> nodes)
        wp_send_json_success( [ 'items' => array_values( $items ) ] );
    }

    public static function ajax_platform_health(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        wp_send_json_success( self::get_platform_statuses( get_option( 'servertrack_debug_log', [] ) ) );
    }

    public static function ajax_stats_breakdown(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        wp_send_json_success( self::compute_breakdown( get_option( 'servertrack_debug_log', [] ) ) );
    }

    // DASH-2: Clear-log owned by ServerTrack_Admin. Dashboard button (#st-clear-log-btn)
    // calls action=servertrack_clear_log with the admin nonce (cfg.nonce), which is
    // handled by ServerTrack_Admin::ajax_clear_log(). No duplicate handler needed here.

    public static function ajax_drain_retries(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $queue = get_option( 'servertrack_retry_queue', [] );
        $sent  = 0;
        $failed = 0;

        foreach ( $queue as $item ) {
            $platform  = $item['platform'] ?? '';
            $event_obj = $item['event']    ?? null;
            if ( ! $event_obj || ! $platform ) { $failed++; continue; }

            $result = null;
            switch ( $platform ) {
                case 'meta':   if ( class_exists( 'ServerTrack_Meta' )   ) $result = ServerTrack_Meta::send( $event_obj );   break;
                case 'tiktok': if ( class_exists( 'ServerTrack_TikTok' ) ) $result = ServerTrack_TikTok::send( $event_obj ); break;
                case 'google': if ( class_exists( 'ServerTrack_Google' ) ) $result = ServerTrack_Google::send( $event_obj ); break;
            }
            if ( $result && isset( $result['code'] ) && (int) $result['code'] >= 200 && (int) $result['code'] < 300 ) {
                $sent++;
            } else {
                $failed++;
            }
        }
        update_option( 'servertrack_retry_queue', [] );
        wp_send_json_success( [ 'sent' => $sent, 'failed' => $failed ] );
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    private static function get_platform_statuses( array $logs ): array {
        $last = [];
        foreach ( array_reverse( $logs ) as $entry ) {
            $p = $entry['platform'] ?? '';
            if ( $p && ! isset( $last[ $p ] ) ) $last[ $p ] = $entry;
            if ( count( $last ) >= 3 ) break;
        }
        $out = [];
        foreach ( [ 'meta', 'google', 'tiktok' ] as $p ) {
            $e = $last[ $p ] ?? null;
            $out[ $p ] = [
                'last_ts'     => $e['timestamp'] ?? null,
                'last_status' => $e['status']    ?? null,
            ];
        }
        return $out;
    }

    private static function compute_breakdown( array $logs ): array {
        $week_ago = gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS );
        $by_platform = [];
        $by_event    = [];
        foreach ( $logs as $entry ) {
            $ts = substr( $entry['timestamp'] ?? '', 0, 10 );
            if ( $ts < $week_ago ) continue;
            $p = $entry['platform']   ?? 'unknown';
            $e = $entry['event_name'] ?? ( $entry['event_type'] ?? 'unknown' );
            $by_platform[ $p ] = ( $by_platform[ $p ] ?? 0 ) + 1;
            $by_event[ $e ]    = ( $by_event[ $e ]    ?? 0 ) + 1;
        }
        arsort( $by_platform ); arsort( $by_event );
        return [ 'by_platform' => $by_platform, 'by_event' => array_slice( $by_event, 0, 10, true ) ];
    }
}
