<?php
/**
 * TikTok Events settings tab — view fragment only.
 * C1/C9: <form> and settings_fields() removed. render_page() owns the form.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="st-settings-section">
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e( 'Enable TikTok Events', 'ratul-ads-conversion-tracker' ); ?></th>
        <td>
            <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratul_act_tiktok_enabled" value="1"
                    <?php checked( 1, get_option( 'ratul_act_tiktok_enabled', 0 ) ); ?> />
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Send events to TikTok Events API', 'ratul-ads-conversion-tracker' ); ?></span>
</label>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ratul_act_tiktok_pixel_id"><?php esc_html_e( 'TikTok Pixel ID', 'ratul-ads-conversion-tracker' ); ?></label></th>
        <td>
            <input type="text"
                   id="ratul_act_tiktok_pixel_id"
                   name="ratul_act_tiktok_pixel_id"
                   value="<?php echo esc_attr( get_option( 'ratul_act_tiktok_pixel_id', '' ) ); ?>"
                   class="regular-text st-field-input"
                   placeholder="e.g. C1234ABCD5678"
                   autocomplete="off" />
            <p class="description"><?php esc_html_e( 'Found in TikTok Ads Manager → Assets → Events → Web Events.', 'ratul-ads-conversion-tracker' ); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ratul_act_tiktok_access_token"><?php esc_html_e( 'Access Token', 'ratul-ads-conversion-tracker' ); ?></label></th>
        <td>
            <input type="password"
                   id="ratul_act_tiktok_access_token"
                   name="ratul_act_tiktok_access_token"
                   value="<?php echo esc_attr( get_option( 'ratul_act_tiktok_access_token', '' ) ); ?>"
                   class="regular-text st-field-input"
                   autocomplete="new-password" />
            <p class="description"><?php esc_html_e( 'Generate from TikTok Events Manager → Manage → Generate Access Token.', 'ratul-ads-conversion-tracker' ); ?></p>
            <div style="margin-top: 12px; display: flex; align-items: center; gap: 10px;">
                <button type="button" class="button button-secondary st-connection-check-btn" data-platform="tiktok" data-pixel-input="#ratul_act_tiktok_pixel_id" data-token-input="#ratul_act_tiktok_access_token">
                    <?php esc_html_e( 'Verify API Connection', 'ratul-ads-conversion-tracker' ); ?>
                    <span class="st-spinner" style="display:none; width: 12px; height: 12px; margin-left: 5px; border-width: 1.5px;"></span>
                </button>
                <span class="st-badge connection-status-badge st-status-inactive" id="st-tiktok-connection-badge"><?php esc_html_e( 'Unverified', 'ratul-ads-conversion-tracker' ); ?></span>
            </div>
        </td>
    </tr>
</table>
</div><!-- /.st-settings-section -->
<hr />
<h2><?php esc_html_e( 'Send Test Event', 'ratul-ads-conversion-tracker' ); ?></h2>
<p><?php esc_html_e( 'Sends a dummy Purchase event to TikTok Events API to verify your credentials.', 'ratul-ads-conversion-tracker' ); ?></p>
<button type="button" class="button button-secondary ratul-ads-conversion-tracker-test-btn" data-platform="tiktok">
    <?php esc_html_e( 'Send Test Event → TikTok', 'ratul-ads-conversion-tracker' ); ?>
</button>
<div class="st-test-result ratul-ads-conversion-tracker-test-response" id="ratul-ads-conversion-tracker-test-response-tiktok"></div>


