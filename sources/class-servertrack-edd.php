<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_EDD
 *
 * Tracks EDD purchases and registrations across Meta, Google, and TikTok.
 *
 * EDD API compatibility:
 *   Primary path   — EDD 3.0+ : edd_get_order(), EDD_Order object
 *   Legacy fallback — EDD <3.0 : edd_get_payment(), EDD_Payment object
 *
 * The primary path is attempted first. If edd_get_order() does not exist
 * (EDD < 3.0) or returns false, the legacy path runs automatically.
 * Both paths produce the same $user_data / $custom_data shape so the
 * platform sending block below is shared and never duplicated.
 */
class ServerTrack_EDD {

    public static function init() {
        if ( ! get_option( 'servertrack_source_edd_enabled', 0 ) ) return;
        if ( ! function_exists( 'EDD' ) ) return;

        add_action( 'edd_complete_purchase',        [ self::class, 'on_purchase' ],       10, 1 );
        add_action( 'edd_user_registration',        [ self::class, 'on_registration' ],   10, 3 );
        add_action( 'servertrack_send_edd_purchase', [ self::class, 'send_purchase_async' ], 10, 1 );
    }

    // ── Purchase hook — seed event_id then schedule async ──────────────────

    public static function on_purchase( int $payment_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        // Generate + store event_id synchronously BEFORE scheduling.
        // The cron handler calls get_event_id() — it must already exist in meta.
        $event_id = ServerTrack_Dedup::generate_event_id( 'edd_purchase_' . $payment_id );
        ServerTrack_Dedup::store_event_id( $payment_id, $event_id );

        wp_schedule_single_event( time(), 'servertrack_send_edd_purchase', [ $payment_id ] );
    }

    // ── Async cron handler ────────────────────────────────────────────

