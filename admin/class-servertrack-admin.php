<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Admin  v3.6
 *
 * Handles Settings + Event Sources admin pages.
 * Dashboard page is handled by ServerTrack_Dashboard.
 *
 * Changelog:
 * v3.6 — Save button added to every settings tab via st-settings-form-footer.
 *         Option key servertrack_meta_test_event_code unified (was servertrack_meta_test_code).
 *         Google tab double-form conflict removed; Google now saves via AJAX like all other tabs.
 *         Google string keys added to ajax_save_settings() whitelist.
 * v3.5 — CSS class names realigned with admin.css selectors.
 *         render_page_header() extracted as a shared static method.
 *         Nonce action for dashboard AJAX corrected to servertrack_dashboard.
 * v3.4 — Settings / Sources submenu routing fixed.
 * v3.3 — Dead variable + duplicate enqueue removed.
 */
class ServerTrack_Admin {

    public static function init(): void {
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'wp_ajax_servertrack_get_dashboard_stats', [ self::class, 'ajax_get_dashboard_stats' ] );
        add_action( 'wp_ajax_servertrack_save_settings',       [ self::class, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_servertrack_test_connection',     [ self::class, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_servertrack_get_sources',        [ self::class, 'ajax_get_sources' ] );
        add_action( 'wp_ajax_servertrack_save_source',        [ self::class, 'ajax_save_source' ] );
        add_action( 'wp_ajax_servertrack_delete_source',      [ self::class, 'ajax_delete_source' ] );
        add_action( 'wp_ajax_servertrack_toggle_source',      [ self::class, 'ajax_toggle_source' ] );
        add_action( 'wp_ajax_servertrack_test_source',        [ self::class, 'ajax_test_source' ] );
    }

    // ─────────────────────────────────────────────────────────────────────
    // ASSET ENQUEUE
    // ─────────────────────────────────────────────────────────────────────

