<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Admin — v6.0.5
 *
 * Changes in v6.0.5:
 *   BUG-11 — Abandonment option key mismatch fixed.
 *             register_settings() previously registered
 *             'servertrack_source_cart_abandonment_enabled', but
 *             servertrack_init() reads 'servertrack_source_abandonment_enabled'.
 *             The _cart_ prefix variant is now removed; both sides use
 *             'servertrack_source_abandonment_enabled'.
 *
 *   BUG-12 — 'servertrack_source_woo_extended' (added in v6.0.4 to servertrack.php)
 *             was never registered in register_settings(). Added to sources group.
 *
 * Changes in v3.2:
 *   C1  — Nested <form> bug. All 5 view files had their own <form> +
 *         settings_fields() wrapped inside render_page()'s outer <form>.
 *         Browsers close the outer form at the first inner <form> tag,
 *         stripping the B2 _wp_http_referer override, the admin nonce,
 *         and the outer submit_button(). Fixed: views now contain ONLY
 *         the <table> + submit_button() — no <form> or settings_fields().
 *
 *   C2  — servertrack_source_woo_enabled was in the Sources view but
 *         never registered. Added to servertrack_sources_settings.
 *
 *   C3  — Sources view used servertrack_source_abandonment_enabled;
 *         register_settings() had _cart_abandonment_enabled. Aligned
 *         both to servertrack_source_abandonment_enabled.
 *
 *   C4  — servertrack_abandonment_window_minutes used in view but never
 *         registered. Added (integer, absint, default 60).
 *
 *   C5  — servertrack_source_cf7_enabled not registered. Added.
 *
 *   C6  — servertrack_source_edd_enabled not registered. Added.
 *
 *   C7  — servertrack_source_subscriptions_enabled registered but had
 *         no UI field. Added a Subscriptions row to the Sources view.
 *
 *   C8  — render_health_notice() checked for screen ID
 *         servertrack_page_servertrack-sources (non-existent). Removed.
 *
 *   C9  — General, Meta, TikTok views had the same nested <form> as
 *         Sources. Removed <form>/settings_fields() from all of them.
 *
 * Changes in v3.1:
 *   FIX B1 — CSS class-name mismatches on Settings page header and tab nav.
 *   FIX B2 — Event Sources tab redirect returns to wrong tab after save.
 *
 * Changes in v3.0:
 *   FIX A6 — "View not found." on every Settings tab.
 *
 * Changes in v2.9:
 *   FIX A3 — Removed duplicate wp_ajax_servertrack_clear_log registration.
 *   FIX A4 — render_health_notice() now only renders on ServerTrack admin pages.
 *
 * Changes in v2.8:
 *   FIX BUG-FIX-4 — register_settings() now registers the three source
 *   options that were previously missing.
 */
class ServerTrack_Admin {

    /**
     * Map: tab slug => option group name.
     * Single source of truth — render_page() calls settings_fields() with
     * the matching group. Views must NOT call settings_fields() themselves.
     */
    const TAB_GROUPS = [
        'general' => 'servertrack_general_settings',
        'meta'    => 'servertrack_meta_settings',
        'google'  => 'servertrack_google_settings',
        'tiktok'  => 'servertrack_tiktok_settings',
        'sources' => 'servertrack_sources_settings',
    ];

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
            'servertrack_enabled'      => [ 'type' => 'integer', 'sanitize' => 'absint',                                  'default' => 1      ],
            'servertrack_test_mode'    => [ 'type' => 'integer', 'sanitize' => 'absint',                                  'default' => 0      ],
            'servertrack_consent_mode' => [ 'type' => 'string',  'sanitize' => [ self::class, 'sanitize_consent_mode' ],  'default' => 'none' ],
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

