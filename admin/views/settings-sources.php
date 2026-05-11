<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<form method="post" action="options.php">
    <?php settings_fields( 'servertrack_sources_settings' ); ?>

    <h2><?php esc_html_e( 'Event Sources', 'servertrack' ); ?></h2>
    <p><?php esc_html_e( 'Enable or disable individual event source integrations.', 'servertrack' ); ?></p>

    <table class="form-table" role="presentation">

        <!-- WooCommerce -->
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
                    <?php esc_html_e( 'Requires WooCommerce. Sends an InitiateCheckout event to Meta and TikTok, and a begin_checkout event to Google, when a cart is abandoned.', 'servertrack' ); ?>
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
