<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<form method="post" action="options.php">
    <?php settings_fields( 'servertrack_settings' ); ?>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php esc_html_e( 'Enable Google Ads', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_google_enabled" value="1" <?php checked( 1, get_option( 'servertrack_google_enabled', 0 ) ); ?> />
                    <?php esc_html_e( 'Send Enhanced Conversions to Google Ads', 'servertrack' ); ?>
                </label>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="servertrack_google_customer_id"><?php esc_html_e( 'Google Ads Customer ID', 'servertrack' ); ?></label></th>
            <td>
                <input type="text" id="servertrack_google_customer_id" name="servertrack_google_customer_id"
                       value="<?php echo esc_attr( get_option( 'servertrack_google_customer_id', '' ) ); ?>"
                       class="regular-text" placeholder="1234567890 (no dashes)" />
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="servertrack_google_conversion_id"><?php esc_html_e( 'Conversion Action ID', 'servertrack' ); ?></label></th>
            <td>
                <input type="text" id="servertrack_google_conversion_id" name="servertrack_google_conversion_id"
                       value="<?php echo esc_attr( get_option( 'servertrack_google_conversion_id', '' ) ); ?>"
                       class="regular-text" />
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="servertrack_google_developer_token"><?php esc_html_e( 'Developer Token', 'servertrack' ); ?></label></th>
            <td>
                <input type="password" id="servertrack_google_developer_token" name="servertrack_google_developer_token"
                       value="<?php echo esc_attr( get_option( 'servertrack_google_developer_token', '' ) ); ?>"
                       class="regular-text" autocomplete="new-password" />
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="servertrack_google_client_id"><?php esc_html_e( 'OAuth Client ID', 'servertrack' ); ?></label></th>
            <td>
                <input type="text" id="servertrack_google_client_id" name="servertrack_google_client_id"
                       value="<?php echo esc_attr( get_option( 'servertrack_google_client_id', '' ) ); ?>"
                       class="regular-text" />
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="servertrack_google_client_secret"><?php esc_html_e( 'OAuth Client Secret', 'servertrack' ); ?></label></th>
            <td>
                <input type="password" id="servertrack_google_client_secret" name="servertrack_google_client_secret"
                       value="<?php echo esc_attr( get_option( 'servertrack_google_client_secret', '' ) ); ?>"
                       class="regular-text" autocomplete="new-password" />
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="servertrack_google_refresh_token"><?php esc_html_e( 'OAuth Refresh Token', 'servertrack' ); ?></label></th>
            <td>
                <input type="password" id="servertrack_google_refresh_token" name="servertrack_google_refresh_token"
                       value="<?php echo esc_attr( get_option( 'servertrack_google_refresh_token', '' ) ); ?>"
                       class="regular-text" autocomplete="new-password" />
                <p class="description"><?php esc_html_e( 'Obtain an offline refresh token by completing the Google OAuth consent flow once.', 'servertrack' ); ?></p>
            </td>
        </tr>
    </table>
    <?php submit_button(); ?>
</form>
<hr />
<h2><?php esc_html_e( 'Send Test Event', 'servertrack' ); ?></h2>
<button type="button" class="button button-secondary servertrack-test-btn" data-platform="google">
    <?php esc_html_e( 'Send Test Event → Google', 'servertrack' ); ?>
</button>
<div class="servertrack-test-response" id="servertrack-test-response-google"></div>
