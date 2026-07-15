<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ratul_ACT_Admin — v3.2
 *
 * Changes in v3.2:
 *   C1  — Nested <form> bug. All 5 view files had their own <form> +
 *         settings_fields() wrapped inside render_page()'s outer <form>.
 *         Browsers close the outer form at the first inner <form> tag,
 *         stripping the B2 _wp_http_referer override, the admin nonce,
 *         and the outer submit_button(). Fixed: views now contain ONLY
 *         the <table> + submit_button() — no <form> or settings_fields().
 *
 *   C2  — ratul_act_source_woo_enabled was in the Sources view but
 *         never registered. Added to ratul_act_sources_settings.
 *
 *   C3  — Sources view used ratul_act_source_abandonment_enabled;
 *         register_settings() had _cart_abandonment_enabled. Aligned
 *         both to ratul_act_source_cart_abandonment_enabled.
 *
 *   C4  — ratul_act_abandonment_window_minutes used in view but never
 *         registered. Added (integer, absint, default 60).
 *
 *   C5  — ratul_act_source_cf7_enabled not registered. Added.
 *
 *   C6  — ratul_act_source_edd_enabled not registered. Added.
 *
 *   C7  — ratul_act_source_subscriptions_enabled registered but had
 *         no UI field. Added a Subscriptions row to the Sources view.
 *
 *   C8  — render_health_notice() checked for screen ID
 *         ratul_act_page_ratul-ads-conversion-tracker-sources (non-existent). Removed.
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
 *   FIX A3 — Removed duplicate wp_ajax_ratul_act_clear_log registration.
 *   FIX A4 — render_health_notice() now only renders on Ratul_ACT admin pages.
 *
 * Changes in v2.8:
 *   FIX BUG-FIX-4 — register_settings() now registers the three source
 *   options that were previously missing.
 */
class Ratul_ACT_Admin {

    /**
     * Map: tab slug => option group name.
     * Single source of truth — render_page() calls settings_fields() with
     * the matching group. Views must NOT call settings_fields() themselves.
     */
    const TAB_GROUPS = [
        'general' => 'ratul_act_general_settings',
        'meta'    => 'ratul_act_meta_settings',
        'google'  => 'ratul_act_google_settings',
        'tiktok'  => 'ratul_act_tiktok_settings',
        'sources' => 'ratul_act_sources_settings',
        'license' => 'ratul_act_license_settings',
    ];

    private static function settings_url( string $tab = '', array $extra = [] ): string {
        $args = array_merge( [ 'page' => 'ratul-ads-conversion-tracker-settings' ], $extra );
        if ( $tab !== '' ) {
            $args['tab'] = $tab;
        }
        return admin_url( 'admin.php?' . http_build_query( $args ) );
    }

