<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Dashboard  v4.5
 *
 * v4.5 — KPI auto-update wired to servertrack_get_dashboard_stats:
 *         The doRefresh() call already updates log rows via
 *         servertrack_log_data. Added a second AJAX call to
 *         servertrack_get_dashboard_stats (using dashboard_nonce from
 *         wp_localize_script) that patches the six KPI <div> values so
 *         numbers update live without a page reload.
 *
 *         Also fixed: ajaxUrl was built inline from the PHP-injected
 *         variable but the servertrack_admin object (which carries
 *         dashboard_nonce for Stats) is only available when admin.js is
 *         localised on this hook. Resolved by passing both nonces via
 *         wp_localize_script in ServerTrack_Admin::enqueue_assets() and
 *         reading them in the inline <script> via window.servertrack_admin.
 *
 * v4.4 — BUG-A…F frontend fixes (see previous changelog).
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
            '<svg class="%s" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
            esc_attr( $cls ),
            $inner
        );
    }

    public static function init(): void {
        add_action( 'admin_menu',            [ self::class, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ], 5 );
        add_filter( 'script_loader_tag',     [ self::class, 'add_chartjs_integrity' ], 10, 2 );

        add_action( 'wp_ajax_servertrack_log_data',        [ self::class, 'ajax_log_data' ] );
        add_action( 'wp_ajax_servertrack_platform_health', [ self::class, 'ajax_platform_health' ] );
        add_action( 'wp_ajax_servertrack_stats_breakdown', [ self::class, 'ajax_stats_breakdown' ] );
        add_action( 'wp_ajax_servertrack_clear_log',       [ self::class, 'ajax_clear_log' ] );
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

    // ── Menu ─────────────────────────────────────────────────────────────────

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
        add_submenu_page( 'servertrack', __( 'Event Sources', 'servertrack' ), __( 'Event Sources', 'servertrack' ), 'manage_options', 'servertrack-sources', [ 'ServerTrack_Admin', 'render_page' ] );
    }

    public static function enqueue_assets( string $hook ): void {
        $allowed_hooks = [
            'toplevel_page_servertrack',
            'servertrack_page_servertrack-sources',
        ];
        if ( ! in_array( $hook, $allowed_hooks, true ) ) return;
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js',
            [],
            '4.4.3',
            true
        );
    }

    // ── Main Dashboard Page ───────────────────────────────────────────────────

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
        $kpis = [
            [ 'id' => 'st-kpi-total',  'label' => 'Events Today',  'val' => $stats['today_count'],        'sub' => 'All platforms',   'icon' => 'signal',       'color' => 'teal'   ],
            [ 'id' => 'st-kpi-rate',   'label' => 'Success Rate',  'val' => $stats['success_rate'] . '%', 'sub' => 'Last 7 days',     'icon' => 'check-circle', 'color' => 'green'  ],
            [ 'id' => 'st-kpi-emq',    'label' => 'Avg EMQ Score', 'val' => $stats['avg_emq'],            'sub' => '0–10 scale',      'icon' => 'target',       'color' => 'purple' ],
            [ 'id' => 'st-kpi-retry',  'label' => 'Retry Queue',   'val' => $stats[