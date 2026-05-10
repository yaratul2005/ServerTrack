<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Admin
 *
 * Bug fixes in this version:
 *   - SETTINGS RESET BUG (major): All tabs shared one settings group
 *     'servertrack_settings'. When the General tab was saved, WordPress ran
 *     its options pipeline for that group, and because Meta / Google / TikTok
 *     fields were NOT present in the General form, their sanitize_callback was
 *     called with an empty value — effectively blanking those fields.
 *
 *     FIX: Each tab now has its own option group:
 *       servertrack_general_settings
 *       servertrack_meta_settings
 *       servertrack_google_settings
 *       servertrack_tiktok_settings
 *       servertrack_sources_settings
 *     Saving one tab can never touch another tab's options.
 *
 *   - fire_test_event(): TikTok test was sending 'Lead' → 'SubmitForm'.
 *     Changed to 'Purchase' so it appears in all three platforms' test panels.
 *   - ajax_test_event(): missing wp_send_json_error path caused JS parse error
 *     leaving the button stuck on 'Sending…'. Fixed.
 *   - handle_oauth_callback(): missing state/nonce CSRF check. Fixed.
 *   - fire_test_event(): order_id was never set in custom_data. Logger::log()
 *     reads $event->custom_data['order_id'] — so test events always logged
 *     Order ID blank. Added order_id => 0 explicitly and event_source_url
 *     so test event log rows are fully populated.
 */
class ServerTrack_Admin {

    /**
     * Map: tab slug => option group name.
     * This is the single source of truth — views call settings_fields() with
     * the group that matches their tab slug.
     */
    const TAB_GROUPS = [
        'general' => 'servertrack_general_settings',
        'meta'    => 'servertrack_meta_settings',
        'google'  => 'servertrack_google_settings',
        'tiktok'  => 'servertrack_tiktok_settings',
        'sources' => 'servertrack_sources_settings',
    ];

    public static function init() {
        add_action( 'admin_menu',            [ self::class, 'register_menu' ] );
        add_action( 'admin_init',            [ self::class, 'register_settings' ] );
        add_action( 'admin_init',            [ self::class, 'handle_oauth_callback' ] );
        add_action( 'admin_init',            [ self::class, 'handle_oauth_revoke' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'admin_notices',         [ self::class, 'render_health_notice' ] );
        add_action( 'wp_ajax_servertrack_clear_log',  [ self::class, 'ajax_clear_log' ] );
        add_action( 'wp_ajax_servertrack_test_event', [ self::class, 'ajax_test_event' ] );
        add_action( 'wp_ajax_servertrack_get_logs',   [ self::class, 'ajax_get_logs' ] );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Menu & Assets
    // ─────────────────────────────────────────────────────────────────────

    public static function register_menu() {
        add_options_page(
            __( 'ServerTrack Settings', 'servertrack' ),
            __( 'ServerTrack', 'servertrack' ),
            'manage_options',
            'servertrack',
            [ self::class, 'render_page' ]
        );
    }

    public static function enqueue_assets( string $hook ) {
        if ( 'settings_page_servertrack' !== $hook ) return;
        wp_enqueue_style( 'servertrack-admin', SERVERTRACK_URL . 'admin/assets/admin.css', [], SERVERTRACK_VERSION );
        wp_enqueue_script( 'servertrack-admin', SERVERTRACK_URL . 'admin/assets/admin.js', [ 'jquery' ], SERVERTRACK_VERSION, true );
        wp_localize_script( 'servertrack-admin', 'servertrack_admin', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'servertrack_admin_nonce' ),
        ] );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Settings Registration  ← THE FIX IS HERE
    // ─────────────────────────────────────────────────────────────────────

    public static function register_settings() {

        // ── General tab ──────────────────────────────────────────────────
        $general_options = [
            'servertrack_enabled'      => [ 'type' => 'integer', 'sanitize' => 'absint',               'default' => 1      ],
            'servertrack_test_mode'    => [ 'type' => 'integer', 'sanitize' => 'absint',               'default' => 0      ],
            'servertrack_consent_mode' => [ 'type' => 'string',  'sanitize' => [ self::class, 'sanitize_consent_mode' ], 'default' => 'none' ],
        ];
        self::register_group( 'servertrack_general_settings', $general_options );

        // ── Meta CAPI tab ────────────────────────────────────────────────
        $meta_options = [
            'servertrack_meta_enabled'         => [ 'type' => 'integer', 'sanitize' => 'absint',              'default' => 0  ],
            'servertrack_meta_pixel_id'        => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_meta_access_token'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_meta_test_event_code' => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
        ];
        self::register_group( 'servertrack_meta_settings', $meta_options );

        // ── Google Ads tab ───────────────────────────────────────────────
        $google_options = [
            'servertrack_google_enabled'          => [ 'type' => 'integer', 'sanitize' => 'absint',              'default' => 0  ],
            'servertrack_google_customer_id'      => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_conversion_id'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_developer_token'  => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_client_id'        => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_client_secret'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_refresh_token'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_gtag_id'          => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_gtag_label'       => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
        ];
        self::register_group( 'servertrack_google_settings', $google_options );

        // ── TikTok Events tab ────────────────────────────────────────────
        $tiktok_options = [
            'servertrack_tiktok_enabled'      => [ 'type' => 'integer', 'sanitize' => 'absint',              'default' => 0  ],
            'servertrack_tiktok_pixel_id'     => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_tiktok_access_token' => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
        ];
        self::register_group( 'servertrack_tiktok_settings', $tiktok_options );

        // ── Sources tab ──────────────────────────────────────────────────
        $sources_options = [
            'servertrack_source_woo_enabled'   => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
            'servertrack_source_cf7_enabled'   => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0 ],
            'servertrack_source_edd_enabled'   => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0 ],
            'servertrack_scroll_depth'         => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
            'servertrack_video_tracking'       => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
            'servertrack_wishlist_tracking'    => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
        ];
        self::register_group( 'servertrack_sources_settings', $sources_options );

