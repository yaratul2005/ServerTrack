<?php
/**
 * Plugin Name:       ServerTrack
 * Plugin URI:        https://github.com/yaratul2005/ServerTrack
 * Description:       Server-side Conversion API tracking for Meta, Google, and TikTok — WooCommerce, CF7, EDD, cart abandonment, subscription renewals, and refunds. Full admin dashboard with live log.
 * Version:           4.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            YASER AHMMED RATUL
 * Author URI:        https://github.com/yaratul2005
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       servertrack
 * Domain Path:       /languages
 *
 * @package ServerTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Constants ────────────────────────────────────────────────────────────────
define( 'SERVERTRACK_VERSION', '4.0.0' );
define( 'SERVERTRACK_DIR',     plugin_dir_path( __FILE__ ) );
define( 'SERVERTRACK_URL',     plugin_dir_url( __FILE__ ) );

// ── Autoload core utilities (always needed, even in cron) ────────────────────
require_once SERVERTRACK_DIR . 'includes/class-servertrack-event.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-hasher.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-logger.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-dedup.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-consent.php';
require_once SERVERTRACK_DIR . 'includes/class-servertrack-retry.php';

// ── Bootstrap ────────────────────────────────────────────────────────────────
require_once SERVERTRACK_DIR . 'includes/class-servertrack-core.php';
add_action( 'plugins_loaded', [ 'ServerTrack_Core', 'init' ], 5 );

// ── Activation / deactivation ────────────────────────────────────────────────
register_activation_hook(
    __FILE__,
    function () {
        // Ensure default option values exist on first activation
        $defaults = [
            'servertrack_enabled'                     => 1,
            'servertrack_meta_enabled'                => 0,
            'servertrack_google_enabled'              => 0,
            'servertrack_tiktok_enabled'              => 0,
            'servertrack_source_woo_enabled'          => 1,
            'servertrack_source_cf7_enabled'          => 0,
            'servertrack_source_edd_enabled'          => 0,
            'servertrack_source_abandonment_enabled'  => 0,
            'servertrack_abandonment_window_minutes'  => 60,
            'servertrack_dedup_ttl_days'              => 30,
            'servertrack_log_max_entries'             => 200,
            'servertrack_scroll_depth'                => 1,
            'servertrack_video_tracking'              => 1,
            'servertrack_wishlist_tracking'           => 1,
        ];
        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value );
            }
        }
    }
);

register_deactivation_hook(
    __FILE__,
    function () {
        wp_clear_scheduled_hook( 'servertrack_process_retry' );
        wp_clear_scheduled_hook( 'servertrack_check_abandonments' );
    }
);
