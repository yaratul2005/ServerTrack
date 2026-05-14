<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Dashboard  v4.2
 *
 * v4.2 — Removed last remaining emoji ('Done' in drain-retries JS callback).
 *         Added 'color' key to every KPI definition and applied
 *         st-kpi-icon-{color} CSS variant class to each KPI icon wrapper so
 *         the SVG badge background renders correctly.
 *
 * v4.1 — Replaced every emoji with a clean inline SVG icon.
 * v3.2 — Chart.js no longer loaded on the Settings page.
 * v3.1 — Removed duplicate CSS enqueue.
 * v2.9 — HTML class names realigned with admin.css selectors.
 * v2.8 — Settings/Sources submenu callbacks fixed.
 * v2.7 — KPI IDs, nonce, breakdown, auto-refresh, dead variable.
 */
class ServerTrack_Dashboard {

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
            '<svg class="%s" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
            esc_attr( $cls ),
            $inner
        );
    }

    public static function init(): void {
        add_action( 'admin_menu',            [ self::class, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ], 5 );

        add_action( 'wp_ajax_servertrack_log_data',        [ self::class, 'ajax_log_data' ] );
        add_action( 'wp_ajax_servertrack_platform_health', [ self::class, 'ajax_platform_health' ] );
        add_action( 'wp_ajax_servertrack_stats_breakdown', [ self::class, 'ajax_stats_breakdown' ] );
        add_action( 'wp_ajax_servertrack_clear_log',       [ self::class, 'ajax_clear_log' ] );
        add_action( 'wp_ajax_servertrack_drain_retries',   [ self::class, 'ajax_drain_retries' ] );
    }

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

        add_submenu_page( 'servertrack', __( 'Dashboard', 'servertrack' ),    __( 'Dashboard', 'servertrack' ),    'manage_options', 'servertrack',          [ self::class, 'render_page' ] );
        add_submenu_page( 'servertrack', __( 'Settings', 'servertrack' ),     __( 'Settings', 'servertrack' ),     'manage_options', 'servertrack-settings', [ 'ServerTrack_Admin', 'render_page' ] );
        add_submenu_page( 'servertrack', __( 'Event Sources', 'servertrack' ), __( 'Event Sources', 'servertrack' ), 'manage_options', 'servertrack-sources',  [ 'ServerTrack_Admin', 'render_page' ] );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( 'toplevel_page_servertrack' !== $hook ) {
            return;
        }
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js',
            [],
            '4.4.3',
            true
        );
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( $current_page !== '' && $current_page !== 'servertrack' ) {
            return;
        }

        $logs        = get_option( 'servertrack_debug_log', [] );
        $recent_logs = array_slice( array_reverse( $logs ), 0, 200 );
        $stats       = self::compute_stats( $logs );
        $emq_data    = ServerTrack_MatchQuality::get_daily_averages( 7 );
        $platforms   = self::get_platform_statuses( $logs );
        $breakdown   = self::compute_breakdown( $logs );
        $retry_items = get_option( 'servertrack_retry_queue', [] );
        $nonce       = wp_create_nonce( 'servertrack_dashboard' );

        ?>
        <div class="wrap" id="servertrack-wrap">

        <?php ServerTrack_Admin::render_page_header(); ?>

        <?php
        $kpis = [
            [ 'id' => 'st-kpi-total',  'label' => 'Events Today',  'val' => $stats['today_count'],        'sub' => 'All platforms',   'icon' => 'signal',       'color' => 'teal'   ],
            [ 'id' => 'st-kpi-rate',   'label' => 'Success Rate',  'val' => $stats['success_rate'] . '%', 'sub' => 'Last 7 days',     'icon' => 'check-circle', 'color' => 'green'  ],
            [ 'id' => 'st-kpi-emq',    'label' => 'Avg EMQ Score', 'val' => $stats['avg_emq'],            'sub' => '0-10 scale',      'icon' => 'target',       'color' => 'purple' ],
            [ 'id' => 'st-kpi-retry',  'label' => 'Retry Queue',   'val' => $stats['retry_queue'],        'sub' => 'Pending retries', 'icon' => 'refresh-cw',   'color' => 'orange' ],
            [ 'id' => 'st-kpi-week',   'label' => 'Week Total',    'val' => $stats['week_total'],         'sub' => 'Last 7 days',     'icon' => 'bar-chart-2',  'color' => 'blue'   ],
            [ 'id' => 'st-kpi-errors', 'label' => 'Week Errors',   'val' => $stats['week_errors'],        'sub' => 'Last 7 days',     'icon' => 'x-circle',     'color' => 'red'    ],
        ];
        ?>

        <div class="st-kpi-grid">
            <?php foreach ( $kpis as $kpi ) : ?>
            <div class="st-kpi-card" id="<?php echo esc_attr( $kpi['id'] ); ?>">
                <div class="st-kpi-icon st-kpi-icon-<?php echo esc_attr( $kpi['color'] ); ?>">
                    <?php echo self::svg( $kpi['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </div>
                <div class="st-kpi-value"><?php echo esc_html( $kpi['val'] ); ?></div>
                <div class="st-kpi-label"><?php echo esc_html( $kpi['label'] ); ?></div>
                <div class="st-kpi-sub"><?php echo esc_html( $kpi['sub'] ); ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php
        $view = SERVERTRACK_DIR . 'admin/views/dashboard.php';
        if ( file_exists( $view ) ) {
            include $view;
        }
        ?>

        </div><!-- #servertrack-wrap -->

        <script>
        ( function() {
            var ajaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
            var breakdown = <?php echo wp_json_encode( $breakdown ); ?>;
            var emqData   = <?php echo wp_json_encode( $emq_data ); ?>;

            function patch( id, val ) {
                var el = document.getElementById( id );
                if ( el ) el.querySelector( '.st-kpi-value' ).textContent = val;
            }

            /* Auto-refresh KPIs every 60s */
            function refreshStats() {
                var xhr = new XMLHttpRequest();
                xhr.open( 'POST', ajaxUrl );
                xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
                xhr.onload = function() {
                    try {
                        var res = JSON.parse( xhr.responseText );
                        if ( res.success && res.data ) {
                            var d = res.data;
                            patch( 'st-kpi-total',  d.today_count   !== undefined ? d.today_count   : '' );
                            patch( 'st-kpi-rate',   d.success_rate  !== undefined ? d.success_rate + '%' : '' );
                            patch( 'st-kpi-emq',    d.avg_emq       !== undefined ? d.avg_emq       : '' );
                            patch( 'st-kpi-retry',  d.retry_queue   !== undefined ? d.retry_queue   : '' );
                            patch( 'st-kpi-week',   d.week_total    !== undefined ? d.week_total    : '' );
                            patch( 'st-kpi-errors', d.week_errors   !== undefined ? d.week_errors   : '' );
                        }
                    } catch(e) {}
                };
                xhr.send( 'action=servertrack_log_data&nonce=' + encodeURIComponent(nonce) );
            }
            setInterval( refreshStats, 60000 );

            /* Breakdown Chart */
            var bCanvas = document.getElementById( 'st-breakdown-chart' );
            if ( bCanvas && typeof Chart !== 'undefined' && breakdown ) {
                var labels  = Object.keys( breakdown );
                var values  = labels.map( function(k) { return breakdown[k]; } );
                new Chart( bCanvas, {
                    type : 'doughnut',
                    data : {
                        labels   : labels,
                        datasets : [{ data: values, backgroundColor: ['#0ea5e0','#22c55e','#f97316','#a855f7','#ef4444','#eab308'] }]
                    },
                    options : { responsive: true, plugins: { legend: { position: 'bottom' } } }
                } );
            }

            /* EMQ Chart */
            var eCanvas = document.getElementById( 'st-emq-chart' );
            if ( eCanvas && typeof Chart !== 'undefined' && emqData ) {
                var eLabels = emqData.map( function(r) { return r.date; } );
                var eVals   = emqData.map( function(r) { return r.avg; } );
                new Chart( eCanvas, {
                    type : 'line',
                    data : {
                        labels   : eLabels,
                        datasets : [{ label: 'Avg EMQ', data: eVals, borderColor: '#0ea5e0', tension: 0.3, fill: false }]
                    },
                    options : { responsive: true, scales: { y: { min:0, max:10 } } }
                } );
            }

            /* Clear Log */
            var clearBtn = document.getElementById( 'st-clear-log' );
            if ( clearBtn ) {
                clearBtn.addEventListener( 'click', function() {
                    if ( ! confirm( 'Clear all log entries?' ) ) return;
                    var x = new XMLHttpRequest();
                    x.open( 'POST', ajaxUrl );
                    x.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
                    x.onload = function() { window.location.reload(); };
                    x.send( 'action=servertrack_clear_log&nonce=' + encodeURIComponent(nonce) );
                } );
            }

            /* Drain Retries */
            var drainBtn = document.getElementById( 'st-drain-retries' );
            if ( drainBtn ) {
                drainBtn.addEventListener( 'click', function() {
                    drainBtn.disabled = true;
                    drainBtn.textContent = 'Processing...';
                    var x = new XMLHttpRequest();
                    x.open( 'POST', ajaxUrl );
                    x.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
                    x.onload = function() {
                        drainBtn.textContent = 'Done';
                        setTimeout( function() { window.location.reload(); }, 1000 );
                    };
                    x.send( 'action=servertrack_drain_retries&nonce=' + encodeURIComponent(nonce) );
                } );
            }
        } )();
        </script>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* STATS HELPERS                                                        */
    /* ------------------------------------------------------------------ */

    public static function compute_stats( array $logs ): array {
        $today     = gmdate( 'Y-m-d' );
        $week_ago  = strtotime( '-7 days' );

        $today_count   = 0;
        $week_total    = 0;
        $week_success  = 0;
        $week_errors   = 0;
        $emq_sum       = 0;
        $emq_count     = 0;

        foreach ( $logs as $entry ) {
            $ts      = strtotime( $entry['time'] ?? '' );
            $status  = strtolower( $entry['status'] ?? '' );
            $is_ok   = ( $status === 'success' || $status === 'sent' );

            if ( gmdate( 'Y-m-d', $ts ) === $today ) {
                $today_count++;
            }
            if ( $ts >= $week_ago ) {
                $week_total++;
                if ( $is_ok ) $week_success++;
                else          $week_errors++;
            }
            if ( isset( $entry['emq'] ) && is_numeric( $entry['emq'] ) ) {
                $emq_sum   += (float) $entry['emq'];
                $emq_count++;
            }
        }

        $success_rate = $week_total > 0 ? round( ( $week_success / $week_total ) * 100, 1 ) : 0;
        $avg_emq      = $emq_count  > 0 ? round( $emq_sum / $emq_count, 1 ) : 0;
        $retry_queue  = count( get_option( 'servertrack_retry_queue', [] ) );

        return compact( 'today_count', 'week_total', 'week_success', 'week_errors', 'success_rate', 'avg_emq', 'retry_queue' );
    }

    public static function compute_breakdown( array $logs ): array {
        $map = [];
        foreach ( $logs as $entry ) {
            $platform = sanitize_key( $entry['platform'] ?? 'unknown' );
            $map[ $platform ] = ( $map[ $platform ] ?? 0 ) + 1;
        }
        return $map;
    }

    public static function get_platform_statuses( array $logs ): array {
        $platforms = [ 'meta' => 'unknown', 'google' => 'unknown', 'tiktok' => 'unknown' ];
        $recent    = array_slice( array_reverse( $logs ), 0, 50 );
        foreach ( $recent as $entry ) {
            $p = strtolower( $entry['platform'] ?? '' );
            if ( isset( $platforms[ $p ] ) && $platforms[ $p ] === 'unknown' ) {
                $s = strtolower( $entry['status'] ?? '' );
                $platforms[ $p ] = ( $s === 'success' || $s === 'sent' ) ? 'ok' : 'error';
            }
        }
        return $platforms;
    }

    /* ------------------------------------------------------------------ */
    /* AJAX HANDLERS                                                        */
    /* ------------------------------------------------------------------ */

    public static function ajax_log_data(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );

        $logs   = get_option( 'servertrack_debug_log', [] );
        $stats  = self::compute_stats( $logs );
        wp_send_json_success( $stats );
    }

    public static function ajax_platform_health(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );

        $logs      = get_option( 'servertrack_debug_log', [] );
        $platforms = self::get_platform_statuses( $logs );
        wp_send_json_success( [ 'platforms' => $platforms ] );
    }

    public static function ajax_stats_breakdown(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );

        $logs      = get_option( 'servertrack_debug_log', [] );
        $breakdown = self::compute_breakdown( $logs );
        wp_send_json_success( [ 'breakdown' => $breakdown ] );
    }

    public static function ajax_clear_log(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );

        update_option( 'servertrack_debug_log', [] );
        wp_send_json_success( [ 'message' => 'Log cleared.' ] );
    }

    public static function ajax_drain_retries(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );

        $queue = get_option( 'servertrack_retry_queue', [] );
        $count = count( $queue );
        // Attempt to fire each queued event
        foreach ( $queue as $item ) {
            do_action( 'servertrack_fire_retry', $item );
        }
        update_option( 'servertrack_retry_queue', [] );
        wp_send_json_success( [ 'drained' => $count ] );
    }
}
