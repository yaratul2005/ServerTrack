<?php
/**
 * ServerTrack — Dashboard Overview Tab  v2.3
 *
 * Stable baseline view (reverted from broken v6.2.0)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$st_meta_configured   = get_option( 'servertrack_meta_enabled', 0 )
                        && get_option( 'servertrack_meta_pixel_id', '' )
                        && get_option( 'servertrack_meta_access_token', '' );
$st_google_configured = get_option( 'servertrack_google_enabled', 0 )
                        && get_option( 'servertrack_google_refresh_token', '' );
$st_tiktok_configured = get_option( 'servertrack_tiktok_enabled', 0 )
                        && get_option( 'servertrack_tiktok_pixel_id', '' )
                        && get_option( 'servertrack_tiktok_access_token', '' );
?>

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
                        <div class="st-platform-logo st-platform-logo-meta">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 2C6.477 2 2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.879V14.89h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.989C20.343 21.129 24 16.99 24 12c0-5.523-4.477-10-10-10z"/></svg>
                        </div>
                        <div>
                            <div class="st-platform-name"><?php esc_html_e( 'Meta CAPI', 'servertrack' ); ?></div>
                            <div class="st-platform-subtext"><?php esc_html_e( 'Conversions API', 'servertrack' ); ?></div>
                        </div>
                    </div>
                    <span class="st-status-pill <?php echo $st_meta_configured ? 'st-status-ok' : 'st-status-inactive'; ?>">
                        <span class="st-status-pill-dot"></span>
                        <?php echo $st_meta_configured ? esc_html__( 'Active', 'servertrack' ) : esc_html__( 'Inactive', 'servertrack' ); ?>
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
            </div>

            <!-- Google Ads -->
            <div class="st-platform-card">
                <div class="st-platform-card-header">
                    <div class="st-platform-identity">
                        <div class="st-platform-logo st-platform-logo-google">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M21.35 11.1H12.18V13.83H18.69C18.36 17.64 15.19 19.27 12.19 19.27C8.36 19.27 5 16.25 5 12C5 7.75 8.36 4.73 12.19 4.73C13.76 4.73 15.19 5.27 16.33 6.31L18.61 4.04C16.95 2.57 14.81 1.5 12.19 1.5C6.49 1.5 1.91 5.74 1.91 11.5C1.91 17.26 6.49 21.5 12.19 21.5C17.59 21.5 21.35 18.08 21.35 11.1Z"/></svg>
                        </div>
                        <div>
                            <div class="st-platform-name"><?php esc_html_e( 'Google Ads', 'servertrack' ); ?></div>
                            <div class="st-platform-subtext"><?php esc_html_e( 'Enhanced Conversions', 'servertrack' ); ?></div>
                        </div>
                    </div>
                    <span class="st-status-pill <?php echo $st_google_configured ? 'st-status-ok' : 'st-status-inactive'; ?>">
                        <span class="st-status-pill-dot"></span>
                        <?php echo $st_google_configured ? esc_html__( 'Active', 'servertrack' ) : esc_html__( 'Inactive', 'servertrack' ); ?>
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
            </div>

            <!-- TikTok -->
            <div class="st-platform-card">
                <div class="st-platform-card-header">
                    <div class="st-platform-identity">
                        <div class="st-platform-logo st-platform-logo-tiktok">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M19.321 5.562a5.124 5.124 0 0 1-.443-.258 6.228 6.228 0 0 1-1.137-.966c-.849-.971-1.166-1.956-.968-2.904.053-.258.128-.518.225-.773.167-.46.35-.918.558-1.357l.014-.032c.038-.08.076-.163.114-.245h-3.818c-.14.375-.287.762-.42 1.137a7.04 7.04 0 0 1-.36 1.08 4.96 4.96 0 0 1-3.09 2.615c-.196.07-.397.123-.6.162-.204.038-.41.059-.615.059H8.71v10.82c0 .916-.744 1.66-1.66 1.66a1.66 1.66 0 0 1-1.66-1.66V7.58h-1.5v9.12c0 1.775 1.445 3.22 3.22 3.22a3.22 3.22 0 0 0 3.22-3.22V8.7a7.89 7.89 0 0 0 4.52 1.44 7.81 7.81 0 0 0 3.22-.693v-3.38a4.08 4.08 0 0 1-1.212.175z"/></svg>
                        </div>
                        <div>
                            <div class="st-platform-name"><?php esc_html_e( 'TikTok Events', 'servertrack' ); ?></div>
                            <div class="st-platform-subtext"><?php esc_html_e( 'Events API', 'servertrack' ); ?></div>
                        </div>
                    </div>
                    <span class="st-status-pill <?php echo $st_tiktok_configured ? 'st-status-ok' : 'st-status-inactive'; ?>">
                        <span class="st-status-pill-dot"></span>
                        <?php echo $st_tiktok_configured ? esc_html__( 'Active', 'servertrack' ) : esc_html__( 'Inactive', 'servertrack' ); ?>
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
            </div>
        </div>
    </div>

    <!-- Activity Feed -->
    <div class="st-card">
        <h3 class="st-card-title">
            <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <?php esc_html_e( 'Recent Events', 'servertrack' ); ?>
        </h3>
        <ul class="st-activity-feed" id="st-activity-feed">
            <li style="padding: 20px; text-align: center; color: var(--st-text-muted);">
                <?php esc_html_e( 'No events yet.', 'servertrack' ); ?>
            </li>
        </ul>
    </div>
</div>
