<?php
/**
 * Plugin Name:     ServerTrack — Native Server-Side Events
 * Plugin Slug:     servertrack-native
 * Text Domain:     servertrack
 * Version:         1.0.0
 * Requires WP:     6.0+
 * Requires PHP:    7.4+
 * Requires WC:     7.0+
 * License:         GPLv2 or later
 * Author:          Yaser Ahmmed Ratul
 * Author URI:      https://yaratul.com
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'SERVERTRACK_VERSION', '1.0.0' );
define( 'SERVERTRACK_FILE', __FILE__ );
define( 'SERVERTRACK_DIR', plugin_dir_path( __FILE__ ) );
define( 'SERVERTRACK_URL', plugin_dir_url( __FILE__ ) );

// Require Core Files
require_once SERVERTRACK_DIR . 'includes/class-servertrack-hasher.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-logger.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-dedup.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-consent.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-event.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-core.php';

// Activation / Deactivation / Uninstall
register_activation_hook( SERVERTRACK_FILE, 'servertrack_activate' );
register_deactivation_hook( SERVERTRACK_FILE, 'servertrack_deactivate' );

function servertrack_activate() {
    // Set safe defaults on first activation — never overwrite existing values
    $defaults = [
        'servertrack_enabled'          => 1,
        'servertrack_test_mode'        => 0,
        'servertrack_consent_mode'     => 'none',
        'servertrack_meta_enabled'     => 0,
        'servertrack_google_enabled'   => 0,
        'servertrack_tiktok_enabled'   => 0,
        'servertrack_source_woo_enabled' => 1,
        'servertrack_source_cf7_enabled' => 0,
        'servertrack_source_edd_enabled' => 0,
        'servertrack_debug_log'        => [],
        'servertrack_cf7_mappings'     => [],
    ];
    foreach ( $defaults as $key => $value ) {
        if ( false === get_option( $key ) ) {
            add_option( $key, $value );
        }
    }
}

function servertrack_deactivate() {
    // Clear any pending cron jobs on deactivation
    wp_clear_scheduled_hook( 'servertrack_send_woo_purchase' );
}

// Initialize Plugin
function servertrack_native_init() {
    ServerTrack_Core::init();
}
add_action( 'plugins_loaded', 'servertrack_native_init' );
