<?php
/**
 * Ratul_ACT — WP-CLI Commands (Feature #10)
 *
 * Provides developer-friendly CLI commands for testing, debugging, and
 * managing Ratul_ACT from the server terminal.
 *
 * Available commands:
 *   wp ratul-ads-conversion-tracker status              — Show plugin config and platform status
 *   wp ratul-ads-conversion-tracker log [--limit=50]    — Display recent event log entries
 *   wp ratul-ads-conversion-tracker log clear           — Clear all log entries
 *   wp ratul-ads-conversion-tracker test-purchase <id>  — Re-fire a purchase event for an order
 *   wp ratul-ads-conversion-tracker retry               — Process the retry queue now
 *   wp ratul-ads-conversion-tracker emq <order_id>      — Score an order's user_data
 *   wp ratul-ads-conversion-tracker ltv <user_id>       — Show customer LTV stats
 *   wp ratul-ads-conversion-tracker webhook test <url>  — Send a test webhook to a URL
 *
 * @package Ratul_ACT
 * @since   6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

class Ratul_ACT_CLI {

    /**
     * Show current plugin configuration and platform status.
     *
     * ## EXAMPLES
     *
     *   wp ratul-ads-conversion-tracker status
     *
     * @when after_wp_load
     */
    public function status(): void {
        WP_CLI::line( '' );
        WP_CLI::line( '=== Ratul_ACT ' . RATUL_ACT_VERSION . ' ===' );
        WP_CLI::line( '' );

        $rows = [
            [ 'Setting', 'Value' ],
        ];

        $rows[] = [ 'Plugin enabled',     get_option( 'ratul_act_enabled' ) ? 'Yes' : 'No' ];
        $rows[] = [ 'Debug mode',          get_option( 'ratul_act_debug_mode' ) ? 'ON' : 'off' ];
        $rows[] = [ 'Meta pixel ID',       get_option( 'ratul_act_meta_pixel_id', '—' ) ?: '—' ];
        $rows[] = [ 'Meta access token',   get_option( 'ratul_act_meta_access_token' ) ? '*** set ***' : 'NOT SET' ];
        $rows[] = [ 'TikTok pixel ID',     get_option( 'ratul_act_tiktok_pixel_id', '—' ) ?: '—' ];
        $rows[] = [ 'TikTok access token', get_option( 'ratul_act_tiktok_access_token' ) ? '*** set ***' : 'NOT SET' ];
        $rows[] = [ 'Google MP secret',    get_option( 'ratul_act_google_api_secret' ) ? '*** set ***' : 'NOT SET' ];
        $rows[] = [ 'Webhook enabled',     get_option( 'ratul_act_webhook_enabled' ) ? 'Yes' : 'No' ];
        $rows[] = [ 'Webhook URL',         get_option( 'ratul_act_webhook_url', '—' ) ?: '—' ];

        $log   = (array) get_option( 'ratul_act_debug_log', [] );
        $retry = (array) get_option( 'ratul_act_retry_queue', [] );
        $rows[] = [ 'Log entries', count( $log ) ];
        $rows[] = [ 'Retry queue', count( $retry ) . ' item(s)' ];

        WP_CLI\Utils\format_items( 'table', array_slice( $rows, 1 ), [ 'Setting', 'Value' ] );
    }

    /**
     * Display recent event log entries.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Number of entries to show. Default 50.
     *
     * [--platform=<platform>]
     * : Filter by platform (meta, tiktok, google, webhook).
     *
     * [--status=<status>]
     * : Filter by status (success, error).
     *
     * ## EXAMPLES
     *
     *   wp ratul-ads-conversion-tracker log
     *   wp ratul-ads-conversion-tracker log --limit=20 --platform=meta --status=error
     *
     * @subcommand log
     * @when after_wp_load
     */
    public function log( array $args, array $assoc_args ): void {
        $sub = $args[0] ?? '';
        if ( $sub === 'clear' ) {
            update_option( 'ratul_act_debug_log', [] );
            WP_CLI::success( 'Log cleared.' );
            return;
        }

        $limit    = (int) ( $assoc_args['limit']    ?? 50 );
        $platform = $assoc_args['platform'] ?? '';
        $status   = $assoc_args['status']   ?? '';

        $log = array_reverse( (array) get_option( 'ratul_act_debug_log', [] ) );

        if ( $platform ) {
            $log = array_filter( $log, fn( $e ) => ( $e['platform'] ?? '' ) === $platform );
        }
        if ( $status ) {
            $log = array_filter( $log, fn( $e ) => ( $e['status'] ?? '' ) === $status );
        }

        $log = array_slice( array_values( $log ), 0, $limit );

        if ( empty( $log ) ) {
            WP_CLI::line( 'No log entries found.' );
            return;
        }

        // FIX #6: Logger v2.0 stores 'timestamp', not 'time'
        $rows = array_map( function ( $entry ) {
            return [
                'time'     => $entry['timestamp']  ?? '',  // fixed: was $entry['time']
                'platform' => $entry['platform']   ?? '',
                'event'    => $entry['event_type'] ?? '',
                'order_id' => $entry['order_id']   ?? '',
                'status'   => $entry['status']     ?? '',
                'emq'      => isset( $entry['emq_score'] )
                    ? $entry['emq_score'] . ' (' . ( $entry['emq_grade'] ?? '' ) . ')'
                    : '—',
            ];
        }, $log );

        WP_CLI\Utils\format_items( 'table', $rows, [ 'time', 'platform', 'event', 'order_id', 'status', 'emq' ] );
    }

    /**
     * Re-fire the Purchase CAPI event for an existing order.
     *
     * Resets all Ratul_ACT dedup flags for the order so the event passes
     * through all guards as if it had never been sent before.
     * Does NOT remove any WooCommerce order data.
     *
     * ## OPTIONS
     *
     * <order_id>
     * : The WooCommerce order ID to test.
     *
     * ## EXAMPLES
     *
     *   wp ratul-ads-conversion-tracker test-purchase 123
     *
     * @subcommand test-purchase
     * @when after_wp_load
     */
    public function test_purchase( array $args ): void {
        $order_id = (int) ( $args[0] ?? 0 );
        if ( ! $order_id ) {
            WP_CLI::error( 'Please provide an order ID.' );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            WP_CLI::error( "Order #{$order_id} not found." );
        }

        WP_CLI::line( "Re-firing Purchase event for order #{$order_id}..." );

        // FIX #1: Ratul_ACT_Dedup::delete() never existed.
        // Use reset_for_order() which correctly clears _ratul_act_event_id
        // and _ratul_act_server_sent via HPOS-aware delete_meta().
        Ratul_ACT_Dedup::reset_for_order( $order_id );

        // Pass 'thankyou' trigger so Meta + TikTok blocks execute
        // (the Google block runs regardless of trigger).
        do_action( 'ratul_act_send_woo_purchase', $order_id, 'thankyou' );

        WP_CLI::success( 'Done. Check log: wp ratul-ads-conversion-tracker log --limit=5' );
    }

    /**
     * Process the retry queue immediately.
     *
     * ## EXAMPLES
     *
     *   wp ratul-ads-conversion-tracker retry
     *
     * @when after_wp_load
     */
    public function retry(): void {
        $queue = (array) get_option( 'ratul_act_retry_queue', [] );
        $count = count( $queue );

        if ( $count === 0 ) {
            WP_CLI::line( 'Retry queue is empty.' );
            return;
        }

        WP_CLI::line( "Processing {$count} item(s) in retry queue..." );
        do_action( 'ratul_act_process_retry_queue' );
        WP_CLI::success( 'Done.' );
    }

    /**
     * Score the user_data for a given order using the EMQ scorer.
     *
     * ## OPTIONS
     *
     * <order_id>
     * : WooCommerce order ID.
     *
     * ## EXAMPLES
     *
     *   wp ratul-ads-conversion-tracker emq 123
     *
     * @when after_wp_load
     */
    public function emq( array $args ): void {
        $order_id = (int) ( $args[0] ?? 0 );
        if ( ! $order_id ) {
            WP_CLI::error( 'Please provide an order ID.' );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            WP_CLI::error( "Order #{$order_id} not found." );
        }

        $email = $order->get_billing_email();
        $phone = preg_replace( '/[^0-9+]/', '', $order->get_billing_phone() );
        $fbc   = $order->get_meta( '_ratul_act_fbc' );
        $fbp   = $order->get_meta( '_ratul_act_fbp' );

        $user_data = array_filter( [
            'em'  => $email ? Ratul_ACT_Hasher::hash( strtolower( trim( $email ) ) ) : '',
            'ph'  => $phone ? Ratul_ACT_Hasher::hash( $phone ) : '',
            'fbc' => $fbc ?: '',
            'fbp' => $fbp ?: '',
            'fn'  => $order->get_billing_first_name() ? Ratul_ACT_Hasher::hash_name( $order->get_billing_first_name() ) : '',
            'ln'  => $order->get_billing_last_name()  ? Ratul_ACT_Hasher::hash_name( $order->get_billing_last_name() )  : '',
            'zp'  => $order->get_billing_postcode()   ? Ratul_ACT_Hasher::hash_zip( $order->get_billing_postcode() )   : '',
            'ct'  => $order->get_billing_city()       ? Ratul_ACT_Hasher::hash_city( $order->get_billing_city() )       : '',
        ] );

        $emq = Ratul_ACT_MatchQuality::score( $user_data );

        WP_CLI::line( '' );
        WP_CLI::line( "EMQ Score for Order #{$order_id}" );
        WP_CLI::line( '─────────────────────────────' );
        WP_CLI::line( 'Score : ' . ( $emq['score'] ?? '?' ) . ' / 10' );
        WP_CLI::line( 'Grade : ' . strtoupper( $emq['grade'] ?? '?' ) );
        WP_CLI::line( '' );

        $rows = [];
        foreach ( ( $emq['signals'] ?? [] ) as $signal => $val ) {
            $rows[] = [ 'signal' => $signal, 'present' => $val ? 'yes' : 'no' ];
        }
        if ( $rows ) {
            WP_CLI\Utils\format_items( 'table', $rows, [ 'signal', 'present' ] );
        }
    }

    /**
     * Show Customer LTV stats.
     *
     * ## OPTIONS
     *
     * <user_id>
     * : WordPress user ID.
     *
     * ## EXAMPLES
     *
     *   wp ratul-ads-conversion-tracker ltv 42
     *
     * @when after_wp_load
     */
    public function ltv( array $args ): void {
        $user_id = (int) ( $args[0] ?? 0 );
        if ( ! $user_id ) {
            WP_CLI::error( 'Please provide a user ID.' );
        }

        if ( ! class_exists( 'Ratul_ACT_LTV' ) ) {
            WP_CLI::error( 'LTV class not loaded.' );
        }

        $stats = Ratul_ACT_LTV::get_customer_stats( $user_id );

        if ( empty( $stats ) ) {
            WP_CLI::line( "No orders found for user #{$user_id}." );
            return;
        }

        WP_CLI::line( '' );
        WP_CLI::line( "LTV Stats for User #{$user_id}" );
        WP_CLI::line( '─────────────────────────────' );
        foreach ( $stats as $key => $val ) {
            WP_CLI::line( str_pad( $key, 20 ) . ': ' . $val );
        }
    }

    /**
     * Send a test webhook.
     *
     * ## OPTIONS
     *
     * <url>
     * : The webhook URL to test.
     *
     * ## EXAMPLES
     *
     *   wp ratul-ads-conversion-tracker webhook test https://example.com/hook
     *
     * @subcommand webhook
     * @when after_wp_load
     */
    public function webhook( array $args ): void {
        $sub = $args[0] ?? '';
        $url = $args[1] ?? '';

        if ( $sub !== 'test' || ! $url ) {
            WP_CLI::error( 'Usage: wp ratul-ads-conversion-tracker webhook test <url>' );
        }

        WP_CLI::line( "Sending test webhook to: {$url}" );

        $result = Ratul_ACT_Webhook::send_test( $url );

        if ( $result['success'] ) {
            WP_CLI::success( "Webhook delivered! HTTP {$result['http_code']}" );
        } else {
            WP_CLI::error( "Failed: HTTP {$result['http_code']} — {$result['message']}" );
        }
    }
}

// Register all subcommands
WP_CLI::add_command( 'ratul-ads-conversion-tracker', 'Ratul_ACT_CLI' );


