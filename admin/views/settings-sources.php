<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<form method="post" action="options.php">
    <?php settings_fields( 'servertrack_sources_settings' ); ?>

    <h2><?php esc_html_e( 'Event Sources', 'servertrack' ); ?></h2>
    <p><?php esc_html_e( 'Enable or disable individual event source integrations.', 'servertrack' ); ?></p>

    <table class="form-table" role="presentation">

        <!-- ── WooCommerce core ───────────────────────────────────────────── -->
        <tr>
            <th scope="row"><?php esc_html_e( 'WooCommerce', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_source_woo_enabled" value="1"
                        <?php checked( 1, get_option( 'servertrack_source_woo_enabled', 1 ) ); ?> />
                    <?php esc_html_e( 'Enable WooCommerce tracking (Purchase, ViewContent, AddToCart, InitiateCheckout, AddPaymentInfo, CompleteRegistration, Refund, Renewal, SubscriptionCancelled)', 'servertrack' ); ?>
                </label>
            </td>
        </tr>

        <!-- ── WooCommerce sub-options heading ───────────────────────────── -->
        <tr>
            <th scope="row" colspan="2" style="padding-bottom:4px;padding-top:20px">
                <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#9ca3af">
                    <?php esc_html_e( 'WooCommerce — Additional Events', 'servertrack' ); ?>
                </span>
            </th>
        </tr>

        <!-- Cart Abandonment -->
        <tr>
            <th scope="row" style="padding-left:24px"><?php esc_html_e( 'Cart Abandonment', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_source_abandonment_enabled" value="1"
                        <?php checked( 1, get_option( 'servertrack_source_abandonment_enabled', 0 ) ); ?> />
                    <?php esc_html_e( 'Enable cart abandonment tracking (fires InitiateCheckout CAPI event after the abandonment window)', 'servertrack' ); ?>
                </label>
                <p class="description">
                    <?php esc_html_e( 'Sends an InitiateCheckout event to Meta and TikTok, and a begin_checkout event to Google, when a cart is abandoned.', 'servertrack' ); ?>
                </p>
                <br />
                <label>
                    <?php esc_html_e( 'Abandonment window (minutes):', 'servertrack' ); ?>
                    <input type="number" name="servertrack_abandonment_window_minutes"
                        value="<?php echo esc_attr( get_option( 'servertrack_abandonment_window_minutes', 60 ) ); ?>"
                        min="5" max="1440" step="5" style="width:80px;" />
                </label>
                <p class="description">
                    <?php esc_html_e( 'Minimum time (in minutes) of cart inactivity before the event fires. Default: 60. Minimum: 5.', 'servertrack' ); ?>
                </p>
            </td>
        </tr>

        <!-- Order Status Events (v3.3) -->
        <tr>
            <th scope="row" style="padding-left:24px"><?php esc_html_e( 'Order Status Events', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_source_order_status_enabled" value="1"
                        <?php checked( 1, get_option( 'servertrack_source_order_status_enabled', 1 ) ); ?> />
                    <?php esc_html_e( 'Enable order lifecycle events (on-hold, failed, cancelled)', 'servertrack' ); ?>
                </label>
                <p class="description">
                    <?php esc_html_e( 'Sends a Lead event (Meta), Contact/SubmitForm (TikTok), and generate_lead (Google) when an order status changes to on-hold, failed, or cancelled. Useful for re-engagement and retargeting audiences.', 'servertrack' ); ?>
                </p>
            </td>
        </tr>

        <!-- Partial Refund Events (v3.3) -->
        <tr>
            <th scope="row" style="padding-left:24px"><?php esc_html_e( 'Partial Refund Events', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_source_partial_refund_enabled" value="1"
                        <?php checked( 1, get_option( 'servertrack_source_partial_refund_enabled', 1 ) ); ?> />
                    <?php esc_html_e( 'Enable partial refund tracking (separate from full-order refunds)', 'servertrack' ); ?>
                </label>
                <p class="description">
                    <?php esc_html_e( 'Sends a negative-value Purchase (Meta/TikTok) or refund (Google) event for each partial refund, using the exact refund amount — not the full order total.', 'servertrack' ); ?>
                </p>
            </td>
        </tr>

        <!-- Add to Wishlist Events (v3.3) -->
        <tr>
            <th scope="row" style="padding-left:24px"><?php esc_html_e( 'Add to Wishlist Events', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_source_wishlist_enabled" value="1"
                        <?php checked( 1, get_option( 'servertrack_source_wishlist_enabled', 0 ) ); ?> />
                    <?php esc_html_e( 'Enable AddToWishlist tracking (requires YITH or TI WooCommerce Wishlist plugin)', 'servertrack' ); ?>
                </label>
                <p class="description">
                    <?php esc_html_e( 'Sends an AddToWishlist event to Meta and TikTok. Not available for Google (no standard equivalent). Disabled by default — enable only if a supported wishlist plugin is active.', 'servertrack' ); ?>
                </p>
                <?php
                $yith_active = function_exists( 'YITH_WCWL' ) || class_exists( 'YITH_WCWL' );
                $ti_active   = function_exists( 'TIWL' )       || defined( 'TIWL_VERSION' );
                if ( ! $yith_active && ! $ti_active ) : ?>
                <p class="description" style="color:#b91c1c;margin-top:4px">
                    ⚠ <?php esc_html_e( 'No supported wishlist plugin detected. Install YITH WooCommerce Wishlist or TI WooCommerce Wishlist to use this feature.', 'servertrack' ); ?>
                </p>
                <?php endif; ?>
            </td>
        </tr>

        <!-- ── Other sources ──────────────────────────────────────────────── -->
        <tr>
            <th scope="row" colspan="2" style="padding-bottom:4px;padding-top:20px">
                <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#9ca3af">
                    <?php esc_html_e( 'Other Sources', 'servertrack' ); ?>
                </span>
            </th>
        </tr>

        <!-- Contact Form 7 -->
        <tr>
            <th scope="row"><?php esc_html_e( 'Contact Form 7', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_source_cf7_enabled" value="1"
                        <?php checked( 1, get_option( 'servertrack_source_cf7_enabled', 0 ) ); ?> />
                    <?php esc_html_e( 'Enable Contact Form 7 tracking (Lead event on form submit)', 'servertrack' ); ?>
                </label>
            </td>
        </tr>

        <!-- Easy Digital Downloads -->
        <tr>
            <th scope="row"><?php esc_html_e( 'Easy Digital Downloads', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_source_edd_enabled" value="1"
                        <?php checked( 1, get_option( 'servertrack_source_edd_enabled', 0 ) ); ?> />
                    <?php esc_html_e( 'Enable Easy Digital Downloads tracking (Purchase, Refund, new customer)', 'servertrack' ); ?>
                </label>
            </td>
        </tr>

    </table>

    <?php submit_button(); ?>
</form>
