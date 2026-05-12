<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Admin — v2.9
 *
 * Changes in v2.9:
 *   FIX A3 — Removed duplicate wp_ajax_servertrack_clear_log registration.
 *     ServerTrack_Admin::init() previously registered ajax_clear_log alongside
 *     ServerTrack_Dashboard::init(). WordPress executes only the FIRST
 *     registered callback for a given action tag; the second is silently
 *     discarded. Because Dashboard registers its handler in its own init()
 *     call and owns the 'servertrack_dashboard' nonce that the JS sends,
 *     the Admin registration was the stale duplicate. It has been removed.
 *     Ownership of servertrack_clear_log now belongs exclusively to
 *     ServerTrack_Dashboard.
 *
 *   FIX A4 — render_health_notice() now only renders on ServerTrack admin
 *     pages. Previously it was hooked on 'admin_notices' with no page guard,
 *     causing the configuration warning to appear on the Dashboard page and
 *     every other WP admin screen. An early-return check on $screen->id now
 *     restricts the notice to 'servertrack_page_servertrack-settings' and
 *     'servertrack_page_servertrack-sources'.
 *
 * Changes in v2.8:
 *   FIX BUG-FIX-4 — register_settings() now registers the three source
 *   options that were previously missing:
 *     servertrack_source_order_status_enabled  (Order Status Events)
 *     servertrack_source_wishlist_enabled       (AddToWishlist Events)
 *     servertrack_source_partial_refund_enabled (Partial Refund Events)
 *   Without these registrations WordPress silently discarded any changes
 *   to these toggles on Settings save, making Order Status Events,
 *   AddToWishlist, and Partial Refund Events impossible to persistently
 *   enable or disable from the UI.
 *
 * Changes in v2.7:
 *   - ajax_get_logs(): Was returning wp_send_json_success( $logs ) — a raw
 *     PHP array. admin.js expected res.data.html (an HTML string of <tr> rows).
 *     Fix: render rows via ob_start() + ServerTrack_Dashboard::render_log_rows()
 *     and return { html: $html, total: $count } so admin.js can inject the
 *     HTML directly into #st-log-tbody.
 *
 * Changes in v2.6:
 *   - enqueue_assets(): Added 'dashboard_nonce' key to wp_localize_script so
 *     that dashboard AJAX actions (drain retries, manual refresh, clear log)
 *     can send the correct nonce expected by ServerTrack_Dashboard AJAX
 *     handlers which call check_ajax_referer( 'servertrack_dashboard', 'nonce' ).
 *     Previously the v3.0 Dashboard class fix removed the premature
 *     wp_localize_script call but never re-added the dashboard nonce, causing
 *     all dashboard AJAX requests to return HTTP 403 / -1.
 *
 * Changes in v2.5:
 *   - render_page_header() changed from private to public so that
 *     ServerTrack_Dashboard::render_page() can call it cross-class.
 *     Private visibility caused a PHP Fatal Error on the Dashboard page.
 *
 * Changes in v2.4:
 *   - render_page_header(): SVG placeholder icon replaced with the real
 *     bglogo.png (transparent-background logo) loaded via SERVERTRACK_URL.
 *     An onerror JS handler falls back to .st-logo-icon-fallback if the
 *     image cannot be fetched (e.g. during local dev with no assets).
 *   - Header now shows SERVERTRACK_VERSION as a small version badge.
 *
 * Changes in v2.3 (previous):
 *   - admin-dashboard.css styles merged into admin.css.
 *   - .st-dashboard-grid responsive class replaces inline grid style.
 *
 * Changes in v2.2:
 *   - CRITICAL FIX: All internal URLs updated from
 *     options-general.php?page=servertrack  (old Settings submenu)
 *     to admin.php?page=servertrack-settings  (current top-level submenu).
 *   - register_menu(): Removed stale add_options_page() registration.
 *   - enqueue_assets(): Hook check updated to match the new page hook.
 *   - handle_oauth_callback() / handle_oauth_revoke(): All wp_safe_redirect()
 *     calls updated.
 *   - render_page(): Tab hrefs updated.
 *   - render_health_notice(): All settings URLs updated.
 *   - Added ST_SETTINGS_URL helper constant for DRY URL construction.
 *
 * Changes from v1 → v2.0 (Dashboard overhaul):
 *   - New 'dashboard' tab, AJAX handler, dark gradient header strip.
 */
class ServerTrack_Admin {

