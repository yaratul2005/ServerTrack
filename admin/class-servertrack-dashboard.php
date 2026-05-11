<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Dashboard  v2.0
 *
 * Changes in v2.0 (feature/admin-dashboard-v2):
 *   - Auto-refresh live event log every 30 s via AJAX (no full page reload).
 *   - Per-platform event breakdown: doughnut chart (Meta / TikTok / Google).
 *   - EMQ Scorecard: colour-coded grade distribution bar.
 *   - Top-5 event types: horizontal bar chart.
 *   - Retry queue panel with drain-all AJAX action (servertrack_drain_retries).
 *   - Clear-log AJAX action (servertrack_clear_log) with nonce guard.
 *   - Real-time event counter badge that increments on auto-refresh.
 *   - New AJAX handler: servertrack_stats_breakdown — returns per-platform
 *     counts, EMQ grade distribution, and top event types from current log.
 *   - All inline CSS rewritten with CSS custom-properties for easy theming.
 *   - settings-sources submenu page wired to updated settings-sources view.
 *
 * Changes in v1.0 (original):
 *   Platform Health, 7-day EMQ line chart, live event log, quick stats,
 *   AJAX handlers ajax_log_data / ajax_platform_health.
 */
class ServerTrack_Dashboard {

    public static function init(): void {
        add_action( 'admin_menu',            [ self::class, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );

        // v1 AJAX
        add_action( 'wp_ajax_servertrack_log_data',        [ self::class, 'ajax_log_data' ] );
        add_action( 'wp_ajax_servertrack_platform_health', [ self::class, 'ajax_platform_health' ] );

        // v2 AJAX
        add_action( 'wp_ajax_servertrack_stats_breakdown', [ self::class, 'ajax_stats_breakdown' ] );
        add_action( 'wp_ajax_servertrack_clear_log',       [ self::class, 'ajax_clear_log' ] );
        add_action( 'wp_ajax_servertrack_drain_retries',   [ self::class, 'ajax_drain_retries' ] );
    }

    // ────────────────────────────────────────────────────────────────────────
    // MENU
    // ────────────────────────────────────────────────────────────────────────

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
        add_submenu_page( 'servertrack', __( 'Dashboard', 'servertrack' ),       __( 'Dashboard', 'servertrack' ),       'manage_options', 'servertrack',          [ self::class, 'render_page' ] );
        add_submenu_page( 'servertrack', __( 'Settings', 'servertrack' ),        __( 'Settings', 'servertrack' ),        'manage_options', 'servertrack-settings', [ self::class, 'render_settings' ] );
        add_submenu_page( 'servertrack', __( 'Event Sources', 'servertrack' ),   __( 'Event Sources', 'servertrack' ),   'manage_options', 'servertrack-sources',  [ self::class, 'render_sources' ] );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'servertrack' ) === false ) return;
        wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js', [], '4.4.3', true );
    }

    // ────────────────────────────────────────────────────────────────────────
    // MAIN DASHBOARD PAGE
    // ────────────────────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $logs        = get_option( 'servertrack_debug_log', [] );
        $recent_logs = array_slice( array_reverse( $logs ), 0, 200 );
        $stats       = self::compute_stats( $logs );
        $emq_data    = ServerTrack_MatchQuality::get_daily_averages( 7 );
        $platforms   = self::get_platform_statuses();
        $breakdown   = self::compute_breakdown( $logs );
        $retry_items = get_option( 'servertrack_retry_queue', [] );
        $nonce       = wp_create_nonce( 'servertrack_dashboard' );

        ?>
        <div class="wrap" id="st-app">
        <style>
            :root{
                --st-bg:#f6f7fb;
                --st-surface:#fff;
                --st-border:#e5e7eb;
                --st-text:#111827;
                --st-muted:#6b7280;
                --st-faint:#9ca3af;
                --st-primary:#6366f1;
                --st-primary-light:rgba(99,102,241,.1);
                --st-success:#16a34a;
                --st-success-bg:#dcfce7;
                --st-error:#dc2626;
                --st-error-bg:#fee2e2;
                --st-warn:#d97706;
                --st-warn-bg:#fef3c7;
                --st-info:#2563eb;
                --st-info-bg:#dbeafe;
                --st-radius:12px;
                --st-shadow:0 1px 3px rgba(0,0,0,.07),0 4px 12px rgba(0,0,0,.04);
            }
            *{box-sizing:border-box;}
            #st-app{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
                     max-width:1400px;padding-bottom:40px;background:var(--st-bg);
                     margin-top:0 !important;}
            /* ── Header ──────────────────────────────────────────── */
            .st-header{display:flex;align-items:center;gap:12px;
                        padding:20px 0 18px;border-bottom:1px solid var(--st-border);margin-bottom:24px;}
            .st-header h1{margin:0;font-size:22px;font-weight:700;color:var(--st-text);}
            .st-pill{background:var(--st-primary-light);color:var(--st-primary);
                      border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600;
                      letter-spacing:.03em;}
            .st-refresh-badge{margin-left:auto;display:flex;align-items:center;gap:8px;}
            .st-pulse{width:8px;height:8px;border-radius:50%;background:var(--st-success);
                       animation:st-pulse 2s infinite;}
            @keyframes st-pulse{0%,100%{opacity:1}50%{opacity:.3}}
            .st-refresh-btn{display:flex;align-items:center;gap:6px;background:var(--st-surface);
                             border:1px solid var(--st-border);border-radius:8px;
                             padding:6px 12px;font-size:12px;color:var(--st-muted);cursor:pointer;}
            .st-refresh-btn:hover{border-color:var(--st-primary);color:var(--st-primary);}
            /* ── KPI grid ────────────────────────────────────────── */
            .st-kpi-grid{display:grid;
                          grid-template-columns:repeat(auto-fill,minmax(190px,1fr));
                          gap:14px;margin-bottom:22px;}
            .st-kpi{background:var(--st-surface);border:1px solid var(--st-border);
                     border-radius:var(--st-radius);padding:18px 20px;
                     box-shadow:var(--st-shadow);transition:box-shadow .15s;}
            .st-kpi:hover{box-shadow:0 4px 20px rgba(0,0,0,.09);}
            .st-kpi-label{font-size:11px;font-weight:600;color:var(--st-faint);
                           text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;}
            .st-kpi-val{font-size:30px;font-weight:800;color:var(--st-text);line-height:1.1;
                         font-variant-numeric:tabular-nums;}
            .st-kpi-sub{font-size:11px;color:var(--st-faint);margin-top:4px;}
            .st-kpi-delta{display:inline-block;font-size:11px;font-weight:600;
                           border-radius:10px;padding:2px 7px;margin-top:6px;}
            .st-kpi-delta.up{background:var(--st-success-bg);color:var(--st-success);}
            .st-kpi-delta.down{background:var(--st-error-bg);color:var(--st-error);}
            /* ── 2-col row ───────────────────────────────────────── */
            .st-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;}
            .st-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;}
            @media(max-width:1100px){.st-row-3{grid-template-columns:1fr 1fr;}}
            @media(max-width:780px){.st-row,.st-row-3{grid-template-columns:1fr;}}
            /* ── Panel ───────────────────────────────────────────── */
            .st-panel{background:var(--st-surface);border:1px solid var(--st-border);
                       border-radius:var(--st-radius);padding:20px;box-shadow:var(--st-shadow);
                       margin-bottom:0;}
            .st-panel-header{display:flex;align-items:center;justify-content:space-between;
                              margin-bottom:16px;}
            .st-panel-title{font-size:14px;font-weight:700;color:var(--st-text);margin:0;}
            .st-panel-action{font-size:12px;color:var(--st-primary);cursor:pointer;
                              background:none;border:none;padding:0;text-decoration:underline;
                              text-underline-offset:2px;}
            .st-panel-action:hover{color:#4338ca;}
            /* ── Platform health ─────────────────────────────────── */
            .st-plat-list{display:flex;flex-direction:column;gap:8px;}
            .st-plat-row{display:flex;align-items:center;gap:10px;
                          padding:10px 14px;background:#f9fafb;
                          border-radius:8px;border:1px solid #f3f4f6;}
            .st-plat-name{font-weight:600;font-size:13px;color:var(--st-text);flex:1;}
            .st-plat-stat{font-size:12px;color:var(--st-muted);}
            .st-badge{padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;
                       letter-spacing:.03em;}
            .st-badge.on{background:var(--st-success-bg);color:var(--st-success);}
            .st-badge.off{background:#f3f4f6;color:var(--st-faint);}
            .st-badge.warn{background:var(--st-warn-bg);color:var(--st-warn);}
            /* ── EMQ Scorecard ───────────────────────────────────── */
            .st-emq-grades{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;}
            .st-grade-pill{display:flex;align-items:center;justify-content:space-between;
                            padding:8px 12px;border-radius:8px;font-size:13px;font-weight:600;}
            .st-grade-pill.excellent{background:var(--st-success-bg);color:var(--st-success);}
            .st-grade-pill.good{background:var(--st-info-bg);color:var(--st-info);}
            .st-grade-pill.fair{background:var(--st-warn-bg);color:var(--st-warn);}
            .st-grade-pill.poor{background:var(--st-error-bg);color:var(--st-error);}
            .st-grade-count{font-size:20px;font-weight:800;}
            /* ── Log ─────────────────────────────────────────────── */
            .st-filter-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;}
            .st-filter-bar select,.st-filter-bar input{
                padding:6px 10px;border:1px solid var(--st-border);
                border-radius:7px;font-size:12px;color:var(--st-text);
                background:var(--st-surface);}
            .st-filter-bar select:focus,.st-filter-bar input:focus{
                outline:none;border-color:var(--st-primary);}
            .st-log-wrap{max-height:460px;overflow-y:auto;
                          border:1px solid var(--st-border);border-radius:9px;}
            .st-log-table{width:100%;border-collapse:collapse;font-size:12px;}
            .st-log-table th{text-align:left;padding:9px 12px;background:#f9fafb;
                              color:var(--st-muted);font-weight:600;font-size:11px;
                              text-transform:uppercase;letter-spacing:.05em;
                              border-bottom:1px solid var(--st-border);position:sticky;top:0;z-index:1;}
            .st-log-table td{padding:8px 12px;border-bottom:1px solid #f9fafb;
                              color:var(--st-text);vertical-align:middle;}
            .st-log-table tr:hover td{background:#fafafa;}
            .st-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:5px;vertical-align:middle;}
            .st-dot.success{background:var(--st-success);}
            .st-dot.error{background:var(--st-error);}
            .st-dot.skipped{background:var(--st-warn);}
            .st-dot.queued,.st-dot.dedup_blocked,.st-dot.retrying{background:var(--st-faint);}
            .st-emq-chip{display:inline-block;padding:1px 7px;border-radius:9px;
                          font-size:10px;font-weight:700;}
            .st-emq-chip.excellent{background:var(--st-success-bg);color:var(--st-success);}
            .st-emq-chip.good{background:var(--st-info-bg);color:var(--st-info);}
            .st-emq-chip.fair{background:var(--st-warn-bg);color:var(--st-warn);}
            .st-emq-chip.poor{background:var(--st-error-bg);color:var(--st-error);}
            /* ── Retry queue ─────────────────────────────────────── */
            .st-retry-list{max-height:220px;overflow-y:auto;display:flex;
                            flex-direction:column;gap:6px;}
            .st-retry-item{padding:9px 12px;background:#f9fafb;border-radius:8px;
                            border:1px solid #f3f4f6;font-size:12px;color:var(--st-text);
                            display:flex;align-items:center;gap:10px;}
            .st-retry-plat{font-weight:700;font-size:11px;padding:2px 7px;
                            border-radius:10px;background:var(--st-primary-light);
                            color:var(--st-primary);}
            /* ── Spinner ─────────────────────────────────────────── */
            .st-spinner{display:none;width:14px;height:14px;border:2px solid #e5e7eb;
                         border-top-color:var(--st-primary);border-radius:50%;
                         animation:st-spin .6s linear infinite;vertical-align:middle;margin-left:6px;}
            @keyframes st-spin{to{transform:rotate(360deg)}}
            .st-spinning .st-spinner{display:inline-block;}
            /* ── Empty state ─────────────────────────────────────── */
            .st-empty{padding:32px;text-align:center;color:var(--st-faint);font-size:13px;}
            .st-empty svg{margin:0 auto 10px;display:block;opacity:.3;}
        </style>

        <?php // ── Header ──────────────────────────────────────────────────── ?>
        <div class="st-header">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="var(--st-primary)" stroke-width="2.2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            <h1>ServerTrack</h1>
            <span class="st-pill">v5.0</span>
            <div class="st-refresh-badge">
                <span id="st-live-count" style="font-size:12px;color:var(--st-muted);">Live</span>
                <span class="st-pulse" title="Auto-refreshing every 30s"></span>
                <button class="st-refresh-btn" id="st-manual-refresh" title="Refresh now">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    Refresh
                    <span class="st-spinner"></span>
                </button>
            </div>
        </div>

        <?php // ── KPI Cards ────────────────────────────────────────────────── ?>
        <div class="st-kpi-grid" id="st-kpis">
            <?php
            $kpis = [
                [ 'label' => 'Events Today',  'val' => $stats['today_count'],  'sub' => 'All platforms' ],
                [ 'label' => 'Success Rate',  'val' => $stats['success_rate'] . '%', 'sub' => 'Last 7 days' ],
                [ 'label' => 'Avg EMQ Score', 'val' => $stats['avg_emq'],      'sub' => '0–10 scale' ],
                [ 'label' => 'Retry Queue',   'val' => $stats['retry_queue'],  'sub' => 'Pending retries' ],
                [ 'label' => 'Total (7d)',    'val' => $stats['week_total'],   'sub' => 'Events sent' ],
                [ 'label' => 'Errors (7d)',   'val' => $stats['week_errors'],  'sub' => 'Failed sends' ],
            ];
            foreach ( $kpis as $k ) :
            ?>
            <div class="st-kpi">
                <div class="st-kpi-label"><?php echo esc_html( $k['label'] ); ?></div>
                <div class="st-kpi-val"><?php echo esc_html( $k['val'] ); ?></div>
                <div class="st-kpi-sub"><?php echo esc_html( $k['sub'] ); ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php // ── Row 1: Platform Health + EMQ Scorecard ───────────────────── ?>
        <div class="st-row">
            <!-- Platform Health -->
            <div class="st-panel">
                <div class="st-panel-header">
                    <span class="st-panel-title">🛰 Platform Health</span>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=servertrack-settings' ) ); ?>" class="st-panel-action">Configure →</a>
                </div>
                <div class="st-plat-list">
                    <?php foreach ( $platforms as $p ) :
                        $enabled = $p['enabled'];
                        $badge   = $enabled ? 'on' : 'off';
                        $badge_label = $enabled ? esc_html( $p['status'] ) : 'Disabled';
                        $warn    = $enabled && strpos( $p['status'], 'Missing' ) !== false;
                        if ( $warn ) { $badge = 'warn'; }
                    ?>
                    <div class="st-plat-row">
                        <span class="st-plat-name"><?php echo esc_html( $p['name'] ); ?></span>
                        <?php if ( $enabled ) : ?>
                            <span class="st-plat-stat"><?php echo esc_html( $p['today'] ?? 0 ); ?> today</span>
                        <?php endif; ?>
                        <span class="st-badge <?php echo esc_attr( $badge ); ?>"><?php echo $badge_label; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- EMQ Scorecard -->
            <div class="st-panel">
                <div class="st-panel-header">
                    <span class="st-panel-title">🎯 EMQ Scorecard (7 days)</span>
                </div>
                <div class="st-emq-grades">
                    <?php
                    $grade_labels = [
                        'excellent' => '✅ Excellent (8–10)',
                        'good'      => '🔵 Good (6–7.9)',
                        'fair'      => '🟡 Fair (4–5.9)',
                        'poor'      => '🔴 Poor (0–3.9)',
                    ];
                    foreach ( $grade_labels as $grade => $label ) :
                        $count = $breakdown['emq_grades'][ $grade ] ?? 0;
                    ?>
                    <div class="st-grade-pill <?php echo esc_attr( $grade ); ?>">
                        <span style="font-size:12px;"><?php echo esc_html( $label ); ?></span>
                        <span class="st-grade-count"><?php echo esc_html( $count ); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <canvas id="st-emq-chart" height="110"></canvas>
            </div>
        </div>

        <?php // ── Row 2: Platform Breakdown + Top Events ────────────────────── ?>
        <div class="st-row" style="margin-top:16px;">
            <!-- Per-Platform Doughnut -->
            <div class="st-panel">
                <div class="st-panel-header">
                    <span class="st-panel-title">📡 Events by Platform (7d)</span>
                </div>
                <div style="max-width:260px;margin:0 auto;">
                    <canvas id="st-plat-chart" height="180"></canvas>
                </div>
                <div style="display:flex;justify-content:center;gap:16px;margin-top:12px;flex-wrap:wrap;">
                    <?php foreach ( $breakdown['by_platform'] as $plat => $cnt ) : ?>
                    <span style="font-size:12px;color:var(--st-muted);">
                        <strong style="color:var(--st-text);"><?php echo esc_html( $cnt ); ?></strong>
                        <?php echo esc_html( ucfirst( $plat ) ); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Top Event Types -->
            <div class="st-panel">
                <div class="st-panel-header">
                    <span class="st-panel-title">📊 Top Event Types (7d)</span>
                </div>
                <canvas id="st-events-chart" height="180"></canvas>
            </div>
        </div>

        <?php // ── Row 3: Retry Queue ────────────────────────────────────────── ?>
        <?php if ( ! empty( $retry_items ) ) : ?>
        <div style="margin-top:16px;">
        <div class="st-panel">
            <div class="st-panel-header">
                <span class="st-panel-title">🔄 Retry Queue <span style="font-weight:400;color:var(--st-faint);font-size:12px;">(<?php echo count( $retry_items ); ?> pending)</span></span>
                <button class="st-panel-action" id="st-drain-btn">Drain all now</button>
            </div>
            <div class="st-retry-list" id="st-retry-list">
                <?php foreach ( array_slice( $retry_items, 0, 10 ) as $i => $item ) :
                    $plat  = esc_html( $item['platform']   ?? 'unknown' );
                    $event = esc_html( $item['event_name'] ?? '—' );
                    $tries = (int) ( $item['attempts'] ?? 0 );
                    $ts    = esc_html( $item['last_attempt'] ?? '' );
                ?>
                <div class="st-retry-item">
                    <span class="st-retry-plat"><?php echo $plat; ?></span>
                    <span><?php echo $event; ?></span>
                    <span style="margin-left:auto;color:var(--st-faint);"><?php echo $tries; ?> attempt<?php echo $tries !== 1 ? 's' : ''; ?></span>
                    <span style="color:var(--st-faint);font-size:11px;"><?php echo $ts; ?></span>
                </div>
                <?php endforeach; ?>
                <?php if ( count( $retry_items ) > 10 ) : ?>
                <div style="text-align:center;padding:8px;font-size:12px;color:var(--st-faint);">+ <?php echo count( $retry_items ) - 10; ?> more</div>
                <?php endif; ?>
            </div>
        </div>
        </div>
        <?php endif; ?>

        <?php // ── Live Event Log ────────────────────────────────────────────── ?>
        <div style="margin-top:16px;">
        <div class="st-panel">
            <div class="st-panel-header">
                <span class="st-panel-title">📋 Live Event Log
                    <span style="font-weight:400;color:var(--st-faint);font-size:12px;margin-left:6px;">last 200 · auto-refreshes every 30s</span>
                    <span class="st-spinner" id="st-log-spinner"></span>
                </span>
                <button class="st-panel-action" id="st-clear-log-btn" style="color:var(--st-error);">Clear log</button>
            </div>

            <div class="st-filter-bar">
                <select id="st-fp" onchange="stFilter()">
                    <option value="">All Platforms</option>
                    <option value="meta">Meta</option>
                    <option value="tiktok">TikTok</option>
                    <option value="google">Google</option>
                    <option value="all">All</option>
                </select>
                <select id="st-fs" onchange="stFilter()">
                    <option value="">All Statuses</option>
                    <option value="success">✅ Success</option>
                    <option value="error">❌ Error</option>
                    <option value="skipped">⏭ Skipped</option>
                    <option value="dedup_blocked">🚫 Dedup Blocked</option>
                    <option value="queued">🕐 Queued</option>
                    <option value="retrying">🔄 Retrying</option>
                </select>
                <input type="text" id="st-fe" placeholder="Search event…" oninput="stFilter()" style="min-width:160px;">
                <input type="text" id="st-fo" placeholder="Order #…" oninput="stFilter()" style="width:100px;">
            </div>

            <div class="st-log-wrap">
                <table class="st-log-table">
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
                        <?php self::render_log_rows( $recent_logs ); ?>
                    </tbody>
                </table>
            </div>
        </div>
        </div>

        </div><!-- #st-app -->

        <script>
        (function(){
            var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

            // ── EMQ 7-day line chart ──────────────────────────────────────────
            (function(){
                var labels = <?php echo wp_json_encode( array_keys( $emq_data ) ); ?>;
                var scores = <?php echo wp_json_encode( array_values( array_map( fn($d) => $d['avg'] ?? 0, $emq_data ) ) ); ?>;
                var ctx = document.getElementById('st-emq-chart');
                if(!ctx) return;
                if(!labels.length){
                    ctx.parentElement.insertAdjacentHTML('beforeend','<p style="color:var(--st-faint);font-size:12px;text-align:center;margin-top:8px;">No EMQ data yet.</p>');
                    ctx.style.display='none'; return;
                }
                new Chart(ctx,{
                    type:'line',
                    data:{labels:labels,datasets:[{
                        label:'Avg EMQ',data:scores,
                        borderColor:'#6366f1',backgroundColor:'rgba(99,102,241,.08)',
                        tension:.4,fill:true,pointRadius:4,pointBackgroundColor:'#6366f1',
                        pointBorderColor:'#fff',pointBorderWidth:2
                    }]},
                    options:{responsive:true,plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return ' EMQ: '+c.parsed.y.toFixed(1);}}}},
                        scales:{y:{min:0,max:10,ticks:{stepSize:2,font:{size:10}},grid:{color:'#f3f4f6'}},x:{grid:{display:false},ticks:{font:{size:10}}}}}
                });
            })();

            // ── Platform breakdown doughnut ───────────────────────────────────
            (function(){
                var pd = <?php echo wp_json_encode( $breakdown['by_platform'] ); ?>;
                var labels = Object.keys(pd).map(function(k){ return k.charAt(0).toUpperCase()+k.slice(1); });
                var data   = Object.values(pd);
                var ctx    = document.getElementById('st-plat-chart');
                if(!ctx) return;
                if(!data.length||data.every(function(v){return v===0;})){
                    ctx.parentElement.insertAdjacentHTML('beforeend','<p style="color:var(--st-faint);font-size:12px;text-align:center;margin-top:8px;">No data yet.</p>');
                    ctx.style.display='none'; return;
                }
                new Chart(ctx,{
                    type:'doughnut',
                    data:{labels:labels,datasets:[{
                        data:data,
                        backgroundColor:['#6366f1','#0ea5e9','#22c55e'],
                        borderWidth:2,borderColor:'#fff',hoverOffset:6
                    }]},
                    options:{responsive:true,cutout:'65%',
                        plugins:{legend:{position:'bottom',labels:{font:{size:11},padding:12,boxWidth:10}},
                        tooltip:{callbacks:{label:function(c){return ' '+c.label+': '+c.parsed+' events';}}}}}
                });
            })();

            // ── Top event types bar chart ─────────────────────────────────────
            (function(){
                var te = <?php echo wp_json_encode( $breakdown['top_events'] ); ?>;
                var labels = Object.keys(te);
                var data   = Object.values(te);
                var ctx    = document.getElementById('st-events-chart');
                if(!ctx) return;
                if(!labels.length){
                    ctx.parentElement.insertAdjacentHTML('beforeend','<p style="color:var(--st-faint);font-size:12px;text-align:center;margin-top:8px;">No data yet.</p>');
                    ctx.style.display='none'; return;
                }
                new Chart(ctx,{
                    type:'bar',
                    data:{labels:labels,datasets:[{
                        label:'Events',data:data,
                        backgroundColor:'rgba(99,102,241,.75)',
                        borderRadius:5,borderSkipped:false
                    }]},
                    options:{indexAxis:'y',responsive:true,
                        plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return ' '+c.parsed.x+' events';}}}},
                        scales:{x:{grid:{color:'#f3f4f6'},ticks:{font:{size:10}}},y:{grid:{display:false},ticks:{font:{size:11}}}}}
                });
            })();

            // ── Log filter ────────────────────────────────────────────────────
            window.stFilter = function(){
                var fp = document.getElementById('st-fp').value.toLowerCase();
                var fs = document.getElementById('st-fs').value.toLowerCase();
                var fe = document.getElementById('st-fe').value.toLowerCase();
                var fo = document.getElementById('st-fo').value.toLowerCase();
                var rows = document.querySelectorAll('#st-log-tbody tr[data-row]');
                rows.forEach(function(row){
                    var rp = (row.dataset.platform||'').toLowerCase();
                    var rs = (row.dataset.status||'').toLowerCase();
                    var re = (row.dataset.event||'').toLowerCase();
                    var ro = (row.dataset.order||'').toLowerCase();
                    var ok = true;
                    if(fp && rp!==fp) ok=false;
                    if(fs && rs!==fs) ok=false;
                    if(fe && re.indexOf(fe)<0) ok=false;
                    if(fo && ro.indexOf(fo)<0) ok=false;
                    row.style.display = ok ? '' : 'none';
                });
            };

            // ── Auto-refresh log every 30s ────────────────────────────────────
            var lastCount = <?php echo (int) count( $recent_logs ); ?>;
            function refreshLog(){
                var btn = document.getElementById('st-manual-refresh');
                if(btn) btn.classList.add('st-spinning');
                var spinner = document.getElementById('st-log-spinner');
                if(spinner) spinner.style.display='inline-block';
                var fd = new FormData();
                fd.append('action','servertrack_log_data');
                fd.append('nonce', nonce);
                fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(res){
                        if(!res.success) return;
                        var rows = res.data;
                        renderRows(rows);
                        var badge = document.getElementById('st-live-count');
                        if(badge) badge.textContent = 'Live · '+rows.length+' events';
                        lastCount = rows.length;
                        stFilter();
                    })
                    .catch(function(){})
                    .finally(function(){
                        if(btn) btn.classList.remove('st-spinning');
                        if(spinner) spinner.style.display='none';
                    });
            }

            function renderRows(rows){
                var tbody = document.getElementById('st-log-tbody');
                if(!tbody) return;
                tbody.innerHTML = rows.map(function(e){
                    var s  = e.status     || '';
                    var p  = e.platform   || '';
                    var ev = e.event_type || '';
                    var oid= e.order_id   || '';
                    var msg= e.message    || '';
                    var t  = e.timestamp  || '';
                    var emq= e.emq_score != null ? e.emq_score : null;
                    var g  = e.emq_grade  || '';
                    var emqHtml = emq !== null ? '<span class="st-emq-chip '+escHtml(g)+'">'+escHtml(String(emq))+'</span>' : '&mdash;';
                    return '<tr data-row="1" data-platform="'+escAttr(p)+'" data-status="'+escAttr(s)+'" data-event="'+escAttr(ev)+'" data-order="'+escAttr(oid)+'">'
                        +'<td style="white-space:nowrap;color:var(--st-faint);font-size:11px;">'+escHtml(t)+'</td>'
                        +'<td><span class="st-dot '+escAttr(s)+'"></span>'+escHtml(s)+'</td>'
                        +'<td><strong>'+escHtml(p)+'</strong></td>'
                        +'<td>'+escHtml(ev)+'</td>'
                        +'<td>'+(oid?'#'+escHtml(oid):'&mdash;')+'</td>'
                        +'<td>'+emqHtml+'</td>'
                        +'<td style="color:var(--st-muted);">'+escHtml(msg)+'</td>'
                        +'</tr>';
                }).join('');
            }

            function escHtml(s){
                return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }
            function escAttr(s){ return escHtml(s); }

            // Auto-refresh interval
            setInterval(refreshLog, 30000);

            // Manual refresh button
            var manualBtn = document.getElementById('st-manual-refresh');
            if(manualBtn) manualBtn.addEventListener('click', refreshLog);

            // ── Clear log ─────────────────────────────────────────────────────
            var clearBtn = document.getElementById('st-clear-log-btn');
            if(clearBtn) clearBtn.addEventListener('click', function(){
                if(!confirm('Clear all log entries? This cannot be undone.')) return;
                var fd = new FormData();
                fd.append('action','servertrack_clear_log');
                fd.append('nonce', nonce);
                fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(res){
                        if(res.success){
                            document.getElementById('st-log-tbody').innerHTML = '<tr><td colspan="7" class="st-empty">Log cleared.</td></tr>';
                        }
                    });
            });

            // ── Drain retries ─────────────────────────────────────────────────
            var drainBtn = document.getElementById('st-drain-btn');
            if(drainBtn) drainBtn.addEventListener('click', function(){
                drainBtn.textContent = 'Draining…';
                drainBtn.disabled = true;
                var fd = new FormData();
                fd.append('action','servertrack_drain_retries');
                fd.append('nonce', nonce);
                fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(res){
                        drainBtn.textContent = res.success ? 'Drained ✓' : 'Failed';
                        setTimeout(function(){ location.reload(); }, 1200);
                    });
            });

        })();
        </script>
        <?php
    }

    // ────────────────────────────────────────────────────────────────────────
    // LOG ROW RENDERER (used by initial PHP render + AJAX refresh)
    // ────────────────────────────────────────────────────────────────────────

    private static function render_log_rows( array $logs ): void {
        if ( empty( $logs ) ) {
            echo '<tr><td colspan="7" class="st-empty">';
            echo '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 17v-2m3 2v-4m3 4v-6M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/></svg>';
            echo esc_html__( 'No events logged yet. Events will appear here once sent.', 'servertrack' );
            echo '</td></tr>';
            return;
        }
        foreach ( $logs as $entry ) :
            $status   = esc_attr( $entry['status']     ?? '' );
            $platform = esc_html( $entry['platform']   ?? '' );
            $event    = esc_html( $entry['event_type'] ?? '' );
            $order_id = esc_html( $entry['order_id']   ?? '' );
            $msg      = esc_html( $entry['message']    ?? '' );
            $time     = esc_html( $entry['timestamp']  ?? '' );
            $emq      = $entry['emq_score'] ?? null;
            $grade    = esc_attr( $entry['emq_grade'] ?? '' );
        ?>
        <tr data-row="1"
            data-platform="<?php echo esc_attr( $entry['platform'] ?? '' ); ?>"
            data-status="<?php echo $status; ?>"
            data-event="<?php echo esc_attr( $entry['event_type'] ?? '' ); ?>"
            data-order="<?php echo esc_attr( $entry['order_id'] ?? '' ); ?>">
            <td style="white-space:nowrap;color:var(--st-faint);font-size:11px;"><?php echo $time; ?></td>
            <td><span class="st-dot <?php echo $status; ?>"></span><?php echo $status; ?></td>
            <td><strong><?php echo $platform; ?></strong></td>
            <td><?php echo $event; ?></td>
            <td><?php echo $order_id ? '#' . $order_id : '&mdash;'; ?></td>
            <td><?php if ( null !== $emq ) : ?>
                <span class="st-emq-chip <?php echo $grade; ?>"><?php echo esc_html( $emq ); ?></span>
            <?php else : ?>&mdash;<?php endif; ?></td>
            <td style="color:var(--st-muted);"><?php echo $msg; ?></td>
        </tr>
        <?php endforeach;
    }

    // ────────────────────────────────────────────────────────────────────────
    // SUB-PAGES
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Render the Settings sub-page by delegating to ServerTrack_Admin.
     * ServerTrack_Admin is always loaded via servertrack.php before this runs.
     */
    public static function render_settings(): void {
        if ( class_exists( 'ServerTrack_Admin' ) && method_exists( 'ServerTrack_Admin', 'render_page' ) ) {
            ServerTrack_Admin::render_page();
        } else {
            echo '<div class="wrap"><h1>ServerTrack Settings</h1>';
            echo '<p style="color:red;">⚠️ ServerTrack_Admin class not found. Please re-upload the plugin files.</p>';
            echo '</div>';
        }
    }

    public static function render_sources(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        echo '<div class="wrap"><h1>' . esc_html__( 'Event Sources', 'servertrack' ) . '</h1>';
        $view = plugin_dir_path( __FILE__ ) . 'views/settings-sources.php';
        if ( file_exists( $view ) ) include $view;
        echo '</div>';
    }

    // ────────────────────────────────────────────────────────────────────────
    // AJAX HANDLERS
    // ────────────────────────────────────────────────────────────────────────

    /** v1: return last 200 log entries for AJAX refresh. */
    public static function ajax_log_data(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $logs = get_option( 'servertrack_debug_log', [] );
        wp_send_json_success( array_slice( array_reverse( $logs ), 0, 200 ) );
    }

    /** v1: platform health status. */
    public static function ajax_platform_health(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        wp_send_json_success( self::get_platform_statuses() );
    }

    /** v2: KPI stats + per-platform breakdown + top events. */
    public static function ajax_stats_breakdown(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $logs = get_option( 'servertrack_debug_log', [] );
        wp_send_json_success( [
            'stats'     => self::compute_stats( $logs ),
            'breakdown' => self::compute_breakdown( $logs ),
        ] );
    }

    /** v2: clear entire debug log. */
    public static function ajax_clear_log(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        update_option( 'servertrack_debug_log', [] );
        wp_send_json_success( [ 'cleared' => true ] );
    }

    /** v2: trigger immediate processing of the retry queue. */
    public static function ajax_drain_retries(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        if ( class_exists( 'ServerTrack_Retry' ) && method_exists( 'ServerTrack_Retry', 'process_queue' ) ) {
            ServerTrack_Retry::process_queue();
            wp_send_json_success( [ 'drained' => true ] );
        } else {
            wp_send_json_error( 'Retry class not found' );
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // DATA HELPERS
    // ────────────────────────────────────────────────────────────────────────

    private static function compute_stats( array $logs ): array {
        $today     = gmdate( 'Y-m-d' );
        $seven_ago = strtotime( '-7 days' );

        $today_count  = 0;
        $week_total   = 0;
        $week_success = 0;
        $week_errors  = 0;
        $emq_sum      = 0.0;
        $emq_count    = 0;

        foreach ( $logs as $entry ) {
            $ts   = strtotime( $entry['timestamp'] ?? '' );
            $date = gmdate( 'Y-m-d', $ts );

            if ( $date === $today ) $today_count++;

            if ( $ts >= $seven_ago ) {
                $week_total++;
                $s = $entry['status'] ?? '';
                if ( $s === 'success' ) $week_success++;
                if ( $s === 'error' )   $week_errors++;
                if ( isset( $entry['emq_score'] ) ) {
                    $emq_sum  += (float) $entry['emq_score'];
                    $emq_count++;
                }
            }
        }

        $retry_queue = count( get_option( 'servertrack_retry_queue', [] ) );

        return [
            'today_count'  => $today_count,
            'week_total'   => $week_total,
            'week_errors'  => $week_errors,
            'success_rate' => $week_total > 0 ? round( ( $week_success / $week_total ) * 100 ) : 0,
            'avg_emq'      => $emq_count > 0 ? round( $emq_sum / $emq_count, 1 ) : '—',
            'retry_queue'  => $retry_queue,
        ];
    }

    /**
     * Compute per-platform counts, EMQ grade distribution, and top 5 event types
     * from the last 7 days of log entries.
     */
    private static function compute_breakdown( array $logs ): array {
        $seven_ago   = strtotime( '-7 days' );
        $by_platform = [ 'meta' => 0, 'tiktok' => 0, 'google' => 0 ];
        $emq_grades  = [ 'excellent' => 0, 'good' => 0, 'fair' => 0, 'poor' => 0 ];
        $event_types = [];

        foreach ( $logs as $entry ) {
            $ts = strtotime( $entry['timestamp'] ?? '' );
            if ( $ts < $seven_ago ) continue;
            if ( ( $entry['status'] ?? '' ) !== 'success' ) continue;

            $plat = strtolower( $entry['platform'] ?? '' );
            if ( isset( $by_platform[ $plat ] ) ) {
                $by_platform[ $plat ]++;
            }

            $grade = strtolower( $entry['emq_grade'] ?? '' );
            if ( isset( $emq_grades[ $grade ] ) ) {
                $emq_grades[ $grade ]++;
            }

            $ev = $entry['event_type'] ?? '';
            if ( $ev ) {
                $event_types[ $ev ] = ( $event_types[ $ev ] ?? 0 ) + 1;
            }
        }

        // Top 5 event types by count
        arsort( $event_types );
        $top_events = array_slice( $event_types, 0, 5, true );

        return [
            'by_platform' => $by_platform,
            'emq_grades'  => $emq_grades,
            'top_events'  => $top_events,
        ];
    }

    private static function get_platform_statuses(): array {
        $logs      = get_option( 'servertrack_debug_log', [] );
        $today     = gmdate( 'Y-m-d' );
        $today_map = [ 'meta' => 0, 'tiktok' => 0, 'google' => 0 ];

        foreach ( $logs as $entry ) {
            $ts   = strtotime( $entry['timestamp'] ?? '' );
            $date = gmdate( 'Y-m-d', $ts );
            $plat = strtolower( $entry['platform'] ?? '' );
            if ( $date === $today && isset( $today_map[ $plat ] ) && ( $entry['status'] ?? '' ) === 'success' ) {
                $today_map[ $plat ]++;
            }
        }

        return [
            [
                'name'    => 'Meta (Facebook)',
                'enabled' => (bool) get_option( 'servertrack_meta_enabled', 0 ),
                'status'  => get_option( 'servertrack_meta_pixel_id' ) ? 'Configured' : 'Missing Pixel ID',
                'today'   => $today_map['meta'],
            ],
            [
                'name'    => 'TikTok',
                'enabled' => (bool) get_option( 'servertrack_tiktok_enabled', 0 ),
                'status'  => get_option( 'servertrack_tiktok_pixel_id' ) ? 'Configured' : 'Missing Pixel ID',
                'today'   => $today_map['tiktok'],
            ],
            [
                'name'    => 'Google (GA4)',
                'enabled' => (bool) get_option( 'servertrack_google_enabled', 0 ),
                'status'  => get_option( 'servertrack_google_measurement_id' ) ? 'Configured' : 'Missing Measurement ID',
                'today'   => $today_map['google'],
            ],
        ];
    }
}
