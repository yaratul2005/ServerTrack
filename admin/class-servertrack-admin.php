<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServerTrack_Admin {

    public static function init() {
        add_action( 'admin_menu', [ self::class, 'register_menu' ] );
        add_action( 'admin_init', [ self::class, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'admin_notices', [ self::class, 'render_health_notice' ] );
        add_action( 'wp_ajax_servertrack_clear_log', [ self::class, 'ajax_clear_log' ] );
        add_action( 'wp_ajax_servertrack_test_event', [ self::class, 'ajax_test_event' ] );
        add_action( 'wp_ajax_servertrack_get_logs', [ self::class, 'ajax_get_logs' ] );
    }

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
        if ( 'settings_page_servertrack' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'servertrack-admin', SERVERTRACK_URL . 'admin/assets/admin.css', [], SERVERTRACK_VERSION );
        wp_enqueue_script( 'servertrack-admin', SERVERTRACK_URL . 'admin/assets/admin.js', [ 'jquery' ], SERVERTRACK_VERSION, true );
        wp_localize_script( 'servertrack-admin', 'servertrack_admin', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'servertrack_admin_nonce' ),
        ] );
    }

    public static function register_settings() {
        $options = [
            'servertrack_enabled', 'servertrack_test_mode', 'servertrack_consent_mode',
            'servertrack_meta_enabled', 'servertrack_meta_pixel_id', 'servertrack_meta_access_token', 'servertrack_meta_test_event_code',
            'servertrack_google_enabled', 'servertrack_google_customer_id', 'servertrack_google_conversion_id',
            'servertrack_google_developer_token', 'servertrack_google_client_id', 'servertrack_google_client_secret',
            'servertrack_google_refresh_token',
            'servertrack_tiktok_enabled', 'servertrack_tiktok_pixel_id', 'servertrack_tiktok_access_token',
            'servertrack_source_woo_enabled', 'servertrack_source_cf7_enabled', 'servertrack_source_edd_enabled',
            'servertrack_cf7_mappings', // JSON field mapping per CF7 form ID
        ];
        foreach ( $options as $option ) {
            // Skip cf7_mappings in this generic loop because it requires a special array sanitization
            if ( 'servertrack_cf7_mappings' === $option ) continue;
            
            register_setting( 'servertrack_settings', $option, [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ] );
        }

        // CF7 mappings is an array — sanitize each nested value
        register_setting( 'servertrack_settings', 'servertrack_cf7_mappings', [
            'sanitize_callback' => [ self::class, 'sanitize_cf7_mappings' ],
        ] );
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
        ?>
        <div class="wrap" id="servertrack-wrap">
            <h1 class="servertrack-page-title">
                <span class="servertrack-logo-mark">&#9654;</span>
                <?php esc_html_e( 'ServerTrack — Server-Side Events', 'servertrack' ); ?>
            </h1>

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

    public static function ajax_clear_log() {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        ServerTrack_Logger::clear_logs();
        wp_send_json_success( [ 'message' => __( 'Log cleared.', 'servertrack' ) ] );
    }

    public static function ajax_test_event( ) {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $platform = isset( $_POST['platform'] ) ? sanitize_text_field( wp_unslash( $_POST['platform'] ) ) : '';
        $result   = self::fire_test_event( $platform );
        wp_send_json_success( $result );
    }

    public static function ajax_get_logs() {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $logs = get_option( 'servertrack_debug_log', [] );
        wp_send_json_success( $logs );
    }

    private static function fire_test_event( string $platform ): array {
        require_once SERVERTRACK_DIR . 'platforms/class-servertrack-meta.php';
        require_once SERVERTRACK_DIR . 'platforms/class-servertrack-google.php';
        require_once SERVERTRACK_DIR . 'platforms/class-servertrack-tiktok.php';

        $event_id = 'test_event_' . time();

        $event = new ServerTrack_Event( 'Lead', $event_id );
        $event->set_user_data( [
            'email'      => ServerTrack_Hasher::hash_email( 'test@example.com' ),
            'first_name' => ServerTrack_Hasher::hash( 'Test' ),
            'last_name'  => ServerTrack_Hasher::hash( 'User' ),
            'ip'         => '127.0.0.1',
            'user_agent' => 'ServerTrack/Test',
        ] );
        $event->set_custom_data( [ 'currency' => 'USD', 'value' => 1.00, 'contents' => [] ] );

        switch ( $platform ) {
            case 'meta':
                return ServerTrack_Meta::send( $event );
            case 'google':
                return ServerTrack_Google::send( $event );
            case 'tiktok':
                return ServerTrack_TikTok::send( $event );
        }
        return [ 'status' => 'error', 'message' => 'Unknown platform.' ];
    }

    /**
     * Renders an admin-wide health notice if the plugin is active but
     * no platforms are enabled — guides the user to configure the plugin.
     */
    public static function render_health_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Suppress on the plugin's own settings page — not helpful there
        $screen = get_current_screen();
        if ( $screen && 'settings_page_servertrack' === $screen->id ) return;

        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        $meta_ok   = get_option( 'servertrack_meta_enabled', 0 ) && get_option( 'servertrack_meta_pixel_id', '' ) && get_option( 'servertrack_meta_access_token', '' );
        $google_ok = get_option( 'servertrack_google_enabled', 0 ) && get_option( 'servertrack_google_customer_id', '' ) && get_option( 'servertrack_google_refresh_token', '' );
        $tiktok_ok = get_option( 'servertrack_tiktok_enabled', 0 ) && get_option( 'servertrack_tiktok_pixel_id', '' ) && get_option( 'servertrack_tiktok_access_token', '' );

        if ( $meta_ok || $google_ok || $tiktok_ok ) return; // At least one platform is good — no notice needed

        $settings_url = admin_url( 'options-general.php?page=servertrack&tab=meta' );
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong><?php esc_html_e( 'ServerTrack:', 'servertrack' ); ?></strong>
                <?php esc_html_e( 'Plugin is active but no ad platforms are configured. Events will not be sent until at least one platform is set up.', 'servertrack' ); ?>
                &nbsp;<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Configure now →', 'servertrack' ); ?></a>
            </p>
        </div>
        <?php
    }
}