    public static function init() {
        add_action( 'admin_init',            [ self::class, 'register_settings' ] );
        add_action( 'admin_init',            [ self::class, 'handle_oauth_callback' ] );
        add_action( 'admin_init',            [ self::class, 'handle_oauth_revoke' ] );
        add_action( 'admin_init',            [ self::class, 'handle_license_actions' ] );
        add_filter( 'woocommerce_admin_order_actions', [ self::class, 'add_manual_purchase_order_action' ], 10, 2 );
        add_action( 'admin_head', [ self::class, 'add_manual_purchase_order_action_css' ] );
        add_action( 'admin_action_ratul_act_manual_purchase', [ self::class, 'handle_manual_purchase_action' ] );
        add_action( 'add_meta_boxes', [ self::class, 'add_manual_purchase_meta_box' ] );
        add_action( 'admin_notices', [ self::class, 'render_manual_purchase_notice' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'admin_notices',         [ self::class, 'render_health_notice' ] );
        add_action( 'wp_ajax_ratul_act_test_event',          [ self::class, 'ajax_test_event' ] );
        add_action( 'wp_ajax_ratul_act_get_logs',            [ self::class, 'ajax_get_logs' ] );
        add_action( 'wp_ajax_ratul_act_get_dashboard_stats', [ self::class, 'ajax_get_dashboard_stats' ] );
        add_action( 'wp_ajax_ratul_act_verify_credentials',  [ self::class, 'ajax_verify_credentials' ] );

        // Custom columns hooks for manual CAPI approval
        add_filter( 'manage_edit-shop_order_columns',        [ self::class, 'add_orders_column' ] );
        add_action( 'manage_shop_order_posts_custom_column', [ self::class, 'render_orders_column_content' ], 10, 2 );
        add_filter( 'manage_woocommerce_page_wc-orders_columns',        [ self::class, 'add_orders_column' ] );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ self::class, 'render_orders_column_content' ], 10, 2 );
        add_action( 'admin_action_ratul_act_mark_fraud', [ self::class, 'handle_mark_fraud_action' ] );
    }

    // ─────────────────────────────────────────────────────────────────
    // Assets
    // ─────────────────────────────────────────────────────────────────

    public static function enqueue_assets( string $hook ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $allowed_pages = [
            'ratul-ads-conversion-tracker',
            'ratul-ads-conversion-tracker-settings',
            'ratul-ads-conversion-tracker-attribution',
            'ratul-ads-conversion-tracker-offline',
        ];
        
        if ( ! in_array( $current_page, $allowed_pages, true ) ) {
            return;
        }

        wp_enqueue_style(
            'ratul-ads-conversion-tracker-admin-base',
            RATUL_ACT_URL . 'admin/assets/css/base.css',
            [],
            RATUL_ACT_VERSION
        );
        wp_enqueue_style(
            'ratul-ads-conversion-tracker-admin-tabs',
            RATUL_ACT_URL . 'admin/assets/css/tabs.css',
            [ 'ratul-ads-conversion-tracker-admin-base' ],
            RATUL_ACT_VERSION
        );
        wp_enqueue_style(
            'ratul-ads-conversion-tracker-admin-dashboard',
            RATUL_ACT_URL . 'admin/assets/css/dashboard.css',
            [ 'ratul-ads-conversion-tracker-admin-base' ],
            RATUL_ACT_VERSION
        );
        wp_enqueue_style(
            'ratul-ads-conversion-tracker-admin-settings',
            RATUL_ACT_URL . 'admin/assets/css/settings.css',
            [ 'ratul-ads-conversion-tracker-admin-base' ],
            RATUL_ACT_VERSION
        );
        wp_enqueue_script(
            'ratul-ads-conversion-tracker-admin',
            RATUL_ACT_URL . 'admin/assets/admin.js',
            [ 'jquery' ],
            RATUL_ACT_VERSION,
            true
        );
        wp_localize_script( 'ratul-ads-conversion-tracker-admin', 'ratul_act_admin', [
            'ajax_url'        => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'ratul_act_admin_nonce' ),
            'dashboard_nonce' => wp_create_nonce( 'ratul_act_dashboard' ),
            'platforms' => [
                'meta'   => [
                    'enabled'    => (bool) get_option( 'ratul_act_meta_enabled', 0 ),
                    'configured' => (bool) (
                        get_option( 'ratul_act_meta_pixel_id', '' ) &&
                        get_option( 'ratul_act_meta_access_token', '' )
                    ),
                ],
                'google' => [
                    'enabled'    => (bool) get_option( 'ratul_act_google_enabled', 0 ),
                    'configured' => (bool) get_option( 'ratul_act_google_refresh_token', '' ),
                ],
                'tiktok' => [
                    'enabled'    => (bool) get_option( 'ratul_act_tiktok_enabled', 0 ),
                    'configured' => (bool) (
                        get_option( 'ratul_act_tiktok_pixel_id', '' ) &&
                        get_option( 'ratul_act_tiktok_access_token', '' )
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
            'ratul_act_enabled'      => [ 'type' => 'integer', 'sanitize' => 'absint',                                  'default' => 1      ],
            'ratul_act_test_mode'    => [ 'type' => 'integer', 'sanitize' => 'absint',                                  'default' => 0      ],
            'ratul_act_consent_mode' => [ 'type' => 'string',  'sanitize' => [ self::class, 'sanitize_consent_mode' ],  'default' => 'none' ],
        ];
        self::register_group( 'ratul_act_general_settings', $general_options );

        $meta_options = [
            'ratul_act_meta_enabled'         => [ 'type' => 'integer', 'sanitize' => 'absint',              'default' => 0  ],
            'ratul_act_meta_pixel_id'        => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'ratul_act_meta_access_token'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'ratul_act_meta_test_event_code' => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'ratul_act_meta_am_email'   => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
            'ratul_act_meta_am_phone'   => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
            'ratul_act_meta_am_name'    => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
            'ratul_act_meta_am_city'    => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
            'ratul_act_meta_am_state'   => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
            'ratul_act_meta_am_zip'     => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
            'ratul_act_meta_am_country' => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
        ];
        self::register_group( 'ratul_act_meta_settings', $meta_options );

        $google_options = [
            'ratul_act_google_enabled'          => [ 'type' => 'integer', 'sanitize' => 'absint',              'default' => 0  ],
            'ratul_act_google_customer_id'      => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'ratul_act_google_conversion_id'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'ratul_act_google_conversion_label' => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'ratul_act_google_developer_token'  => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'ratul_act_google_consent_ad_user_data' => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
            'ratul_act_google_consent_ad_personalization' => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
            'ratul_act_google_refresh_token'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'ratul_act_google_client_id'        => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'ratul_act_google_client_secret'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
        ];
        self::register_group( 'ratul_act_google_settings', $google_options );

        $tiktok_options = [
            'ratul_act_tiktok_enabled'      => [ 'type' => 'integer', 'sanitize' => 'absint',              'default' => 0  ],
            'ratul_act_tiktok_pixel_id'     => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'ratul_act_tiktok_access_token' => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
        ];
        self::register_group( 'ratul_act_tiktok_settings', $tiktok_options );

        /*
         * C2  — ratul_act_source_woo_enabled added (was in view, not registered).
         * C3  — key aligned to ratul_act_source_cart_abandonment_enabled
         *       (view had _abandonment_enabled, a different name).
         * C4  — ratul_act_abandonment_window_minutes added.
         * C5  — ratul_act_source_cf7_enabled added.
         * C6  — ratul_act_source_edd_enabled added.
         * C7  — ratul_act_source_subscriptions_enabled was already here;
         *       a UI toggle has been added to the Sources view.
         */
        $license_options = [
            'ratul_act_license_key' => [ 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'default' => '' ],
        ];
        self::register_group( 'ratul_act_license_settings', $license_options );

        $sources_options = [
            'ratul_act_source_woo_enabled'              => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1  ],
            'ratul_act_source_cart_abandonment_enabled' => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'ratul_act_abandonment_window_minutes'      => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 60 ],
            'ratul_act_manual_purchase_enabled'         => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'ratul_act_source_order_status_enabled'     => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1  ],
            'ratul_act_source_wishlist_enabled'         => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'ratul_act_source_partial_refund_enabled'   => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1  ],
            'ratul_act_source_cf7_enabled'              => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'ratul_act_source_edd_enabled'              => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'ratul_act_source_subscriptions_enabled'    => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
        ];
        self::register_group( 'ratul_act_sources_settings', $sources_options );
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
        if ( empty( $_GET['ratul_act_oauth'] ) || empty( $_GET['code'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        check_admin_referer( 'ratul_act_oauth_google', 'state' );
        $code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
        if ( class_exists( 'Ratul_ACT_Google_OAuth' ) ) {
            $result = Ratul_ACT_Google_OAuth::exchange_code( $code );
            $tab    = 'google';
            $extra  = $result ? [ 'oauth' => 'success' ] : [ 'oauth' => 'error' ];
        } else {
            $extra = [ 'oauth' => 'error' ];
            $tab   = 'google';
        }
        wp_safe_redirect( self::settings_url( $tab, $extra ) );
        exit;
    }

            public static function add_manual_purchase_order_action( $actions, $order ) {
        if ( ! get_option( 'ratul_act_manual_purchase_enabled', 0 ) ) {
            return $actions;
        }

        if ( $order->get_meta( '_ratul_act_manual_purchase_sent' ) === 'yes' ) {
            return $actions;
        }

        $url = wp_nonce_url( admin_url( 'admin.php?action=ratul_act_manual_purchase&order_id=' . $order->get_id() ), 'ratul_act_manual_purchase_' . $order->get_id() );

        $actions['st_manual_purchase'] = [
            'url'    => $url,
            'name'   => __( 'Fire CAPI Purchase Event', 'ratul-ads-conversion-tracker' ),
            'action' => 'st-manual-purchase',
        ];

        return $actions;
    }

    public static function add_manual_purchase_order_action_css() {
        if ( ! get_option( 'ratul_act_manual_purchase_enabled', 0 ) ) {
            return;
        }
        echo '<style>.wc-action-button-st-manual-purchase::after { font-family: dashicons; content: "\f502"; color: #0ea5a0; }</style>';
    }

        public static function add_manual_purchase_meta_box() {
        if ( ! get_option( 'ratul_act_manual_purchase_enabled', 0 ) ) {
            return;
        }

        $screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
            && function_exists('wc_get_page_screen_id')
            ? wc_get_page_screen_id( 'shop-order' )
            : 'shop_order';

        add_meta_box(
            'ratul_act_manual_purchase',
            __( 'Ratul_ACT - Purchase Event', 'ratul-ads-conversion-tracker' ),
            [ self::class, 'render_manual_purchase_meta_box' ],
            $screen,
            'side',
            'high'
        );
    }

    public static function render_manual_purchase_meta_box( $post_or_order_object ) {
        $order = ( $post_or_order_object instanceof WP_Post )
            ? wc_get_order( $post_or_order_object->ID )
            : $post_or_order_object;

        if ( ! $order ) {
            echo '<p>Could not load order.</p>';
            return;
        }

        $sent_platforms = $order->get_meta( '_ratul_act_server_sent' );
        if ( ! is_array( $sent_platforms ) ) {
            $sent_platforms = [];
        }
        $sent = $order->get_meta( '_ratul_act_manual_purchase_sent' ) === 'yes' || in_array( 'meta', $sent_platforms, true );
        $fraud = $order->get_meta( '_ratul_act_manual_purchase_fraud' ) === 'yes';
        $url = wp_nonce_url( admin_url( 'admin.php?action=ratul_act_manual_purchase&order_id=' . $order->get_id() ), 'ratul_act_manual_purchase_' . $order->get_id() );
        $fraud_url = wp_nonce_url( admin_url( 'admin.php?action=ratul_act_mark_fraud&order_id=' . $order->get_id() ), 'ratul_act_mark_fraud_' . $order->get_id() );

        if ( $sent ) {
            echo '<div style="color:#10b981; font-weight:600; padding:10px 0;"><span class="dashicons dashicons-yes-alt"></span> ' . __( 'Purchase event successfully synced.', 'ratul-ads-conversion-tracker' ) . '</div>';
        } elseif ( $fraud ) {
            echo '<div style="color:#ef4444; font-weight:600; padding:10px 0;"><span class="dashicons dashicons-warning"></span> ' . __( 'Order marked as fraud. Sync ignored.', 'ratul-ads-conversion-tracker' ) . '</div>';
            echo '<a href="' . esc_url( $url ) . '" class="button button-primary" style="width:100%; text-align:center; margin-top:8px;">' . __( 'Fire Purchase Event anyway', 'ratul-ads-conversion-tracker' ) . '</a>';
        } else {
            echo '<p>' . __( 'Manual purchase mode is active. This order has not been synced to advertising platforms yet.', 'ratul-ads-conversion-tracker' ) . '</p>';
            echo '<div style="display:flex; flex-direction:column; gap:8px;">';
            echo '<a href="' . esc_url( $url ) . '" class="button button-primary" style="width:100%; text-align:center;">' . __( 'Fire Purchase Event', 'ratul-ads-conversion-tracker' ) . '</a>';
            echo '<a href="' . esc_url( $fraud_url ) . '" class="button" style="width:100%; text-align:center; color:#ef4444; border-color:#ef4444;">' . __( 'Mark as Fraud', 'ratul-ads-conversion-tracker' ) . '</a>';
            echo '</div>';
        }
    }

    public static function add_orders_column( $columns ) {
        if ( ! get_option( 'ratul_act_manual_purchase_enabled', 0 ) ) {
            return $columns;
        }
        $new_columns = [];
        foreach ( $columns as $key => $column ) {
            $new_columns[ $key ] = $column;
            if ( 'order_status' === $key ) {
                $new_columns['ratul_act_capi'] = __( 'CAPI Purchase', 'ratul-ads-conversion-tracker' );
            }
        }
        if ( ! isset( $new_columns['ratul_act_capi'] ) ) {
            $new_columns['ratul_act_capi'] = __( 'CAPI Purchase', 'ratul-ads-conversion-tracker' );
        }
        return $new_columns;
    }

    public static function render_orders_column_content( $column, $post_or_order_object ) {
        if ( 'ratul_act_capi' !== $column ) {
            return;
        }

        $order = ( $post_or_order_object instanceof WP_Post )
            ? wc_get_order( $post_or_order_object->ID )
            : $post_or_order_object;

        if ( ! $order ) {
            return;
        }

        $sent_platforms = $order->get_meta( '_ratul_act_server_sent' );
        if ( ! is_array( $sent_platforms ) ) {
            $sent_platforms = [];
        }
        $sent = $order->get_meta( '_ratul_act_manual_purchase_sent' ) === 'yes' || in_array( 'meta', $sent_platforms, true );
        $fraud = $order->get_meta( '_ratul_act_manual_purchase_fraud' ) === 'yes';

        if ( $sent ) {
            echo '<span style="color:#10b981; font-weight:600;"><span class="dashicons dashicons-yes-alt" style="vertical-align:middle; font-size:18px;"></span> ' . esc_html__( 'Approved', 'ratul-ads-conversion-tracker' ) . '</span>';
        } elseif ( $fraud ) {
            echo '<span style="color:#ef4444; font-weight:600;"><span class="dashicons dashicons-warning" style="vertical-align:middle; font-size:18px;"></span> ' . esc_html__( 'Fraud / Ignored', 'ratul-ads-conversion-tracker' ) . '</span>';
        } else {
            $approve_url = wp_nonce_url( admin_url( 'admin.php?action=ratul_act_manual_purchase&order_id=' . $order->get_id() ), 'ratul_act_manual_purchase_' . $order->get_id() );
            $fraud_url   = wp_nonce_url( admin_url( 'admin.php?action=ratul_act_mark_fraud&order_id=' . $order->get_id() ), 'ratul_act_mark_fraud_' . $order->get_id() );

            echo '<div style="display:flex; gap:6px;">';
            echo '<a href="' . esc_url( $approve_url ) . '" class="button button-small button-primary" style="background:#0ea5a0; border-color:#0ea5a0;">' . esc_html__( 'Approve & Sync', 'ratul-ads-conversion-tracker' ) . '</a>';
            echo '<a href="' . esc_url( $fraud_url ) . '" class="button button-small" style="color:#ef4444; border-color:#ef4444;">' . esc_html__( 'Mark Fraud', 'ratul-ads-conversion-tracker' ) . '</a>';
            echo '</div>';
        }
    }

    public static function handle_mark_fraud_action() {
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        check_admin_referer( 'ratul_act_mark_fraud_' . $order_id );

        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->update_meta_data( '_ratul_act_manual_purchase_fraud', 'yes' );
            $order->save();
        }

        $url = admin_url( 'edit.php?post_type=shop_order' );
        if ( function_exists( 'wc_get_page_screen_id' ) && wc_get_page_screen_id( 'shop-order' ) ) {
            $url = admin_url( 'admin.php?page=wc-orders' ); // HPOS
        }

        $url = add_query_arg( [
            'st_manual_status' => 'success',
            'st_manual_msg'    => urlencode( __( 'Order marked as fraud. Purchase event ignored.', 'ratul-ads-conversion-tracker' ) )
        ], $url );

        wp_safe_redirect( $url );
        exit;
    }

    public static function render_manual_purchase_notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['st_manual_status'] ) && isset( $_GET['st_manual_msg'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $status = sanitize_text_field( wp_unslash( $_GET['st_manual_status'] ) );
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $msg = sanitize_text_field( wp_unslash( $_GET['st_manual_msg'] ) );
            $class = $status === 'success' ? 'notice-success' : 'notice-error';
            $message = urldecode( $msg );
            printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
        }
    }

    public static function handle_manual_purchase_action() {
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        check_admin_referer( 'ratul_act_manual_purchase_' . $order_id );

        if ( class_exists( 'Ratul_ACT_Source_WooCommerce' ) ) {
            $result = Ratul_ACT_Source_WooCommerce::fire_manual_purchase( $order_id );

            $url = admin_url( 'edit.php?post_type=shop_order' );
            if ( function_exists( 'wc_get_page_screen_id' ) && wc_get_page_screen_id( 'shop-order' ) ) {
                $url = admin_url( 'admin.php?page=wc-orders' ); // HPOS
            }

            $url = add_query_arg( [
                'st_manual_status' => $result['success'] ? 'success' : 'error',
                'st_manual_msg'    => urlencode( $result['message'] )
            ], $url );

            wp_safe_redirect( $url );
            die();
        }
    }

    public static function handle_license_actions(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        if ( isset( $_POST['st_license_action'] ) && isset( $_POST['ratul_act_license_key'] ) ) {
            check_admin_referer( 'ratul_act_license_action', 'ratul_act_license_nonce' );
            $action = sanitize_text_field( wp_unslash( $_POST['st_license_action'] ) );
            $key = sanitize_text_field( wp_unslash( $_POST['ratul_act_license_key'] ) );

            if ( 'activate' === $action ) {
                $result = Ratul_ACT_License::activate( $key );
                add_settings_error( 'ratul_act_license_messages', 'st_license', $result['message'], $result['success'] ? 'success' : 'error' );
            } elseif ( 'deactivate' === $action ) {
                $result = Ratul_ACT_License::deactivate();
                add_settings_error( 'ratul_act_license_messages', 'st_license', $result['message'], $result['success'] ? 'success' : 'error' );
            }
        }
    }

    public static function handle_oauth_revoke(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty( $_GET['ratul_act_revoke'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        check_admin_referer( 'ratul_act_revoke_google' );
        if ( class_exists( 'Ratul_ACT_Google_OAuth' ) ) {
            Ratul_ACT_Google_OAuth::revoke();
        }
        wp_safe_redirect( self::settings_url( 'google', [ 'revoked' => '1' ] ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────
    // Health Notice
    // C8 — Removed non-existent screen ID ratul_act_page_ratul-ads-conversion-tracker-sources.
    //      The Settings page screen ID is ratul_act_page_ratul-ads-conversion-tracker-settings.
    // ─────────────────────────────────────────────────────────────────

    public static function render_health_notice(): void {
        $screen = get_current_screen();
        $allowed_screens = [
            'ratul_act_page_ratul-ads-conversion-tracker-settings',
            'settings_page_ratul-ads-conversion-tracker',
            'toplevel_page_ratul-ads-conversion-tracker',
        ];
        if ( ! $screen || ! in_array( $screen->id, $allowed_screens, true ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $issues = [];

        if ( get_option( 'ratul_act_meta_enabled', 0 ) ) {
            if ( ! get_option( 'ratul_act_meta_pixel_id', '' ) || ! get_option( 'ratul_act_meta_access_token', '' ) ) {
                $issues[] = sprintf(
                    'Meta CAPI is enabled but missing credentials. <a href="%s">Configure Meta →</a>',
                    esc_url( self::settings_url( 'meta' ) )
                );
            }
        }

        if ( get_option( 'ratul_act_google_enabled', 0 ) ) {
            if ( ! get_option( 'ratul_act_google_refresh_token', '' ) ) {
                $issues[] = sprintf(
                    'Google Ads is enabled but not authenticated. <a href="%s">Configure Google →</a>',
                    esc_url( self::settings_url( 'google' ) )
                );
            }
        }

        if ( get_option( 'ratul_act_tiktok_enabled', 0 ) ) {
            if ( ! get_option( 'ratul_act_tiktok_pixel_id', '' ) || ! get_option( 'ratul_act_tiktok_access_token', '' ) ) {
                $issues[] = sprintf(
                    'TikTok Events is enabled but missing credentials. <a href="%s">Configure TikTok →</a>',
                    esc_url( self::settings_url( 'tiktok' ) )
                );
            }
        }

        if ( empty( $issues ) ) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible"><p><strong>Ratuls-ACT:</strong></p><ul>';
        foreach ( $issues as $issue ) {
            echo '<li>' . wp_kses( $issue, [ 'a' => [ 'href' => [] ] ] ) . '</li>';
        }
        echo '</ul></div>';
    }

    // ─────────────────────────────────────────────────────────────────
    // Page Header
    // ─────────────────────────────────────────────────────────────────

    public static function render_page_header(): void {
        ?>
        <div class="st-page-header">
            <div class="st-page-header-left">
                <div class="st-logo-icon-wrap">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect width="36" height="36" rx="9" fill="url(#logo-grad)"/>
                        <path d="M8 22 L13 14 L18 19 L23 10 L28 16" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                        <circle cx="28" cy="16" r="2.5" fill="#0ef0e8" opacity="0.9"/>
                        <defs>
                            <linearGradient id="logo-grad" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse">
                                <stop offset="0%" stop-color="#0ea5a0"/>
                                <stop offset="100%" stop-color="#0a6b68"/>
                            </linearGradient>
                        </defs>
                    </svg>
                </div>
                <div class="st-page-title-group">
                    <h1>Ratul Ads Conversion Tracker</h1>
                    <p>Server-Side Tracking &mdash; CAPI for Meta, Google &amp; TikTok</p>
                </div>
            </div>
            <div class="st-header-badges">
                <span class="st-header-version"><?php echo esc_html( 'v' . RATUL_ACT_VERSION ); ?></span>
                <nav>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ratul-ads-conversion-tracker' ) ); ?>"
                       style="color:rgba(255,255,255,.65);text-decoration:none;font-size:.8125rem;margin-right:12px;"
                    ><?php esc_html_e( 'Dashboard', 'ratul-ads-conversion-tracker' ); ?></a>
                    <a href="<?php echo esc_url( self::settings_url() ); ?>"
                       style="color:rgba(255,255,255,.65);text-decoration:none;font-size:.8125rem;"
                    ><?php esc_html_e( 'Settings', 'ratul-ads-conversion-tracker' ); ?></a>
                </nav>
            </div>
        </div>
        <?php
    }


    // ─────────────────────────────────────────────────────────────────
    // Settings Page
    //
    // The outer <form> here is THE only form on the page.
    // Views must NOT contain their own <form> or settings_fields() call.
    // C1 — All views have been stripped of their nested <form> wrappers.
    // ─────────────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

        if ( 'ratul-ads-conversion-tracker-sources' === $page ) {
            $tab = 'sources';
        }

        if ( ! array_key_exists( $tab, self::TAB_GROUPS ) ) {
            $tab = 'general';
        }
        ?>
        <div class="wrap" id="ratul-ads-conversion-tracker-wrap">
        <?php self::render_page_header(); ?>

        <nav class="st-tab-nav">
            <?php
            $tabs = [
                'general' => __( 'General', 'ratul-ads-conversion-tracker' ),
                'meta'    => __( 'Meta CAPI', 'ratul-ads-conversion-tracker' ),
                'google'  => __( 'Google Ads', 'ratul-ads-conversion-tracker' ),
                'tiktok'  => __( 'TikTok', 'ratul-ads-conversion-tracker' ),
                'sources' => __( 'Event Sources', 'ratul-ads-conversion-tracker' ),
                'license' => __( 'License', 'ratul-ads-conversion-tracker' ),
            ];
            foreach ( $tabs as $slug => $label ) :
                $url     = esc_url( self::settings_url( $slug ) );
                $classes = 'nav-tab' . ( $tab === $slug ? ' nav-tab-active' : '' );
            ?>
            <a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $classes ); ?>"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>

        <form method="post" action="options.php" class="st-settings-form">
            <?php
            settings_fields( self::TAB_GROUPS[ $tab ] );

            /*
             * B2 FIX — override _wp_http_referer so options.php redirects
             * back to the correct tab after saving.
             */
            $return_url = self::settings_url( $tab );
            echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( $return_url ) . '" />';
            ?>

            <?php
            $view = plugin_dir_path( __FILE__ ) . 'views/settings-' . $tab . '.php';
            if ( file_exists( $view ) ) {
                include $view;
            } else {
                echo '<p>' . esc_html__( 'View not found.', 'ratul-ads-conversion-tracker' ) . '</p>';
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
        check_ajax_referer( 'ratul_act_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $platform   = isset( $_POST['platform'] ) ? sanitize_key( wp_unslash( $_POST['platform'] ) ) : '';
        $event_name = isset( $_POST['event_name'] ) ? sanitize_text_field( wp_unslash( $_POST['event_name'] ) ) : 'Purchase';

        $allowed_platforms = [ 'meta', 'google', 'tiktok' ];
        if ( ! in_array( $platform, $allowed_platforms, true ) ) {
            wp_send_json_error( 'Invalid platform.' );
        }

        $event_id = Ratul_ACT_Dedup::generate_event_id();
        $event    = ( new Ratul_ACT_Event( $event_name, $event_id ) )
            ->set_custom_data( [ 'value' => 1.00, 'currency' => 'USD' ] );

        $result = [];
        if ( 'meta' === $platform && class_exists( 'Ratul_ACT_Meta' ) ) {
            $result = Ratul_ACT_Meta::send( $event );
        } elseif ( 'google' === $platform && class_exists( 'Ratul_ACT_Google' ) ) {
            $result = Ratul_ACT_Google::send( $event );
        } elseif ( 'tiktok' === $platform && class_exists( 'Ratul_ACT_TikTok' ) ) {
            $result = Ratul_ACT_TikTok::send( $event );
        }

        if ( ! empty( $result['status'] ) && 'success' === $result['status'] ) {
            wp_send_json_success( $result );
        } else {
            if ( isset( $result['status'] ) && 'skipped' === $result['status'] ) {
                $result['message'] = sprintf( __( '%s is disabled. Please enable it and save settings first.', 'ratul-ads-conversion-tracker' ), ucfirst( $platform ) );
            }
            wp_send_json_error( $result );
        }
    }

    public static function ajax_get_logs(): void {
        check_ajax_referer( 'ratul_act_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $logs   = get_option( 'ratul_act_debug_log', [] );
        $recent = array_slice( array_reverse( $logs ), 0, 200 );
        $count  = count( $logs );

        ob_start();
        Ratul_ACT_Dashboard::render_log_rows( $recent );
        $html = ob_get_clean();

        wp_send_json_success( [
            'html'  => $html,
            'total' => $count,
        ] );
    }

    public static function ajax_get_dashboard_stats(): void {
        check_ajax_referer( 'ratul_act_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $stats = get_option( 'ratul_act_stats', [] );
        wp_send_json_success( $stats );
    }

    public static function ajax_verify_credentials(): void {
        check_ajax_referer( 'ratul_act_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $platform = isset( $_POST['platform'] ) ? sanitize_key( wp_unslash( $_POST['platform'] ) ) : '';
        $pixel_id = isset( $_POST['pixel_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pixel_id'] ) ) : '';
        $token    = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

        if ( empty( $pixel_id ) || empty( $token ) ) {
            wp_send_json_error( [ 'message' => __( 'Pixel ID and Access Token are required.', 'ratul-ads-conversion-tracker' ) ] );
        }

        if ( 'meta' === $platform ) {
            $url = 'https://graph.facebook.com/v17.0/' . $pixel_id . '?access_token=' . $token;
            $res = wp_remote_get( $url, [ 'timeout' => 15 ] );
            if ( is_wp_error( $res ) ) {
                wp_send_json_error( [ 'message' => $res->get_error_message() ] );
            }
            $body = wp_remote_retrieve_body( $res );
            $data = json_decode( $body, true );
            if ( ! empty( $data['error'] ) ) {
                wp_send_json_error( [ 'message' => $data['error']['message'] ?? __( 'Invalid API credentials.', 'ratul-ads-conversion-tracker' ) ] );
            }
            wp_send_json_success( [ 'message' => __( 'Meta Connection Successful!', 'ratul-ads-conversion-tracker' ) ] );
        } elseif ( 'tiktok' === $platform ) {
            $url = 'https://business-api.tiktok.com/open_api/v1.3/event/track/';
            $body = wp_json_encode( [
                'pixel_code' => $pixel_id,
                'event'      => 'PageView',
                'event_id'   => 'verify_conn_' . time(),
                'timestamp'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'context'    => [
                    'ad'   => [ 'callback' => 'test' ],
                    'user' => [ 'ip' => '127.0.0.1', 'user_agent' => 'Test' ]
                ]
            ] );
            $res = wp_remote_post( $url, [
                'timeout' => 15,
                'headers' => [
                    'Access-Token' => $token,
                    'Content-Type' => 'application/json'
                ],
                'body'    => $body
            ] );
            if ( is_wp_error( $res ) ) {
                wp_send_json_error( [ 'message' => $res->get_error_message() ] );
            }
            $resp_body = wp_remote_retrieve_body( $res );
            $data = json_decode( $resp_body, true );
            if ( isset( $data['code'] ) && (int) $data['code'] !== 0 ) {
                wp_send_json_error( [ 'message' => $data['message'] ?? __( 'Invalid API credentials.', 'ratul-ads-conversion-tracker' ) ] );
            }
            wp_send_json_success( [ 'message' => __( 'TikTok Connection Successful!', 'ratul-ads-conversion-tracker' ) ] );
        }

        wp_send_json_error( [ 'message' => __( 'Unsupported platform verification.', 'ratul-ads-conversion-tracker' ) ] );
    }
}
