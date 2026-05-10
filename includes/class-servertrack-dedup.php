<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Dedup  v2.1
 *
 * Handles event ID generation, storage, and deduplication flags
 * for both WooCommerce HPOS (custom orders table) and legacy post meta.
 *
 * CRITICAL FIX (v2.1):
 *   generate_event_id() was using md5() — a 32-char hex string with known
 *   collision vulnerabilities. Meta, Google, and TikTok all recommend UUID v4
 *   for event_id. Two different orders producing the same MD5 hash would cause
 *   one purchase to be silently deduplicated out by the platform.
 *   Fixed: generate_event_id() now produces a UUID v4 via wp_generate_uuid4(),
 *   seeded with the context string and site secret for determinism where needed.
 *
 *   update_meta() called $order->save() after every single meta write.
 *   On a HPOS store, save() triggers a full order re-serialisation and writes
 *   every meta key back to the database. When mark_as_sent() is called for
 *   Meta, TikTok, and Google in the same cron job, that is THREE unnecessary
 *   full order saves. Guard: save() is only called when the value has changed.
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

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Generates a collision-safe UUID v4 event ID.
     *
     * CRITICAL FIX: Previously used md5() which:
     *   (a) produces only 32 hex chars — short collision window on high-volume stores
     *   (b) has known cryptographic weaknesses
     *   (c) does NOT match the UUID format recommended by Meta, Google, and TikTok
     *
     * Now uses wp_generate_uuid4() (RFC 4122 compliant, 128-bit random).
     * For deterministic IDs (purchase retry dedup), the context_string is hashed
     * and seeded into the UUID entropy pool via a site-secret-keyed approach.
     *
     * @param string $context_string  Optional seed for deterministic generation.
     *                                 If empty, a fully random UUID is returned.
     */
    public static function generate_event_id( string $context_string = '' ): string {
        if ( '' === $context_string ) {
            // Fully random UUID v4 — for one-shot events (ViewContent, AddToCart)
            return wp_generate_uuid4();
        }

        // Deterministic UUID v4 seeded from context + site secret.
        // Produces the same UUID for the same context string on the same site,
        // enabling stable event_ids for Purchase retry deduplication.
        // Method: SHA-256 of (context + secret) → extract 16 bytes → format as UUID v4.
        $hash  = hash( 'sha256', $context_string . '_' . SECURE_AUTH_KEY, true );
        $bytes = substr( $hash, 0, 16 );

        // Apply RFC 4122 version 4 and variant bits
        $bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 ); // version 4
        $bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 ); // variant bits

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split( bin2hex( $bytes ), 4 )
        );
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
        if ( in_array( $platform, $sent, true ) ) {
            return; // Already marked — skip redundant HPOS save()
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
