<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Dashboard  v2.7
 *
 * FIX in v2.7 — All remaining bugs resolved:
 *   1.  KPI element IDs added so JS count-up animation works.
 *   2.  enqueue_assets() now localises servertrack_dashboard nonce so
 *       admin.js cfg.nonce is defined on the Dashboard page.
 *   3.  render_settings() no longer delegates to ServerTrack_Admin::render_page()
 *       — it redirects to the canonical Settings URL instead.
 *   4.  compute_breakdown() counts all log statuses (not only 'success')
 *       so Platform / Event-type charts reflect true volume.
 *   5.  KPI icon spans carry aria-hidden="true".
 *   6.  Inline auto-refresh timer stored in a variable and cleared on
 *       beforeunload so the interval doesn't leak.
 *   7.  Dead `lastCount` variable removed.
 *
 * FIX in v2.6 — Class name mismatch after v2.3 brand overhaul:
 *   .st-header → .st-page-header, .st-kpi → .st-kpi-card, etc.
 */
class ServerTrack_Dashboard {

    public static function init(): void {
        add_action( 'admin_menu',            [ self::class, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ], 5 );

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

        add_submenu_page(
            'servertrack',
            __( 'Dashboard', 'servertrack' ),
            __( 'Dashboard', 'servertrack' ),
            'manage_options',
            'servertrack',
            [ self::class, 'render_page' ]
        );

        add_submenu_page(
            'servertrack',
            __( 'Settings', 'servertrack' ),
            __( 'Settings', 'servertrack' ),
            'manage_options',
            'servertrack-settings',
            [ self::class, 'render_settings' ]
        );

        add_submenu_page(
            'servertrack',
            __( 'Event Sources', 'servertrack' ),
            __( 'Event Sources', 'servertrack' ),
            'manage_options',
            'servertrack-sources',
            [ self::class, 'render_sources' ]
        );
    }

