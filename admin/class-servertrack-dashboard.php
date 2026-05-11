<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Dashboard  v1.0
 *
 * Feature #6 — Admin Dashboard.
 *
 * Renders the main ServerTrack admin page at:
 *   WP Admin → ServerTrack → Dashboard
 *
 * Sections:
 *   1. Platform Health — API connectivity status per enabled platform
 *   2. Event Match Quality — 7-day EMQ trend chart (Canvas)
 *   3. Live Event Log — last 100 log entries, filterable by platform/status
 *   4. Quick Stats — total events today, success rate, retry queue depth
 *
 * All data is read from existing plugin options/logs — no new DB tables.
 */
class ServerTrack_Dashboard {

    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'wp_ajax_servertrack_log_data', [ self::class, 'ajax_log_data' ] );
        add_action( 'wp_ajax_servertrack_platform_health', [ self::class, 'ajax_platform_health' ] );
    }

    // ────────────────────────────────────────────────────────────────────────
    // MENU
    // ────────────────────────────────────────────────────────────────────────

    public static function register_menu(): void {
        add_menu_page(
            __( 'ServerTrack', 'servertrack' ),
            __( 'ServerTrack', 'servertrack' ),
            'manage_options',
            'servertrack',
            [ self::class, 'render_page' ],
            'data:image/svg+xml;base64,' . base64_encode(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>'
            ),
            56
        );
        add_submenu_page( 'servertrack', __( 'Dashboard', 'servertrack' ), __( 'Dashboard', 'servertrack' ), 'manage_options', 'servertrack', [ self::class, 'render_page' ] );
        add_submenu_page( 'servertrack', __( 'Settings', 'servertrack' ), __( 'Settings', 'servertrack' ), 'manage_options', 'servertrack-settings', [ self::class, 'render_settings' ] );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'servertrack' ) === false ) return;
        wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js', [], '4.4.3', true );
    }

    // ────────────────────────────────────────────────────────────────────────
    // MAIN PAGE
    // ────────────────────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $logs        = get_option( 'servertrack_debug_log', [] );
        $recent_logs = array_slice( array_reverse( $logs ), 0, 100 );
        $stats       = self::compute_stats( $logs );
        $emq_data    = ServerTrack_MatchQuality::get_daily_averages( 7 );
        $platforms   = self::get_platform_statuses();

        ?>
        <div class="wrap" id="servertrack-dashboard">
        <style>
            #servertrack-dashboard{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;max-width:1400px}
            .st-header{display:flex;align-items:center;gap:12px;margin-bottom:24px}
            .st-header h1{margin:0;font-size:24px;font-weight:700;color:#1e1e2e}
            .st-version{background:#f0f0f5;border-radius:12px;padding:3px 10px;font-size:12px;color:#666}
            .st-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-bottom:24px}
            .st-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
            .st-card h3{margin:0 0 4px;font-size:13px;color:#6b7280;font-weight:500;text-transform:uppercase;letter-spacing:.05em}
            .st-card .st-val{font-size:32px;font-weight:700;color:#111827;line-height:1.2}
            .st-card .st-sub{font-size:12px;color:#9ca3af;margin-top:4px}
            .st-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px}
            @media(max-width:900px){.st-row{grid-template-columns:1fr}}
            .st-panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
            .st-panel h2{margin:0 0 16px;font-size:15px;font-weight:600;color:#111827}
            .st-platform-list{display:flex;flex-direction:column;gap:10px}
            .st-platform{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#f9fafb;border-radius:8px}
            .st-platform-name{font-weight:600;font-size:14px;color:#374151}
            .st-badge{padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600}
            .st-badge.enabled{background:#dcfce7;color:#16a34a}
            .st-badge.disabled{background:#f3f4f6;color:#9ca3af}
            .st-badge.ok{background:#dbeafe;color:#1d4ed8}
            .st-log-table{width:100%;border-collapse:collapse;font-size:13px}
            .st-log-table th{text-align:left;padding:8px 12px;background:#f9fafb;color:#6b7280;font-weight:500;border-bottom:1px solid #e5e7eb;position:sticky;top:0}
            .st-log-table td{padding:8px 12px;border-bottom:1px solid #f3f4f6;color:#374151;vertical-align:middle}
            .st-log-table tr:hover td{background:#fafafa}
            .st-status{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}
            .st-status.success{background:#22c55e}
            .st-status.error{background:#ef4444}
            .st-status.skipped{background:#f59e0b}
            .st-status.queued,.st-status.dedup_blocked{background:#6b7280}
            .st-log-wrap{max-height:480px;overflow-y:auto;border-radius:8px;border:1px solid #e5e7eb}
            .st-filter-bar{display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap}
            .st-filter-bar select,.st-filter-bar input{padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;color:#374151}
            .st-emq-grade{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
            .st-emq-grade.excellent{background:#dcfce7;color:#15803d}
            .st-emq-grade.good{background:#dbeafe;color:#1d4ed8}
            .st-emq-grade.fair{background:#fef9c3;color:#92400e}
            .st-emq-grade.poor{background:#fee2e2;color:#b91c1c}
        </style>

        <div class="st-header">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            <h1>ServerTrack</h1>
            <span class="st-version">v5.0</span>
        </div>

        <!-- QUICK STATS -->
        <div class="st-grid">
            <div class="st-card">
                <h3>Events Today</h3>
                <div class="st-val"><?php echo esc_html( $stats['today_count'] ); ?></div>
                <div class="st-sub">All platforms</div>
            </div>
            <div class="st-card">
                <h3>Success Rate</h3>
                <div class="st-val"><?php echo esc_html( $stats['success_rate'] ); ?>%</div>
                <div class="st-sub">Last 7 days</div>
            </div>
            <div class="st-card">
                <h3>Avg EMQ Score</h3>
                <div class="st-val"><?php echo esc_html( $stats['avg_emq'] ); ?></div>
                <div class="st-sub">0–10 scale</div>
            </div>
            <div class="st-card">
                <h3>Retry Queue</h3>
                <div class="st-val"><?php echo esc_html( $stats['retry_queue'] ); ?></div>
                <div class="st-sub">Pending retries</div>
            </div>
        </div>

        <div class="st-row">
            <!-- PLATFORM HEALTH -->
            <div class="st-panel">
                <h2>🛰 Platform Health</h2>
                <div class="st-platform-list">
                    <?php foreach ( $platforms as $p ) : ?>
                    <div class="st-platform">
                        <span class="st-platform-name"><?php echo esc_html( $p['name'] ); ?></span>
                        <span class="st-badge <?php echo esc_attr( $p['enabled'] ? 'enabled' : 'disabled' ); ?>">
                            <?php echo $p['enabled'] ? esc_html( $p['status'] ) : 'Disabled'; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- EMQ CHART -->
            <div class="st-panel">
                <h2>📊 Event Match Quality (7 days)</h2>
                <canvas id="st-emq-chart" height="120"></canvas>
            </div>
        </div>

        <!-- LIVE EVENT LOG -->
        <div class="st-panel">
            <h2>📋 Live Event Log <small style="font-weight:400;color:#9ca3af;font-size:13px">(last 100 events)</small></h2>
            <div class="st-filter-bar">
                <select id="st-filter-platform" onchange="stFilterLog()">
                    <option value="">All Platforms</option>
                    <option value="meta">Meta</option>
                    <option value="tiktok">TikTok</option>
                    <option value="google">Google</option>
                    <option value="all">All</option>
                </select>
                <select id="st-filter-status" onchange="stFilterLog()">
                    <option value="">All Statuses</option>
                    <option value="success">Success</option>
                    <option value="error">Error</option>
                    <option value="skipped">Skipped</option>
                    <option value="dedup_blocked">Dedup Blocked</option>
                    <option value="queued">Queued</option>
                </select>
                <input type="text" id="st-filter-search" placeholder="Search event type..." oninput="stFilterLog()" style="min-width:180px">
            </div>
            <div class="st-log-wrap">
                <table class="st-log-table" id="st-log-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Status</th>
                            <th>Platform</th>
                            <th>Event</th>
                            <th>Order</th>
                            <th>EMQ</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody id="st-log-tbody">
                    <?php foreach ( $recent_logs as $entry ) :
                        $status   = esc_attr( $entry['status']     ?? '' );
                        $platform = esc_html( $entry['platform']   ?? '' );
                        $event    = esc_html( $entry['event_type'] ?? '' );
                        $order_id = esc_html( $entry['order_id']   ?? '' );
                        $msg      = esc_html( $entry['message']    ?? '' );
                        $time     = esc_html( $entry['timestamp']  ?? '' );
                        $emq      = $entry['emq_score'] ?? null;
                        $grade    = $entry['emq_grade'] ?? '';
                    ?>
                    <tr data-platform="<?php echo esc_attr( $entry['platform'] ?? '' ); ?>" data-status="<?php echo $status; ?>" data-event="<?php echo esc_attr( $entry['event_type'] ?? '' ); ?>">
                        <td style="white-space:nowrap;color:#9ca3af"><?php echo $time; ?></td>
                        <td><span class="st-status <?php echo $status; ?>"></span><?php echo $status; ?></td>
                        <td><strong><?php echo $platform; ?></strong></td>
                        <td><?php echo $event; ?></td>
                        <td><?php echo $order_id ? '#' . $order_id : '—'; ?></td>
                        <td><?php if ( null !== $emq ) : ?>
                            <span class="st-emq-grade <?php echo esc_attr( $grade ); ?>"><?php echo esc_html( $emq ); ?></span>
                        <?php else : ?>—<?php endif; ?></td>
                        <td style="color:#6b7280"><?php echo $msg; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        </div><!-- .wrap -->

        <script>
        // EMQ Chart
        (function(){
            var labels = <?php echo wp_json_encode( array_keys( $emq_data ) ); ?>;
            var scores = <?php echo wp_json_encode( array_values( array_map( fn($d) => $d['avg'], $emq_data ) ) ); ?>;
            var ctx = document.getElementById('st-emq-chart');
            if(!ctx||!labels.length){ctx&&(ctx.parentElement.innerHTML+='<p style="color:#9ca3af;font-size:13px;margin-top:12px">No EMQ data yet. Events will appear here once sent.</p>');return;}
            new Chart(ctx,{
                type:'line',
                data:{
                    labels:labels,
                    datasets:[{
                        label:'Avg EMQ Score',
                        data:scores,
                        borderColor:'#6366f1',
                        backgroundColor:'rgba(99,102,241,0.08)',
                        tension:0.4,
                        fill:true,
                        pointRadius:4,
                        pointBackgroundColor:'#6366f1'
                    }]
                },
                options:{
                    responsive:true,
                    plugins:{legend:{display:false}},
                    scales:{
                        y:{min:0,max:10,ticks:{stepSize:2},grid:{color:'#f3f4f6'}},
                        x:{grid:{display:false}}
                    }
                }
            });
        })();

        // Log filter
        function stFilterLog(){
            var platform = document.getElementById('st-filter-platform').value.toLowerCase();
            var status   = document.getElementById('st-filter-status').value.toLowerCase();
            var search   = document.getElementById('st-filter-search').value.toLowerCase();
            document.querySelectorAll('#st-log-tbody tr').forEach(function(row){
                var rp = (row.dataset.platform||'').toLowerCase();
                var rs = (row.dataset.status||'').toLowerCase();
                var re = (row.dataset.event||'').toLowerCase();
                var show = true;
                if(platform && rp !== platform) show = false;
                if(status   && rs !== status)   show = false;
                if(search   && re.indexOf(search) < 0) show = false;
                row.style.display = show ? '' : 'none';
            });
        }
        </script>
        <?php
    }

    // ────────────────────────────────────────────────────────────────────────
    // SETTINGS PAGE (stub — existing settings flow)
    // ────────────────────────────────────────────────────────────────────────

    public static function render_settings(): void {
        // Delegate to existing settings renderer if available
        if ( class_exists( 'ServerTrack_Settings' ) && method_exists( 'ServerTrack_Settings', 'render_page' ) ) {
            ServerTrack_Settings::render_page();
        } else {
            echo '<div class="wrap"><h1>ServerTrack Settings</h1><p>Settings class not loaded.</p></div>';
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // AJAX HANDLERS
    // ────────────────────────────────────────────────────────────────────────

    public static function ajax_log_data(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $logs = get_option( 'servertrack_debug_log', [] );
        wp_send_json_success( array_slice( array_reverse( $logs ), 0, 100 ) );
    }

    public static function ajax_platform_health(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        wp_send_json_success( self::get_platform_statuses() );
    }

    // ────────────────────────────────────────────────────────────────────────
    // DATA HELPERS
    // ────────────────────────────────────────────────────────────────────────

    private static function compute_stats( array $logs ): array {
        $today        = gmdate( 'Y-m-d' );
        $seven_ago    = strtotime( '-7 days' );
        $today_count  = 0;
        $week_total   = 0;
        $week_success = 0;
        $emq_sum      = 0;
        $emq_count    = 0;

        foreach ( $logs as $entry ) {
            $ts   = strtotime( $entry['timestamp'] ?? '' );
            $date = substr( $entry['timestamp'] ?? '', 0, 10 );
            if ( $date === $today ) $today_count++;
            if ( $ts >= $seven_ago ) {
                $week_total++;
                if ( ( $entry['status'] ?? '' ) === 'success' ) $week_success++;
                if ( isset( $entry['emq_score'] ) ) {
                    $emq_sum  += (float) $entry['emq_score'];
                    $emq_count++;
                }
            }
        }

        $retry_queue = count( get_option( 'servertrack_retry_queue', [] ) );

        return [
            'today_count'  => $today_count,
            'success_rate' => $week_total > 0 ? round( ( $week_success / $week_total ) * 100 ) : 0,
            'avg_emq'      => $emq_count > 0 ? round( $emq_sum / $emq_count, 1 ) : '—',
            'retry_queue'  => $retry_queue,
        ];
    }

    private static function get_platform_statuses(): array {
        return [
            [
                'name'    => 'Meta (Facebook)',
                'enabled' => (bool) get_option( 'servertrack_meta_enabled', 0 ),
                'status'  => get_option( 'servertrack_meta_pixel_id' ) ? 'Configured' : 'Missing Pixel ID',
            ],
            [
                'name'    => 'TikTok',
                'enabled' => (bool) get_option( 'servertrack_tiktok_enabled', 0 ),
                'status'  => get_option( 'servertrack_tiktok_pixel_id' ) ? 'Configured' : 'Missing Pixel ID',
            ],
            [
                'name'    => 'Google (GA4)',
                'enabled' => (bool) get_option( 'servertrack_google_enabled', 0 ),
                'status'  => get_option( 'servertrack_google_measurement_id' ) ? 'Configured' : 'Missing Measurement ID',
            ],
        ];
    }
}
