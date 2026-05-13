<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Admin — v6.3.0
 *
 * v6.3.0 — Routing & backend–frontend wiring fixes:
 *
 *   FIX-RT-1 — render_page() defaulted $current_tab to 'general' even when
 *              the URL was ?page=servertrack-sources (no tab param). Now
 *              auto-maps page slug → tab: servertrack-sources → 'sources',
 *              servertrack-settings → falls back to tab param or 'general'.
 *
 *   FIX-RT-2 — register_menu() pointed Event Sources submenu to
 *              ServerTrack_Admin::render_page() which always showed the full
 *              settings chrome (tabs, form, header). Unified: both
 *              servertrack-settings AND servertrack-sources now call
 *              render_page() but render_page() detects the page slug so the
 *              correct tab content loads without tabs showing on sources page.
 *              Kept separate page slugs so WP builds the correct screen IDs
 *              for enqueue_assets() hooks.
 *
 *   FIX-SK-1 — settings-sources.php uses name="servertrack_source_cart_abandonment_enabled"
 *              but register_settings() registered servertrack_source_abandonment_enabled.
 *              Fixed register_settings() key to match the view:
 *              servertrack_source_cart_abandonment_enabled.
 *
 *   FIX-BF-1 — ajax_get_dashboard_stats returned events_today/events_week
 *              but dashboard.php called #st-kpi-total / #st-kpi-rate etc.
 *              Added full KPI payload (today_count, week_total, success_rate,
 *              avg_emq, retry_queue, week_errors) to match render_page() IDs.
 *
 *   FIX-BF-2 — wp_localize_script 'servertrack_admin' was missing
 *              'platforms' data on the servertrack-sources hook so admin.js
 *              platform checks threw undefined. Already present but verified
 *              all three hooks receive localize data.
 *
 * v6.2.0 — GAP-1…GAP-8 fixes (see previous changelog).
 * v6.1.0 — FIX-DR-1…FIX-DR-4, CRASH-FIX.
 */
class ServerTrack_Admin {

    const TAB_GROUPS = [
        'general' => 'servertrack_general_settings',
        'meta'    => 'servertrack_meta_settings',
        'google'  => 'servertrack_google_settings',
        'tiktok'  => 'servertrack_tiktok_settings',
        'sources' => 'servertrack_sources_settings',
    ];

    // ─── Token helpers ──────────────────────────────────────────────────────

    private static function encrypt_token( string $plaintext ): string {
        $key    = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
        $iv     = random_bytes( 16 );
        $cipher = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        if ( false === $cipher ) return $plaintext;
        return base64_encode( $iv . $cipher );
    }

