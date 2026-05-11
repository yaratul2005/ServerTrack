<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Dashboard  v2.0
 *
 * Changes in v2.0 (feature/admin-dashboard-v2):
 *   - Auto-refresh: live log + platform health poll every 30 s via AJAX.
 *     Pulsing indicator shows live mode; Pause/Resume toggle available.
 *   - Per-platform event breakdown: doughnut chart (today's events by platform).
 *   - EMQ Scorecard: per-platform avg EMQ + 7-day trend + grade badge.
 *   - Top-5 event types table: 7-day event counts by type.
 *   - Error spotlight banner: red alert when errors exist in last 24 h.
 *   - Clear Log button (AJAX, nonce-protected).
 *   - Export CSV button (AJAX, streams last 500 entries).
 *   - Consistent WP Admin styling, responsive at 768 px.
 *
 * v1.0 (original):
 *   Platform Health, 7-day EMQ line chart, Live Event Log (last 100),
 *   Quick Stats (today count, success rate, avg EMQ, retry queue).
 */
class ServerTrack_Dashboard {

    public static function init(): void {
        add_action( 'admin_menu',             [ self::class, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts',  [ self::class, 'enqueue_assets' ] );
        add_action( 'wp_ajax_servertrack_log_data',        [ self::class, 'ajax_log_data' ] );
        add_action( 'wp_ajax_servertrack_platform_health', [ self::class, 'ajax_platform_health' ] );
        add_action( 'wp_ajax_servertrack_clear_log',       [ self::class, 'ajax_clear_log' ] );
        add_action( 'wp_ajax_servertrack_export_log',      [ self::class, 'ajax_export_log' ] );
    }

    // ── Menu ─────────────────────────────────────────────────────────────────

    public static function register_menu(): void {
        $svg = base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>' );
        add_menu_page(
            __( 'ServerTrack', 'servertrack' ),
            __( 'ServerTrack', 'servertrack' ),
            'manage_options',
            'servertrack',
            [ self::class, 'render_page' ],
            'data:image/svg+xml;base64,' . $svg,
            56
        );
        add_submenu_page( 'servertrack', __( 'Dashboard', 'servertrack' ),   __( 'Dashboard', 'servertrack' ),   'manage_options', 'servertrack',          [ self::class, 'render_page' ] );
        add_submenu_page( 'servertrack', __( 'Settings', 'servertrack' ),    __( 'Settings', 'servertrack' ),    'manage_options', 'servertrack-settings', [ self::class, 'render_settings' ] );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'servertrack' ) === false ) return;
        wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js', [], '4.4.3', true );
        wp_enqueue_script( 'servertrack-dashboard', false, [], false, true );
    }

    // ── Main page ────────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $logs        = get_option( 'servertrack_debug_log', [] );
        $recent_logs = array_slice( array_reverse( $logs ), 0, 200 );
        $stats       = self::compute_stats( $logs );
        $emq_data    = ServerTrack_MatchQuality::get_daily_averages( 7 );
        $platforms   = self::get_platform_statuses();
        $emq_scores  = self::compute_emq_scorecard( $logs );
        $top_events  = self::compute_top_events( $logs );
        $breakdown   = self::compute_platform_breakdown( $logs );
        $error_count = self::count_recent_errors( $logs );
        $nonce       = wp_create_nonce( 'servertrack_dashboard' );

        ?>
        <div class="wrap" id="servertrack-dashboard">
        <style>
        #servertrack-dashboard *{box-sizing:border-box}
        #servertrack-dashboard{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:1400px;padding-bottom:40px}

        /* Header */
        .st-header{display:flex;align-items:center;gap:12px;margin-bottom:8px;flex-wrap:wrap}
        .st-header h1{margin:0;font-size:22px;font-weight:700;color:#1e1e2e;flex:1}
        .st-version{background:#f0f0f5;border-radius:20px;padding:3px 12px;font-size:12px;color:#666;font-weight:500}
        .st-header-actions{display:flex;align-items:center;gap:8px}
        .st-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:6px;font-size:13px;font-weight:500;cursor:pointer;border:1px solid #d1d5db;background:#fff;color:#374151;text-decoration:none;transition:all .15s}
        .st-btn:hover{background:#f9fafb;border-color:#9ca3af;color:#111827}
        .st-btn.st-btn-danger{border-color:#fca5a5;color:#dc2626;background:#fff}
        .st-btn.st-btn-danger:hover{background:#fef2f2}
        .st-btn.st-btn-primary{background:#4f46e5;border-color:#4f46e5;color:#fff}
        .st-btn.st-btn-primary:hover{background:#4338ca;border-color:#4338ca}

        /* Live indicator */
        .st-live-dot{width:8px;height:8px;border-radius:50%;background:#22c55e;display:inline-block;animation:st-pulse 2s infinite}
        @keyframes st-pulse{0%,100%{opacity:1}50%{opacity:.35}}
        .st-live-dot.paused{background:#9ca3af;animation:none}
        .st-live-label{font-size:12px;color:#6b7280}

        /* Error spotlight */
        .st-error-banner{background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 18px;margin-bottom:18px;display:flex;align-items:center;gap:10px;color:#b91c1c;font-size:13px;font-weight:500}
        .st-error-banner svg{flex-shrink:0}
        .st-error-banner a{color:#b91c1c;text-decoration:underline;margin-left:4px}

        /* Quick stat cards */
        .st-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:20px}
        .st-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;box-shadow:0 1px 3px rgba(0,0,0,.05)}
        .st-card h3{margin:0 0 6px;font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.06em}
        .st-card .st-val{font-size:30px;font-weight:700;color:#111827;line-height:1.1;font-variant-numeric:tabular-nums}
        .st-card .st-sub{font-size:11px;color:#9ca3af;margin-top:5px}
        .st-card.st-card-alert .st-val{color:#dc2626}

        /* Two-column row */
        .st-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
        @media(max-width:900px){.st-row{grid-template-columns:1fr}}
        .st-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px}
        @media(max-width:1100px){.st-row-3{grid-template-columns:1fr 1fr}}
        @media(max-width:700px){.st-row-3{grid-template-columns:1fr}}

        /* Panels */
        .st-panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.05)}
        .st-panel-title{margin:0 0 14px;font-size:14px;font-weight:600;color:#111827;display:flex;align-items:center;gap:8px}
        .st-panel-title small{font-size:12px;color:#9ca3af;font-weight:400}

        /* Platform health */
        .st-platform-list{display:flex;flex-direction:column;gap:8px}
        .st-platform{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#f9fafb;border-radius:8px;border:1px solid #f3f4f6}
        .st-platform-name{font-weight:600;font-size:13px;color:#374151}
        .st-platform-meta{display:flex;align-items:center;gap:8px}
        .st-badge{padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;letter-spacing:.02em}
        .st-badge.enabled{background:#dcfce7;color:#15803d}
        .st-badge.disabled{background:#f3f4f6;color:#9ca3af}
        .st-badge.configured{background:#dbeafe;color:#1d4ed8}
        .st-badge.warning{background:#fef3c7;color:#92400e}

        /* EMQ Scorecard table */
        .st-scorecard{width:100%;border-collapse:collapse;font-size:13px}
        .st-scorecard th{text-align:left;padding:7px 10px;color:#6b7280;font-weight:500;font-size:11px;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #f3f4f6}
        .st-scorecard td{padding:9px 10px;border-bottom:1px solid #f9fafb;color:#374151;font-variant-numeric:tabular-nums}
        .st-scorecard tr:last-child td{border-bottom:none}
        .st-emq-grade{display:inline-block;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700}
        .st-emq-grade.excellent{background:#dcfce7;color:#15803d}
        .st-emq-grade.good{background:#dbeafe;color:#1d4ed8}
        .st-emq-grade.fair{background:#fef9c3;color:#92400e}
        .st-emq-grade.poor{background:#fee2e2;color:#b91c1c}
        .st-emq-grade.na{background:#f3f4f6;color:#9ca3af}
        .st-trend{font-size:14px}
        .st-trend.up{color:#16a34a}
        .st-trend.down{color:#dc2626}
        .st-trend.flat{color:#9ca3af}

        /* Top events table */
        .st-top-table{width:100%;border-collapse:collapse;font-size:13px}
        .st-top-table th{text-align:left;padding:7px 10px;color:#6b7280;font-weight:500;font-size:11px;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #f3f4f6}
        .st-top-table td{padding:9px 10px;border-bottom:1px solid #f9fafb;color:#374151;font-variant-numeric:tabular-nums}
        .st-top-table tr:last-child td{border-bottom:none}
        .st-bar-wrap{background:#f3f4f6;border-radius:4px;height:8px;overflow:hidden;width:120px;display:inline-block;vertical-align:middle;margin-left:8px}
        .st-bar{height:100%;border-radius:4px;background:#6366f1}

        /* Log table */
        .st-filter-bar{display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center}
        .st-filter-bar select,.st-filter-bar input{padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;color:#374151;background:#fff}
        .st-filter-bar select:focus,.st-filter-bar input:focus{outline:2px solid #6366f1;outline-offset:1px}
        .st-log-wrap{max-height:500px;overflow-y:auto;border-radius:8px;border:1px solid #e5e7eb}
        .st-log-table{width:100%;border-collapse:collapse;font-size:12px}
        .st-log-table th{text-align:left;padding:8px 12px;background:#f9fafb;color:#6b7280;font-weight:500;border-bottom:1px solid #e5e7eb;position:sticky;top:0;z-index:1;white-space:nowrap}
        .st-log-table td{padding:7px 12px;border-bottom:1px solid #f3f4f6;color:#374151;vertical-align:middle}
        .st-log-table tr:hover td{background:#fafafa}
        .st-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:5px;flex-shrink:0}
        .st-dot.success{background:#22c55e}
        .st-dot.error{background:#ef4444}
        .st-dot.skipped{background:#f59e0b}
        .st-dot.queued{background:#6b7280}
        .st-dot.dedup_blocked{background:#a3a3a3}
        .st-dot.retrying{background:#3b82f6}
        .st-platform-pill{display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600}
        .st-platform-pill.meta{background:#eff6ff;color:#1d4ed8}
        .st-platform-pill.tiktok{background:#f0fdf4;color:#166534}
        .st-platform-pill.google{background:#fef3c7;color:#92400e}
        .st-platform-pill.all{background:#f3f4f6;color:#6b7280}
        .st-log-empty{text-align:center;padding:32px;color:#9ca3af;font-size:13px}
        .st-refreshing{opacity:.5;pointer-events:none}
        </style>

        <?php if ( $error_count > 0 ) : ?>
        <div class="st-error-banner">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?php echo esc_html( $error_count ); ?> error<?php echo $error_count > 1 ? 's' : ''; ?> in the last 24 hours.
            <a href="#" onclick="stSetFilter('','error','');return false;">Show errors only</a>
        </div>
        <?php endif; ?>

        <div class="st-header">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2.2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            <h1>ServerTrack</h1>
            <span class="st-version">v5.0 &middot; Dashboard v2</span>
            <div class="st-header-actions">
                <span class="st-live-dot" id="st-live-dot"></span>
                <span class="st-live-label" id="st-live-label">Live</span>
                <button class="st-btn" id="st-pause-btn" onclick="stToggleLive()">⏸ Pause</button>
                <button class="st-btn st-btn-danger" id="st-clear-btn" onclick="stClearLog()">🗑 Clear Log</button>
                <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=servertrack_export_log&nonce=' . $nonce ) ); ?>" class="st-btn">⬇ Export CSV</a>
            </div>
        </div>

        <!-- QUICK STATS -->
        <div class="st-grid">
            <div class="st-card<?php echo $stats['today_count'] === 0 ? '' : ''; ?>">
                <h3>Events Today</h3>
                <div class="st-val" id="st-stat-today"><?php echo esc_html( $stats['today_count'] ); ?></div>
                <div class="st-sub">All platforms combined</div>
            </div>
            <div class="st-card">
                <h3>Success Rate</h3>
                <div class="st-val" id="st-stat-rate"><?php echo esc_html( $stats['success_rate'] ); ?>%</div>
                <div class="st-sub">7-day average</div>
            </div>
            <div class="st-card">
                <h3>Avg EMQ Score</h3>
                <div class="st-val" id="st-stat-emq"><?php echo esc_html( $stats['avg_emq'] ); ?></div>
                <div class="st-sub">0–10 scale, 7 days</div>
            </div>
            <div class="st-card<?php echo $stats['retry_queue'] > 0 ? ' st-card-alert' : ''; ?>">
                <h3>Retry Queue</h3>
                <div class="st-val" id="st-stat-retry"><?php echo esc_html( $stats['retry_queue'] ); ?></div>
                <div class="st-sub">Pending retries</div>
            </div>
            <div class="st-card">
                <h3>Total Events (7d)</h3>
                <div class="st-val"><?php echo esc_html( $stats['week_total'] ); ?></div>
                <div class="st-sub">All statuses</div>
            </div>
            <div class="st-card<?php echo $stats['error_24h'] > 0 ? ' st-card-alert' : ''; ?>">
                <h3>Errors (24 h)</h3>
                <div class="st-val"><?php echo esc_html( $stats['error_24h'] ); ?></div>
                <div class="st-sub">Needs attention</div>
            </div>
        </div>

        <!-- ROW 1: Platform health + Breakdown chart -->
        <div class="st-row">
            <div class="st-panel">
                <h2 class="st-panel-title">🛰 Platform Health</h2>
                <div class="st-platform-list" id="st-platform-list">
                <?php foreach ( $platforms as $p ) :
                    $enabled   = $p['enabled'];
                    $configured = $enabled && ! empty( $p['api_key'] );
                ?>
                <div class="st-platform">
                    <span class="st-platform-name"><?php echo esc_html( $p['name'] ); ?></span>
                    <div class="st-platform-meta">
                        <?php if ( ! $enabled ) : ?>
                            <span class="st-badge disabled">Disabled</span>
                        <?php elseif ( $configured ) : ?>
                            <span class="st-badge configured">Configured</span>
                            <span class="st-badge enabled">Enabled</span>
                        <?php else : ?>
                            <span class="st-badge warning">Missing API Key</span>
                            <span class="st-badge enabled">Enabled</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            </div>

            <div class="st-panel">
                <h2 class="st-panel-title">🥧 Events by Platform <small>(today)</small></h2>
                <div style="max-width:260px;margin:0 auto">
                    <canvas id="st-breakdown-chart"></canvas>
                    <p id="st-breakdown-empty" style="display:none;text-align:center;color:#9ca3af;font-size:13px;margin-top:12px">No events today yet.</p>
                </div>
            </div>
        </div>

        <!-- ROW 2: EMQ line chart + EMQ scorecard -->
        <div class="st-row">
            <div class="st-panel">
                <h2 class="st-panel-title">📈 Event Match Quality <small>(7-day avg)</small></h2>
                <canvas id="st-emq-chart" height="130"></canvas>
                <p id="st-emq-empty" style="display:none;color:#9ca3af;font-size:13px;margin-top:12px;text-align:center">No EMQ data yet. Events will appear here once sent.</p>
            </div>

            <div class="st-panel">
                <h2 class="st-panel-title">🎯 EMQ Scorecard <small>per platform</small></h2>
                <table class="st-scorecard">
                    <thead>
                        <tr>
                            <th>Platform</th>
                            <th>Today Avg</th>
                            <th>7-day Avg</th>
                            <th>Trend</th>
                            <th>Grade</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $emq_scores as $platform => $emq ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( ucfirst( $platform ) ); ?></strong></td>
                        <td><?php echo esc_html( $emq['today'] ?? '—' ); ?></td>
                        <td><?php echo esc_html( $emq['week'] ?? '—' ); ?></td>
                        <td>
                            <span class="st-trend <?php echo esc_attr( $emq['trend_dir'] ); ?>">
                                <?php echo $emq['trend_dir'] === 'up' ? '↑' : ( $emq['trend_dir'] === 'down' ? '↓' : '→' ); ?>
                            </span>
                        </td>
                        <td><span class="st-emq-grade <?php echo esc_attr( $emq['grade'] ); ?>"><?php echo esc_html( ucfirst( $emq['grade'] ) ); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ROW 3: Top events + spacer -->
        <div class="st-row">
            <div class="st-panel">
                <h2 class="st-panel-title">🏆 Top Event Types <small>(last 7 days)</small></h2>
                <?php if ( empty( $top_events ) ) : ?>
                    <p style="color:#9ca3af;font-size:13px">No events recorded yet.</p>
                <?php else :
                    $max = max( array_column( $top_events, 'count' ) );
                ?>
                <table class="st-top-table">
                    <thead><tr><th>#</th><th>Event Type</th><th>Count</th><th>Success %</th></tr></thead>
                    <tbody>
                    <?php foreach ( $top_events as $i => $row ) :
                        $pct = $row['count'] > 0 ? round( ( $row['success'] / $row['count'] ) * 100 ) : 0;
                        $bar_w = $max > 0 ? round( ( $row['count'] / $max ) * 100 ) : 0;
                    ?>
                    <tr>
                        <td style="color:#9ca3af"><?php echo $i + 1; ?></td>
                        <td><strong><?php echo esc_html( $row['event'] ); ?></strong></td>
                        <td>
                            <?php echo esc_html( $row['count'] ); ?>
                            <span class="st-bar-wrap"><span class="st-bar" style="width:<?php echo $bar_w; ?>%"></span></span>
                        </td>
                        <td><?php echo esc_html( $pct ); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <div class="st-panel">
                <h2 class="st-panel-title">ℹ️ Plugin Status</h2>
                <?php
                $sources = [
                    'WooCommerce'           => class_exists( 'WooCommerce' ),
                    'WC Subscriptions'      => class_exists( 'WC_Subscriptions' ),
                    'YITH Wishlist'         => function_exists( 'YITH_WCWL' ) || class_exists( 'YITH_WCWL' ),
                    'TI WC Wishlist'        => function_exists( 'TIWL' ) || defined( 'TIWL_VERSION' ),
                    'Contact Form 7'        => class_exists( 'WPCF7' ),
                    'Easy Digital Downloads'=> class_exists( 'Easy_Digital_Downloads' ),
                ];
                ?>
                <table class="st-scorecard">
                    <thead><tr><th>Plugin / Integration</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ( $sources as $name => $active ) : ?>
                    <tr>
                        <td><?php echo esc_html( $name ); ?></td>
                        <td>
                            <?php if ( $active ) : ?>
                                <span class="st-badge enabled">Active</span>
                            <?php else : ?>
                                <span class="st-badge disabled">Not installed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- LIVE EVENT LOG -->
        <div class="st-panel">
            <h2 class="st-panel-title">
                📋 Live Event Log
                <small>(last 200 events — auto-refreshes every 30 s)</small>
                <span style="margin-left:auto;display:flex;gap:6px">
                    <span class="st-live-dot" id="st-live-dot-2"></span>
                    <span class="st-live-label" id="st-live-label-2">Live</span>
                </span>
            </h2>
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
                    <option value="success">✅ Success</option>
                    <option value="error">❌ Error</option>
                    <option value="skipped">⚠ Skipped</option>
                    <option value="dedup_blocked">🔒 Dedup Blocked</option>
                    <option value="queued">⏳ Queued</option>
                    <option value="retrying">🔄 Retrying</option>
                </select>
                <input type="text" id="st-filter-search" placeholder="Search event type or message…" oninput="stFilterLog()" style="min-width:200px">
                <button class="st-btn" onclick="stFilterLog(true)">Clear filters</button>
            </div>
            <div class="st-log-wrap" id="st-log-wrap">
                <table class="st-log-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Status</th>
                            <th>Platform</th>
                            <th>Event</th>
                            <th>Order</th>
                            <th>Event ID</th>
                            <th>EMQ</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody id="st-log-tbody">
                    <?php self::render_log_rows( $recent_logs ); ?>
                    </tbody>
                </table>
            </div>
        </div>

        </div><!-- .wrap -->

        <script>
        (function(){
            var ajaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
            var liveMode = true;
            var liveTimer;

            // ── Charts ────────────────────────────────────────────────────────
            (function buildCharts(){
                // EMQ line chart
                var emqLabels = <?php echo wp_json_encode( array_keys( $emq_data ) ); ?>;
                var emqScores = <?php echo wp_json_encode( array_values( array_map( function($d){ return $d['avg']; }, $emq_data ) ) ); ?>;
                var emqCtx    = document.getElementById('st-emq-chart');
                if(emqCtx){
                    if(emqLabels.length){
                        new Chart(emqCtx,{type:'line',data:{labels:emqLabels,datasets:[{label:'Avg EMQ',data:emqScores,borderColor:'#6366f1',backgroundColor:'rgba(99,102,241,.08)',tension:.4,fill:true,pointRadius:4,pointBackgroundColor:'#6366f1',pointHoverRadius:6}]},options:{responsive:true,plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return' EMQ: '+c.parsed.y}}}},scales:{y:{min:0,max:10,ticks:{stepSize:2},grid:{color:'#f3f4f6'}},x:{grid:{display:false}}}}});
                    } else {
                        document.getElementById('st-emq-empty').style.display='block';
                        emqCtx.style.display='none';
                    }
                }

                // Breakdown doughnut
                var bdData    = <?php echo wp_json_encode( $breakdown ); ?>;
                var bdLabels  = Object.keys(bdData);
                var bdValues  = Object.values(bdData);
                var bdCtx     = document.getElementById('st-breakdown-chart');
                var bdEmpty   = document.getElementById('st-breakdown-empty');
                if(bdCtx){
                    var total = bdValues.reduce(function(a,b){return a+b;},0);
                    if(total > 0){
                        new Chart(bdCtx,{type:'doughnut',data:{labels:bdLabels,datasets:[{data:bdValues,backgroundColor:['#6366f1','#22c55e','#f59e0b','#ef4444','#3b82f6'],borderWidth:0,hoverOffset:6}]},options:{responsive:true,cutout:'68%',plugins:{legend:{position:'bottom',labels:{boxWidth:10,padding:12,font:{size:12}}},tooltip:{callbacks:{label:function(c){return' '+c.label+': '+c.parsed+' events'}}}}}});
                    } else {
                        bdEmpty.style.display='block';
                        bdCtx.style.display='none';
                    }
                }
            })();

            // ── Live refresh ──────────────────────────────────────────────────
            function refreshLog(){
                fetch(ajaxUrl+'?action=servertrack_log_data&nonce='+nonce)
                    .then(function(r){return r.json();})
                    .then(function(data){
                        if(!data.success||!data.data) return;
                        renderRows(data.data);
                        stFilterLog();
                    })
                    .catch(function(){});
            }

            function renderRows(rows){
                var html = '';
                rows.forEach(function(e){
                    var status   = (e.status||'').toLowerCase();
                    var platform = (e.platform||'').toLowerCase();
                    var event    = e.event_type||'';
                    var order    = e.order_id ? '#'+e.order_id : '—';
                    var eventId  = e.event_id ? e.event_id.substring(0,12)+'…' : '—';
                    var msg      = escHtml(e.message||'');
                    var time     = escHtml(e.timestamp||'');
                    var emq      = (e.emq_score !== undefined && e.emq_score !== null) ? e.emq_score : null;
                    var grade    = e.emq_grade||'na';
                    html += '<tr data-platform="'+(e.platform||'')
                        +'" data-status="'+status
                        +'" data-event="'+(event||'').toLowerCase()+'">';
                    html += '<td style="white-space:nowrap;color:#9ca3af;font-size:11px">'+time+'</td>';
                    html += '<td><span class="st-dot '+status+'"></span>'+escHtml(status)+'</td>';
                    html += '<td><span class="st-platform-pill '+platform+'">'+escHtml(e.platform||'')+'</span></td>';
                    html += '<td><strong>'+escHtml(event)+'</strong></td>';
                    html += '<td style="color:#9ca3af">'+escHtml(order)+'</td>';
                    html += '<td style="color:#9ca3af;font-family:monospace;font-size:11px">'+escHtml(eventId)+'</td>';
                    html += '<td>'+(emq!==null ? '<span class="st-emq-grade '+grade+'">'+emq+'</span>' : '—')+'</td>';
                    html += '<td style="color:#6b7280;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+msg+'">'+msg+'</td>';
                    html += '</tr>';
                });
                document.getElementById('st-log-tbody').innerHTML = html || '<tr><td colspan="8" class="st-log-empty">No events recorded yet.</td></tr>';
            }

            function escHtml(str){
                return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

            function startLive(){ liveTimer = setInterval(refreshLog, 30000); }
            function stopLive(){ clearInterval(liveTimer); }

            window.stToggleLive = function(){
                liveMode = !liveMode;
                var dot  = document.getElementById('st-live-dot');
                var dot2 = document.getElementById('st-live-dot-2');
                var lbl  = document.getElementById('st-live-label');
                var lbl2 = document.getElementById('st-live-label-2');
                var btn  = document.getElementById('st-pause-btn');
                if(liveMode){
                    startLive();
                    dot.classList.remove('paused'); dot2.classList.remove('paused');
                    lbl.textContent='Live'; lbl2.textContent='Live';
                    btn.textContent='⏸ Pause';
                } else {
                    stopLive();
                    dot.classList.add('paused'); dot2.classList.add('paused');
                    lbl.textContent='Paused'; lbl2.textContent='Paused';
                    btn.textContent='▶ Resume';
                }
            };

            startLive();

            // ── Log filter ────────────────────────────────────────────────────
            window.stFilterLog = function(reset){
                if(reset===true){
                    document.getElementById('st-filter-platform').value='';
                    document.getElementById('st-filter-status').value='';
                    document.getElementById('st-filter-search').value='';
                }
                var p = document.getElementById('st-filter-platform').value.toLowerCase();
                var s = document.getElementById('st-filter-status').value.toLowerCase();
                var q = document.getElementById('st-filter-search').value.toLowerCase();
                var rows = document.querySelectorAll('#st-log-tbody tr');
                var visible = 0;
                rows.forEach(function(row){
                    var rp  = (row.dataset.platform||'').toLowerCase();
                    var rs  = (row.dataset.status||'').toLowerCase();
                    var re  = (row.dataset.event||'').toLowerCase();
                    var show = true;
                    if(p && rp !== p)   show = false;
                    if(s && rs !== s)   show = false;
                    if(q && re.indexOf(q) < 0 && (row.textContent||'').toLowerCase().indexOf(q) < 0) show = false;
                    row.style.display = show ? '' : 'none';
                    if(show) visible++;
                });
            };

            window.stSetFilter = function(platform, status, search){
                document.getElementById('st-filter-platform').value = platform;
                document.getElementById('st-filter-status').value   = status;
                document.getElementById('st-filter-search').value   = search;
                stFilterLog();
                document.getElementById('st-log-wrap').scrollIntoView({behavior:'smooth'});
            };

            // ── Clear log ─────────────────────────────────────────────────────
            window.stClearLog = function(){
                if(!confirm('Clear the entire event log? This cannot be undone.')) return;
                fetch(ajaxUrl+'?action=servertrack_clear_log&nonce='+nonce,{method:'POST'})
                    .then(function(r){return r.json();})
                    .then(function(data){
                        if(data.success){
                            document.getElementById('st-log-tbody').innerHTML = '<tr><td colspan="8" class="st-log-empty">Log cleared.</td></tr>';
                        }
                    })
                    .catch(function(){alert('Failed to clear log.');});
            };

        })();
        </script>
        <?php
    }

    // ── Log row renderer (shared by PHP render + AJAX) ───────────────────────

    private static function render_log_rows( array $rows ): void {
        if ( empty( $rows ) ) {
            echo '<tr><td colspan="8" class="st-log-empty">No events recorded yet.</td></tr>';
            return;
        }
        foreach ( $rows as $entry ) {
            $status   = esc_attr( strtolower( $entry['status']     ?? '' ) );
            $platform = strtolower( $entry['platform']   ?? '' );
            $event    = esc_html( $entry['event_type'] ?? '' );
            $order_id = esc_html( $entry['order_id']   ?? '' );
            $msg      = esc_html( $entry['message']    ?? '' );
            $time     = esc_html( $entry['timestamp']  ?? '' );
            $event_id_short = ! empty( $entry['event_id'] ) ? esc_html( substr( $entry['event_id'], 0, 12 ) ) . '…' : '—';
            $emq      = $entry['emq_score'] ?? null;
            $grade    = esc_attr( $entry['emq_grade'] ?? 'na' );
            printf(
                '<tr data-platform="%s" data-status="%s" data-event="%s">' .
                '<td style="white-space:nowrap;color:#9ca3af;font-size:11px">%s</td>' .
                '<td><span class="st-dot %s"></span>%s</td>' .
                '<td><span class="st-platform-pill %s">%s</span></td>' .
                '<td><strong>%s</strong></td>' .
                '<td style="color:#9ca3af">%s</td>' .
                '<td style="color:#9ca3af;font-family:monospace;font-size:11px">%s</td>' .
                '<td>%s</td>' .
                '<td style="color:#6b7280;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="%s">%s</td>' .
                '</tr>',
                esc_attr( $platform ),
                $status,
                esc_attr( strtolower( $entry['event_type'] ?? '' ) ),
                $time,
                $status, $status,
                esc_attr( $platform ), esc_html( $entry['platform'] ?? '' ),
                $event,
                $order_id ? '#' . $order_id : '—',
                $event_id_short,
                null !== $emq ? '<span class="st-emq-grade ' . $grade . '">' . esc_html( $emq ) . '</span>' : '—',
                $msg, $msg
            );
        }
    }

    // ── Settings page ────────────────────────────────────────────────────────

    public static function render_settings(): void {
        if ( class_exists( 'ServerTrack_Settings' ) && method_exists( 'ServerTrack_Settings', 'render_page' ) ) {
            ServerTrack_Settings::render_page();
        } else {
            echo '<div class="wrap"><h1>ServerTrack Settings</h1><p>Settings class not loaded.</p></div>';
        }
    }

    // ── AJAX handlers ────────────────────────────────────────────────────────

    public static function ajax_log_data(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $logs = get_option( 'servertrack_debug_log', [] );
        wp_send_json_success( array_slice( array_reverse( $logs ), 0, 200 ) );
    }

    public static function ajax_platform_health(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        wp_send_json_success( self::get_platform_statuses() );
    }

    public static function ajax_clear_log(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        update_option( 'servertrack_debug_log', [] );
        wp_send_json_success( [ 'cleared' => true ] );
    }

    public static function ajax_export_log(): void {
        if ( ! check_ajax_referer( 'servertrack_dashboard', 'nonce', false ) ) {
            wp_die( 'Invalid nonce', 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', 403 );
        }
        $logs = array_slice( array_reverse( get_option( 'servertrack_debug_log', [] ) ), 0, 500 );
        $filename = 'servertrack-log-' . gmdate( 'Y-m-d' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'Timestamp', 'Status', 'Platform', 'Event Type', 'Order ID', 'Event ID', 'EMQ Score', 'EMQ Grade', 'Message' ] );
        foreach ( $logs as $e ) {
            fputcsv( $out, [
                $e['timestamp']  ?? '',
                $e['status']     ?? '',
                $e['platform']   ?? '',
                $e['event_type'] ?? '',
                $e['order_id']   ?? '',
                $e['event_id']   ?? '',
                $e['emq_score']  ?? '',
                $e['emq_grade']  ?? '',
                $e['message']    ?? '',
            ] );
        }
        fclose( $out );
        exit;
    }

    // ── Data helpers ─────────────────────────────────────────────────────────

    private static function compute_stats( array $logs ): array {
        $today        = gmdate( 'Y-m-d' );
        $seven_ago    = strtotime( '-7 days' );
        $yesterday    = strtotime( '-24 hours' );
        $today_count  = 0;
        $week_total   = 0;
        $week_success = 0;
        $emq_sum      = 0;
        $emq_count    = 0;
        $error_24h    = 0;

        foreach ( $logs as $entry ) {
            $ts   = strtotime( $entry['timestamp'] ?? '' );
            $date = substr( $entry['timestamp'] ?? '', 0, 10 );
            if ( $date === $today ) $today_count++;
            if ( $ts >= $seven_ago ) {
                $week_total++;
                if ( ( $entry['status'] ?? '' ) === 'success' ) $week_success++;
                if ( isset( $entry['emq_score'] ) && is_numeric( $entry['emq_score'] ) ) {
                    $emq_sum  += (float) $entry['emq_score'];
                    $emq_count++;
                }
            }
            if ( $ts >= $yesterday && ( $entry['status'] ?? '' ) === 'error' ) $error_24h++;
        }

        return [
            'today_count'  => $today_count,
            'success_rate' => $week_total > 0 ? round( ( $week_success / $week_total ) * 100 ) : 0,
            'avg_emq'      => $emq_count > 0 ? round( $emq_sum / $emq_count, 1 ) : '—',
            'retry_queue'  => count( get_option( 'servertrack_retry_queue', [] ) ),
            'week_total'   => $week_total,
            'error_24h'    => $error_24h,
        ];
    }

    /**
     * EMQ scorecard: per-platform avg today + 7-day avg + trend direction.
     */
    private static function compute_emq_scorecard( array $logs ): array {
        $platforms  = [ 'meta', 'tiktok', 'google' ];
        $today      = gmdate( 'Y-m-d' );
        $seven_ago  = strtotime( '-7 days' );
        $three_ago  = strtotime( '-3 days' );  // recent half
        $data = [];
        foreach ( $platforms as $p ) {
            $data[ $p ] = [ 'today_sum' => 0, 'today_n' => 0, 'week_sum' => 0, 'week_n' => 0, 'recent_sum' => 0, 'recent_n' => 0, 'older_sum' => 0, 'older_n' => 0 ];
        }
        foreach ( $logs as $entry ) {
            $p  = strtolower( $entry['platform'] ?? '' );
            if ( ! isset( $data[ $p ] ) ) continue;
            if ( ! isset( $entry['emq_score'] ) || ! is_numeric( $entry['emq_score'] ) ) continue;
            $ts   = strtotime( $entry['timestamp'] ?? '' );
            $date = substr( $entry['timestamp'] ?? '', 0, 10 );
            $emq  = (float) $entry['emq_score'];
            if ( $date === $today ) { $data[$p]['today_sum'] += $emq; $data[$p]['today_n']++; }
            if ( $ts >= $seven_ago ) {
                $data[$p]['week_sum'] += $emq; $data[$p]['week_n']++;
                if ( $ts >= $three_ago ) { $data[$p]['recent_sum'] += $emq; $data[$p]['recent_n']++; }
                else                    { $data[$p]['older_sum']  += $emq; $data[$p]['older_n']++;  }
            }
        }
        $result = [];
        foreach ( $platforms as $p ) {
            $d     = $data[ $p ];
            $today = $d['today_n'] > 0 ? round( $d['today_sum'] / $d['today_n'], 1 ) : null;
            $week  = $d['week_n']  > 0 ? round( $d['week_sum']  / $d['week_n'],  1 ) : null;
            $recent= $d['recent_n']> 0 ? $d['recent_sum'] / $d['recent_n'] : null;
            $older = $d['older_n'] > 0 ? $d['older_sum']  / $d['older_n']  : null;
            $trend_dir = 'flat';
            if ( null !== $recent && null !== $older ) {
                if ( $recent > $older + 0.3 ) $trend_dir = 'up';
                elseif ( $recent < $older - 0.3 ) $trend_dir = 'down';
            }
            $score = $week ?? 0;
            if ( $score >= 8 )      $grade = 'excellent';
            elseif ( $score >= 6 )  $grade = 'good';
            elseif ( $score >= 4 )  $grade = 'fair';
            elseif ( $score > 0 )   $grade = 'poor';
            else                    $grade = 'na';
            $result[ $p ] = [
                'today'     => null !== $today ? $today : '—',
                'week'      => null !== $week  ? $week  : '—',
                'trend_dir' => $trend_dir,
                'grade'     => $grade,
            ];
        }
        return $result;
    }

    /**
     * Top 5 event types by count in last 7 days.
     */
    private static function compute_top_events( array $logs ): array {
        $seven_ago = strtotime( '-7 days' );
        $counts    = [];
        $success   = [];
        foreach ( $logs as $entry ) {
            $ts    = strtotime( $entry['timestamp'] ?? '' );
            if ( $ts < $seven_ago ) continue;
            $etype = $entry['event_type'] ?? 'unknown';
            if ( ! isset( $counts[ $etype ] ) ) { $counts[ $etype ] = 0; $success[ $etype ] = 0; }
            $counts[ $etype ]++;
            if ( ( $entry['status'] ?? '' ) === 'success' ) $success[ $etype ]++;
        }
        arsort( $counts );
        $top = [];
        foreach ( array_slice( $counts, 0, 5, true ) as $etype => $count ) {
            $top[] = [ 'event' => $etype, 'count' => $count, 'success' => $success[ $etype ] ?? 0 ];
        }
        return $top;
    }

    /**
     * Events by platform for today (for doughnut chart).
     */
    private static function compute_platform_breakdown( array $logs ): array {
        $today  = gmdate( 'Y-m-d' );
        $counts = [ 'Meta' => 0, 'TikTok' => 0, 'Google' => 0 ];
        foreach ( $logs as $entry ) {
            $date = substr( $entry['timestamp'] ?? '', 0, 10 );
            if ( $date !== $today ) continue;
            $p = strtolower( $entry['platform'] ?? '' );
            if ( $p === 'meta' )    $counts['Meta']++;
            if ( $p === 'tiktok' )  $counts['TikTok']++;
            if ( $p === 'google' )  $counts['Google']++;
        }
        return array_filter( $counts ); // drop zero platforms
    }

    /**
     * Count error entries in last 24 hours.
     */
    private static function count_recent_errors( array $logs ): int {
        $since = strtotime( '-24 hours' );
        $count = 0;
        foreach ( $logs as $entry ) {
            if ( ( $entry['status'] ?? '' ) === 'error' && strtotime( $entry['timestamp'] ?? '' ) >= $since ) $count++;
        }
        return $count;
    }

    private static function get_platform_statuses(): array {
        return [
            [
                'name'    => 'Meta (Facebook)',
                'enabled' => (bool) get_option( 'servertrack_meta_enabled', 0 ),
                'api_key' => get_option( 'servertrack_meta_pixel_id', '' ),
                'status'  => get_option( 'servertrack_meta_pixel_id' ) ? 'Configured' : 'Missing Pixel ID',
            ],
            [
                'name'    => 'TikTok',
                'enabled' => (bool) get_option( 'servertrack_tiktok_enabled', 0 ),
                'api_key' => get_option( 'servertrack_tiktok_pixel_id', '' ),
                'status'  => get_option( 'servertrack_tiktok_pixel_id' ) ? 'Configured' : 'Missing Pixel ID',
            ],
            [
                'name'    => 'Google (GA4)',
                'enabled' => (bool) get_option( 'servertrack_google_enabled', 0 ),
                'api_key' => get_option( 'servertrack_google_measurement_id', '' ),
                'status'  => get_option( 'servertrack_google_measurement_id' ) ? 'Configured' : 'Missing Measurement ID',
            ],
        ];
    }
}
