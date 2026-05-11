<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<form method="post" action="options.php">
    <?php settings_fields( 'servertrack_sources_settings' ); ?>

    <h2><?php esc_html_e( 'Event Sources', 'servertrack' ); ?></h2>
    <p><?php esc_html_e( 'Enable or disable individual event source integrations.', 'servertrack' ); ?></p>

    <table class="form-table" role="presentation">

        <!-- ================================================================ -->
        <!-- WooCommerce core -->
        <!-- ================================================================ -->
        <tr>
            <th scope="row"><?php esc_html_e( 'WooCommerce', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_source_woo_enabled" value="1"
                        <?php checked( 1, get_option( 'servertrack_source_woo_enabled', 1 ) ); ?> />
                    <?php esc_html_e( 'Enable WooCommerce tracking (Purchase, ViewContent, AddToCart, InitiateCheckout, AddPaymentInfo, CompleteRegistration, Refund, Renewal)', 'servertrack' ); ?>
                </label>
            </td>
        </tr>

        <!-- Cart Abandonment -->
        <tr>
            <th scope="row"><?php esc_html_e( 'Cart Abandonment', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_source_abandonment_enabled" value="1"
                        <?php checked( 1, get_option( 'servertrack_source_abandonment_enabled', 0 ) ); ?> />
                    <?php esc_html_e( 'Enable cart abandonment tracking (fires InitiateCheckout CAPI event after the abandonment window)', 'servertrack' ); ?>
                </label>
                <p class="description">
                    <?php esc_html_e( 'Requires WooCommerce. Sends InitiateCheckout (Meta/TikTok) and begin_checkout (Google) when a cart is abandoned.', 'servertrack' ); ?>
                </p>
                <br />
                <label>
                    <?php esc_html_e( 'Abandonment window (minutes):', 'servertrack' ); ?>
                    <input type="number" name="servertrack_abandonment_window_minutes"
                        value="<?php echo esc_attr( get_option( 'servertrack_abandonment_window_minutes', 60 ) ); ?>"
                        min="5" max="1440" step="5" style="width:80px;" />
                </label>
                <p class="description"><?php esc_html_e( 'Minimum cart inactivity before event fires. Default: 60 min. Minimum: 5 min.', 'servertrack' ); ?></p>
            </td>
        </tr>

        <!-- ================================================================ -->
        <!-- v3.3 NEW: Order Status Events -->
        <!-- ================================================================ -->
        <tr style="background:#f9fafb;">
            <th scope="row">
                <?php esc_html_e( 'Order Status Events', 'servertrack' ); ?>
                <span style="display:block;font-size:11px;font-weight:400;color:#6b7280;margin-top:2px;">v3.3</span>
            </th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_source_order_status_enabled" value="1"
                        <?php checked( 1, get_option( 'servertrack_source_order_status_enabled', 1 ) ); ?> />
                    <?php esc_html_e( 'Enable order lifecycle events (on-hold, failed, cancelled)', 'servertrack' ); ?>
                </label>
                <p class="description">
                    <?php esc_html_e( 'Fires server-side Lead / Contact / SubmitForm events when an order transitions to on-hold, failed, or cancelled status. Useful for re-engagement and win-back audiences. Enabled by default.', 'servertrack' ); ?>
                </p>
            </td>
        </tr>

        <!-- v3.3 NEW: AddToWishlist Events -->
        <tr style="background:#f9fafb;">
            <th scope="row">
                <?php esc_html_e( 'AddToWishlist Events', 'servertrack' ); ?>
                <span style="display:block;font-size:11px;font-weight:400;color:#6b7280;margin-top:2px;">v3.3 · Opt-in</span>
            </th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_source_wishlist_enabled" value="1"
                        <?php checked( 1, get_option( 'servertrack_source_wishlist_enabled', 0 ) ); ?> />
                    <?php esc_html_e( 'Enable AddToWishlist CAPI events (Meta & TikTok)', 'servertrack' ); ?>
                </label>
                <p class="description">
                    <?php esc_html_e( 'Requires YITH WooCommerce Wishlist or TI WooCommerce Wishlist plugin. Fires AddToWishlist to Meta and TikTok when a customer adds a product to a wishlist. Disabled by default (opt-in).', 'servertrack' ); ?>
                </p>
            </td>
        </tr>

        <!-- v3.3 NEW: Partial Refund Events -->
        <tr style="background:#f9fafb;">
            <th scope="row">
                <?php esc_html_e( 'Partial Refund Events', 'servertrack' ); ?>
                <span style="display:block;font-size:11px;font-weight:400;color:#6b7280;margin-top:2px;">v3.3</span>
            </th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_source_partial_refund_enabled" value="1"
                        <?php checked( 1, get_option( 'servertrack_source_partial_refund_enabled', 1 ) ); ?> />
                    <?php esc_html_e( 'Enable partial refund CAPI events (exact refund amount, not order total)', 'servertrack' ); ?>
                </label>
                <p class="description">
                    <?php esc_html_e( 'Sends a Purchase event with a negative value equal to the exact partial refund amount. Handled separately from full refunds. Enabled by default.', 'servertrack' ); ?>
                </p>
            </td>
        </tr>

        <!-- ================================================================ -->
        <!-- Contact Form 7 -->
        <!-- ================================================================ -->
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
