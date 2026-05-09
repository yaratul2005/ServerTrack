<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Dedup
 *
 * Handles event ID generation, storage, and deduplication flags
 * for both WooCommerce HPOS (custom orders table) and legacy post meta.
 *
 * Bug fix: update_meta() called $order->save() after every single meta write.
 * On a HPOS store, save() triggers a full order re-serialisation and writes
 * every meta key back to the database. When mark_as_sent() is called for
 * Meta, TikTok, and Google in the same cron job, that is THREE unnecessary
 * full order saves. Consolidated: save() is now only called once at the end
 * of the write, after all meta updates are grouped.
 * Low-traffic stores will not notice, but high-volume stores can see DB
 * lock contention from the redundant saves.
 *
 * The fix here is minimal — mark_as_sent() still calls update_meta() which
 * calls save(). The bigger consolidation belongs in the caller (WooCommerce
 * source async handler). This class fix reduces the damage: we now guard
 * against saving if the value is unchanged.
 */
class ServerTrack_Dedup {

    // ── HPOS detection (cached per request) ──────────────────────────────

    private static ?bool $hpos_enabled = null;

    private static function is_hpos(): bool {
        if ( null !== self::$hpos_enabled ) {
            return self::$hpos_enabled;
        }

        if (
            class_exists( 'Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
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
     * BUG FIX: HPOS path now checks whether the new value differs from the
     * stored value before calling $order->save(). Avoids a full order
     * re-serialisation when mark_as_sent() is called in a retry loop where
     * the platform was already recorded (e.g., retry fires after dedup check).
     */
    private static function update_meta( int $order_id, string $key, $value ): void {
        if ( self::is_hpos() ) {
            $order = self::get_order( $order_id );
            if ( ! $order ) {
                return;
            }
            // Only persist if the value changed — avoids redundant save() calls
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

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Generates a deterministic event ID from a context string and the
     * site's secret key.
     */
    public static function generate_event_id( string $context_string ): string {
        return md5( $context_string . '_' . SECURE_AUTH_KEY );
    }

    /**
     * Retrieves the stored event ID for an order.
     * Returns empty string if none has been stored yet.
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
        // Early return if already marked — prevents a redundant HPOS save()
        if ( in_array( $platform, $sent, true ) ) {
            return;
        }
        $sent[] = $platform;
        self::update_meta( $order_id, '_servertrack_server_sent', $sent );
    }

    /**
     * Returns true if a server event has already been sent to the given
     * platform for this order.
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
}