    public static function decrypt_token( string $stored ): string {
        $key = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
        $raw = base64_decode( $stored, true );
        if ( false === $raw || strlen( $raw ) < 17 ) return $stored;
        $iv         = substr( $raw, 0, 16 );
        $ciphertext = substr( $raw, 16 );
        $plain      = openssl_decrypt( $ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return ( false !== $plain ) ? $plain : $stored;
    }

    public static function sanitize_refresh_token( $value ): string {
        $value = sanitize_text_field( $value );
        if ( '' === $value ) return '';
        $raw = base64_decode( $value, true );
        if ( false !== $raw && strlen( $raw ) >= 17 ) return $value;
        return self::encrypt_token( $value );
    }

    // ─── URL helper ─────────────────────────────────────────────────────────

    private static function settings_url( string $tab = '', array $extra = [] ): string {
        $args = array_merge( [ 'page' => 'servertrack-settings' ], $extra );
        if ( $tab !== '' ) $args['tab'] = $tab;
        return admin_url( 'admin.php?' . http_build_query( $args ) );
    }

    // ─── Init ───────────────────────────────────────────────────────────────

    public static function init() {
        add_action( 'admin_init',            [ self::class, 'register_settings' ] );
        add_action( 'admin_init',            [ self::class, 'handle_oauth_start' ] );
        add_action( 'admin_init',            [ self::class, 'handle_oauth_callback' ] );
        add_action( 'admin_init',            [ self::class, 'handle_oauth_revoke' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'admin_notices',         [ self::class, 'render_health_notice' ] );
        add_action( 'wp_ajax_servertrack_test_event',          [ self::class, 'ajax_test_event' ] );
        add_action( 'wp_ajax_servertrack_get_logs',            [ self::class, 'ajax_get_logs' ] );
        add_action( 'wp_ajax_servertrack_clear_log',           [ self::class, 'ajax_clear_log' ] );
        add_action( 'wp_ajax_servertrack_get_dashboard_stats', [ self::class, 'ajax_get_dashboard_stats' ] );
    }

    // ─── Assets ─────────────────────────────────────────────────────────────

    public static function enqueue_assets( string $hook ) {
        $allowed_hooks = [
            'servertrack_page_servertrack-settings',
            'servertrack_page_servertrack-sources',
            'toplevel_page_servertrack',
        ];
        if ( ! in_array( $hook, $allowed_hooks, true ) ) return;

        wp_enqueue_style(
            'servertrack-admin-dashboard',
            SERVERTRACK_URL . 'admin/assets/admin-dashboard.css',
            [],
            SERVERTRACK_VERSION
        );
        wp_enqueue_style(
            'servertrack-admin',
            SERVERTRACK_URL . 'admin/assets/admin.css',
            [ 'servertrack-admin-dashboard' ],
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

    // ─── Settings Registration ──────────────────────────────────────────────

    public static function register_settings() {
        $general_options = [
            'servertrack_enabled'      => [ 'type' => 'integer', 'sanitize' => 'absint',                                 'default' => 1      ],
            'servertrack_test_mode'    => [ 'type' => 'integer', 'sanitize' => 'absint',                                 'default' => 0      ],
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
            'servertrack_google_enabled'          => [ 'type' => 'integer', 'sanitize' => 'absint',                                  'default' => 0  ],
            'servertrack_google_conversion_id'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field',                     'default' => '' ],
            'servertrack_google_conversion_label' => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field',                     'default' => '' ],
            'servertrack_google_refresh_token'    => [ 'type' => 'string',  'sanitize' => [ self::class, 'sanitize_refresh_token' ],  'default' => '' ],
            'servertrack_google_client_id'        => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field',                     'default' => '' ],
            'servertrack_google_client_secret'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field',                     'default' => '' ],
        ];
        self::register_group( 'servertrack_google_settings', $google_options );

        $tiktok_options = [
            'servertrack_tiktok_enabled'      => [ 'type' => 'integer', 'sanitize' => 'absint',              'default' => 0  ],
            'servertrack_tiktok_pixel_id'     => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_tiktok_access_token' => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
        ];
        self::register_group( 'servertrack_tiktok_settings', $tiktok_options );

        // FIX-SK-1: option key aligned to settings-sources.php checkbox name.
        // The view uses name="servertrack_source_cart_abandonment_enabled" so the
        // registered key must match — previously 'servertrack_source_abandonment_enabled'
        // caused saves to silently fail (WP rejected the unregistered key).
        $sources_options = [
            'servertrack_source_woo_enabled'                 => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1  ],
            'servertrack_source_woo_extended'                => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'servertrack_source_order_status_enabled'        => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1  ],
            'servertrack_source_wishlist_enabled'            => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'servertrack_source_partial_refund_enabled'      => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1  ],
            'servertrack_source_cart_abandonment_enabled'    => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'servertrack_abandonment_window_minutes'         => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 60 ],
            'servertrack_source_cf7_enabled'                 => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'servertrack_source_edd_enabled'                 => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'servertrack_source_subscriptions_enabled'       => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
        ];
        self::register_group( 'servertrack_sources_settings', $sources_options );
    }

    private static function register_group( string $group, array $options ): void {
        foreach ( $options as $key => $args ) {
            register_setting( $group, $key, [
                'type'              => $args['type'],
                'sanitize_callback' => $args['sanitize'],
                'default'           => $args['default'],
            ] );
        }
    }

    // ─── Settings Page Render ───────────────────────────────────────────────

    /**
     * FIX-RT-1 / FIX-RT-2:
     *
     * Both ?page=servertrack-settings and ?page=servertrack-sources call
     * this method. We auto-detect the active tab from the page slug so that
     * Event Sources always shows the 'sources' tab regardless of whether a
     * ?tab= param was passed.
     *
     * ?page=servertrack-sources              → tab = 'sources'
     * ?page=servertrack-settings&tab=meta    → tab = 'meta'
     * ?page=servertrack-settings             → tab = 'general'
     */
    public static function render_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page_slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'servertrack-settings';

