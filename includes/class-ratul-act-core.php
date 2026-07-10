<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ratul_ACT_Core — backward-compatibility shim (v6.0.3+)
 *
 * This class is intentionally a no-op for its bootstrap. In v6.0.2 and earlier,
 * Ratul_ACT_Core was a competing bootstrap system inside includes/ that was
 * never actually loaded or called from the main plugin file (ratul-ads-conversion-tracker.php).
 *
 * As of v6.0.3 the single authoritative bootstrap is ratul_act_init() in
 * ratul-ads-conversion-tracker.php. Ratul_ACT_Core::init() is kept here as a safe no-op.
 *
 * BUG-FIX (v6.0.5):
 *   dispatch_to_all() and dispatch_to_platforms() were missing entirely from
 *   this shim. Ratul_ACT_Source_WooCommerce calls them on every WooCommerce
 *   CAPI event (Purchase, AddToCart, InitiateCheckout, etc.). The missing
 *   methods caused a PHP Fatal Error ("Call to undefined method") on every
 *   single event, producing the "There has been a critical error on this
 *   website." white screen.
 *
 *   Fix: implement both dispatch methods here using the platform classes
 *   (Ratul_ACT_Meta, Ratul_ACT_TikTok, Ratul_ACT_Google) and
 *   Ratul_ACT_Dedup for per-platform already-sent guards.
 */
class Ratul_ACT_Core {

    /**
     * No-op. The real bootstrap runs via ratul_act_init() at plugins_loaded.
     *
     * @since 6.0.3
     */
    public static function init(): void {
        _doing_it_wrong(
            __METHOD__,
            'Ratul_ACT_Core::init() is a no-op shim since v6.0.3. ' .
            'The authoritative bootstrap is ratul_act_init() in ratul-ads-conversion-tracker.php.',
            '6.0.3'
        );
    }

    /**
     * Dispatch an event to ALL three platforms (Meta, TikTok, Google),
     * skipping any that have already received this event (per dedup key).
     *
     * This is the method called by Ratul_ACT_Source_WooCommerce for every
     * WooCommerce CAPI event. It was missing from this shim, causing a PHP
     * Fatal Error on every hook firing.
     *
     * @param Ratul_ACT_Event  $event     Fully populated event DTO.
     * @param string|int|null    $dedup_key Dedup key; defaults to event_id.
     */
    public static function dispatch_to_all( Ratul_ACT_Event $event, $dedup_key = null ): void {
        self::dispatch_to_platforms( $event, [ 'meta', 'tiktok', 'google', 'snapchat', 'pinterest', 'linkedin' ], $dedup_key );
    }

    /**
     * Dispatch an event to a specific subset of platforms.
     *
     * Checks Ratul_ACT_Dedup::already_sent() per platform before sending,
     * then marks each successfully dispatched platform via
     * Ratul_ACT_Dedup::mark_sent().
     *
     * @param Ratul_ACT_Event  $event      Fully populated event DTO.
     * @param string[]           $platforms  Any subset of ['meta','tiktok','google'].
     * @param string|int|null    $dedup_key  Dedup key; defaults to event_id.
     */
    public static function dispatch_to_platforms(
        Ratul_ACT_Event $event,
        array $platforms,
        $dedup_key = null
    ): void {
        $key = (string) ( $dedup_key ?? $event->event_id );

        Ratul_ACT_Dispatcher::dispatch( $event, $platforms, $key );
    }
}

