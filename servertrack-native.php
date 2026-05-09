<?php
/**
 * Plugin Name:     ServerTrack
 * Plugin Slug:     servertrack
 * Description:     A high-performance, zero-dependency server-side tracking plugin for WordPress. Completely bypasses ad blockers and iOS privacy restrictions.
 * Text Domain:     servertrack
 * Version:         1.1.0
 * Requires WP:     6.0
 * Requires PHP:    7.4
 * Requires WC:     7.0+
 * License:         GPLv2 or later
 * Author:          Yaser Ahmmed Ratul
 * Author URI:      https://yaratul.com
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SERVERTRACK_VERSION', '1.1.0' );
define( 'SERVERTRACK_FILE', __FILE__ );
define( 'SERVERTRACK_DIR', plugin_dir_path( __FILE__ ) );
define( 'SERVERTRACK_URL', plugin_dir_url( __FILE__ ) );

// Core includes — always loaded (required by cron callbacks in all contexts)
require_once SERVERTRACK_DIR . 'includes/class-servertrack-hasher.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-logger.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-dedup.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-consent.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-event.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-core.php';

// ── Activation / Deactivation / Uninstall ────────────────────────────────────
register_activation_hook( SERVERTRACK_FILE,   'servertrack_activate' );
register_deactivation_hook( SERVERTRACK_FILE, 'servertrack_deactivate' );

function servertrack_activate() {
    // Verify minimum requirements before activating
    if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
        deactivate_plugins( plugin_basename( SERVERTRACK_FILE ) );
        wp_die(
            esc_html__( 'ServerTrack requires PHP 7.4 or higher. Please upgrade PHP and try again.', 'servertrack' ),
            esc_html__( 'Plugin Activation Error', 'servertrack' ),
            [ 'back_link' => true ]
        );
    }

    if ( version_compare( get_bloginfo( 'version' ), '6.0', '<' ) ) {
        deactivate_plugins( plugin_basename( SERVERTRACK_FILE ) );
        wp_die(
            esc_html__( 'ServerTrack requires WordPress 6.0 or higher. Please upgrade WordPress and try again.', 'servertrack' ),
            esc_html__( 'Plugin Activation Error', 'servertrack' ),
            [ 'back_link' => true ]
        );
    }

    // Set safe defaults on first activation — never overwrite existing values
    $defaults = [
        'servertrack_enabled'            => 1,
        'servertrack_test_mode'          => 0,
        'servertrack_consent_mode'       => 'none',
        'servertrack_meta_enabled'       => 0,
        'servertrack_google_enabled'     => 0,
        'servertrack_tiktok_enabled'     => 0,
        'servertrack_source_woo_enabled' => 1,
        'servertrack_source_cf7_enabled' => 0,
        'servertrack_source_edd_enabled' => 0,
        'servertrack_debug_log'          => [],
        'servertrack_cf7_mappings'       => [],
    ];
    foreach ( $defaults as $key => $value ) {
        if ( false === get_option( $key ) ) {
            add_option( $key, $value );
        }
    }
}

function servertrack_deactivate() {
    // Clear all pending cron jobs registered by ServerTrack
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
    ServerTrack_Core::init();
}
add_action( 'plugins_loaded', 'servertrack_native_init' );