        // Auto-map the Event Sources page to the 'sources' tab.
        if ( 'servertrack-sources' === $page_slug ) {
            $current_tab = 'sources';
        } else {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
            if ( ! array_key_exists( $current_tab, self::TAB_GROUPS ) ) {
                $current_tab = 'general';
            }
        }

        $option_group = self::TAB_GROUPS[ $current_tab ];

        // For the Event Sources submenu, the form must post back to the same
        // page slug so that options_page_added_page_action resolves correctly.
        if ( 'servertrack-sources' === $page_slug ) {
            $action_url = admin_url( 'admin.php?page=servertrack-sources&settings-updated=true' );
        } else {
            $action_url = self::settings_url( $current_tab, [ 'settings-updated' => 'true' ] );
        }
        ?>
        <div class="wrap servertrack-settings-wrap">
            <div class="servertrack-settings-header">
                <h1>
                <?php
                if ( 'servertrack-sources' === $page_slug ) {
                    esc_html_e( 'Event Sources', 'servertrack' );
                } else {
                    esc_html_e( 'ServerTrack Settings', 'servertrack' );
                }
                ?>
                </h1>
            </div>

            <?php if ( 'servertrack-sources' !== $page_slug ) : ?>
            <nav class="servertrack-tab-nav">
                <?php
                $tabs = [
                    'general' => __( 'General',     'servertrack' ),
                    'meta'    => __( 'Meta CAPI',   'servertrack' ),
                    'google'  => __( 'Google Ads',  'servertrack' ),
                    'tiktok'  => __( 'TikTok',      'servertrack' ),
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
            <?php endif; ?>

            <?php if ( isset( $_GET['settings-updated'] ) ) : // phpcs:ignore ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'servertrack' ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( $action_url ); ?>">
                <?php
                settings_fields( $option_group );
                $view_file = SERVERTRACK_DIR . 'admin/views/settings-' . $current_tab . '.php';
                if ( file_exists( $view_file ) ) {
                    include $view_file;
                } else {
                    echo '<p>' . esc_html__( 'View not found: settings-' . $current_tab . '.php', 'servertrack' ) . '</p>';
                }
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    // ─── Health Notice ──────────────────────────────────────────────────────

    public static function render_health_notice(): void {
        $screen = get_current_screen();
        if ( ! $screen ) return;
        $st_screens = [
            'toplevel_page_servertrack',
            'servertrack_page_servertrack-settings',
            'servertrack_page_servertrack-sources',
        ];
        if ( ! in_array( $screen->id, $st_screens, true ) ) return;
        if ( ! get_option( 'servertrack_meta_enabled' ) && ! get_option( 'servertrack_google_enabled' ) && ! get_option( 'servertrack_tiktok_enabled' ) ) {
            echo '<div class="notice notice-warning"><p>' .
                esc_html__( 'ServerTrack: No tracking platform is enabled. Visit Settings to configure at least one platform.', 'servertrack' ) .
                '</p></div>';
        }
    }

    // ─── OAuth ──────────────────────────────────────────────────────────────

    public static function handle_oauth_start(): void {
        if ( ! isset( $_GET['servertrack_oauth_start'] ) || $_GET['servertrack_oauth_start'] !== 'google' ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ),
            'servertrack_oauth_start_google'
        ) ) wp_die( esc_html__( 'Security check failed.', 'servertrack' ) );

        $state = bin2hex( random_bytes( 32 ) );
        set_transient( 'servertrack_oauth_state_' . $state, 1, 10 * MINUTE_IN_SECONDS );

        $client_id    = get_option( 'servertrack_google_client_id', '' );
        $redirect_uri = admin_url( 'admin.php?page=servertrack-settings&tab=google&servertrack_oauth=google' );

