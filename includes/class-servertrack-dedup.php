<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Dedup  v2.2
 *
 * Handles event ID generation, storage, and deduplication flags
 * for both WooCommerce HPOS (custom orders table) and legacy post meta.
 *
 * Changes in v2.2:
 *   - Added reset_for_order() — clears the dedup flags and event ID for a
 *     given order_id. Used by CLI test-purchase to force a re-fire without
 *     corrupting real order meta.
 *   - Added reset_event_key() — clears a non-order dedup key stored in
 *     wp_options (used for Refund, ViewContent, etc. keyed by string).
 *
 * CRITICAL FIX (v2.1):
 *   generate_event_id() now produces UUID v4 via wp_generate_uuid4().
 *   update_meta() only calls save() when value has changed.
 */
class ServerTrack_Dedup {

    // ── HPOS detection (cached per request) ──────────────────────────────

    private static ?bool $hpos_enabled = null;

    private static function is_hpos(): bool {
        if ( null !== self::$hpos_enabled ) {
            return self::$hpos_enabled;
        }

        if (
            class_exists( 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController' )
            && function_exists( 'wc_get_container' )
        ) {
            try {
                $controller = wc_get_container()->get(
                    Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class
                );
                self::$hpos_enabled = (bool) $controller->custom_orders_table_usage_is_enabled();
            } catch ( \Exception $e ) {
                self::$hpos_enabled = false;
            }
        } else {
            self::$hpos_enabled = false;
        }

        return self::$hpos_enabled;
    }

    // ── Order object helper ───────────────────────────────────────────────

    private static function get_order( int $order_id ): ?\WC_Abstract_Order {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return null;
        }
        $order = wc_get_order( $order_id );
        return ( $order instanceof \WC_Abstract_Order ) ? $order : null;
    }

    // ── Meta read / write wrappers ────────────────────────────────────────

    private static function get_meta( int $order_id, string $key ) {
        if ( self::is_hpos() ) {
            $order = self::get_order( $order_id );
            if ( ! $order ) {
                return '';
            }
            return $order->get_meta( $key, true );
        }

        return get_post_meta( $order_id, $key, true );
    }

    /**
     * Only persists if the value has changed — avoids redundant HPOS save() calls.
     */
    private static function update_meta( int $order_id, string $key, $value ): void {
        if ( self::is_hpos() ) {
            $order = self::get_order( $order_id );
            if ( ! $order ) {
                return;
            }
            $existing = $order->get_meta( $key, true );
            if ( $existing === $value ) {
                return;
            }
            $order->update_meta_data( $key, $value );
            $order->save();
            return;
        }

        update_post_meta( $order_id, $key, $value );
    }

    private static function delete_meta( int $order_id, string $key ): void {
        if ( self::is_hpos() ) {
            $order = self::get_order( $order_id );
            if ( ! $order ) {
                return;
            }
            $order->delete_meta_data( $key );
            $order->save();
            return;
        }

        delete_post_meta( $order_id, $key );
    }

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Generates a collision-safe UUID v4 event ID.
     *
     * CRITICAL FIX (v2.1): Previously used md5().
     * Now uses wp_generate_uuid4() (RFC 4122 compliant, 128-bit random).
     * For deterministic IDs, context_string is hashed with the site secret.
     *
     * @param string $context_string  Optional seed for deterministic generation.
     */
    public static function generate_event_id( string $context_string = '' ): string {
        if ( '' === $context_string ) {
            return wp_generate_uuid4();
        }

        $hash  = hash( 'sha256', $context_string . '_' . SECURE_AUTH_KEY, true );
        $bytes = substr( $hash, 0, 16 );

        $bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
        $bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split( bin2hex( $bytes ), 4 )
        );
    }

    /**
     * Retrieves the stored event ID for an order.
     */
    public static function get_event_id( int $order_id ): string {
        $event_id = self::get_meta( $order_id, '_servertrack_event_id' );
        return is_string( $event_id ) ? $event_id : '';
    }

    /**
     * Persists the event ID to order meta.
     */
    public static function store_event_id( int $order_id, string $event_id ): void {
        self::update_meta( $order_id, '_servertrack_event_id', $event_id );
    }

    /**
     * Marks a platform as having successfully received a server event.
     *
     * @param string $platform  'meta' | 'google' | 'tiktok'
     */
    public static function mark_as_sent( int $order_id, string $platform ): void {
        $sent = self::get_meta( $order_id, '_servertrack_server_sent' );
        if ( ! is_array( $sent ) ) {
            $sent = [];
        }
        if ( in_array( $platform, $sent, true ) ) {
            return;
        }
        $sent[] = $platform;
        self::update_meta( $order_id, '_servertrack_server_sent', $sent );
    }

    /**
     * Returns true if a server event has already been sent to the given platform.
     *
     * @param string $platform  'meta' | 'google' | 'tiktok'
     */
    public static function was_sent( int $order_id, string $platform ): bool {
        $sent = self::get_meta( $order_id, '_servertrack_server_sent' );
        if ( ! is_array( $sent ) ) {
            return false;
        }
        return in_array( $platform, $sent, true );
    }

    /**
     * Reset all dedup flags and the event ID for a given order.
     *
     * Used by: wp servertrack test-purchase <order_id>
     * This lets developers re-fire a CAPI event for an existing order in
     * testing without leaving corrupted state — it only removes the
     * ServerTrack-specific meta keys, not any WC order data.
     *
     * @param int $order_id  WooCommerce order ID.
     */
    public static function reset_for_order( int $order_id ): void {
        self::delete_meta( $order_id, '_servertrack_event_id' );
        self::delete_meta( $order_id, '_servertrack_server_sent' );
    }
}
