<?php
/**
 * ServerTrack — Dashboard Overview Tab
 *
 * Shown as the first tab when the plugin admin page loads.
 * All live data (KPIs, activity) is fetched client-side via
 * AJAX (servertrack_get_dashboard_stats) so this file only
 * renders the skeleton HTML — no PHP data loops needed.
 *
 * v2.3: Replaced inline grid style with .st-dashboard-grid class
 *        so the responsive breakpoint in admin.css fires correctly.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Quick config check for header badges
$st_meta_configured   = get_option( 'servertrack_meta_enabled', 0 )
                        && get_option( 'servertrack_meta_pixel_id', '' )
                        && get_option( 'servertrack_meta_access_token', '' );
$st_google_configured = get_option( 'servertrack_google_enabled', 0 )
                        && get_option( 'servertrack_google_refresh_token', '' );
$st_tiktok_configured = get_option( 'servertrack_tiktok_enabled', 0 )
                        && get_option( 'servertrack_tiktok_pixel_id', '' )
                        && get_option( 'servertrack_tiktok_access_token', '' );
?>

<!-- KPI Cards -->
<div class="st-kpi-grid" id="st-kpi-grid">

    <div class="st-kpi-card">
        <div class="st-kpi-icon st-kpi-icon-teal">
            <svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        </div>
        <div class="st-kpi-value" id="st-kpi-total">
            <div class="st-skeleton st-skeleton-kpi-value"></div>
        </div>
        <div class="st-kpi-label" id="st-kpi-label-total">
            <div class="st-skeleton st-skeleton-kpi-label"></div>
        </div>
        <div class="st-kpi-trend st-kpi-trend-info"></div>
    </div>

    <div class="st-kpi-card">
        <div class="st-kpi-icon st-kpi-icon-green">
            <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div class="st-kpi-value" id="st-kpi-success">
            <div class="st-skeleton st-skeleton-kpi-value"></div>
        </div>
        <div class="st-kpi-label" id="st-kpi-label-success">
            <div class="st-skeleton st-skeleton-kpi-label"></div>
        </div>
        <div class="st-kpi-trend st-kpi-trend-success"></div>
    </div>

    <div class="st-kpi-card">
        <div class="st-kpi-icon st-kpi-icon-red">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div class="st-kpi-value" id="st-kpi-failed">
            <div class="st-skeleton st-skeleton-kpi-value"></div>
        </div>
        <div class="st-kpi-label" id="st-kpi-label-failed">
            <div class="st-skeleton st-skeleton-kpi-label"></div>
        </div>
        <div class="st-kpi-trend st-kpi-trend-error"></div>
    </div>

    <div class="st-kpi-card">
        <div class="st-kpi-icon st-kpi-icon-amber">
            <svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        </div>
        <div class="st-kpi-value" id="st-kpi-rate">
            <div class="st-skeleton st-skeleton-kpi-value"></div>
        </div>
        <div class="st-kpi-label" id="st-kpi-label-rate">
            <div class="st-skeleton st-skeleton-kpi-label"></div>
        </div>
        <div class="st-kpi-trend st-kpi-trend-amber"></div>
    </div>

</div><!-- /.st-kpi-grid -->

<!-- Two-column layout: Platform Health + Activity Feed -->
<!-- v2.3 FIX: use .st-dashboard-grid class (responsive breakpoints in admin.css)
     instead of the previous raw inline style that had no mobile fallback -->
<div class="st-dashboard-grid">

    <!-- Platform Health Cards -->
    <div>
        <div class="st-card-title" style="margin-bottom:12px">
            <svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:none;stroke:var(--st-brand);stroke-width:2;stroke-linecap:round;stroke-linejoin:round">
                <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
            </svg>
            <?php esc_html_e( 'Platform Status', 'servertrack' ); ?>
        </div>

        <div class="st-platform-grid">

            <!-- Meta CAPI -->
            <div class="st-platform-card">
                <div class="st-platform-card-header">
                    <div class="st-platform-identity">
                        <div class="st-platform-logo st-platform-logo-meta">f</div>
                        <div>
                            <div class="st-platform-name"><?php esc_html_e( 'Meta CAPI', 'servertrack' ); ?></div>
                            <div class="st-platform-subtext"><?php esc_html_e( 'Conversions API', 'servertrack' ); ?></div>
                        </div>
                    </div>
                    <span class="st-status-pill <?php echo $st_meta_configured ? 'st-status-ok' : ( get_option('servertrack_meta_enabled',0) ? 'st-status-warning' : 'st-status-inactive' ); ?>" id="st-health-pill-meta">
                        <span class="st-status-pill-dot"></span>
                        <?php echo $st_meta_configured ? esc_html__( 'Active', 'servertrack' ) : ( get_option('servertrack_meta_enabled',0) ? esc_html__( 'Setup Required', 'servertrack' ) : esc_html__( 'Inactive', 'servertrack' ) ); ?>
                    </span>
                </div>
                <div class="st-platform-stats">
                    <div class="st-platform-stat">
                        <div class="st-platform-stat-val" id="st-last-send-meta"><?php esc_html_e( '—', 'servertrack' ); ?></div>
                        <div class="st-platform-stat-key"><?php esc_html_e( 'Last Send', 'servertrack' ); ?></div>
                    </div>
                    <div class="st-platform-stat">
                        <div class="st-platform-stat-val"><?php echo $st_meta_configured ? esc_html__( 'Connected', 'servertrack' ) : esc_html__( 'Not set', 'servertrack' ); ?></div>
                        <div class="st-platform-stat-key"><?php esc_html_e( 'Token', 'servertrack' ); ?></div>
                    </div>
                </div>
                <button type="button" class="st-test-btn" data-platform="meta">
                    <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    <?php esc_html_e( 'Send Test → Meta', 'servertrack' ); ?>
                </button>
                <div class="st-test-result" id="st-test-result-meta"></div>
            </div>

            <!-- Google Ads -->
            <div class="st-platform-card">
                <div class="st-platform-card-header">
                    <div class="st-platform-identity">
                        <div class="st-platform-logo st-platform-logo-google">G</div>
                        <div>
                            <div class="st-platform-name"><?php esc_html_e( 'Google Ads', 'servertrack' ); ?></div>
                            <div class="st-platform-subtext"><?php esc_html_e( 'Enhanced Conversions', 'servertrack' ); ?></div>
                        </div>
                    </div>
                    <span class="st-status-pill <?php echo $st_google_configured ? 'st-status-ok' : ( get_option('servertrack_google_enabled',0) ? 'st-status-warning' : 'st-status-inactive' ); ?>" id="st-health-pill-google">
                        <span class="st-status-pill-dot"></span>
                        <?php echo $st_google_configured ? esc_html__( 'Active', 'servertrack' ) : ( get_option('servertrack_google_enabled',0) ? esc_html__( 'Setup Required', 'servertrack' ) : esc_html__( 'Inactive', 'servertrack' ) ); ?>
                    </span>
                </div>
                <div class="st-platform-stats">
                    <div class="st-platform-stat">
                        <div class="st-platform-stat-val" id="st-last-send-google"><?php esc_html_e( '—', 'servertrack' ); ?></div>
                        <div class="st-platform-stat-key"><?php esc_html_e( 'Last Send', 'servertrack' ); ?></div>
                    </div>
                    <div class="st-platform-stat">
                        <div class="st-platform-stat-val"><?php echo $st_google_configured ? esc_html__( 'OAuth OK', 'servertrack' ) : esc_html__( 'Not set', 'servertrack' ); ?></div>
                        <div class="st-platform-stat-key"><?php esc_html_e( 'OAuth', 'servertrack' ); ?></div>
                    </div>
                </div>
                <button type="button" class="st-test-btn" data-platform="google">
                    <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    <?php esc_html_e( 'Send Test → Google', 'servertrack' ); ?>
                </button>
                <div class="st-test-result" id="st-test-result-google"></div>
            </div>

            <!-- TikTok -->
            <div class="st-platform-card">
                <div class="st-platform-card-header">
                    <div class="st-platform-identity">
                        <div class="st-platform-logo st-platform-logo-tiktok">TT</div>
                        <div>
                            <div class="st-platform-name"><?php esc_html_e( 'TikTok Events', 'servertrack' ); ?></div>
                            <div class="st-platform-subtext"><?php esc_html_e( 'Events API', 'servertrack' ); ?></div>
                        </div>
                    </div>
                    <span class="st-status-pill <?php echo $st_tiktok_configured ? 'st-status-ok' : ( get_option('servertrack_tiktok_enabled',0) ? 'st-status-warning' : 'st-status-inactive' ); ?>" id="st-health-pill-tiktok">
                        <span class="st-status-pill-dot"></span>
                        <?php echo $st_tiktok_configured ? esc_html__( 'Active', 'servertrack' ) : ( get_option('servertrack_tiktok_enabled',0) ? esc_html__( 'Setup Required', 'servertrack' ) : esc_html__( 'Inactive', 'servertrack' ) ); ?>
                    </span>
                </div>
                <div class="st-platform-stats">
                    <div class="st-platform-stat">
                        <div class="st-platform-stat-val" id="st-last-send-tiktok"><?php esc_html_e( '—', 'servertrack' ); ?></div>
                        <div class="st-platform-stat-key"><?php esc_html_e( 'Last Send', 'servertrack' ); ?></div>
                    </div>
                    <div class="st-platform-stat">
                        <div class="st-platform-stat-val"><?php echo $st_tiktok_configured ? esc_html__( 'Connected', 'servertrack' ) : esc_html__( 'Not set', 'servertrack' ); ?></div>
                        <div class="st-platform-stat-key"><?php esc_html_e( 'Token', 'servertrack' ); ?></div>
                    </div>
                </div>
                <button type="button" class="st-test-btn" data-platform="tiktok">
                    <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    <?php esc_html_e( 'Send Test → TikTok', 'servertrack' ); ?>
                </button>
                <div class="st-test-result" id="st-test-result-tiktok"></div>
            </div>

        </div><!-- /.st-platform-grid -->
    </div><!-- /platform health col -->

    <!-- Activity Feed -->
    <div class="st-card">
        <h3 class="st-card-title">
            <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <?php esc_html_e( 'Recent Events', 'servertrack' ); ?>
        </h3>
        <ul class="st-activity-feed" id="st-activity-feed">
            <li class="st-loading-screen">
                <div class="st-spinner"></div>
                <div class="st-loading-text"><?php esc_html_e( 'Loading…', 'servertrack' ); ?></div>
            </li>
        </ul>
    </div>

</div><!-- /.st-dashboard-grid -->
