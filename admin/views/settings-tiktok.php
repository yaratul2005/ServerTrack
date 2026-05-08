<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<form method="post" action="options.php">
    <?php settings_fields( 'servertrack_settings' ); ?>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php esc_html_e( 'Enable TikTok Events API', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_tiktok_enabled" value="1" <?php checked( 1, get_option( 'servertrack_tiktok_enabled', 0 ) ); ?> />
                    <?php esc_html_e( 'Send events to TikTok Events API', 'servertrack' ); ?>
                </label>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="servertrack_tiktok_pixel_id"><?php esc_html_e( 'TikTok Pixel ID', 'servertrack' ); ?></label></th>
            <td>
                <input type="text" id="servertrack_tiktok_pixel_id" name="servertrack_tiktok_pixel_id"
                       value="<?php echo esc_attr( get_option( 'servertrack_tiktok_pixel_id', '' ) ); ?>"
                       class="regular-text" />
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="servertrack_tiktok_access_token"><?php esc_html_e( 'Events API Access Token', 'servertrack' ); ?></label></th>
            <td>
                <input type="password" id="servertrack_tiktok_access_token" name="servertrack_tiktok_access_token"
                       value="<?php echo esc_attr( get_option( 'servertrack_tiktok_access_token', '' ) ); ?>"
                       class="regular-text" autocomplete="new-password" />
                <p class="description"><?php esc_html_e( 'Found in TikTok Events Manager → your Pixel → Settings → Events API.', 'servertrack' ); ?></p>
            </td>
        </tr>
    </table>
    <?php submit_button(); ?>
</form>
<hr />
<h2><?php esc_html_e( 'Send Test Event', 'servertrack' ); ?></h2>
<button type="button" class="button button-secondary servertrack-test-btn" data-platform="tiktok">
    <?php esc_html_e( 'Send Test Event → TikTok', 'servertrack' ); ?>
</button>
<div class="servertrack-test-response" id="servertrack-test-response-tiktok"></div>