    public static function send_purchase_async( int $payment_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        $event_id = ServerTrack_Dedup::get_event_id( $payment_id );

        // ── Build user_data + custom_data ─────────────────────────────
        // Try EDD 3.0+ first, fall back to legacy EDD <3.0 API
        if ( function_exists( 'edd_get_order' ) ) {
            $result = self::build_data_edd3( $payment_id );
        } else {
            $result = self::build_data_legacy( $payment_id );
        }

        // Both helpers return null if the order/payment cannot be found
        if ( null === $result ) {
            ServerTrack_Logger::log(
                'error', 'all',
                'EDD order/payment not found for ID ' . $payment_id,
                '', $event_id, $payment_id, 'Purchase'
            );
            return;
        }

        [ $user_data, $custom_data ] = $result;

        // ── Append browser cookies (best-effort — may be empty in cron context) ─
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_COOKIE['_fbp'] ) )    $user_data['fbp']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );
        if ( ! empty( $_COOKIE['_fbc'] ) )    $user_data['fbc']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        if ( ! empty( $_COOKIE['ttclid'] ) )  $user_data['ttclid'] = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) );
        if ( ! empty( $_COOKIE['_gcl_aw'] ) ) $user_data['gclid']  = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) );
        // phpcs:enable

        // ── Send to each enabled platform ─────────────────────────────

        // Meta
        if ( get_option( 'servertrack_meta_enabled', 0 ) ) {
            if ( ! ServerTrack_Dedup::was_sent( $payment_id, 'meta' ) ) {
                if ( ServerTrack_Consent::is_granted( 'meta' ) ) {
                    $e = ( new ServerTrack_Event( 'Purchase', $event_id ) )
                        ->set_user_data( $user_data )
                        ->set_custom_data( $custom_data );
                    ServerTrack_Meta::send( $e );
                    ServerTrack_Dedup::mark_as_sent( $payment_id, 'meta' );
                } else {
                    ServerTrack_Logger::log( 'skipped', 'meta', 'Consent not granted', '', $event_id, $payment_id, 'Purchase' );
                }
            } else {
                ServerTrack_Logger::log( 'dedup_blocked', 'meta', 'Already sent', '', $event_id, $payment_id, 'Purchase' );
            }
        }

        // Google
        if ( get_option( 'servertrack_google_enabled', 0 ) ) {
            if ( ! ServerTrack_Dedup::was_sent( $payment_id, 'google' ) ) {
                if ( ServerTrack_Consent::is_granted( 'google' ) ) {
                    $e = ( new ServerTrack_Event( 'Purchase', $event_id ) )
                        ->set_user_data( $user_data )
                        ->set_custom_data( $custom_data );
                    ServerTrack_Google::send( $e );
                    ServerTrack_Dedup::mark_as_sent( $payment_id, 'google' );
                } else {
                    ServerTrack_Logger::log( 'skipped', 'google', 'Consent not granted', '', $event_id, $payment_id, 'Purchase' );
                }
            } else {
                ServerTrack_Logger::log( 'dedup_blocked', 'google', 'Already sent', '', $event_id, $payment_id, 'Purchase' );
            }
        }

        // TikTok
        if ( get_option( 'servertrack_tiktok_enabled', 0 ) ) {
            if ( ! ServerTrack_Dedup::was_sent( $payment_id, 'tiktok' ) ) {
                if ( ServerTrack_Consent::is_granted( 'tiktok' ) ) {
                    $e = ( new ServerTrack_Event( 'Purchase', $event_id ) )
                        ->set_user_data( $user_data )
                        ->set_custom_data( $custom_data );
                    ServerTrack_TikTok::send( $e );
                    ServerTrack_Dedup::mark_as_sent( $payment_id, 'tiktok' );
                } else {
                    ServerTrack_Logger::log( 'skipped', 'tiktok', 'Consent not granted', '', $event_id, $payment_id, 'Purchase' );
                }
            } else {
                ServerTrack_Logger::log( 'dedup_blocked', 'tiktok', 'Already sent', '', $event_id, $payment_id, 'Purchase' );
            }
        }
    }

    // ── EDD 3.0+ data builder ────────────────────────────────────────

    /**
     * Builds user_data and custom_data using the EDD 3.0+ Orders API.
     * Uses: edd_get_order(), EDD_Order, EDD_Order_Item
     *
     * @return array|null  [ $user_data, $custom_data ] or null if not found.
     */
    private static function build_data_edd3( int $payment_id ): ?array {
        $order = edd_get_order( $payment_id );
        if ( ! $order ) {
            return null;
        }

        $user_data = [];

        // Hashed PII
        if ( ! empty( $order->email ) ) {
            $user_data['email'] = ServerTrack_Hasher::hash_email( $order->email );
        }
        if ( ! empty( $order->ip ) ) {
            $user_data['ip'] = $order->ip;
        }

        // Customer name via EDD customer record
        $customer = edd_get_customer( $order->customer_id );
        if ( $customer ) {
            $name_parts = explode( ' ', $customer->name, 2 );
            if ( ! empty( $name_parts[0] ) ) {
                $user_data['first_name'] = ServerTrack_Hasher::hash( $name_parts[0] );
            }
            if ( ! empty( $name_parts[1] ) ) {
                $user_data['last_name'] = ServerTrack_Hasher::hash( $name_parts[1] );
            }
        }

        // Order items → contents array
        $contents = [];
        $items    = $order->get_items();
        if ( is_array( $items ) ) {
            foreach ( $items as $item ) {
                $contents[] = [
                    'id'         => (string) $item->product_id,
                    'quantity'   => (int) $item->quantity,
                    'item_price' => (float) $item->unit_price,
                ];
            }
        }

        $custom_data = [
            'currency'     => $order->currency,
            'value'        => (float) $order->total,
            'contents'     => $contents,
            'content_type' => 'product',
            'order_id'     => $payment_id,
        ];

        return [ $user_data, $custom_data ];
    }

    // ── EDD legacy (<3.0) data builder ────────────────────────────────

    /**
     * Builds user_data and custom_data using the legacy EDD <3.0 Payments API.
     * Uses: edd_get_payment(), edd_get_payment_meta(), EDD_Payment
     *
     * @return array|null  [ $user_data, $custom_data ] or null if not found.
     */
    private static function build_data_legacy( int $payment_id ): ?array {
        if ( ! function_exists( 'edd_get_payment' ) ) {
            return null;
        }

        $payment = edd_get_payment( $payment_id );
        if ( ! $payment ) {
            return null;
        }

        $meta      = edd_get_payment_meta( $payment_id );
        $user_info = $meta['user_info'] ?? [];

        $user_data = [];

        if ( ! empty( $payment->email ) ) {
            $user_data['email'] = ServerTrack_Hasher::hash_email( $payment->email );
        }
        if ( ! empty( $user_info['first_name'] ) ) {
            $user_data['first_name'] = ServerTrack_Hasher::hash( $user_info['first_name'] );
        }
        if ( ! empty( $user_info['last_name'] ) ) {
            $user_data['last_name'] = ServerTrack_Hasher::hash( $user_info['last_name'] );
        }
        if ( ! empty( $payment->ip ) ) {
            $user_data['ip'] = $payment->ip;
        }

        $downloads = $meta['downloads'] ?? [];
        $contents  = [];
        foreach ( $downloads as $download ) {
            $contents[] = [
                'id'         => (string) ( $download['id'] ?? '' ),
                'quantity'   => (int) ( $download['quantity'] ?? 1 ),
                'item_price' => (float) ( $download['price'] ?? 0 ),
            ];
        }

        $custom_data = [
            'currency'     => $payment->currency,
            'value'        => (float) $payment->total,
            'contents'     => $contents,
            'content_type' => 'product',
            'order_id'     => $payment_id,
        ];

        return [ $user_data, $custom_data ];
    }

    // ── Lead: new customer registration ─────────────────────────────────

    public static function on_registration( string $user_login, string $user_email, int $user_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        $event_id  = ServerTrack_Dedup::generate_event_id( 'edd_lead_' . $user_id . '_' . wp_generate_uuid4() );
        $user_data = [];

        if ( ! empty( $user_email ) ) {
            $user_data['email'] = ServerTrack_Hasher::hash_email( $user_email );
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_COOKIE['_fbp'] ) )   $user_data['fbp']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );
        if ( ! empty( $_COOKIE['_fbc'] ) )   $user_data['fbc']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        if ( ! empty( $_COOKIE['ttclid'] ) ) $user_data['ttclid'] = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) );
        // phpcs:enable

        $event = new ServerTrack_Event( 'Lead', $event_id );
        $event->set_user_data( $user_data );
        $event->set_custom_data( [ 'currency' => 'USD', 'value' => 0.0, 'contents' => [] ] );

        if ( get_option( 'servertrack_meta_enabled', 0 ) && ServerTrack_Consent::is_granted( 'meta' ) ) {
            ServerTrack_Meta::send( $event );
        }
        if ( get_option( 'servertrack_tiktok_enabled', 0 ) && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            ServerTrack_TikTok::send( $event );
        }
    }
}
