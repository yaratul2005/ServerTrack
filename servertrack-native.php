<?php
/**
 * Plugin Name:     ServerTrack
 * Plugin URI:      https://yaratul.com/servertrack
 * Description:     High-performance server-side tracking for WordPress. Sends events to Meta CAPI, Google Ads, and TikTok Events API — completely bypassing ad blockers and iOS restrictions.
 * Text Domain:     servertrack
 * Domain Path:     /languages
 * Version:         2.0.0
 * Requires WP:     6.0
 * Requires PHP:    7.4
 * License:         GPLv2 or later
 * Author:          Yaser Ahmmed Ratul
 * Author URI:      https://yaratul.com
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SERVERTRACK_VERSION', '2.0.0' );
define( 'SERVERTRACK_FILE',    __FILE__ );
define( 'SERVERTRACK_DIR',     plugin_dir_path( __FILE__ ) );
define( 'SERVERTRACK_URL',     plugin_dir_url( __FILE__ ) );

// ── Core — always loaded (required in every context including cron) ───────────
require_once SERVERTRACK_DIR . 'includes/class-servertrack-hasher.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-logger.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-dedup.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-consent.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-event.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-retry.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-core.php';

// ── Lifecycle hooks ───────────────────────────────────────────────────────────
register_activation_hook( SERVERTRACK_FILE,   'servertrack_activate' );
register_deactivation_hook( SERVERTRACK_FILE, 'servertrack_deactivate' );

function servertrack_activate() {
    if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
        deactivate_plugins( plugin_basename( SERVERTRACK_FILE ) );
        wp_die(
            esc_html__( 'ServerTrack requires PHP 7.4 or higher.', 'servertrack' ),
            esc_html__( 'Plugin Activation Error', 'servertrack' ),
            [ 'back_link' => true ]
        );
    }
    if ( version_compare( get_bloginfo( 'version' ), '6.0', '<' ) ) {
        deactivate_plugins( plugin_basename( SERVERTRACK_FILE ) );
        wp_die(
            esc_html__( 'ServerTrack requires WordPress 6.0 or higher.', 'servertrack' ),
            esc_html__( 'Plugin Activation Error', 'servertrack' ),
            [ 'back_link' => true ]
        );
    }

    // Set safe defaults on first activation — never overwrite existing values
    $defaults = [
        'servertrack_enabled'              => 1,
        'servertrack_test_mode'            => 0,
        'servertrack_consent_mode'         => 'none',
        'servertrack_meta_enabled'         => 0,
        'servertrack_google_enabled'       => 0,
        'servertrack_tiktok_enabled'       => 0,
        'servertrack_source_woo_enabled'   => 1,
        'servertrack_source_cf7_enabled'   => 0,
        'servertrack_source_edd_enabled'   => 0,
        'servertrack_debug_log'            => [],
        'servertrack_cf7_mappings'         => [],
        // FIX: missing engagement tracking defaults
        'servertrack_scroll_depth'         => 1,
        'servertrack_video_tracking'       => 1,
        'servertrack_wishlist_tracking'    => 1,
        // Google gtag settings (missing in v1)
        'servertrack_google_gtag_id'       => '',
        'servertrack_google_gtag_label'    => '',
    ];
    foreach ( $defaults as $key => $value ) {
        if ( false === get_option( $key ) ) {
            add_option( $key, $value );
        }
    }

    // Schedule recurring retry processor if not already scheduled
    if ( ! wp_next_scheduled( 'servertrack_retry_failed_events' ) ) {
        wp_schedule_event( time() + 300, 'hourly', 'servertrack_retry_failed_events' );
    }
}

function servertrack_deactivate() {
    $hooks = [
        'servertrack_send_woo_purchase',
        'servertrack_send_edd_purchase',
        'servertrack_send_renewal_purchase',
        'servertrack_retry_failed_events',
    ];
    foreach ( $hooks as $hook ) {
        wp_clear_scheduled_hook( $hook );
        wp_unschedule_hook( $hook );
    }
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
function servertrack_native_init() {
    load_plugin_textdomain( 'servertrack', false, dirname( plugin_basename( SERVERTRACK_FILE ) ) . '/languages' );
    ServerTrack_Core::init();
}
add_action( 'plugins_loaded', 'servertrack_native_init' );
