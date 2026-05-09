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
 * HPOS detection: uses WooCommerce's CustomOrdersTableController to
 * determine which storage layer is active. Falls back to post meta
 * on any store that has not enabled HPOS (WC < 7.1 or opt-out).
 *
 * Meta keys used:
 *   _servertrack_event_id      — SHA-keyed event UUID for dedup
 *   _servertrack_server_sent   — array of platforms already sent
 */
class ServerTrack_Dedup {

    // ── HPOS detection (cached per request) ──────────────────────────────

    /**
     * Returns true if the store is running WooCommerce HPOS
     * (custom orders table enabled and in use).
     */
    private static ?bool $hpos_enabled = null;

    private static function is_hpos(): bool {
        if ( null !== self::$hpos_enabled ) {
            return self::$hpos_enabled;
        }

        // WC 7.1+ ships the controller. Older versions always use post meta.
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

    /**
     * Returns a WC_Order object for the given ID, or null if not found.
     * Used internally so HPOS meta methods have an order instance.
     */
    private static function get_order( int $order_id ): ?\WC_Abstract_Order {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return null;
        }
        $order = wc_get_order( $order_id );
        return ( $order instanceof \WC_Abstract_Order ) ? $order : null;
    }

    // ── Meta read / write wrappers ────────────────────────────────────────

    /**
     * Reads a single meta value for an order.
     * Routes to HPOS order object or post meta depending on store config.
     *
     * @return mixed  Meta value, or empty string if not found.
     */
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
     * Writes a meta value for an order.
     * Routes to HPOS order object (with save) or update_post_meta.
     */
    private static function update_meta( int $order_id, string $key, $value ): void {
        if ( self::is_hpos() ) {
            $order = self::get_order( $order_id );
            if ( ! $order ) {
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
     * site's secret key. The same context string always produces the same
     * ID, enabling pixel + server event to carry identical IDs.
     */
    public static function generate_event_id( string $context_string ): string {
        return md5( $context_string . '_' . SECURE_AUTH_KEY );
    }

    /**
     * Retrieves the stored event ID for an order.
     * Returns empty string if none has been stored yet.
     *
     * NOTE: This method intentionally does NOT auto-generate.
     * Always call generate_event_id() + store_event_id() first
     * (on the thank-you / purchase hook), then call get_event_id()
     * later in the async cron handler.
     */
    public static function get_event_id( int $order_id ): string {
        $event_id = self::get_meta( $order_id, '_servertrack_event_id' );
        return is_string( $event_id ) ? $event_id : '';
    }

    /**
     * Persists the event ID to order meta.
     * Must be called synchronously on the purchase/thankyou hook,
     * before any wp_schedule_single_event() call.
     */
    public static function store_event_id( int $order_id, string $event_id ): void {
        self::update_meta( $order_id, '_servertrack_event_id', $event_id );
    }

    /**
     * Marks a platform as having successfully received a server event
     * for this order. Used to prevent double-sending.
     *
     * @param string $platform  'meta' | 'google' | 'tiktok'
     */
    public static function mark_as_sent( int $order_id, string $platform ): void {
        $sent = self::get_meta( $order_id, '_servertrack_server_sent' );
        if ( ! is_array( $sent ) ) {
            $sent = [];
        }
        $sent[] = $platform;
        self::update_meta( $order_id, '_servertrack_server_sent', array_unique( $sent ) );
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