        // CF7 mappings live in the Sources group
        register_setting( 'servertrack_sources_settings', 'servertrack_cf7_mappings', [
            'sanitize_callback' => [ self::class, 'sanitize_cf7_mappings' ],
        ] );
    }

    /**
     * Register a group of options and add them to allowed_options.
     *
     * @param string $group   Option group name (used by settings_fields()).
     * @param array  $options [ option_name => [ type, sanitize, default ] ]
     */
    private static function register_group( string $group, array $options ) {
        $names = array_keys( $options );

        // Whitelist for options.php
        add_filter( 'allowed_options', function( array $allowed ) use ( $group, $names ): array {
            $allowed[ $group ] = $names;
            return $allowed;
        } );

        foreach ( $options as $name => $cfg ) {
            register_setting( $group, $name, [
                'type'              => $cfg['type'],
                'sanitize_callback' => $cfg['sanitize'],
                'default'           => $cfg['default'],
            ] );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sanitizers
    // ─────────────────────────────────────────────────────────────────────

    public static function sanitize_consent_mode( $input ): string {
        $allowed = [ 'none', 'cookie_yes', 'complianz', 'manual' ];
        $val     = sanitize_text_field( (string) $input );
        return in_array( $val, $allowed, true ) ? $val : 'none';
    }

    public static function sanitize_cf7_mappings( $input ): array {
        $clean = [];
        if ( ! is_array( $input ) ) return $clean;
        foreach ( $input as $form_id => $fields ) {
            $form_id = absint( $form_id );
            if ( ! $form_id || ! is_array( $fields ) ) continue;
            $clean[ $form_id ] = [
                'email' => sanitize_text_field( $fields['email'] ?? '' ),
                'phone' => sanitize_text_field( $fields['phone'] ?? '' ),
                'name'  => sanitize_text_field( $fields['name']  ?? '' ),
            ];
        }
        return $clean;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Google OAuth 2.0 — Callback
    // ─────────────────────────────────────────────────────────────────────

    public static function handle_oauth_callback() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( empty( $_GET['code'] )
            || empty( $_GET['page'] ) || 'servertrack' !== $_GET['page']
            || empty( $_GET['tab'] )  || 'google'      !== $_GET['tab'] ) {
            return;
        }
        // phpcs:enable

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'servertrack' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        if ( ! wp_verify_nonce( $state, 'servertrack_google_oauth' ) ) {
            wp_safe_redirect( admin_url( 'options-general.php?page=servertrack&tab=google&st_notice=oauth_error' ) );
            exit;
        }

        $client_id     = get_option( 'servertrack_google_client_id', '' );
        $client_secret = get_option( 'servertrack_google_client_secret', '' );
        $redirect_uri  = admin_url( 'options-general.php?page=servertrack&tab=google' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $code = sanitize_text_field( wp_unslash( $_GET['code'] ) );

        if ( ! $client_id || ! $client_secret ) {
            wp_safe_redirect( admin_url( 'options-general.php?page=servertrack&tab=google&st_notice=oauth_no_creds' ) );
            exit;
        }

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'body' => [
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            wp_safe_redirect( admin_url( 'options-general.php?page=servertrack&tab=google&st_notice=oauth_error' ) );
            exit;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['refresh_token'] ) ) {
            ServerTrack_Logger::log(
                'error', 'google',
                'OAuth token exchange failed: ' . ( $body['error_description'] ?? $body['error'] ?? 'Unknown error' ),
                '', '', 0, 'OAuth'
            );
            wp_safe_redirect( admin_url( 'options-general.php?page=servertrack&tab=google&st_notice=oauth_error' ) );
            exit;
        }

        update_option( 'servertrack_google_refresh_token', sanitize_text_field( $body['refresh_token'] ) );
        update_option( 'servertrack_google_access_token',  sanitize_text_field( $body['access_token'] ?? '' ) );
        update_option( 'servertrack_google_token_expires', time() + (int) ( $body['expires_in'] ?? 3600 ) );

        ServerTrack_Logger::log( 'success', 'google', 'Google OAuth authorised. Refresh token stored.', '', '', 0, 'OAuth' );

        wp_safe_redirect( admin_url( 'options-general.php?page=servertrack&tab=google&st_notice=oauth_success' ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Google OAuth — Revoke
    // ─────────────────────────────────────────────────────────────────────

    public static function handle_oauth_revoke() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( empty( $_GET['st_google_action'] ) || 'revoke' !== $_GET['st_google_action'] ) return;
        // phpcs:enable

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'servertrack' ) );
        }

        check_admin_referer( 'st_google_revoke' );

        delete_option( 'servertrack_google_refresh_token' );
        delete_option( 'servertrack_google_access_token' );
        delete_option( 'servertrack_google_token_expires' );

        ServerTrack_Logger::log( 'success', 'google', 'Google OAuth tokens revoked by admin.', '', '', 0, 'OAuth' );

        wp_safe_redirect( admin_url( 'options-general.php?page=servertrack&tab=google&st_notice=oauth_revoked' ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Page render
    // ─────────────────────────────────────────────────────────────────────

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'servertrack' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';

        $tabs = [
            'general' => __( 'General', 'servertrack' ),
            'meta'    => __( 'Meta CAPI', 'servertrack' ),
            'google'  => __( 'Google Ads', 'servertrack' ),
            'tiktok'  => __( 'TikTok Events', 'servertrack' ),
            'sources' => __( 'Sources', 'servertrack' ),
            'debug'   => __( 'Debug Log', 'servertrack' ),
        ];

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $st_notice = isset( $_GET['st_notice'] ) ? sanitize_key( wp_unslash( $_GET['st_notice'] ) ) : '';
        $notices = [
            'oauth_success'  => [ 'success', __( 'Google account connected successfully.', 'servertrack' ) ],
            'oauth_revoked'  => [ 'warning', __( 'Google OAuth tokens have been revoked.', 'servertrack' ) ],
            'oauth_error'    => [ 'error',   __( 'Google OAuth authorisation failed. Check the Debug Log.', 'servertrack' ) ],
            'oauth_no_creds' => [ 'error',   __( 'OAuth failed: Client ID and Secret must be saved first.', 'servertrack' ) ],
            'settings_saved' => [ 'success', __( 'Settings saved.', 'servertrack' ) ],
        ];
        ?>
        <div class="wrap" id="servertrack-wrap">
            <h1 class="servertrack-page-title">
                <span class="servertrack-logo-mark">&#9654;</span>
                <?php esc_html_e( 'ServerTrack — Server-Side Events', 'servertrack' ); ?>
            </h1>

            <?php if ( $st_notice && isset( $notices[ $st_notice ] ) ) :
                [ $type, $msg ] = $notices[ $st_notice ]; ?>
                <div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible inline">
                    <p><?php echo esc_html( $msg ); ?></p>
                </div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper servertrack-tabs" id="servertrack-tab-nav">
                <?php foreach ( $tabs as $slug => $label ) : ?>
                    <a href="?page=servertrack&tab=<?php echo esc_attr( $slug ); ?>"
                       class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>"
                       data-tab="<?php echo esc_attr( $slug ); ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="servertrack-tab-content">
                <?php
                $view_file = SERVERTRACK_DIR . 'admin/views/settings-' . $active_tab . '.php';
                if ( file_exists( $view_file ) ) {
                    include $view_file;
                }
                ?>
            </div>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────
    // Health Notice
    // ─────────────────────────────────────────────────────────────────────

    public static function render_health_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $screen = get_current_screen();
        if ( $screen && 'settings_page_servertrack' === $screen->id ) return;

        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        if ( get_option( 'servertrack_source_woo_enabled', 1 ) && ! class_exists( 'WooCommerce' ) ) {
            $settings_url = admin_url( 'options-general.php?page=servertrack&tab=sources' );
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo '<strong>' . esc_html__( 'ServerTrack:', 'servertrack' ) . '</strong> ';
            echo esc_html__( 'WooCommerce source is enabled but WooCommerce is not active.', 'servertrack' );
            echo ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Review source settings →', 'servertrack' ) . '</a>';
            echo '</p></div>';
        }

        $meta_ok   = get_option( 'servertrack_meta_enabled', 0 ) && get_option( 'servertrack_meta_pixel_id', '' ) && get_option( 'servertrack_meta_access_token', '' );
        $google_ok = get_option( 'servertrack_google_enabled', 0 ) && get_option( 'servertrack_google_customer_id', '' ) && get_option( 'servertrack_google_refresh_token', '' );
        $tiktok_ok = get_option( 'servertrack_tiktok_enabled', 0 ) && get_option( 'servertrack_tiktok_pixel_id', '' ) && get_option( 'servertrack_tiktok_access_token', '' );

        if ( $meta_ok || $google_ok || $tiktok_ok ) return;

        $settings_url = admin_url( 'options-general.php?page=servertrack&tab=meta' );
        echo '<div class="notice notice-warning is-dismissible"><p>';
        echo '<strong>' . esc_html__( 'ServerTrack:', 'servertrack' ) . '</strong> ';
        echo esc_html__( 'Plugin is active but no ad platforms are configured.', 'servertrack' );
        echo ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Configure now →', 'servertrack' ) . '</a>';
        echo '</p></div>';
    }

    // ─────────────────────────────────────────────────────────────────────
    // AJAX handlers
    // ─────────────────────────────────────────────────────────────────────

    public static function ajax_clear_log() {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        ServerTrack_Logger::clear_logs();
        wp_send_json_success( [ 'message' => __( 'Log cleared.', 'servertrack' ) ] );
    }

    public static function ajax_test_event() {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $platform  = isset( $_POST['platform'] )  ? sanitize_text_field( wp_unslash( $_POST['platform'] ) )  : '';
        $test_code = isset( $_POST['test_code'] ) ? sanitize_text_field( wp_unslash( $_POST['test_code'] ) ) : '';

        if ( empty( $platform ) ) {
            wp_send_json_error( 'Platform not specified.' );
        }

        $result = self::fire_test_event( $platform, $test_code );

        if ( isset( $result['status'] ) && 'error' === $result['status'] ) {
            wp_send_json_error( $result );
        }
        wp_send_json_success( $result );
    }

    public static function ajax_get_logs() {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        $logs = get_option( 'servertrack_debug_log', [] );
        wp_send_json_success( $logs );
    }

    private static function fire_test_event( string $platform, string $test_code = '' ): array {
        $event_id = 'test_event_' . time();
        $event    = new ServerTrack_Event( 'Purchase', $event_id );

        $ip = '';
        if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] ) );
        } elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'ServerTrack/Test';

        $event->set_user_data( [
            'email'      => ServerTrack_Hasher::hash_email( 'test@example.com' ),
            'first_name' => ServerTrack_Hasher::hash( 'Test' ),
            'last_name'  => ServerTrack_Hasher::hash( 'User' ),
            'ip'         => $ip,
            'user_agent' => $ua,
        ] );

        $resolved_test_code = '' !== $test_code
            ? $test_code
            : trim( (string) get_option( 'servertrack_meta_test_event_code', '' ) );

        // BUG FIX: order_id was never set here.
        // Logger::log() reads $event->custom_data['order_id'] — without this key
        // every test event logged Order ID as blank/0 with no indication it was intentional.
        // Set to 0 explicitly (correct — test events have no real order).
        // Also set event_source_url so test log rows show a real URL rather than empty.
        $custom_data = [
            'currency'          => 'USD',
            'value'             => 1.00,
            'order_id'          => 0,
            'contents'          => [ [ 'id' => 'TEST-SKU', 'quantity' => 1, 'item_price' => 1.00 ] ],
            'content_type'      => 'product',
        ];

        if ( '' !== $resolved_test_code && 'meta' === $platform ) {
            $custom_data['_test_event_code'] = $resolved_test_code;
        }

        $event->set_custom_data( $custom_data );

        // Set event_source_url to home_url() so the log row is not blank.
        // Real WooCommerce events capture the actual checkout URL; test events
        // use the site home as a safe meaningful fallback.
        $event->event_source_url = home_url( '/' );

        switch ( $platform ) {
            case 'meta':   return ServerTrack_Meta::send( $event );
            case 'google': return ServerTrack_Google::send( $event );
            case 'tiktok': return ServerTrack_TikTok::send( $event );
        }

        return [ 'status' => 'error', 'message' => 'Unknown platform: ' . $platform ];
    }
}