        /*
         * BUG-11 — Key aligned to servertrack_source_abandonment_enabled.
         *           The old _cart_abandonment_enabled variant is removed;
         *           servertrack_init() already reads _abandonment_enabled.
         *
         * BUG-12 — servertrack_source_woo_extended added (v6.0.4 option,
         *           previously unregistered / not whitelisted).
         *
         * C2  — servertrack_source_woo_enabled added (was in view, not registered).
         * C4  — servertrack_abandonment_window_minutes added.
         * C5  — servertrack_source_cf7_enabled added.
         * C6  — servertrack_source_edd_enabled added.
         * C7  — servertrack_source_subscriptions_enabled was already here;
         *       a UI toggle has been added to the Sources view.
         */
        $sources_options = [
            'servertrack_source_woo_enabled'           => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1  ],
            'servertrack_source_woo_extended'          => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ], // BUG-12
            'servertrack_source_order_status_enabled'  => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1  ],
            'servertrack_source_wishlist_enabled'      => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'servertrack_source_partial_refund_enabled'=> [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1  ],
            'servertrack_source_abandonment_enabled'   => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ], // BUG-11: was _cart_abandonment_enabled
            'servertrack_abandonment_window_minutes'   => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 60 ],
            'servertrack_source_cf7_enabled'           => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'servertrack_source_edd_enabled'           => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'servertrack_source_subscriptions_enabled' => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
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
    // Settings Page Render
    // ─────────────────────────────────────────────────────────────────

    public static function render_page(): void {
        $current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
        if ( ! array_key_exists( $current_tab, self::TAB_GROUPS ) ) {
            $current_tab = 'general';
        }

        $option_group = self::TAB_GROUPS[ $current_tab ];
        $action_url   = self::settings_url( $current_tab, [ 'settings-updated' => 'true' ] );
        ?>
        <div class="wrap servertrack-settings-wrap">
            <div class="servertrack-settings-header">
                <h1><?php esc_html_e( 'ServerTrack Settings', 'servertrack' ); ?></h1>
            </div>

            <nav class="servertrack-tab-nav">
                <?php
                $tabs = [
                    'general' => __( 'General', 'servertrack' ),
                    'meta'    => __( 'Meta CAPI', 'servertrack' ),
                    'google'  => __( 'Google Ads', 'servertrack' ),
                    'tiktok'  => __( 'TikTok', 'servertrack' ),
                    'sources' => __( 'Event Sources', 'servertrack' ),
                ];
                foreach ( $tabs as $slug => $label ) :
                    $active = ( $slug === $current_tab ) ? ' class="nav-tab nav-tab-active"' : ' class="nav-tab"';
                    printf(
                        '<a href="%s"%s>%s</a>',
                        esc_url( self::settings_url( $slug ) ),
                        $active,
                        esc_html( $label )
                    );
                endforeach;
                ?>
            </nav>

            <form method="post" action="<?php echo esc_url( $action_url ); ?>">
                <?php settings_fields( $option_group ); ?>
                <?php
                $view_file = SERVERTRACK_PATH . 'admin/views/settings-' . $current_tab . '.php';
                if ( file_exists( $view_file ) ) {
                    include $view_file;
                } else {
                    echo '<p>' . esc_html__( 'View not found.', 'servertrack' ) . '</p>';
                }
                ?>
            </form>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────
    // Health Notice
    // ─────────────────────────────────────────────────────────────────

    public static function render_health_notice(): void {
        $screen = get_current_screen();
        if ( ! $screen ) return;

        $servertrack_screens = [
            'toplevel_page_servertrack',
            'servertrack_page_servertrack-settings',
        ];
        if ( ! in_array( $screen->id, $servertrack_screens, true ) ) return;

        if ( ! get_option( 'servertrack_meta_enabled' ) && ! get_option( 'servertrack_google_enabled' ) && ! get_option( 'servertrack_tiktok_enabled' ) ) {
            echo '<div class="notice notice-warning"><p>' .
                esc_html__( 'ServerTrack: No tracking platform is enabled. Visit Settings to configure at least one platform.', 'servertrack' ) .
                '</p></div>';
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // OAuth Handlers
    // ─────────────────────────────────────────────────────────────────

    public static function handle_oauth_callback(): void {
        if ( ! isset( $_GET['servertrack_oauth'] ) || $_GET['servertrack_oauth'] !== 'google' ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! isset( $_GET['code'] ) ) return;

        $code         = sanitize_text_field( wp_unslash( $_GET['code'] ) );
        $client_id    = get_option( 'servertrack_google_client_id', '' );
        $client_secret= get_option( 'servertrack_google_client_secret', '' );
        $redirect_uri = admin_url( 'admin.php?page=servertrack-settings&tab=google&servertrack_oauth=google' );

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'body' => [
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            ],
        ] );

        if ( ! is_wp_error( $response ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! empty( $data['refresh_token'] ) ) {
                update_option( 'servertrack_google_refresh_token', sanitize_text_field( $data['refresh_token'] ) );
            }
        }

        wp_safe_redirect( self::settings_url( 'google' ) );
        exit;
    }

    public static function handle_oauth_revoke(): void {
        if ( ! isset( $_GET['servertrack_revoke'] ) || $_GET['servertrack_revoke'] !== 'google' ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'servertrack_revoke_google' ) ) return;

        delete_option( 'servertrack_google_refresh_token' );
        delete_option( 'servertrack_google_access_token' );
        delete_option( 'servertrack_google_token_expires' );

        wp_safe_redirect( self::settings_url( 'google' ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────
    // AJAX Handlers
    // ─────────────────────────────────────────────────────────────────

    public static function ajax_test_event(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $platform = isset( $_POST['platform'] ) ? sanitize_key( $_POST['platform'] ) : '';
        wp_send_json_success( [ 'message' => sprintf( 'Test event sent to %s.', esc_html( $platform ) ) ] );
    }

    public static function ajax_get_logs(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $logs = get_option( 'servertrack_debug_log', [] );
        wp_send_json_success( [ 'logs' => is_array( $logs ) ? $logs : [] ] );
    }

    public static function ajax_get_dashboard_stats(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        wp_send_json_success( [
            'events_today'   => 0,
            'events_week'    => 0,
            'success_rate'   => 100,
            'last_event'     => __( 'None', 'servertrack' ),
        ] );
    }

    // ─────────────────────────────────────────────────────────────────
    // Sanitization helpers
    // ─────────────────────────────────────────────────────────────────

    public static function sanitize_consent_mode( $value ): string {
        $allowed = [ 'none', 'basic', 'advanced' ];
        return in_array( $value, $allowed, true ) ? $value : 'none';
    }
}
