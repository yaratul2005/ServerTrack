<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sources tab — uses its own option group: servertrack_sources_settings
 * Saving this tab ONLY saves Sources options. Other tabs are unaffected.
 */

$woo_enabled      = get_option( 'servertrack_source_woo_enabled', 1 );
$cf7_enabled      = get_option( 'servertrack_source_cf7_enabled', 0 );
$edd_enabled      = get_option( 'servertrack_source_edd_enabled', 0 );
$scroll_enabled   = get_option( 'servertrack_scroll_depth', 1 );
$video_enabled    = get_option( 'servertrack_video_tracking', 1 );
$wishlist_enabled = get_option( 'servertrack_wishlist_tracking', 1 );
$woo_active       = class_exists( 'WooCommerce' );
$cf7_active       = class_exists( 'WPCF7' );
$edd_active       = class_exists( 'Easy_Digital_Downloads' );
?>

<form method="post" action="options.php">
    <?php settings_fields( 'servertrack_sources_settings' ); ?>

    <h2><?php esc_html_e( 'Event Sources', 'servertrack' ); ?></h2>
    <p class="description"><?php esc_html_e( 'Choose which integrations fire server-side CAPI events.', 'servertrack' ); ?></p>

    <h3 style="margin-top:2em;"><?php esc_html_e( 'Server-Side Sources', 'servertrack' ); ?></h3>
    <table class="form-table" role="presentation">

        <tr>
            <th scope="row"><?php esc_html_e( 'WooCommerce', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_source_woo_enabled" value="1"
                        <?php checked( 1, $woo_enabled ); ?>
                        <?php disabled( ! $woo_active ); ?> />
                    <?php esc_html_e( 'Enable WooCommerce events', 'servertrack' ); ?>
                </label>
                <?php if ( ! $woo_active ) : ?>
                    <p class="description" style="color:#b32d2e;"><?php esc_html_e( 'WooCommerce is not active.', 'servertrack' ); ?></p>
                <?php else : ?>
                    <p class="description"><?php esc_html_e( 'Fires: ViewContent, AddToCart, InitiateCheckout, AddPaymentInfo, Purchase, CompleteRegistration, Search, ViewCategory', 'servertrack' ); ?></p>
                <?php endif; ?>
            </td>
        </tr>

        <tr>
            <th scope="row"><?php esc_html_e( 'Contact Form 7', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_source_cf7_enabled" value="1"
                        <?php checked( 1, $cf7_enabled ); ?>
                        <?php disabled( ! $cf7_active ); ?> />
                    <?php esc_html_e( 'Enable Contact Form 7 events', 'servertrack' ); ?>
                </label>
                <?php if ( ! $cf7_active ) : ?>
                    <p class="description" style="color:#b32d2e;"><?php esc_html_e( 'Contact Form 7 is not active.', 'servertrack' ); ?></p>
                <?php else : ?>
                    <p class="description"><?php esc_html_e( 'Fires: Lead — configure field mappings below.', 'servertrack' ); ?></p>
                <?php endif; ?>
            </td>
        </tr>

        <tr>
            <th scope="row"><?php esc_html_e( 'Easy Digital Downloads', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_source_edd_enabled" value="1"
                        <?php checked( 1, $edd_enabled ); ?>
                        <?php disabled( ! $edd_active ); ?> />
                    <?php esc_html_e( 'Enable EDD events', 'servertrack' ); ?>
                </label>
                <?php if ( ! $edd_active ) : ?>
                    <p class="description" style="color:#b32d2e;"><?php esc_html_e( 'Easy Digital Downloads is not active.', 'servertrack' ); ?></p>
                <?php else : ?>
                    <p class="description"><?php esc_html_e( 'Fires: Purchase, ViewContent, AddToCart', 'servertrack' ); ?></p>
                <?php endif; ?>
            </td>
        </tr>

    </table>

    <h3 style="margin-top:2.5em;"><?php esc_html_e( 'Browser Engagement Tracking', 'servertrack' ); ?></h3>
    <p class="description"><?php esc_html_e( 'These events fire in the browser pixel only (no server-side CAPI send).', 'servertrack' ); ?></p>
    <table class="form-table" role="presentation">

        <tr>
            <th scope="row"><?php esc_html_e( 'Scroll Depth', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_scroll_depth" value="1"
                        <?php checked( 1, $scroll_enabled ); ?> />
                    <?php esc_html_e( 'Fire ScrollDepth custom event at 25%, 50%, 75%, 100%', 'servertrack' ); ?>
                </label>
                <p class="description"><?php esc_html_e( 'Fires: ScrollDepth (custom) → Meta trackCustom + TikTok.', 'servertrack' ); ?></p>
            </td>
        </tr>

        <tr>
            <th scope="row"><?php esc_html_e( 'Video Tracking', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_video_tracking" value="1"
                        <?php checked( 1, $video_enabled ); ?> />
                    <?php esc_html_e( 'Track HTML5 video play and progress milestones', 'servertrack' ); ?>
                </label>
                <p class="description"><?php esc_html_e( 'Fires: VideoPlay, VideoProgress (custom) at 25/50/75/95%.', 'servertrack' ); ?></p>
            </td>
        </tr>

        <tr>
            <th scope="row"><?php esc_html_e( 'Wishlist Tracking', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_wishlist_tracking" value="1"
                        <?php checked( 1, $wishlist_enabled ); ?> />
                    <?php esc_html_e( 'Track Add-to-Wishlist clicks (YITH / TI WooCommerce Wishlist)', 'servertrack' ); ?>
                </label>
                <p class="description"><?php esc_html_e( 'Fires: AddToWishlist (custom) → Meta trackCustom + TikTok.', 'servertrack' ); ?></p>
            </td>
        </tr>

    </table>

    <h3 style="margin-top:2.5em;"><?php esc_html_e( 'Custom Events', 'servertrack' ); ?></h3>
    <p class="description"><?php esc_html_e( 'Three methods to fire custom events to Meta CAPI + TikTok CAPI simultaneously.', 'servertrack' ); ?></p>

    <div style="background:#f6f7f7;border:1px solid #e0e0e0;padding:1.2em 1.5em;border-radius:4px;max-width:680px;">
        <h4 style="margin-top:0;"><?php esc_html_e( '1. HTML Attribute (No Code)', 'servertrack' ); ?></h4>
        <pre style="background:#1e1e1e;color:#d4d4d4;padding:1em;border-radius:3px;overflow-x:auto;font-size:12px;">&lt;button
  data-servertrack-event="Lead"
  data-servertrack-params='{"content_name":"Newsletter"}'
&gt;Subscribe&lt;/button&gt;</pre>

        <h4><?php esc_html_e( '2. JavaScript API', 'servertrack' ); ?></h4>
        <pre style="background:#1e1e1e;color:#d4d4d4;padding:1em;border-radius:3px;overflow-x:auto;font-size:12px;">window.ServerTrack.track('QuizCompleted', { score: 90 });</pre>

        <h4><?php esc_html_e( '3. PHP Action Hook (Pure Server-Side)', 'servertrack' ); ?></h4>
        <pre style="background:#1e1e1e;color:#d4d4d4;padding:1em;border-radius:3px;overflow-x:auto;font-size:12px;">do_action( 'servertrack_custom_event', 'CouponApplied', [
    'coupon_code' => $coupon_code,
    'value'       => $discount_amount,
    'currency'    => 'USD',
] );</pre>
    </div>

    <?php if ( $cf7_active && $cf7_enabled ) : ?>
    <h3 style="margin-top:2.5em;"><?php esc_html_e( 'CF7 Field Mappings', 'servertrack' ); ?></h3>
    <p class="description"><?php esc_html_e( 'Map CF7 form fields to PII fields for better Event Match Quality.', 'servertrack' ); ?></p>
    <table class="form-table" role="presentation">
        <?php
        $forms    = get_posts( [ 'post_type' => 'wpcf7_contact_form', 'numberposts' => -1 ] );
        $mappings = get_option( 'servertrack_cf7_mappings', [] );
        foreach ( $forms as $form ) :
            $fid   = $form->ID;
            $saved = $mappings[ $fid ] ?? [];
            ?>
            <tr>
                <th scope="row"><?php echo esc_html( $form->post_title ); ?></th>
                <td>
                    <p><label><?php esc_html_e( 'Email field name:', 'servertrack' ); ?><br>
                    <input type="text" name="servertrack_cf7_mappings[<?php echo esc_attr( $fid ); ?>][email]"
                           value="<?php echo esc_attr( $saved['email'] ?? 'your-email' ); ?>" class="regular-text" /></label></p>
                    <p><label><?php esc_html_e( 'Phone field name:', 'servertrack' ); ?><br>
                    <input type="text" name="servertrack_cf7_mappings[<?php echo esc_attr( $fid ); ?>][phone]"
                           value="<?php echo esc_attr( $saved['phone'] ?? 'your-phone' ); ?>" class="regular-text" /></label></p>
                    <p><label><?php esc_html_e( 'Name field name:', 'servertrack' ); ?><br>
                    <input type="text" name="servertrack_cf7_mappings[<?php echo esc_attr( $fid ); ?>][name]"
                           value="<?php echo esc_attr( $saved['name'] ?? 'your-name' ); ?>" class="regular-text" /></label></p>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php submit_button( __( 'Save Source Settings', 'servertrack' ) ); ?>
</form>
