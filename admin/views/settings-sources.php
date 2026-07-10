<?php
/**
 * Event Sources settings tab — view fragment only.
 * C1: <form> and settings_fields() removed. render_page() owns the form.
 * C2: ratuls_act_source_woo_enabled — now registered, name aligned.
 * C3: key aligned to ratuls_act_source_cart_abandonment_enabled.
 * C4: ratuls_act_abandonment_window_minutes — now registered.
 * C5: ratuls_act_source_cf7_enabled — now registered.
 * C6: ratuls_act_source_edd_enabled — now registered.
 * C7: ratuls_act_source_subscriptions_enabled — UI row added.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<h2><?php esc_html_e( 'Event Sources', 'ratuls-act' ); ?></h2>
<p><?php esc_html_e( 'Enable or disable individual event source integrations.', 'ratuls-act' ); ?></p>

<div class="st-settings-section">
<table class="form-table" role="presentation">

    <!-- WooCommerce core -->
    <tr>
        <th scope="row"><?php esc_html_e( 'WooCommerce', 'ratuls-act' ); ?></th>
        <td>
            <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratuls_act_source_woo_enabled" value="1"
                    <?php checked( 1, get_option( 'ratuls_act_source_woo_enabled', 1 ) ); ?> />
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Enable WooCommerce tracking (Purchase, ViewContent, AddToCart, InitiateCheckout, AddPaymentInfo, CompleteRegistration, Refund, Renewal)', 'ratuls-act' ); ?></span>
</label>
        </td>
    </tr>

    <!-- Manual Purchase Verification -->
    <tr style="background:#f0f8ff;">
        <th scope="row">
            <?php esc_html_e( 'Manual Purchase Verification', 'ratuls-act' ); ?>
        </th>
        <td>
            <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
                <div class="st-toggle" style="margin-right:12px;">
                    <input type="checkbox" name="ratuls_act_manual_purchase_enabled" value="1"
                                <?php checked( 1, get_option( 'ratuls_act_manual_purchase_enabled', 0 ) ); ?> />
                    <span class="st-toggle-slider"></span>
                </div>
                <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Enable manual Purchase event firing', 'ratuls-act' ); ?></span>
            </label>

            <p class="description">
                <?php esc_html_e( 'When enabled, automatic Purchase events on the Thank You page are disabled. You must manually fire the Purchase event from the WooCommerce Orders page after verifying the order is legitimate.', 'ratuls-act' ); ?>
            </p>
        </td>
    </tr>

    <!-- Cart Abandonment (C3: key corrected to _cart_abandonment_enabled) -->
    <tr>
        <th scope="row"><?php esc_html_e( 'Cart Abandonment', 'ratuls-act' ); ?></th>
        <td>
            <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratuls_act_source_cart_abandonment_enabled" value="1"
                    <?php checked( 1, get_option( 'ratuls_act_source_cart_abandonment_enabled', 0 ) ); ?> />
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Enable cart abandonment tracking (fires InitiateCheckout CAPI event after the abandonment window)', 'ratuls-act' ); ?></span>
</label>
            <p class="description">
                <?php esc_html_e( 'Requires WooCommerce. Sends InitiateCheckout (Meta/TikTok) and begin_checkout (Google) when a cart is abandoned.', 'ratuls-act' ); ?>
            </p>
            <div style="margin-top: 10px;">
                <strong>Event Mapping:</strong><br>
                <span class="st-badge meta">Meta</span> <span class="st-badge tiktok">TikTok</span> <span class="st-badge google">Google</span> <br>
                <small>Fires: InitiateCheckout (Meta/TikTok), begin_checkout (Google)</small>
            </div>
            <br />
            <label>
                <?php esc_html_e( 'Abandonment window (minutes):', 'ratuls-act' ); ?>
                <input type="number" name="ratuls_act_abandonment_window_minutes"
                    value="<?php echo esc_attr( get_option( 'ratuls_act_abandonment_window_minutes', 60 ) ); ?>"
                    min="5" max="1440" step="5" style="width:80px;" />
            </label>
            <p class="description"><?php esc_html_e( 'Minimum cart inactivity before event fires. Default: 60 min. Minimum: 5 min.', 'ratuls-act' ); ?></p>
        </td>
    </tr>

    <!-- Order Status Events (v3.3) -->
    <tr style="background:#f9fafb;">
        <th scope="row">
            <?php esc_html_e( 'Order Status Events', 'ratuls-act' ); ?>
            <span style="display:block;font-size:11px;font-weight:400;color:#6b7280;margin-top:2px;">v3.3</span>
        </th>
        <td>
            <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratuls_act_source_order_status_enabled" value="1"
                    <?php checked( 1, get_option( 'ratuls_act_source_order_status_enabled', 1 ) ); ?> />
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Enable order lifecycle events (on-hold, failed, cancelled)', 'ratuls-act' ); ?></span>
</label>
            <p class="description">
                <?php esc_html_e( 'Fires server-side Lead / Contact / SubmitForm events when an order transitions to on-hold, failed, or cancelled status.', 'ratuls-act' ); ?>
            </p>
            <div style="margin-top: 10px;">
                <strong>Event Mapping:</strong><br>
                <span class="st-badge meta">Meta</span> <span class="st-badge tiktok">TikTok</span> <span class="st-badge google">Google</span> <br>
                <small>Fires: Lead (on-hold), Contact (failed), SubmitForm (cancelled)</small>
            </div>
        </td>
    </tr>

    <!-- AddToWishlist Events (v3.3) -->
    <tr style="background:#f9fafb;">
        <th scope="row">
            <?php esc_html_e( 'AddToWishlist Events', 'ratuls-act' ); ?>
            <span style="display:block;font-size:11px;font-weight:400;color:#6b7280;margin-top:2px;">v3.3 · Opt-in</span>
        </th>
        <td>
            <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratuls_act_source_wishlist_enabled" value="1"
                    <?php checked( 1, get_option( 'ratuls_act_source_wishlist_enabled', 0 ) ); ?> />
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Enable AddToWishlist CAPI events (Meta & TikTok)', 'ratuls-act' ); ?></span>
</label>
            <p class="description">
                <?php esc_html_e( 'Requires YITH WooCommerce Wishlist or TI WooCommerce Wishlist plugin.', 'ratuls-act' ); ?>
            </p>
            <div style="margin-top: 10px;">
                <strong>Event Mapping:</strong><br>
                <span class="st-badge meta">Meta</span> <span class="st-badge tiktok">TikTok</span> <br>
                <small>Fires: AddToWishlist</small>
            </div>
        </td>
    </tr>

    <!-- Partial Refund Events (v3.3) -->
    <tr style="background:#f9fafb;">
        <th scope="row">
            <?php esc_html_e( 'Partial Refund Events', 'ratuls-act' ); ?>
            <span style="display:block;font-size:11px;font-weight:400;color:#6b7280;margin-top:2px;">v3.3</span>
        </th>
        <td>
            <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratuls_act_source_partial_refund_enabled" value="1"
                    <?php checked( 1, get_option( 'ratuls_act_source_partial_refund_enabled', 1 ) ); ?> />
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Enable partial refund CAPI events (exact refund amount, not order total)', 'ratuls-act' ); ?></span>
</label>
            <p class="description">
                <?php esc_html_e( 'Sends a Purchase event with a negative value equal to the exact partial refund amount.', 'ratuls-act' ); ?>
            </p>
            <div style="margin-top: 10px;">
                <strong>Event Mapping:</strong><br>
                <span class="st-badge meta">Meta</span> <span class="st-badge tiktok">TikTok</span> <span class="st-badge google">Google</span> <br>
                <small>Fires: Purchase (Negative Value)</small>
            </div>
        </td>
    </tr>

    <!-- Contact Form 7 (C5: now registered) -->
    <tr>
        <th scope="row"><?php esc_html_e( 'Contact Form 7', 'ratuls-act' ); ?></th>
        <td>
            <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratuls_act_source_cf7_enabled" value="1"
                    <?php checked( 1, get_option( 'ratuls_act_source_cf7_enabled', 0 ) ); ?> />
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Enable Contact Form 7 tracking (Lead event on form submit)', 'ratuls-act' ); ?></span>
</label>
            <div style="margin-top: 10px;">
                <strong>Event Mapping:</strong><br>
                <span class="st-badge meta">Meta</span> <span class="st-badge tiktok">TikTok</span> <span class="st-badge google">Google</span> <br>
                <small>Fires: Lead</small>
            </div>
        </td>
    </tr>

    <!-- Easy Digital Downloads (C6: now registered) -->
    <tr>
        <th scope="row"><?php esc_html_e( 'Easy Digital Downloads', 'ratuls-act' ); ?></th>
        <td>
            <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratuls_act_source_edd_enabled" value="1"
                    <?php checked( 1, get_option( 'ratuls_act_source_edd_enabled', 0 ) ); ?> />
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Enable Easy Digital Downloads tracking (Purchase, Refund, new customer)', 'ratuls-act' ); ?></span>
</label>
        </td>
    </tr>

    <!-- WooCommerce Subscriptions (C7: UI row added for previously ghost option) -->
    <tr>
        <th scope="row"><?php esc_html_e( 'WooCommerce Subscriptions', 'ratuls-act' ); ?></th>
        <td>
            <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratuls_act_source_subscriptions_enabled" value="1"
                    <?php checked( 1, get_option( 'ratuls_act_source_subscriptions_enabled', 0 ) ); ?> />
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Enable WooCommerce Subscriptions tracking (renewal, cancellation, suspension events)', 'ratuls-act' ); ?></span>
</label>
            <p class="description"><?php esc_html_e( 'Requires WooCommerce Subscriptions plugin.', 'ratuls-act' ); ?></p>
            <div style="margin-top: 10px;">
                <strong>Event Mapping:</strong><br>
                <span class="st-badge meta">Meta</span> <span class="st-badge tiktok">TikTok</span> <span class="st-badge google">Google</span> <br>
                <small>Fires: Renewal, SubscriptionCancelled, SubscriptionPaused</small>
            </div>
        </td>
    </tr>

</table>

</div><!-- /.st-settings-section -->