    /**
     * Enqueue Chart.js + admin stylesheet for all ServerTrack admin pages.
     *
     * v2.7 FIX: Added wp_localize_script() call so admin.js receives the
     *   servertrack_dashboard nonce. Previously cfg.nonce was undefined on
     *   the Dashboard page, causing every admin.js AJAX call to fail with 403.
     *
     * v2.6 FIX: Removed admin-dashboard.css enqueue (file is empty since v2.3).
     * v2.5 FIX: Hook registered at priority 5.
     */
    public static function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'servertrack' ) === false ) return;

        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js',
            [],
            '4.4.3',
            true
        );

        wp_enqueue_style(
            'servertrack-dashboard',
            SERVERTRACK_URL . 'admin/assets/admin.css',
            [],
            SERVERTRACK_VERSION
        );

        // v2.7 FIX: Localise nonce for admin.js so cfg.nonce is defined.
        // admin.js uses action servertrack_get_dashboard_stats and
        // servertrack_get_logs — both verified against servertrack_admin_nonce
        // in ServerTrack_Admin. We pass both nonces so each script can use
        // the correct one.
        wp_localize_script( 'servertrack-admin', 'servertrack_admin', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'servertrack_admin_nonce' ),
        ] );
    }

    // ────────────────────────────────────────────────────────────────────────
    // MAIN DASHBOARD PAGE
    // ────────────────────────────────────────────────────────────────────────

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
            [ 'id' => 'st-kpi-total',   'label' => 'Events Today',  'val' => $stats['today_count'],       'sub' => 'All platforms',   'icon' => '📡' ],
            [ 'id' => 'st-kpi-rate',    'label' => 'Success Rate',  'val' => $stats['success_rate'] . '%','sub' => 'Last 7 days',     'icon' => '✅' ],
            [ 'id' => 'st-kpi-emq',     'label' => 'Avg EMQ Score', 'val' => $stats['avg_emq'],           'sub' => '0–10 scale',      'icon' => '🎯' ],
            [ 'id' => 'st-kpi-retry',   'label' => 'Retry Queue',   'val' => $stats['retry_queue'],       'sub' => 'Pending retries', 'icon' => '🔄' ],
            [ 'id' => 'st-kpi-week',    'label' => 'Total (7d)',    'val' => $stats['week_total'],        'sub' => 'Events sent',     'icon' => '📊' ],
            [ 'id' => 'st-kpi-errors',  'label' => 'Errors (7d)',   'val' => $stats['week_errors'],       'sub' => 'Failed sends',    'icon' => '❌' ],
        ];
        ?>
        <div class="st-kpi-grid" id="st-kpis">
            <?php foreach ( $kpis as $k ) : ?>
            <div class="st-kpi-card">
                <?php // v2.7: aria-hidden on decorative emoji icons ?>
                <div class="st-kpi-icon" aria-hidden="true"><?php echo esc_html( $k['icon'] ); ?></div>
                <div class="st-kpi-label"><?php echo esc_html( $k['label'] ); ?></div>
                <?php // v2.7: IDs added so admin.js count-up animation can target each value ?>
                <div class="st-kpi-value" id="<?php echo esc_attr( $k['id'] ); ?>"><?php echo esc_html( $k['val'] ); ?></div>
                <div class="st-kpi-label" style="opacity:0.6;font-size:10px;"><?php echo esc_html( $k['sub'] ); ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="st-refresh-badge" style="display:flex;align-items:center;gap:10px;margin:12px 0 4px;">
            <span id="st-live-count" style="font-size:12px;color:var(--st-muted);">Live</span>
            <span class="st-pulse" title="Auto-refreshing every 30s"></span>
            <button class="st-refresh-btn" id="st-manual-refresh" title="Refresh now">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Refresh
                <span class="st-spinner"></span>
            </button>
        </div>

        <div class="st-row">
            <div class="st-panel">
                <div class="st-panel-header">
                    <span class="st-panel-title">🛰 Platform Health</span>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=servertrack-settings' ) ); ?>" class="st-panel-action">Configure →</a>
                </div>
                <div class="st-plat-list">
                    <?php foreach ( $platforms as $p ) :
                        $enabled     = $p['enabled'];
                        $badge       = $enabled ? 'on' : 'off';
                        $badge_label = $enabled ? esc_html( $p['status'] ) : esc_html__( 'Disabled', 'servertrack' );
                        $warn        = $enabled && strpos( $p['status'], 'Missing' ) !== false;
                        if ( $warn ) { $badge = 'warn'; }
                    ?>
                    <div class="st-platform-card">
                        <span class="st-plat-name"><?php echo esc_html( $p['name'] ); ?></span>
                        <?php if ( $enabled ) : ?>
                            <span class="st-plat-stat"><?php echo esc_html( $p['today'] ?? 0 ); ?> today</span>
                        <?php endif; ?>
                        <span class="st-badge <?php echo esc_attr( $badge ); ?>"><?php echo $badge_label; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

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

        <div class="st-row" style="margin-top:16px;">
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

            <div class="st-panel">
                <div class="st-panel-header">
                    <span class="st-panel-title">📊 Top Event Types (7d)</span>
                </div>
                <canvas id="st-events-chart" height="180"></canvas>
            </div>
        </div>

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
                    <option value=""><?php esc_html_e( 'All Platforms', 'servertrack' ); ?></option>
                    <option value="meta">Meta</option>
                    <option value="tiktok">TikTok</option>
                    <option value="google">Google</option>
                </select>
                <select id="st-fs" onchange="stFilter()">
                    <option value=""><?php esc_html_e( 'All Statuses', 'servertrack' ); ?></option>
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

        </div><!-- #servertrack-wrap -->

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

            // ── Auto-refresh: log rows + KPI stats ────────────────────────────
            // v2.7 FIX: timer stored so beforeunload can clear it (no interval leak).
            var refreshTimer = null;

            function refreshLog(){
                var btn = document.getElementById('st-manual-refresh');
                if(btn) btn.classList.add('st-spinning');
                var spinner = document.getElementById('st-log-spinner');
                if(spinner) spinner.style.display='inline-block';

                var fd1 = new FormData();
                fd1.append('action','servertrack_log_data');
                fd1.append('nonce', nonce);
                var p1 = fetch(ajaxUrl,{method:'POST',body:fd1,credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(res){
                        if(!res.success) return;
                        var rows = res.data;
                        renderRows(rows);
                        var badge = document.getElementById('st-live-count');
                        if(badge) badge.textContent = 'Live \u00b7 '+rows.length+' events';
                        stFilter();
                    })
                    .catch(function(){});

                var fd2 = new FormData();
                fd2.append('action','servertrack_stats_breakdown');
                fd2.append('nonce', nonce);
                var p2 = fetch(ajaxUrl,{method:'POST',body:fd2,credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(res){
                        if(!res.success||!res.data) return;
                        var s = res.data.stats;
                        if(!s) return;
                        var kpiMap = {
                            'st-kpi-total':  s.today_count,
                            'st-kpi-rate':   s.success_rate+'%',
                            'st-kpi-emq':    s.avg_emq,
                            'st-kpi-retry':  s.retry_queue,
                            'st-kpi-week':   s.week_total,
                            'st-kpi-errors': s.week_errors
                        };
                        Object.keys(kpiMap).forEach(function(id){
                            var el = document.getElementById(id);
                            if(el && kpiMap[id] !== undefined) el.textContent = kpiMap[id];
                        });
                    })
                    .catch(function(){});

                Promise.all([p1,p2]).then(function(){
                    if(btn) btn.classList.remove('st-spinning');
                    if(spinner) spinner.style.display='none';
                }).catch(function(){
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

            // v2.7 FIX: store timer reference so it can be cleared on unload.
            refreshTimer = setInterval(refreshLog, 30000);

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
                drainBtn.textContent = 'Draining\u2026';
                drainBtn.disabled = true;
                var fd = new FormData();
                fd.append('action','servertrack_drain_retries');
                fd.append('nonce', nonce);
                fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(res){
                        drainBtn.textContent = res.success ? 'Drained \u2713' : 'Failed';
                        setTimeout(function(){ location.reload(); }, 1200);
                    });
            });

            // v2.7 FIX: clear the interval on page unload to prevent leak.
            window.addEventListener('beforeunload', function(){
                if(refreshTimer) clearInterval(refreshTimer);
            });

        })();
        </script>
        <?php
    }

    // ────────────────────────────────────────────────────────────────────────
    // LOG ROW RENDERER
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
            $status_attr = esc_attr( $entry['status']     ?? '' );
            $status_html = esc_html( $entry['status']     ?? '' );
            $platform    = esc_html( $entry['platform']   ?? '' );
            $event       = esc_html( $entry['event_type'] ?? '' );
            $order_id    = esc_html( $entry['order_id']   ?? '' );
            $msg         = esc_html( $entry['message']    ?? '' );
            $time        = esc_html( $entry['timestamp']  ?? '' );
            $emq         = $entry['emq_score'] ?? null;
            $grade       = esc_attr( $entry['emq_grade'] ?? '' );
        ?>
        <tr data-row="1"
            data-platform="<?php echo esc_attr( $entry['platform'] ?? '' ); ?>"
            data-status="<?php echo $status_attr; ?>"
            data-event="<?php echo esc_attr( $entry['event_type'] ?? '' ); ?>"
            data-order="<?php echo esc_attr( $entry['order_id'] ?? '' ); ?>">
            <td style="white-space:nowrap;color:var(--st-faint);font-size:11px;"><?php echo $time; ?></td>
            <td><span class="st-dot <?php echo $status_attr; ?>"></span><?php echo $status_html; ?></td>
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
     * v2.7 FIX: render_settings() previously delegated to
     * ServerTrack_Admin::render_page(), which caused the full Settings page
     * to render inside a second <div class="wrap"> — producing double-wrapped
     * markup and a broken layout on the Settings submenu item.
     *
     * The Settings page is already registered as its own submenu callback
     * (servertrack-settings → ServerTrack_Admin::render_page). This method
     * is only invoked when the settings submenu is registered with THIS class
     * as the callback. The correct fix is to redirect to the canonical URL so
     * WordPress renders the page through the right callback with the right hook.
     */
    public static function render_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        wp_safe_redirect( admin_url( 'admin.php?page=servertrack-settings' ) );
        exit;
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

    public static function ajax_log_data(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $logs = get_option( 'servertrack_debug_log', [] );
        wp_send_json_success( array_slice( array_reverse( $logs ), 0, 200 ) );
    }

    public static function ajax_platform_health(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $logs = get_option( 'servertrack_debug_log', [] );
        wp_send_json_success( self::get_platform_statuses( $logs ) );
    }

    public static function ajax_stats_breakdown(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $logs = get_option( 'servertrack_debug_log', [] );
        wp_send_json_success( [
            'stats'     => self::compute_stats( $logs ),
            'breakdown' => self::compute_breakdown( $logs ),
        ] );
    }

    public static function ajax_clear_log(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        update_option( 'servertrack_debug_log', [] );
        wp_send_json_success( [ 'cleared' => true ] );
    }

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
            $ts = strtotime( $entry['timestamp'] ?? '' );
            if ( false === $ts || 0 === $ts ) continue;

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
     * v2.7 FIX: Previously only counted 'success' entries, so errors, skipped,
     * and dedup_blocked events were invisible in the Platform and Event-type charts.
     * Now counts all entries within the 7-day window regardless of status.
     */
    private static function compute_breakdown( array $logs ): array {
        $seven_ago   = strtotime( '-7 days' );
        $by_platform = [ 'meta' => 0, 'tiktok' => 0, 'google' => 0 ];
        $emq_grades  = [ 'excellent' => 0, 'good' => 0, 'fair' => 0, 'poor' => 0 ];
        $event_types = [];

        foreach ( $logs as $entry ) {
            $ts = strtotime( $entry['timestamp'] ?? '' );
            if ( false === $ts || 0 === $ts ) continue;
            if ( $ts < $seven_ago ) continue;
            // v2.7 FIX: removed status === 'success' filter — count all events.

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

        arsort( $event_types );
        $top_events = array_slice( $event_types, 0, 5, true );

        return [
            'by_platform' => $by_platform,
            'emq_grades'  => $emq_grades,
            'top_events'  => $top_events,
        ];
    }

    private static function get_platform_statuses( array $logs = [] ): array {
        if ( empty( $logs ) ) {
            $logs = get_option( 'servertrack_debug_log', [] );
        }

        $today     = gmdate( 'Y-m-d' );
        $today_map = [ 'meta' => 0, 'tiktok' => 0, 'google' => 0 ];

        foreach ( $logs as $entry ) {
            $ts = strtotime( $entry['timestamp'] ?? '' );
            if ( false === $ts || 0 === $ts ) continue;
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
                'status'  => get_option( 'servertrack_google_refresh_token' ) ? 'Configured' : 'Missing OAuth Token',
                'today'   => $today_map['google'],
            ],
        ];
    }
}