        $auth_url = add_query_arg( [
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/adwords',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ], 'https://accounts.google.com/o/oauth2/v2/auth' );

        wp_redirect( esc_url_raw( $auth_url ) ); exit;
    }

    public static function handle_oauth_callback(): void {
        if ( ! isset( $_GET['servertrack_oauth'] ) || $_GET['servertrack_oauth'] !== 'google' ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! isset( $_GET['code'] ) ) return;

        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        if ( empty( $state ) || ! get_transient( 'servertrack_oauth_state_' . $state ) ) {
            wp_die( esc_html__( 'OAuth state mismatch. Please try connecting again.', 'servertrack' ) );
        }
        delete_transient( 'servertrack_oauth_state_' . $state );

        $code          = sanitize_text_field( wp_unslash( $_GET['code'] ) );
        $client_id     = get_option( 'servertrack_google_client_id', '' );
        $client_secret = get_option( 'servertrack_google_client_secret', '' );
        $redirect_uri  = admin_url( 'admin.php?page=servertrack-settings&tab=google&servertrack_oauth=google' );

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
                update_option( 'servertrack_google_refresh_token', self::encrypt_token( $data['refresh_token'] ) );
            }
        }
        wp_safe_redirect( self::settings_url( 'google' ) ); exit;
    }

    public static function handle_oauth_revoke(): void {
        if ( ! isset( $_GET['servertrack_revoke'] ) || $_GET['servertrack_revoke'] !== 'google' ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'servertrack_revoke_google' ) ) return;
        delete_option( 'servertrack_google_refresh_token' );
        delete_option( 'servertrack_google_access_token' );
        delete_option( 'servertrack_google_token_expires' );
        wp_safe_redirect( self::settings_url( 'google' ) ); exit;
    }

    // ─── AJAX ───────────────────────────────────────────────────────────────

    public static function ajax_test_event(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $platform = isset( $_POST['platform'] ) ? sanitize_key( $_POST['platform'] ) : '';
        if ( ! in_array( $platform, [ 'meta', 'tiktok', 'google' ], true ) ) wp_send_json_error( 'Invalid platform.' );

        if ( ! class_exists( 'ServerTrack_Event' ) || ! class_exists( 'ServerTrack_Dedup' ) ) {
            wp_send_json_error( 'Core classes not loaded.' );
        }

        $event_id = ServerTrack_Dedup::generate_event_id( 'TestEvent_admin_' . time() );
        $event    = new ServerTrack_Event( 'PageView', $event_id );
        $event->set_user_data( [ 'ip' => '127.0.0.1', 'user_agent' => 'ServerTrack/TestEvent' ] );
        $event->set_custom_data( [ 'test' => true ] );

        $result = null;
        switch ( $platform ) {
            case 'meta':   if ( class_exists( 'ServerTrack_Meta' )   ) $result = ServerTrack_Meta::send( $event );   break;
            case 'tiktok': if ( class_exists( 'ServerTrack_TikTok' ) ) $result = ServerTrack_TikTok::send( $event ); break;
            case 'google': if ( class_exists( 'ServerTrack_Google' ) ) $result = ServerTrack_Google::send( $event ); break;
        }
        if ( null === $result ) wp_send_json_error( sprintf( 'Sender class for %s not found or platform not enabled.', esc_html( $platform ) ) );

        $ok = isset( $result['code'] ) && (int) $result['code'] >= 200 && (int) $result['code'] < 300;
        if ( $ok ) wp_send_json_success( [ 'message' => sprintf( 'Test event accepted by %s.', esc_html( $platform ) ), 'result' => $result ] );
        else       wp_send_json_error(   [ 'message' => sprintf( 'Test event rejected by %s.', esc_html( $platform ) ), 'result' => $result ] );
    }

    public static function ajax_get_logs(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $logs = get_option( 'servertrack_debug_log', [] );
        if ( ! is_array( $logs ) ) $logs = [];

        if ( empty( $logs ) ) {
            wp_send_json_success( [ 'html' => '<tr><td colspan="7" class="st-empty">' . esc_html__( 'No log entries.', 'servertrack' ) . '</td></tr>' ] );
            return;
        }

        ob_start();
        foreach ( array_reverse( $logs ) as $entry ) {
            $status     = isset( $entry['status'] )     ? esc_attr( $entry['status'] )     : '';
            $ts         = isset( $entry['timestamp'] )  ? esc_html( $entry['timestamp'] )  : '';
            $platform   = isset( $entry['platform'] )   ? esc_html( $entry['platform'] )   : '';
            $event_name = isset( $entry['event_name'] ) ? esc_html( $entry['event_name'] ) : ( isset( $entry['event_type'] ) ? esc_html( $entry['event_type'] ) : '' );
            $event_id   = isset( $entry['event_id'] )   ? esc_html( $entry['event_id'] )   : '';
            $response   = isset( $entry['response'] )   ? esc_html( wp_json_encode( $entry['response'] ) ) : '';
            $error      = isset( $entry['error'] )      ? esc_html( $entry['error'] )      : '';

            echo '<tr data-row="1" data-status="' . $status . '">';
            echo '<td>' . $ts . '</td>';
            echo '<td>' . $platform . '</td>';
            echo '<td>' . $event_name . '</td>';
            echo '<td>' . $event_id . '</td>';
            echo '<td class="st-status-' . $status . '">' . $status . '</td>';
            echo '<td>';
            if ( $response ) {
                echo '<span class="st-response-toggle">' . esc_html__( 'View', 'servertrack' ) . '</span>';
                echo '<div class="st-response-full"><pre>' . $response . '</pre></div>';
            }
            echo '</td>';
            echo '<td>' . $error . '</td>';
            echo '</tr>';
        }
        wp_send_json_success( [ 'html' => ob_get_clean() ] );
    }

    public static function ajax_clear_log(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        update_option( 'servertrack_debug_log', [] );
        wp_send_json_success( [ 'message' => __( 'Log cleared.', 'servertrack' ) ] );
    }

    /**
     * FIX-BF-1: Return the full KPI payload that matches the element IDs
     * used by render_page() and dashboard.php:
     *   #st-kpi-total   ← today_count
     *   #st-kpi-rate    ← success_rate
     *   #st-kpi-emq     ← avg_emq
     *   #st-kpi-retry   ← retry_queue
     *   #st-kpi-week    ← week_total
     *   #st-kpi-errors  ← week_errors
     *
     * Also keeps legacy keys (events_today, events_week, success_rate,
     * last_event) so any older JS reference still works.
     */
    public static function ajax_get_dashboard_stats(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $logs     = get_option( 'servertrack_debug_log', [] );
        if ( ! is_array( $logs ) ) $logs = [];

        $today    = gmdate( 'Y-m-d' );
        $week_ago = gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS );

        $today_count  = 0;
        $week_total   = 0;
        $week_success = 0;
        $week_errors  = 0;
        $emq_sum      = 0.0;
        $emq_count    = 0;
        $last_event   = __( 'None', 'servertrack' );
        $last_ts      = 0;

        foreach ( $logs as $entry ) {
            $ts     = substr( $entry['timestamp'] ?? '', 0, 10 );
            $status = $entry['status'] ?? '';

            if ( $ts === $today ) $today_count++;

            if ( $ts >= $week_ago ) {
                $week_total++;
                if ( 'success' === $status ) {
                    $week_success++;
                    $entry_ts = isset( $entry['timestamp'] ) ? strtotime( $entry['timestamp'] ) : 0;
                    if ( $entry_ts > $last_ts ) {
                        $last_ts    = $entry_ts;
                        $last_event = $entry['event_name'] ?? ( $entry['event_type'] ?? __( 'Unknown', 'servertrack' ) );
                    }
                }
                if ( 'error' === $status ) $week_errors++;
                if ( isset( $entry['emq_score'] ) ) { $emq_sum += (float) $entry['emq_score']; $emq_count++; }
            }
        }

        $success_rate = $week_total > 0 ? (int) round( $week_success / $week_total * 100 ) : 0;
        $avg_emq      = $emq_count  > 0 ? number_format( $emq_sum / $emq_count, 1 ) : '--';
        $retry_queue  = count( get_option( 'servertrack_retry_queue', [] ) );

        wp_send_json_success( [
            // Full KPI keys (matched to render_page element IDs)
            'today_count'  => $today_count,
            'week_total'   => $week_total,
            'week_errors'  => $week_errors,
            'success_rate' => $success_rate,
            'avg_emq'      => $avg_emq,
            'retry_queue'  => $retry_queue,
            // Legacy keys kept for backwards compat
            'events_today' => $today_count,
            'events_week'  => $week_total,
            'last_event'   => $last_event,
        ] );
    }

    // ─── Sanitization helpers ───────────────────────────────────────────────

    public static function sanitize_consent_mode( $value ): string {
        $allowed = [ 'none', 'basic', 'advanced' ];
        return in_array( $value, $allowed, true ) ? $value : 'none';
    }
}