    /**
     * Map: tab slug => option group name.
     * Single source of truth — views call settings_fields() with the matching group.
     */
    const TAB_GROUPS = [
        'general' => 'servertrack_general_settings',
        'meta'    => 'servertrack_meta_settings',
        'google'  => 'servertrack_google_settings',
        'tiktok'  => 'servertrack_tiktok_settings',
        'sources' => 'servertrack_sources_settings',
    ];

    /**
     * Base URL for the Settings sub-page.
     * Use this instead of hardcoding options-general.php or admin.php anywhere.
     */
    private static function settings_url( string $tab = '', array $extra = [] ): string {
        $args = array_merge( [ 'page' => 'servertrack-settings' ], $extra );
        if ( $tab !== '' ) {
            $args['tab'] = $tab;
        }
        return admin_url( 'admin.php?' . http_build_query( $args ) );
    }

    public static function init() {
        // NOTE: Menu registration is handled by ServerTrack_Dashboard::register_menu().
        // ServerTrack_Admin::register_menu() is intentionally removed in v2.2 to
        // avoid registering a duplicate/stale entry under Settings → Settings.
        add_action( 'admin_init',            [ self::class, 'register_settings' ] );
        add_action( 'admin_init',            [ self::class, 'handle_oauth_callback' ] );
        add_action( 'admin_init',            [ self::class, 'handle_oauth_revoke' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'admin_notices',         [ self::class, 'render_health_notice' ] );
        // A3 FIX: servertrack_clear_log is owned by ServerTrack_Dashboard (uses
        // 'servertrack_dashboard' nonce). The duplicate registration here was
        // silently discarded by WordPress (first-registered wins) and created
        // ambiguity about nonce ownership. Removed.
        add_action( 'wp_ajax_servertrack_test_event',          [ self::class, 'ajax_test_event' ] );
        add_action( 'wp_ajax_servertrack_get_logs',            [ self::class, 'ajax_get_logs' ] );
        add_action( 'wp_ajax_servertrack_get_dashboard_stats', [ self::class, 'ajax_get_dashboard_stats' ] );
    }

    // ─────────────────────────────────────────────────────────────────
    // Assets
    // ─────────────────────────────────────────────────────────────────

    /**
     * Enqueue admin CSS + JS.
     *
     * The Settings sub-page is registered under the ServerTrack top-level menu,
     * so its hook slug is 'servertrack_page_servertrack-settings', NOT
     * 'settings_page_servertrack' (which only fires for pages registered via
     * add_options_page()). We match both to be safe, and also accept the
     * top-level dashboard hook.
     *
     * v2.6 FIX: wp_localize_script now includes 'dashboard_nonce' in addition
     * to the existing 'nonce'. Dashboard AJAX handlers
     * (servertrack_stats_breakdown, servertrack_clear_log registered in
     * ServerTrack_Dashboard, servertrack_drain_retries) call
     * check_ajax_referer( 'servertrack_dashboard', 'nonce' ).  The JS must
     * send this separate nonce — not servertrack_admin_nonce — for those
     * requests.  Both nonces are now available on the global servertrack_admin
     * JS object:
     *   servertrack_admin.nonce           → 'servertrack_admin_nonce' (Settings/Tests)
     *   servertrack_admin.dashboard_nonce → 'servertrack_dashboard'   (Dashboard AJAX)
     */
    public static function enqueue_assets( string $hook ) {
        $allowed_hooks = [
            'settings_page_servertrack',            // legacy / fallback
            'servertrack_page_servertrack-settings', // current top-level submenu
            'toplevel_page_servertrack',             // dashboard top-level
        ];
        if ( ! in_array( $hook, $allowed_hooks, true ) ) return;

        wp_enqueue_style(
            'servertrack-admin',
            SERVERTRACK_URL . 'admin/assets/admin.css',
            [],
            SERVERTRACK_VERSION
        );
        wp_enqueue_script(
            'servertrack-admin',
            SERVERTRACK_URL . 'admin/assets/admin.js',
            [ 'jquery' ],
            SERVERTRACK_VERSION,
            true
        );
        wp_localize_script( 'servertrack-admin', 'servertrack_admin', [
            'ajax_url'        => admin_url( 'admin-ajax.php' ),

            // Nonce for Settings-page AJAX (test_event, get_logs,
            // get_dashboard_stats registered in ServerTrack_Admin).
            'nonce'           => wp_create_nonce( 'servertrack_admin_nonce' ),

            // v2.6 FIX — Nonce for Dashboard AJAX actions registered in
            // ServerTrack_Dashboard (stats_breakdown, drain_retries, clear_log,
            // platform_health, log_data).  The Dashboard render_page() also
            // creates this nonce inline for PHP use, but JS needs it here so
            // it is available before the DOM finishes rendering.
            'dashboard_nonce' => wp_create_nonce( 'servertrack_dashboard' ),

            'platforms' => [
                'meta'   => [
                    'enabled'    => (bool) get_option( 'servertrack_meta_enabled', 0 ),
                    'configured' => (bool) (
                        get_option( 'servertrack_meta_pixel_id', '' ) &&
                        get_option( 'servertrack_meta_access_token', '' )
                    ),
                ],
                'google' => [
                    'enabled'    => (bool) get_option( 'servertrack_google_enabled', 0 ),
                    'configured' => (bool) get_option( 'servertrack_google_refresh_token', '' ),
                ],
                'tiktok' => [
                    'enabled'    => (bool) get_option( 'servertrack_tiktok_enabled', 0 ),
                    'configured' => (bool) (
                        get_option( 'servertrack_tiktok_pixel_id', '' ) &&
                        get_option( 'servertrack_tiktok_access_token', '' )
                    ),
                ],
            ],
        ] );
    }

    // ─────────────────────────────────────────────────────────────────
    // Settings Registration
    // ─────────────────────────────────────────────────────────────────

    public static function register_settings() {

        // ── General tab ────────────────────────────────────────────
        $general_options = [
            'servertrack_enabled'      => [ 'type' => 'integer', 'sanitize' => 'absint',                              'default' => 1      ],
            'servertrack_test_mode'    => [ 'type' => 'integer', 'sanitize' => 'absint',                              'default' => 0      ],
            'servertrack_consent_mode' => [ 'type' => 'string',  'sanitize' => [ self::class, 'sanitize_consent_mode' ], 'default' => 'none' ],
        ];
        self::register_group( 'servertrack_general_settings', $general_options );

        // ── Meta CAPI tab ──────────────────────────────────────────
        $meta_options = [
            'servertrack_meta_enabled'         => [ 'type' => 'integer', 'sanitize' => 'absint',              'default' => 0  ],
            'servertrack_meta_pixel_id'        => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_meta_access_token'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_meta_test_event_code' => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
        ];
        self::register_group( 'servertrack_meta_settings', $meta_options );

        // ── Google Ads tab ─────────────────────────────────────────
        $google_options = [
            'servertrack_google_enabled'          => [ 'type' => 'integer', 'sanitize' => 'absint',              'default' => 0  ],
            'servertrack_google_conversion_id'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_conversion_label' => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_refresh_token'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_client_id'        => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_client_secret'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
        ];
        self::register_group( 'servertrack_google_settings', $google_options );

        // ── TikTok tab ─────────────────────────────────────────────
        $tiktok_options = [
            'servertrack_tiktok_enabled'      => [ 'type' => 'integer', 'sanitize' => 'absint',              'default' => 0  ],
            'servertrack_tiktok_pixel_id'     => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_tiktok_access_token' => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
        ];
        self::register_group( 'servertrack_tiktok_settings', $tiktok_options );

        // ── Sources tab ────────────────────────────────────────────
        // v2.8 FIX: These three options were previously unregistered, causing
        // WordPress to silently discard saves for Order Status Events,
        // AddToWishlist, and Partial Refund Events.
        $sources_options = [
            'servertrack_source_order_status_enabled'  => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0 ],
            'servertrack_source_wishlist_enabled'      => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0 ],
            'servertrack_source_partial_refund_enabled'=> [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0 ],
            'servertrack_source_cart_abandonment_enabled' => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0 ],
            'servertrack_source_subscriptions_enabled'    => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0 ],
        ];
        self::register_group( 'servertrack_sources_settings', $sources_options );
    }

    /**
     * Helper: register_setting() for every key in $options under $group.
     */
    private static function register_group( string $group, array $options ): void {
        foreach ( $options as $key => $args ) {
            register_setting(
                $group,
                $key,
                [
                    'type'              => $args['type'],
                    'sanitize_callback' => $args['sanitize'],
                    'default'           => $args['default'],
                ]
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // OAuth callbacks
    // ─────────────────────────────────────────────────────────────────

    public static function handle_oauth_callback(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty( $_GET['servertrack_oauth'] ) || empty( $_GET['code'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
        if ( class_exists( 'ServerTrack_Google_OAuth' ) ) {
            $result = ServerTrack_Google_OAuth::exchange_code( $code );
            $tab    = $result ? 'google' : 'google';
            $extra  = $result ? [ 'oauth' => 'success' ] : [ 'oauth' => 'error' ];
        } else {
            $extra = [ 'oauth' => 'error' ];
            $tab   = 'google';
        }
        wp_safe_redirect( self::settings_url( $tab, $extra ) );
        exit;
    }

    public static function handle_oauth_revoke(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty( $_GET['servertrack_revoke'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        check_admin_referer( 'servertrack_revoke_google' );
        if ( class_exists( 'ServerTrack_Google_OAuth' ) ) {
            ServerTrack_Google_OAuth::revoke();
        }
        wp_safe_redirect( self::settings_url( 'google', [ 'revoked' => '1' ] ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────
    // Health Notice
    // ─────────────────────────────────────────────────────────────────

    /**
     * Show a configuration warning when a platform is enabled but not fully
     * configured.
     *
     * A4 FIX (v2.9): Added page-scope guard. Previously this hook ran on every
     * admin screen (the Dashboard page, post editor, WooCommerce screens, etc.)
     * because there was no $screen->id check. The notice is now restricted to
     * the ServerTrack Settings and Sources sub-pages only, where it is
     * actionable. It is intentionally suppressed on the Dashboard page
     * ('toplevel_page_servertrack') to avoid visual clutter alongside the
     * Platform Health panel which already surfaces missing-credential warnings.
     */
    public static function render_health_notice(): void {
        // A4 FIX: Only show on ServerTrack Settings / Sources pages.
        $screen = get_current_screen();
        $allowed_screens = [
            'servertrack_page_servertrack-settings',
            'servertrack_page_servertrack-sources',
            // Legacy hook slug (add_options_page path).
            'settings_page_servertrack',
        ];
        if ( ! $screen || ! in_array( $screen->id, $allowed_screens, true ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $issues = [];

        if ( get_option( 'servertrack_meta_enabled', 0 ) ) {
            if ( ! get_option( 'servertrack_meta_pixel_id', '' ) || ! get_option( 'servertrack_meta_access_token', '' ) ) {
                $issues[] = sprintf(
                    'Meta CAPI is enabled but missing credentials. <a href="%s">Configure Meta →</a>',
                    esc_url( self::settings_url( 'meta' ) )
                );
            }
        }

        if ( get_option( 'servertrack_google_enabled', 0 ) ) {
            if ( ! get_option( 'servertrack_google_refresh_token', '' ) ) {
                $issues[] = sprintf(
                    'Google Ads is enabled but not authenticated. <a href="%s">Configure Google →</a>',
                    esc_url( self::settings_url( 'google' ) )
                );
            }
        }

        if ( get_option( 'servertrack_tiktok_enabled', 0 ) ) {
            if ( ! get_option( 'servertrack_tiktok_pixel_id', '' ) || ! get_option( 'servertrack_tiktok_access_token', '' ) ) {
                $issues[] = sprintf(
                    'TikTok Events is enabled but missing credentials. <a href="%s">Configure TikTok →</a>',
                    esc_url( self::settings_url( 'tiktok' ) )
                );
            }
        }

        if ( empty( $issues ) ) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible"><p><strong>ServerTrack:</strong></p><ul>';
        foreach ( $issues as $issue ) {
            echo '<li>' . wp_kses( $issue, [ 'a' => [ 'href' => [] ] ] ) . '</li>';
        }
        echo '</ul></div>';
    }

    // ─────────────────────────────────────────────────────────────────
    // Page Header (shared between Dashboard and Settings views)
    // ─────────────────────────────────────────────────────────────────

    public static function render_page_header(): void {
        ?>
        <div class="st-header">
            <div class="st-header-inner">
                <div class="st-logo">
                    <img
                        src="<?php echo esc_url( SERVERTRACK_URL . 'admin/assets/bglogo.png' ); ?>"
                        alt="ServerTrack"
                        width="32"
                        height="32"
                        class="st-logo-img"
                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                    />
                    <span class="st-logo-icon-fallback" style="display:none;">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
                        </svg>
                    </span>
                    <span class="st-logo-name">ServerTrack</span>
                    <span class="st-version-badge"><?php echo esc_html( SERVERTRACK_VERSION ); ?></span>
                </div>
                <nav class="st-header-nav">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=servertrack' ) ); ?>"
                       class="st-nav-link<?php echo ( isset( $_GET['page'] ) && $_GET['page'] === 'servertrack' ) ? ' active' : ''; // phpcs:ignore ?>"
                    ><?php esc_html_e( 'Dashboard', 'servertrack' ); ?></a>
                    <a href="<?php echo esc_url( self::settings_url() ); ?>"
                       class="st-nav-link<?php echo ( isset( $_GET['page'] ) && $_GET['page'] === 'servertrack-settings' ) ? ' active' : ''; // phpcs:ignore ?>"
                    ><?php esc_html_e( 'Settings', 'servertrack' ); ?></a>
                </nav>
            </div>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────
    // Settings Page
    // ─────────────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
        if ( ! array_key_exists( $tab, self::TAB_GROUPS ) ) {
            $tab = 'general';
        }
        ?>
        <div class="wrap" id="servertrack-wrap">
        <?php self::render_page_header(); ?>

        <nav class="st-tabs">
            <?php
            $tabs = [
                'general' => __( 'General', 'servertrack' ),
                'meta'    => __( 'Meta CAPI', 'servertrack' ),
                'google'  => __( 'Google Ads', 'servertrack' ),
                'tiktok'  => __( 'TikTok', 'servertrack' ),
                'sources' => __( 'Event Sources', 'servertrack' ),
            ];
            foreach ( $tabs as $slug => $label ) :
                $url     = esc_url( self::settings_url( $slug ) );
                $current = ( $tab === $slug ) ? ' class="st-tab-active"' : '';
            ?>
            <a href="<?php echo $url; ?>"<?php echo $current; ?>><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>

        <form method="post" action="options.php" class="st-settings-form">
            <?php
            settings_fields( self::TAB_GROUPS[ $tab ] );
            $view = plugin_dir_path( __FILE__ ) . 'views/tab-' . $tab . '.php';
            if ( file_exists( $view ) ) {
                include $view;
            } else {
                echo '<p>' . esc_html__( 'View not found.', 'servertrack' ) . '</p>';
            }
            submit_button();
            ?>
        </form>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────
    // Sanitizers
    // ─────────────────────────────────────────────────────────────────

    public static function sanitize_consent_mode( $value ): string {
        $allowed = [ 'none', 'manual', 'cookieyes', 'complianz' ];
        return in_array( $value, $allowed, true ) ? $value : 'none';
    }

    // ─────────────────────────────────────────────────────────────────
    // AJAX Handlers
    // ─────────────────────────────────────────────────────────────────

    public static function ajax_test_event(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $platform   = isset( $_POST['platform'] ) ? sanitize_key( wp_unslash( $_POST['platform'] ) ) : '';
        $event_name = isset( $_POST['event_name'] ) ? sanitize_text_field( wp_unslash( $_POST['event_name'] ) ) : 'Purchase';

        $allowed_platforms = [ 'meta', 'google', 'tiktok' ];
        if ( ! in_array( $platform, $allowed_platforms, true ) ) {
            wp_send_json_error( 'Invalid platform.' );
        }

        $event_id = ServerTrack_Dedup::generate_event_id();
        $event    = ( new ServerTrack_Event( $event_name, $event_id ) )
            ->set_custom_data( [ 'value' => 1.00, 'currency' => 'USD' ] );

        $result = [];
        if ( 'meta' === $platform && class_exists( 'ServerTrack_Meta' ) ) {
            $result = ServerTrack_Meta::send( $event );
        } elseif ( 'google' === $platform && class_exists( 'ServerTrack_Google' ) ) {
            $result = ServerTrack_Google::send( $event );
        } elseif ( 'tiktok' === $platform && class_exists( 'ServerTrack_TikTok' ) ) {
            $result = ServerTrack_TikTok::send( $event );
        }

        if ( ! empty( $result['status'] ) && 'success' === $result['status'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    public static function ajax_get_logs(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $logs   = get_option( 'servertrack_debug_log', [] );
        $recent = array_slice( array_reverse( $logs ), 0, 200 );
        $count  = count( $logs );

        ob_start();
        ServerTrack_Dashboard::render_log_rows( $recent );
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html, 'total' => $count ] );
    }

    public static function ajax_get_dashboard_stats(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        $logs = get_option( 'servertrack_debug_log', [] );
        wp_send_json_success( [
            'total'   => count( $logs ),
            'today'   => count( array_filter( $logs, fn( $e ) => substr( $e['timestamp'] ?? '', 0, 10 ) === gmdate( 'Y-m-d' ) ) ),
            'errors'  => count( array_filter( $logs, fn( $e ) => ( $e['status'] ?? '' ) === 'error' ) ),
        ] );
    }
}