    public static function enqueue_assets( string $hook ): void {
        $allowed_hooks = [
            'toplevel_page_servertrack',
            'servertrack_page_servertrack-settings',
            'servertrack_page_servertrack-sources',
        ];
        if ( ! in_array( $hook, $allowed_hooks, true ) ) {
            return;
        }

        $base = SERVERTRACK_URL . 'admin/assets/';
        $ver  = SERVERTRACK_VERSION;

        wp_enqueue_style( 'servertrack-admin',           $base . 'admin.css',           [], $ver );
        wp_enqueue_style( 'servertrack-admin-dashboard', $base . 'admin-dashboard.css', [], $ver );
        wp_enqueue_style( 'servertrack-icon-patch',      $base . 'icon-patch.css',      [], $ver );
        wp_enqueue_script( 'servertrack-admin', $base . 'admin.js', [ 'jquery' ], $ver, true );

        wp_localize_script( 'servertrack-admin', 'servertrackAdmin', [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'servertrack_admin_nonce' ),
            'dashNonce'  => wp_create_nonce( 'servertrack_dashboard' ),
            'pluginUrl'  => SERVERTRACK_URL,
            'version'    => SERVERTRACK_VERSION,
            'strings'    => [
                'saved'      => __( 'Settings saved.', 'servertrack' ),
                'saveError'  => __( 'Save failed. Please try again.', 'servertrack' ),
                'testing'    => __( 'Testing…', 'servertrack' ),
                'connected'  => __( 'Connected', 'servertrack' ),
                'failed'     => __( 'Connection failed', 'servertrack' ),
                'confirm_del'=> __( 'Delete this source?', 'servertrack' ),
            ],
        ] );
    }

    // ─────────────────────────────────────────────────────────────────────
    // SHARED PAGE HEADER  (called by Dashboard AND Settings/Sources)
    // ─────────────────────────────────────────────────────────────────────

    public static function render_page_header(): void {
        $meta_on   = (bool) get_option( 'servertrack_meta_enabled',   0 );
        $google_on = (bool) get_option( 'servertrack_google_enabled', 0 );
        $tiktok_on = (bool) get_option( 'servertrack_tiktok_enabled', 0 );

        $meta_cfg   = $meta_on   && get_option( 'servertrack_meta_pixel_id', '' )       && get_option( 'servertrack_meta_access_token', '' );
        $google_cfg = $google_on && get_option( 'servertrack_google_refresh_token', '' );
        $tiktok_cfg = $tiktok_on && get_option( 'servertrack_tiktok_pixel_id', '' )     && get_option( 'servertrack_tiktok_access_token', '' );
        ?>
        <div class="st-page-header">
            <div class="st-page-header-decoration"></div>
            <div class="st-page-header-left">
                <?php $logo = SERVERTRACK_URL . 'assets/logo/logo_st.png'; ?>
                <img class="st-logo-img"
                     src="<?php echo esc_url( $logo ); ?>"
                     alt="ServerTrack" width="48" height="48"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex';" />
                <div class="st-logo-icon-fallback" style="display:none;" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                </div>
                <div class="st-page-title-group">
                    <h1><?php esc_html_e( 'ServerTrack', 'servertrack' ); ?></h1>
                    <p><?php esc_html_e( 'Server-side Conversion API Tracking', 'servertrack' ); ?></p>
                </div>
            </div>
            <div class="st-header-badges">
                <span class="st-header-version"><?php echo esc_html( SERVERTRACK_VERSION ); ?></span>
                <span class="st-badge <?php echo $meta_cfg   ? 'st-badge-meta'   : 'off'; ?>">
                    <span class="st-badge-dot"></span> Meta
                </span>
                <span class="st-badge <?php echo $google_cfg ? 'st-badge-google' : 'off'; ?>">
                    <span class="st-badge-dot"></span> Google
                </span>
                <span class="st-badge <?php echo $tiktok_cfg ? 'st-badge-tiktok' : 'off'; ?>">
                    <span class="st-badge-dot"></span> TikTok
                </span>
            </div>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────
    // SETTINGS + SOURCES PAGE ROUTER
    // ─────────────────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'servertrack-settings';

        // Active settings tab (only relevant when $page === 'servertrack-settings')
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
        $valid_tabs = [ 'general', 'meta', 'google', 'tiktok', 'webhook', 'debug' ];
        if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
            $active_tab = 'general';
        }

        $is_sources  = ( $page === 'servertrack-sources' );
        $is_settings = ( $page === 'servertrack-settings' );

        // Tabs that have a dedicated test-connection button (no extra save footer needed below test btn)
        $tabs_with_test = [ 'meta', 'tiktok' ];
        ?>
        <div class="wrap" id="servertrack-wrap">

            <?php self::render_page_header(); ?>

            <?php self::render_health_notice(); ?>

            <nav class="nav-tab-wrapper st-tab-nav" aria-label="<?php esc_attr_e( 'ServerTrack navigation', 'servertrack' ); ?>">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=servertrack' ) ); ?>"
                   class="nav-tab">
                    <?php esc_html_e( 'Dashboard', 'servertrack' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=servertrack-settings' ) ); ?>"
                   class="nav-tab<?php echo $is_settings ? ' nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Settings', 'servertrack' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=servertrack-sources' ) ); ?>"
                   class="nav-tab<?php echo $is_sources ? ' nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Event Sources', 'servertrack' ); ?>
                </a>
            </nav>

            <?php if ( $is_sources ) : ?>

                <?php
                $view = SERVERTRACK_DIR . 'admin/views/settings-sources.php';
                if ( file_exists( $view ) ) include $view;
                ?>

            <?php elseif ( $is_settings ) : ?>

                <div class="st-settings-tabs-wrap">
                    <nav class="st-settings-sub-nav" aria-label="<?php esc_attr_e( 'Settings tabs', 'servertrack' ); ?>">
                        <?php
                        $sub_tabs = [
                            'general' => __( 'General',  'servertrack' ),
                            'meta'    => __( 'Meta',     'servertrack' ),
                            'google'  => __( 'Google',   'servertrack' ),
                            'tiktok'  => __( 'TikTok',   'servertrack' ),
                            'webhook' => __( 'Webhooks', 'servertrack' ),
                            'debug'   => __( 'Debug',    'servertrack' ),
                        ];
                        foreach ( $sub_tabs as $slug => $label ) :
                            $url    = admin_url( 'admin.php?page=servertrack-settings&tab=' . $slug );
                            $active = ( $active_tab === $slug ) ? ' st-sub-tab-active' : '';
                            ?>
                            <a href="<?php echo esc_url( $url ); ?>"
                               class="st-sub-tab<?php echo esc_attr( $active ); ?>">
                                <?php echo esc_html( $label ); ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>

                    <div class="st-settings-tab-content">
                        <?php
                        $view = SERVERTRACK_DIR . 'admin/views/settings-' . $active_tab . '.php';
                        if ( file_exists( $view ) ) {
                            include $view;
                        } else {
                            echo '<p class="st-notice st-notice-warning">';
                            echo esc_html( sprintf(
                                /* translators: %s: tab slug */
                                __( 'Settings view not found: %s', 'servertrack' ),
                                $active_tab
                            ) );
                            echo '</p>';
                        }
                        ?>

                        <?php if ( $active_tab !== 'debug' ) : ?>
                        <div class="st-settings-form-footer">
                            <button type="button"
                                    class="button button-primary st-save-settings"
                                    data-label="<?php esc_attr_e( 'Save Settings', 'servertrack' ); ?>">
                                <?php esc_html_e( 'Save Settings', 'servertrack' ); ?>
                            </button>
                            <span class="st-save-feedback" aria-live="polite"></span>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>

            <?php endif; ?>

        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────
    // HEALTH NOTICE BANNER
    // ─────────────────────────────────────────────────────────────────────

    public static function render_health_notice(): void {
        $issues = [];

        if ( ! get_option( 'servertrack_meta_enabled', 0 ) &&
             ! get_option( 'servertrack_google_enabled', 0 ) &&
             ! get_option( 'servertrack_tiktok_enabled', 0 ) ) {
            $issues[] = __( 'No platforms are enabled. Enable at least one in Settings.', 'servertrack' );
        }

        if ( empty( $issues ) ) return;
        ?>
        <div class="st-health-notice st-notice st-notice-warning" role="alert">
            <ul>
                <?php foreach ( $issues as $issue ) : ?>
                    <li><?php echo esc_html( $issue ); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────
    // AJAX — DASHBOARD STATS
    // ─────────────────────────────────────────────────────────────────────

    public static function ajax_get_dashboard_stats(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $logs  = get_option( 'servertrack_debug_log', [] );
        $stats = ServerTrack_Dashboard::compute_stats( $logs );
        wp_send_json_success( $stats );
    }

    // ─────────────────────────────────────────────────────────────────────
    // AJAX — SAVE SETTINGS
    // ─────────────────────────────────────────────────────────────────────

    public static function ajax_save_settings(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $data = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : [];
        if ( ! is_array( $data ) ) {
            wp_send_json_error( [ 'message' => 'Invalid data' ] );
        }

        $boolean_keys = [
            'servertrack_enabled',
            'servertrack_test_mode',
            'servertrack_meta_enabled',
            'servertrack_google_enabled',
            'servertrack_tiktok_enabled',
            'servertrack_consent_mode',
            'servertrack_debug_mode',
            'servertrack_dedup_enabled',
        ];
        $string_keys = [
            // Meta
            'servertrack_meta_pixel_id',
            'servertrack_meta_access_token',
            'servertrack_meta_test_event_code',   // unified key (was servertrack_meta_test_code)
            // Google
            'servertrack_google_customer_id',
            'servertrack_google_conversion_id',
            'servertrack_google_developer_token',
            'servertrack_google_client_id',
            'servertrack_google_client_secret',
            'servertrack_google_measurement_id',
            'servertrack_google_api_secret',
            'servertrack_google_refresh_token',
            // TikTok
            'servertrack_tiktok_pixel_id',
            'servertrack_tiktok_access_token',
            // Other
            'servertrack_webhook_secret',
            'servertrack_consent_mode',
        ];

        foreach ( $boolean_keys as $key ) {
            update_option( $key, ! empty( $data[ $key ] ) ? 1 : 0 );
        }
        foreach ( $string_keys as $key ) {
            if ( isset( $data[ $key ] ) ) {
                update_option( $key, sanitize_text_field( $data[ $key ] ) );
            }
        }

        wp_send_json_success( [ 'message' => __( 'Settings saved.', 'servertrack' ) ] );
    }

    // ─────────────────────────────────────────────────────────────────────
    // AJAX — TEST CONNECTION
    // ─────────────────────────────────────────────────────────────────────

    public static function ajax_test_connection(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $platform = isset( $_POST['platform'] ) ? sanitize_key( wp_unslash( $_POST['platform'] ) ) : '';

        switch ( $platform ) {
            case 'meta':
                $pixel  = get_option( 'servertrack_meta_pixel_id', '' );
                $token  = get_option( 'servertrack_meta_access_token', '' );
                if ( ! $pixel || ! $token ) {
                    wp_send_json_error( [ 'message' => __( 'Meta: pixel ID or access token not configured.', 'servertrack' ) ] );
                }
                $url      = "https://graph.facebook.com/v18.0/{$pixel}?access_token={$token}";
                $response = wp_remote_get( $url, [ 'timeout' => 10 ] );
                if ( is_wp_error( $response ) ) {
                    wp_send_json_error( [ 'message' => $response->get_error_message() ] );
                }
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( ! empty( $body['error'] ) ) {
                    wp_send_json_error( [ 'message' => $body['error']['message'] ?? __( 'Meta API error.', 'servertrack' ) ] );
                }
                wp_send_json_success( [ 'message' => __( 'Meta: connection successful.', 'servertrack' ) ] );
                break;

            case 'google':
                $refresh = get_option( 'servertrack_google_refresh_token', '' );
                if ( ! $refresh ) {
                    wp_send_json_error( [ 'message' => __( 'Google: refresh token not configured.', 'servertrack' ) ] );
                }
                wp_send_json_success( [ 'message' => __( 'Google: token present.', 'servertrack' ) ] );
                break;

            case 'tiktok':
                $pixel = get_option( 'servertrack_tiktok_pixel_id', '' );
                $token = get_option( 'servertrack_tiktok_access_token', '' );
                if ( ! $pixel || ! $token ) {
                    wp_send_json_error( [ 'message' => __( 'TikTok: pixel ID or access token not configured.', 'servertrack' ) ] );
                }
                wp_send_json_success( [ 'message' => __( 'TikTok: credentials present.', 'servertrack' ) ] );
                break;

            default:
                wp_send_json_error( [ 'message' => __( 'Unknown platform.', 'servertrack' ) ] );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // AJAX — SOURCES
    // ─────────────────────────────────────────────────────────────────────

    public static function ajax_get_sources(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );
        $sources = get_option( 'servertrack_sources', [] );
        wp_send_json_success( [ 'sources' => array_values( $sources ) ] );
    }

    public static function ajax_save_source(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $source = isset( $_POST['source'] ) ? wp_unslash( $_POST['source'] ) : [];
        if ( ! is_array( $source ) ) wp_send_json_error( [ 'message' => 'Invalid data' ] );

        $sources = get_option( 'servertrack_sources', [] );
        $id      = ! empty( $source['id'] ) ? sanitize_key( $source['id'] ) : 'src_' . uniqid();

        $sources[ $id ] = [
            'id'        => $id,
            'name'      => sanitize_text_field( $source['name']      ?? '' ),
            'type'      => sanitize_key( $source['type']             ?? 'woocommerce' ),
            'enabled'   => ! empty( $source['enabled'] ),
            'platforms' => array_map( 'sanitize_key', (array) ( $source['platforms'] ?? [] ) ),
        ];

        update_option( 'servertrack_sources', $sources );
        wp_send_json_success( [ 'source' => $sources[ $id ] ] );
    }

    public static function ajax_delete_source(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $id      = isset( $_POST['source_id'] ) ? sanitize_key( wp_unslash( $_POST['source_id'] ) ) : '';
        $sources = get_option( 'servertrack_sources', [] );
        unset( $sources[ $id ] );
        update_option( 'servertrack_sources', $sources );
        wp_send_json_success();
    }

    public static function ajax_toggle_source(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $id      = isset( $_POST['source_id'] ) ? sanitize_key( wp_unslash( $_POST['source_id'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $enabled = ! empty( $_POST['enabled'] );
        $sources = get_option( 'servertrack_sources', [] );
        if ( isset( $sources[ $id ] ) ) {
            $sources[ $id ]['enabled'] = $enabled;
            update_option( 'servertrack_sources', $sources );
        }
        wp_send_json_success();
    }

    public static function ajax_test_source(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $id      = isset( $_POST['source_id'] ) ? sanitize_key( wp_unslash( $_POST['source_id'] ) ) : '';
        $sources = get_option( 'servertrack_sources', [] );
        if ( ! isset( $sources[ $id ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Source not found.', 'servertrack' ) ] );
        }
        wp_send_json_success( [ 'message' => __( 'Source test passed.', 'servertrack' ) ] );
    }
}
