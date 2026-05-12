<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Admin — v3.1
 *
 * Changes in v3.1:
 *   FIX B1 — CSS class-name mismatches on Settings page header and tab nav.
 *     render_page_header() was emitting:
 *       .st-header, .st-header-inner, .st-logo-name, .st-version-badge
 *     but admin.css only defines:
 *       .st-page-header, .st-page-header-left, .st-page-title-group,
 *       .st-header-version
 *     The header rendered completely unstyled (no dark gradient, no flex
 *     layout, no logo drop-shadow).
 *
 *     render_page() tab nav was emitting:
 *       <nav class="st-tabs"> … class="st-tab-active"
 *     but admin.css targets:
 *       .st-tab-nav  .nav-tab  .nav-tab-active
 *     The pill-style tab bar was therefore unstyled — just plain links.
 *
 *     Fix: align PHP output to match the class names already defined in
 *     admin.css (.st-page-header, .st-page-header-left, .st-page-title-group,
 *     .st-header-version, .st-tab-nav, .nav-tab, .nav-tab-active).
 *
 *   FIX B2 — Event Sources tab appeared broken / saves not persisting.
 *     settings_fields() injects a hidden _wp_http_referer pointing to the
 *     current REQUEST_URI.  When the form is submitted WordPress redirects to
 *     the options.php success URL which does NOT carry ?tab=sources, so the
 *     user lands back on the General tab every time — making Sources look
 *     like it does nothing.  A hidden <input name="_wp_http_referer"> override
 *     added after settings_fields() corrects the redirect target to
 *     ?page=servertrack-settings&tab={$tab}.  This fix applies to all tabs
 *     so that every save returns to the same tab that was being edited.
 *
 * Changes in v3.0:
 *   FIX A6 — "View not found." on every Settings tab.
 *     render_page() was building the view path as:
 *       'views/tab-' . $tab . '.php'
 *     but all view files in admin/views/ are named:
 *       'settings-' . $tab . '.php'
 *     Fixed by changing the prefix from 'tab-' to 'settings-'.
 *
 * Changes in v2.9:
 *   FIX A3 — Removed duplicate wp_ajax_servertrack_clear_log registration.
 *   FIX A4 — render_health_notice() now only renders on ServerTrack admin pages.
 *
 * Changes in v2.8:
 *   FIX BUG-FIX-4 — register_settings() now registers the three source
 *   options that were previously missing.
 *
 * Changes in v2.7:
 *   - ajax_get_logs(): fixed return shape to { html, total }.
 *
 * Changes in v2.6:
 *   - enqueue_assets(): Added 'dashboard_nonce' to wp_localize_script.
 *
 * Changes in v2.5:
 *   - render_page_header() changed from private to public.
 *
 * Changes in v2.4:
 *   - render_page_header(): real bglogo.png, onerror fallback, version badge.
 *
 * Changes in v2.3:
 *   - admin-dashboard.css merged into admin.css.
 *   - .st-dashboard-grid responsive class.
 *
 * Changes in v2.2:
 *   - All internal URLs updated to admin.php?page=servertrack-settings.
 *   - ST_SETTINGS_URL helper.
 *
 * Changes from v1 → v2.0:
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
     */
    private static function settings_url( string $tab = '', array $extra = [] ): string {
        $args = array_merge( [ 'page' => 'servertrack-settings' ], $extra );
        if ( $tab !== '' ) {
            $args['tab'] = $tab;
        }
        return admin_url( 'admin.php?' . http_build_query( $args ) );
    }

    public static function init() {
        add_action( 'admin_init',            [ self::class, 'register_settings' ] );
        add_action( 'admin_init',            [ self::class, 'handle_oauth_callback' ] );
        add_action( 'admin_init',            [ self::class, 'handle_oauth_revoke' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'admin_notices',         [ self::class, 'render_health_notice' ] );
        add_action( 'wp_ajax_servertrack_test_event',          [ self::class, 'ajax_test_event' ] );
        add_action( 'wp_ajax_servertrack_get_logs',            [ self::class, 'ajax_get_logs' ] );
        add_action( 'wp_ajax_servertrack_get_dashboard_stats', [ self::class, 'ajax_get_dashboard_stats' ] );
    }

    // ─────────────────────────────────────────────────────────────────
    // Assets
    // ─────────────────────────────────────────────────────────────────

    public static function enqueue_assets( string $hook ) {
        $allowed_hooks = [
            'settings_page_servertrack',
            'servertrack_page_servertrack-settings',
            'toplevel_page_servertrack',
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
            'nonce'           => wp_create_nonce( 'servertrack_admin_nonce' ),
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

        $general_options = [
            'servertrack_enabled'      => [ 'type' => 'integer', 'sanitize' => 'absint',                              'default' => 1      ],
            'servertrack_test_mode'    => [ 'type' => 'integer', 'sanitize' => 'absint',                              'default' => 0      ],
            'servertrack_consent_mode' => [ 'type' => 'string',  'sanitize' => [ self::class, 'sanitize_consent_mode' ], 'default' => 'none' ],
        ];
        self::register_group( 'servertrack_general_settings', $general_options );

        $meta_options = [
            'servertrack_meta_enabled'         => [ 'type' => 'integer', 'sanitize' => 'absint',              'default' => 0  ],
            'servertrack_meta_pixel_id'        => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_meta_access_token'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_meta_test_event_code' => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
        ];
        self::register_group( 'servertrack_meta_settings', $meta_options );

        $google_options = [
            'servertrack_google_enabled'          => [ 'type' => 'integer', 'sanitize' => 'absint',              'default' => 0  ],
            'servertrack_google_conversion_id'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_conversion_label' => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_refresh_token'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_client_id'        => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_client_secret'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
        ];
        self::register_group( 'servertrack_google_settings', $google_options );

        $tiktok_options = [
            'servertrack_tiktok_enabled'      => [ 'type' => 'integer', 'sanitize' => 'absint',              'default' => 0  ],
            'servertrack_tiktok_pixel_id'     => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_tiktok_access_token' => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
        ];
        self::register_group( 'servertrack_tiktok_settings', $tiktok_options );

        // v2.8 FIX: previously unregistered options.
        $sources_options = [
            'servertrack_source_order_status_enabled'     => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0 ],
            'servertrack_source_wishlist_enabled'         => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0 ],
            'servertrack_source_partial_refund_enabled'   => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0 ],
            'servertrack_source_cart_abandonment_enabled' => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0 ],
            'servertrack_source_subscriptions_enabled'    => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0 ],
        ];
        self::register_group( 'servertrack_sources_settings', $sources_options );
    }

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
            $tab    = 'google';
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

    public static function render_health_notice(): void {
        $screen = get_current_screen();
        $allowed_screens = [
            'servertrack_page_servertrack-settings',
            'servertrack_page_servertrack-sources',
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
    // Page Header
    // B1 FIX: Emit class names that match admin.css:
    //   .st-page-header  (was .st-header)
    //   .st-page-header-left  (was .st-header-inner)
    //   .st-page-title-group  (was inline span)
    //   .st-header-version  (was .st-version-badge)
    // ─────────────────────────────────────────────────────────────────

    public static function render_page_header(): void {
        ?>
        <div class="st-page-header">
            <div class="st-page-header-left">
                <img
                    src="<?php echo esc_url( SERVERTRACK_URL . 'admin/assets/bglogo.png' ); ?>"
                    alt="ServerTrack"
                    width="46"
                    height="46"
                    class="st-logo-img"
                    onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                />
                <span class="st-logo-icon-fallback" style="display:none;">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
                    </svg>
                </span>
                <div class="st-page-title-group">
                    <h1>ServerTrack</h1>
                    <p>Server-Side Tracking</p>
                </div>
            </div>
            <div class="st-header-badges">
                <span class="st-header-version"><?php echo esc_html( 'v' . SERVERTRACK_VERSION ); ?></span>
                <nav>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=servertrack' ) ); ?>"
                       style="color:rgba(255,255,255,.6);text-decoration:none;font-size:.8125rem;margin-right:12px;"
                    ><?php esc_html_e( 'Dashboard', 'servertrack' ); ?></a>
                    <a href="<?php echo esc_url( self::settings_url() ); ?>"
                       style="color:rgba(255,255,255,.6);text-decoration:none;font-size:.8125rem;"
                    ><?php esc_html_e( 'Settings', 'servertrack' ); ?></a>
                </nav>
            </div>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────
    // Settings Page
    // B1 FIX: Tab nav now emits .st-tab-nav / .nav-tab / .nav-tab-active
    //         to match admin.css selectors (was .st-tabs / .st-tab-active).
    // B2 FIX: A hidden _wp_http_referer override is injected after
    //         settings_fields() so options.php redirects back to the
    //         correct ?tab={$tab} URL after saving.
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

        <?php
        // B1 FIX: class="st-tab-nav" + .nav-tab / .nav-tab-active
        // (previous: class="st-tabs" + .st-tab-active — unmatched by CSS)
        ?>
        <nav class="st-tab-nav">
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
                $classes = 'nav-tab' . ( $tab === $slug ? ' nav-tab-active' : '' );
            ?>
            <a href="<?php echo $url; ?>" class="<?php echo esc_attr( $classes ); ?>"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>

        <form method="post" action="options.php" class="st-settings-form">
            <?php
            settings_fields( self::TAB_GROUPS[ $tab ] );

            /*
             * B2 FIX — Post-save redirect returns to wrong tab.
             * settings_fields() injects a hidden _wp_http_referer equal to
             * the current REQUEST_URI.  options.php uses that value as the
             * redirect destination after saving.  Without ?tab=X in the
             * referer URL the user always lands on the General tab.
             * Overriding _wp_http_referer here forces options.php to
             * redirect back to the tab that is currently being saved.
             */
            $return_url = self::settings_url( $tab );
            echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( $return_url ) . '" />';
            ?>

            <?php
            // A6 FIX (v3.0): files are named 'settings-{tab}.php', not 'tab-{tab}.php'.
            $view = plugin_dir_path( __FILE__ ) . 'views/settings-' . $tab . '.php';
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

        wp_send_json_success( [
            'html'  => $html,
            'total' => $count,
        ] );
    }

    public static function ajax_get_dashboard_stats(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $stats = get_option( 'servertrack_stats', [] );
        wp_send_json_success( $stats );
    }
}
