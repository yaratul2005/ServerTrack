<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Admin — v6.2.0
 *
 * Changes in v6.2.0 (GAP fixes):
 *
 *   GAP-1 — wp_ajax_servertrack_clear_log was never registered.
 *            Added ajax_clear_log() and its AJAX hook so the Settings-page
 *            clear-log button (#st-clear-log) works instead of silently
 *            returning -1 from WordPress.
 *
 *   GAP-2 — ajax_get_logs() returned { logs: [...] } (raw array).
 *            admin.js reads res.data.html to inject rows into #st-log-tbody.
 *            Now builds HTML rows server-side and returns { html: '...' }.
 *
 *   GAP-3 — 'servertrack_page_servertrack-sources' was absent from
 *            enqueue_assets() allowed hooks, so admin.css / admin.js
 *            never loaded on the Event Sources settings page.
 *            Added to allowed hooks.
 *
 *   GAP-4 — servertrack_google_refresh_token used sanitize_text_field
 *            as its sanitize_callback, bypassing encryption when the user
 *            saves a token manually via the Settings form. Changed to
 *            sanitize_refresh_token() which calls encrypt_token() so
 *            manual saves are encrypted consistently with oauth_callback.
 *
 *   GAP-5 — 'settings_page_servertrack' in enqueue_assets() is a dead
 *            hook. ServerTrack registers under its own top-level menu;
 *            the sub-page hook is always servertrack_page_servertrack-*.
 *            Removed the dead entry.
 *
 *   GAP-6 — ajax_get_dashboard_stats() used current_time('Y-m-d') and
 *            current_time('timestamp') (local), while
 *            ServerTrack_Dashboard::compute_stats() uses gmdate() (UTC).
 *            Both now use gmdate / time() so today's event counts match
 *            regardless of server timezone.
 *
 *   GAP-7 — showToast() built msg HTML via raw string concatenation.
 *            XSS risk if an API error body contained HTML characters.
 *            Fixed in admin.js: msg is now escaped through a DOM text
 *            node before insertion.
 *
 *   GAP-8 — admin-dashboard.css existed in assets/ but was never
 *            enqueued. Added wp_enqueue_style for servertrack-admin-dashboard
 *            on all allowed ServerTrack admin hooks.
 *
 * Changes in v6.1.0 (deep-review fixes):
 *   FIX-DR-1 — ajax_get_dashboard_stats: real KPIs from ServerTrack_Logger.
 *   FIX-DR-2 — ajax_test_event: real CAPI dispatch, not a fake response.
 *   FIX-DR-3 — handle_oauth_start/callback: CSRF state token added.
 *   FIX-DR-4 — Google refresh_token AES-256-CBC encrypted at rest.
 *   CRASH-FIX — render_page: SERVERTRACK_PATH → SERVERTRACK_DIR.
 *
 * Changes in v6.0.5:
 *   BUG-11 — Abandonment option key mismatch fixed.
 *   BUG-12 — 'servertrack_source_woo_extended' was never registered.
 *
 * Changes in v3.2:
 *   C1-C9 — Nested <form> bug, unregistered settings options, health
 *            notice screen-ID check, and related fixes.
 */
class ServerTrack_Admin {

    const TAB_GROUPS = [
        'general' => 'servertrack_general_settings',
        'meta'    => 'servertrack_meta_settings',
        'google'  => 'servertrack_google_settings',
        'tiktok'  => 'servertrack_tiktok_settings',
        'sources' => 'servertrack_sources_settings',
    ];

    // -----------------------------------------------------------------
    // FIX-DR-4 / GAP-4: Token encryption helpers
    // -----------------------------------------------------------------

    /**
     * Encrypt a sensitive string using AES-256-CBC + wp_salt('auth').
     * Returns a base64-encoded payload: base64( iv . ciphertext ).
     */
    private static function encrypt_token( string $plaintext ): string {
        $key    = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
        $iv     = random_bytes( 16 );
        $cipher = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        if ( false === $cipher ) {
            return $plaintext; // Fallback: store plaintext if openssl unavailable.
        }
        return base64_encode( $iv . $cipher );
    }

    /**
     * Decrypt a token previously encrypted by encrypt_token().
     * Transparently handles legacy plaintext tokens by returning them as-is.
     */
    public static function decrypt_token( string $stored ): string {
        $key = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
        $raw = base64_decode( $stored, true );
        if ( false === $raw || strlen( $raw ) < 17 ) {
            return $stored; // Legacy plaintext token.
        }
        $iv         = substr( $raw, 0, 16 );
        $ciphertext = substr( $raw, 16 );
        $plain      = openssl_decrypt( $ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return ( false !== $plain ) ? $plain : $stored;
    }

    /**
     * GAP-4: Sanitize callback for servertrack_google_refresh_token.
     *
     * Encrypts the value before storage so that tokens saved manually via
     * the Settings form are encrypted consistently with tokens stored by
     * handle_oauth_callback(). Empty values are stored as-is.
     */
    public static function sanitize_refresh_token( $value ): string {
        $value = sanitize_text_field( $value );
        if ( '' === $value ) {
            return '';
        }
        // If the value is already a valid encrypted blob, leave it.
        $raw = base64_decode( $value, true );
        if ( false !== $raw && strlen( $raw ) >= 17 ) {
            // Looks like an existing encrypted blob — don't double-encrypt.
            return $value;
        }
        return self::encrypt_token( $value );
    }

    // -----------------------------------------------------------------

    private static function settings_url( string $tab = '', array $extra = [] ): string {
        $args = array_merge( [ 'page' => 'servertrack-settings' ], $extra );
        if ( $tab !== '' ) {
            $args['tab'] = $tab;
        }
        return admin_url( 'admin.php?' . http_build_query( $args ) );
    }

    public static function init() {
        add_action( 'admin_init',            [ self::class, 'register_settings' ] );
        add_action( 'admin_init',            [ self::class, 'handle_oauth_start' ] );    // FIX-DR-3
        add_action( 'admin_init',            [ self::class, 'handle_oauth_callback' ] );
        add_action( 'admin_init',            [ self::class, 'handle_oauth_revoke' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'admin_notices',         [ self::class, 'render_health_notice' ] );
        add_action( 'wp_ajax_servertrack_test_event',          [ self::class, 'ajax_test_event' ] );
        add_action( 'wp_ajax_servertrack_get_logs',            [ self::class, 'ajax_get_logs' ] );
        add_action( 'wp_ajax_servertrack_clear_log',           [ self::class, 'ajax_clear_log' ] );   // GAP-1
        add_action( 'wp_ajax_servertrack_get_dashboard_stats', [ self::class, 'ajax_get_dashboard_stats' ] );
    }

    // -----------------------------------------------------------------
    // Assets
    // -----------------------------------------------------------------

    public static function enqueue_assets( string $hook ) {
        // GAP-3: Added 'servertrack_page_servertrack-sources'.
        // GAP-5: Removed dead 'settings_page_servertrack' hook.
        $allowed_hooks = [
            'servertrack_page_servertrack-settings',
            'servertrack_page_servertrack-sources',
            'toplevel_page_servertrack',
        ];
        if ( ! in_array( $hook, $allowed_hooks, true ) ) return;

        // GAP-8: Enqueue admin-dashboard.css (was never enqueued).
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

    // -----------------------------------------------------------------
    // Settings Registration
    // -----------------------------------------------------------------

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

        // GAP-4: servertrack_google_refresh_token now uses sanitize_refresh_token
        //        instead of sanitize_text_field so manual pastes are encrypted.
        $google_options = [
            'servertrack_google_enabled'          => [ 'type' => 'integer', 'sanitize' => 'absint',                                   'default' => 0  ],
            'servertrack_google_conversion_id'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field',                      'default' => '' ],
            'servertrack_google_conversion_label' => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field',                      'default' => '' ],
            'servertrack_google_refresh_token'    => [ 'type' => 'string',  'sanitize' => [ self::class, 'sanitize_refresh_token' ],   'default' => '' ],
            'servertrack_google_client_id'        => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field',                      'default' => '' ],
            'servertrack_google_client_secret'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field',                      'default' => '' ],
        ];
        self::register_group( 'servertrack_google_settings', $google_options );

        $tiktok_options = [
            'servertrack_tiktok_enabled'      => [ 'type' => 'integer', 'sanitize' => 'absint',              'default' => 0  ],
            'servertrack_tiktok_pixel_id'     => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_tiktok_access_token' => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
        ];
        self::register_group( 'servertrack_tiktok_settings', $tiktok_options );

        $sources_options = [
            'servertrack_source_woo_enabled'           => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1  ],
            'servertrack_source_woo_extended'          => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'servertrack_source_order_status_enabled'  => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1  ],
            'servertrack_source_wishlist_enabled'      => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'servertrack_source_partial_refund_enabled'=> [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1  ],
            'servertrack_source_abandonment_enabled'   => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
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

    // -----------------------------------------------------------------
    // Settings Page Render
    // -----------------------------------------------------------------

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
                // CRASH-FIX: Use SERVERTRACK_DIR — SERVERTRACK_PATH was never defined.
                $view_file = SERVERTRACK_DIR . 'admin/views/settings-' . $current_tab . '.php';
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

    // -----------------------------------------------------------------
    // Health Notice
    // -----------------------------------------------------------------

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

    // -----------------------------------------------------------------
    // OAuth Handlers
    // -----------------------------------------------------------------

    /**
     * FIX-DR-3: Issue a state token and redirect to Google's OAuth endpoint.
     */
    public static function handle_oauth_start(): void {
        if ( ! isset( $_GET['servertrack_oauth_start'] ) || $_GET['servertrack_oauth_start'] !== 'google' ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ),
            'servertrack_oauth_start_google'
        ) ) {
            wp_die( esc_html__( 'Security check failed.', 'servertrack' ) );
        }

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

        wp_redirect( esc_url_raw( $auth_url ) );
        exit;
    }

    /**
     * FIX-DR-3: Verify state token before exchanging the OAuth code.
     * FIX-DR-4: Encrypt the refresh_token before storing it.
     */
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

    // -----------------------------------------------------------------
    // AJAX Handlers
    // -----------------------------------------------------------------

    /**
     * FIX-DR-2: Dispatch a real TestEvent via the platform sender.
     */
    public static function ajax_test_event(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $platform = isset( $_POST['platform'] ) ? sanitize_key( $_POST['platform'] ) : '';
        if ( ! in_array( $platform, [ 'meta', 'tiktok', 'google' ], true ) ) {
            wp_send_json_error( 'Invalid platform.' );
        }

        if ( ! class_exists( 'ServerTrack_Event' ) || ! class_exists( 'ServerTrack_Dedup' ) ) {
            wp_send_json_error( 'Core classes not loaded.' );
        }

        $event_id = ServerTrack_Dedup::generate_event_id( 'TestEvent_admin_' . time() );
        $event    = new ServerTrack_Event( 'PageView', $event_id );
        $event->set_user_data( [ 'ip' => '127.0.0.1', 'user_agent' => 'ServerTrack/TestEvent' ] );
        $event->set_custom_data( [ 'test' => true ] );

        $result = null;
        switch ( $platform ) {
            case 'meta':
                if ( class_exists( 'ServerTrack_Meta' ) )   $result = ServerTrack_Meta::send( $event );
                break;
            case 'tiktok':
                if ( class_exists( 'ServerTrack_TikTok' ) ) $result = ServerTrack_TikTok::send( $event );
                break;
            case 'google':
                if ( class_exists( 'ServerTrack_Google' ) ) $result = ServerTrack_Google::send( $event );
                break;
        }

        if ( null === $result ) {
            wp_send_json_error( sprintf( 'Sender class for %s not found or platform not enabled.', esc_html( $platform ) ) );
        }

        $is_success = isset( $result['code'] ) && (int) $result['code'] >= 200 && (int) $result['code'] < 300;
        if ( $is_success ) {
            wp_send_json_success( [ 'message' => sprintf( 'Test event accepted by %s.', esc_html( $platform ) ), 'result' => $result ] );
        } else {
            wp_send_json_error( [ 'message' => sprintf( 'Test event rejected by %s.', esc_html( $platform ) ), 'result' => $result ] );
        }
    }

    /**
     * GAP-2: Return { html: '...' } instead of { logs: [...] } so that
     * admin.js #st-refresh-log can inject rows directly into #st-log-tbody.
     */
    public static function ajax_get_logs(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $logs = get_option( 'servertrack_debug_log', [] );
        if ( ! is_array( $logs ) ) {
            $logs = [];
        }

        if ( empty( $logs ) ) {
            $html = '<tr><td colspan="7" class="st-empty">' . esc_html__( 'No log entries.', 'servertrack' ) . '</td></tr>';
            wp_send_json_success( [ 'html' => $html ] );
            return;
        }

        ob_start();
        foreach ( array_reverse( $logs ) as $entry ) {
            $status     = isset( $entry['status'] ) ? esc_attr( $entry['status'] ) : '';
            $ts         = isset( $entry['timestamp'] ) ? esc_html( $entry['timestamp'] ) : '';
            $platform   = isset( $entry['platform'] )  ? esc_html( $entry['platform'] )  : '';
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
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

    /**
     * GAP-1: Clear the debug log from the Settings page.
     *
     * Uses nonce action 'servertrack_admin_nonce' (cfg.nonce in admin.js)
     * to match the #st-clear-log button's AJAX call.
     */
    public static function ajax_clear_log(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        update_option( 'servertrack_debug_log', [] );
        wp_send_json_success( [ 'message' => __( 'Log cleared.', 'servertrack' ) ] );
    }

    /**
     * GAP-6: Use gmdate() / time() for all date comparisons so that
     * today's event counts match ServerTrack_Dashboard::compute_stats()
     * regardless of the server's local timezone offset.
     *
     * FIX-DR-1: Compute real dashboard KPIs from ServerTrack_Logger.
     */
    public static function ajax_get_dashboard_stats(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        if ( ! class_exists( 'ServerTrack_Logger' ) ) {
            wp_send_json_success( [
                'events_today' => 0,
                'events_week'  => 0,
                'success_rate' => 0,
                'last_event'   => __( 'None', 'servertrack' ),
            ] );
            return;
        }

        $all    = ServerTrack_Logger::get_recent( 1000 );
        // GAP-6: Use UTC (gmdate / time) to match Dashboard::compute_stats.
        $today  = gmdate( 'Y-m-d' );
        $week_ts = time() - ( 7 * DAY_IN_SECONDS );

        $events_today  = 0;
        $events_week   = 0;
        $total_success = 0;
        $total_error   = 0;
        $last_event    = __( 'None', 'servertrack' );
        $last_ts       = 0;

        foreach ( $all as $entry ) {
            $status = $entry['status'] ?? '';

            if ( 'success' === $status ) {
                $total_success++;
                $entry_ts = isset( $entry['timestamp'] ) ? strtotime( $entry['timestamp'] ) : 0;

                if ( isset( $entry['timestamp'] ) && substr( $entry['timestamp'], 0, 10 ) === $today ) {
                    $events_today++;
                }
                if ( $entry_ts && $entry_ts >= $week_ts ) {
                    $events_week++;
                }
                if ( $entry_ts && $entry_ts > $last_ts ) {
                    $last_ts    = $entry_ts;
                    $last_event = $entry['event_name'] ?? ( $entry['event_type'] ?? __( 'Unknown', 'servertrack' ) );
                }
            } elseif ( 'error' === $status ) {
                $total_error++;
            }
        }

        $total        = $total_success + $total_error;
        $success_rate = $total > 0 ? round( ( $total_success / $total ) * 100 ) : 100;

        wp_send_json_success( [
            'events_today' => $events_today,
            'events_week'  => $events_week,
            'success_rate' => $success_rate,
            'last_event'   => $last_event,
        ] );
    }

    // -----------------------------------------------------------------
    // Sanitization helpers
    // -----------------------------------------------------------------

    public static function sanitize_consent_mode( $value ): string {
        $allowed = [ 'none', 'basic', 'advanced' ];
        return in_array( $value, $allowed, true ) ? $value : 'none';
    }
}
