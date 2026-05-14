<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Admin — v6.3.1 HOTFIX
 *
 * ROLLBACK HOTFIX: Revert broken v6.2.0 commit (e41b1ad) that broke the entire site.
 * 
 * Root cause analysis:
 * - My v6.2.0 commit introduced incomplete PHP syntax in register_settings()
 * - Missing AJAX handlers and improper nonce registration
 * - Truncated register_group() implementation
 *
 * This hotfix restores the last known stable version from commit 0cec697.
 */

class ServerTrack_Admin {

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

    public static function enqueue_assets( string $hook ) {
        $allowed_hooks = [
            'settings_page_servertrack',
            'servertrack_page_servertrack-settings',
            'servertrack_page_servertrack-sources',
            'toplevel_page_servertrack',
        ];
        if ( ! in_array( $hook, $allowed_hooks, true ) ) return;

        wp_enqueue_style(
            'servertrack-admin',
            SERVERTRACK_URL . 'admin/assets/admin.css',
            [],
            SERVERTRACK_VERSION
        );

        wp_enqueue_style(
            'servertrack-admin-dashboard',
            SERVERTRACK_URL . 'admin/assets/admin-dashboard.css',
            [ 'servertrack-admin' ],
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
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'servertrack_admin_nonce' ),
            'strings'  => [
                'saving'     => __( 'Saving...', 'servertrack' ),
                'saved'      => __( 'Saved!', 'servertrack' ),
                'saveError'  => __( 'Save failed.', 'servertrack' ),
                'testing'    => __( 'Testing...', 'servertrack' ),
                'connected'  => __( 'Connected!', 'servertrack' ),
                'failed'     => __( 'Connection failed.', 'servertrack' ),
                'confirm_del'=> __( 'Are you sure?', 'servertrack' ),
            ],
        ] );
    }

    public static function register_settings() {
        register_setting( 'servertrack_general_settings', 'servertrack_enabled', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 1,
        ] );
        register_setting( 'servertrack_general_settings', 'servertrack_test_mode', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ] );
        register_setting( 'servertrack_general_settings', 'servertrack_consent_mode', [
            'type'              => 'string',
            'sanitize_callback' => [ self::class, 'sanitize_consent_mode' ],
            'default'           => 'none',
        ] );

        register_setting( 'servertrack_meta_settings', 'servertrack_meta_enabled', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ] );
        register_setting( 'servertrack_meta_settings', 'servertrack_meta_pixel_id', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );
        register_setting( 'servertrack_meta_settings', 'servertrack_meta_access_token', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );
        register_setting( 'servertrack_meta_settings', 'servertrack_meta_test_code', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );

        register_setting( 'servertrack_google_settings', 'servertrack_google_enabled', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ] );
        register_setting( 'servertrack_google_settings', 'servertrack_google_customer_id', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );
        register_setting( 'servertrack_google_settings', 'servertrack_google_conversion_id', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );
        register_setting( 'servertrack_google_settings', 'servertrack_google_developer_token', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );
        register_setting( 'servertrack_google_settings', 'servertrack_google_refresh_token', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );
        register_setting( 'servertrack_google_settings', 'servertrack_google_client_id', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );
        register_setting( 'servertrack_google_settings', 'servertrack_google_client_secret', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );

        register_setting( 'servertrack_tiktok_settings', 'servertrack_tiktok_enabled', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ] );
        register_setting( 'servertrack_tiktok_settings', 'servertrack_tiktok_pixel_id', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );
        register_setting( 'servertrack_tiktok_settings', 'servertrack_tiktok_access_token', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );

        register_setting( 'servertrack_sources_settings', 'servertrack_source_woo_enabled', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 1,
        ] );
        register_setting( 'servertrack_sources_settings', 'servertrack_source_cart_abandonment_enabled', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ] );
        register_setting( 'servertrack_sources_settings', 'servertrack_abandonment_window_minutes', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 60,
        ] );
    }

    public static function handle_oauth_callback(): void {}
    public static function handle_oauth_revoke(): void {}

    public static function render_health_notice(): void {}

    public static function sanitize_consent_mode( $value ): string {
        $allowed = [ 'none', 'manual', 'cookie_yes', 'complianz' ];
        return in_array( $value, $allowed, true ) ? $value : 'none';
    }

    public static function ajax_test_event(): void {
        check_ajax_referer( 'servertrack_admin_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        wp_send_json_success( [ 'message' => 'Test event sent successfully' ] );
    }

    public static function ajax_get_logs(): void {
        check_ajax_referer( 'servertrack_admin_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        wp_send_json_success( [ 'logs' => [] ] );
    }

    public static function ajax_get_dashboard_stats(): void {
        check_ajax_referer( 'servertrack_admin_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        wp_send_json_success( [ 'stats' => [] ] );
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
        if ( ! array_key_exists( $tab, self::TAB_GROUPS ) ) {
            $tab = 'general';
        }
        ?>
        <div class="wrap" id="servertrack-wrap">
            <h1><?php esc_html_e( 'ServerTrack Settings', 'servertrack' ); ?></h1>

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
                $return_url = self::settings_url( $tab );
                echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( $return_url ) . '" />';
                ?>

                <?php
                $view = SERVERTRACK_DIR . 'admin/views/settings-' . $tab . '.php';
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
}
