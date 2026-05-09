<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Admin
 *
 * Day 6 additions:
 *   - handle_oauth_callback(): exchanges Google auth code for access + refresh tokens.
 *   - handle_oauth_revoke():   clears all Google OAuth tokens and logs the action.
 *   - Both handlers fire on admin_init before any output is sent.
 *   - register_settings() updated: servertrack_consent_mode uses sanitize_text_field
 *     and is validated against an allowlist.
 *
 * Day 7 additions:
 *   - render_health_notice() extended: also warns when plugin is enabled but
 *     WooCommerce is absent and the Woo source is still toggled on.
 *
 * Bug fix (settings not saving):
 *   - Added 'allowed_options' filter to whitelist all servertrack_* options.
 *     WordPress 5.5+ requires options to appear in this filter OR be registered
 *     via register_setting() with a matching group. The filter is the reliable
 *     canonical approach — without it options.php silently rejects the save.
 */
class ServerTrack_Admin {

    public static function init() {
        add_action( 'admin_menu',            [ self::class, 'register_menu' ] );
        add_action( 'admin_init',            [ self::class, 'register_settings' ] );
        add_action( 'admin_init',            [ self::class, 'handle_oauth_callback' ] );
        add_action( 'admin_init',            [ self::class, 'handle_oauth_revoke' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'admin_notices',         [ self::class, 'render_health_notice' ] );
        add_action( 'wp_ajax_servertrack_clear_log',   [ self::class, 'ajax_clear_log' ] );
        add_action( 'wp_ajax_servertrack_test_event',  [ self::class, 'ajax_test_event' ] );
        add_action( 'wp_ajax_servertrack_get_logs',    [ self::class, 'ajax_get_logs' ] );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Menu & Assets
    // ──────────────────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────────────────
    // Settings Registration
    // ──────────────────────────────────────────────────────────────────────────

    public static function register_settings() {

        $all_options = [
            'servertrack_enabled', 'servertrack_test_mode', 'servertrack_consent_mode',
            'servertrack_meta_enabled', 'servertrack_meta_pixel_id', 'servertrack_meta_access_token', 'servertrack_meta_test_event_code',
            'servertrack_google_enabled', 'servertrack_google_customer_id', 'servertrack_google_conversion_id',
            'servertrack_google_developer_token', 'servertrack_google_client_id', 'servertrack_google_client_secret',
            'servertrack_google_refresh_token',
            'servertrack_tiktok_enabled', 'servertrack_tiktok_pixel_id', 'servertrack_tiktok_access_token',
            'servertrack_source_woo_enabled', 'servertrack_source_cf7_enabled', 'servertrack_source_edd_enabled',
            'servertrack_cf7_mappings',
        ];

        $bool_options = [
            'servertrack_enabled', 'servertrack_test_mode',
            'servertrack_meta_enabled', 'servertrack_google_enabled', 'servertrack_tiktok_enabled',
            'servertrack_source_woo_enabled', 'servertrack_source_cf7_enabled', 'servertrack_source_edd_enabled',
        ];

        /*
         * FIX: Whitelist every servertrack_* option in the allowed_options filter.
         *
         * WordPress 5.5+ validates submitted option names against this list inside
         * options.php. If an option is NOT in allowed_options its submitted value
         * is silently discarded — the form appears to save but the DB is never
         * written, so fields reset to blank on next load.
         *
         * register_setting() does add options to this list, but only when the
         * group key in settings_fields() exactly matches the group passed to
         * register_setting(). Hooking 'allowed_options' directly is the safest
         * canonical approach and works regardless of page context.
         */
        add_filter( 'allowed_options', function( $allowed ) use ( $all_options ) {
            $allowed['servertrack_settings'] = $all_options;
            return $allowed;
        } );

        foreach ( $all_options as $option ) {
            if ( 'servertrack_cf7_mappings' === $option ) continue;

            $is_bool = in_array( $option, $bool_options, true );

            // consent_mode gets its own allowlist sanitizer
            if ( 'servertrack_consent_mode' === $option ) {
                register_setting( 'servertrack_settings', $option, [
                    'sanitize_callback' => [ self::class, 'sanitize_consent_mode' ],
                    'default'           => 'none',
                ] );
                continue;
            }

            register_setting( 'servertrack_settings', $option, [
                'type'              => $is_bool ? 'integer' : 'string',
                'sanitize_callback' => $is_bool ? 'absint' : 'sanitize_text_field',
                'default'           => $is_bool ? 0 : '',
            ] );
        }

        register_setting( 'servertrack_settings', 'servertrack_cf7_mappings', [
            'sanitize_callback' => [ self::class, 'sanitize_cf7_mappings' ],
        ] );
    }

    public static function sanitize_consent_mode( $input ): string {
        $allowed = [ 'none', 'granted', 'denied' ];
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

    // ──────────────────────────────────────────────────────────────────────────
    // Google OAuth 2.0 — Callback handler (Day 6)
    // ──────────────────────────────────────────────────────────────────────────

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

        $client_id     = get_option( 'servertrack_google_client_id', '' );
        $client_secret = get_option( 'servertrack_google_client_secret', '' );
        $redirect_uri  = admin_url( 'options-general.php?page=servertrack&tab=google' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $code          = sanitize_text_field( wp_unslash( $_GET['code'] ) );

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

        ServerTrack_Logger::log(
            'success', 'google',
            'Google OAuth authorised. Refresh token stored.',
            '', '', 0, 'OAuth'
        );

        wp_safe_redirect( admin_url( 'options-general.php?page=servertrack&tab=google&st_notice=oauth_success' ) );
        exit;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Google OAuth — Revoke / Disconnect handler (Day 6)
    // ──────────────────────────────────────────────────────────────────────────

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

        ServerTrack_Logger::log(
            'success', 'google',
            'Google OAuth tokens revoked by admin.',
            '', '', 0, 'OAuth'
        );

        wp_safe_redirect( admin_url( 'options-general.php?page=servertrack&tab=google&st_notice=oauth_revoked' ) );
        exit;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Page render
    // ──────────────────────────────────────────────────────────────────────────

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
            'oauth_success' => [ 'success', __( 'Google account connected successfully. ServerTrack is now authorised to send Enhanced Conversions.', 'servertrack' ) ],
            'oauth_revoked' => [ 'warning', __( 'Google OAuth tokens have been revoked. You can re-connect at any time.', 'servertrack' ) ],
            'oauth_error'   => [ 'error',   __( 'Google OAuth authorisation failed. Check the Debug Log for details and try again.', 'servertrack' ) ],
            'oauth_no_creds'=> [ 'error',   __( 'OAuth failed: Client ID and Client Secret must be saved before connecting.', 'servertrack' ) ],
            'settings_saved'=> [ 'success', __( 'Settings saved.', 'servertrack' ) ],
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

    // ──────────────────────────────────────────────────────────────────────────
    // Health Notice (Day 7 — extended)
    // ──────────────────────────────────────────────────────────────────────────

    public static function render_health_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $screen = get_current_screen();
        if ( $screen && 'settings_page_servertrack' === $screen->id ) return;

        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        if ( get_option( 'servertrack_source_woo_enabled', 1 ) && ! class_exists( 'WooCommerce' ) ) {
            $settings_url = admin_url( 'options-general.php?page=servertrack&tab=sources' );
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo '<strong>' . esc_html__( 'ServerTrack:', 'servertrack' ) . '</strong> ';
            echo esc_html__( 'WooCommerce source is enabled but WooCommerce is not active. No purchase events will fire.', 'servertrack' );
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
        echo esc_html__( 'Plugin is active but no ad platforms are configured. Events will not be sent until at least one platform is set up.', 'servertrack' );
        echo ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Configure now →', 'servertrack' ) . '</a>';
        echo '</p></div>';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AJAX handlers
    // ──────────────────────────────────────────────────────────────────────────

    public static function ajax_clear_log() {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        ServerTrack_Logger::clear_logs();
        wp_send_json_success( [ 'message' => __( 'Log cleared.', 'servertrack' ) ] );
    }

    public static function ajax_test_event() {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        $platform = isset( $_POST['platform'] ) ? sanitize_text_field( wp_unslash( $_POST['platform'] ) ) : '';
        $result   = self::fire_test_event( $platform );
        wp_send_json_success( $result );
    }

    public static function ajax_get_logs() {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        $logs = get_option( 'servertrack_debug_log', [] );
        wp_send_json_success( $logs );
    }

    private static function fire_test_event( string $platform ): array {
        $event_id = 'test_event_' . time();
        $event    = new ServerTrack_Event( 'Lead', $event_id );
        $event->set_user_data( [
            'email'      => ServerTrack_Hasher::hash_email( 'test@example.com' ),
            'first_name' => ServerTrack_Hasher::hash( 'Test' ),
            'last_name'  => ServerTrack_Hasher::hash( 'User' ),
            'ip'         => '127.0.0.1',
            'user_agent' => 'ServerTrack/Test',
        ] );
        $event->set_custom_data( [ 'currency' => 'USD', 'value' => 1.00, 'contents' => [] ] );
        switch ( $platform ) {
            case 'meta':   return ServerTrack_Meta::send( $event );
            case 'google': return ServerTrack_Google::send( $event );
            case 'tiktok': return ServerTrack_TikTok::send( $event );
        }
        return [ 'status' => 'error', 'message' => 'Unknown platform.' ];
    }
}
