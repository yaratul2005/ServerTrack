<?php
/**
 * TikTok Events settings tab — view fragment only.
 * C1/C9: <form> and settings_fields() removed. render_page() owns the form.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="st-settings-section">
    <div class="st-settings-section-header">
        <div class="st-section-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12 C9 12 10 17 12 17 C14 17 15 15 15 12 L15 3 C15 3 16 8 21 8"/><path d="M9 15 C9 15 7 16 7 18.5 C7 21 9 22 12 22 C15 22 17 21 17 18.5"/></svg>
        </div>
        <div class="st-settings-section-title">
            <h3 style="margin:0;"><?php esc_html_e( 'TikTok Events API', 'ratul-ads-conversion-tracker' ); ?></h3>
            <p class="st-settings-section-desc"><?php esc_html_e( 'Configure server-side event tracking for TikTok Ads.', 'ratul-ads-conversion-tracker' ); ?></p>
        </div>
    </div>
    <div class="st-settings-section-body">
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
        </td>
    </tr>
</table>
    </div>
</div><!-- /.st-settings-section -->

<div class="st-settings-section" style="margin-top:0;">
    <div class="st-settings-section-header">
        <div class="st-section-icon" style="background:#111827;color:#fff;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        </div>
        <div class="st-settings-section-title">
            <h3 style="margin:0;"><?php esc_html_e( 'Send Test Event', 'ratul-ads-conversion-tracker' ); ?></h3>
            <p class="st-settings-section-desc"><?php esc_html_e( 'Sends a dummy Purchase event to verify your TikTok credentials.', 'ratul-ads-conversion-tracker' ); ?></p>
        </div>
    </div>
    <div class="st-settings-section-body">
        <button type="button" class="button button-secondary ratul-ads-conversion-tracker-test-btn" data-platform="tiktok">
            <?php esc_html_e( 'Send Test Event → TikTok', 'ratul-ads-conversion-tracker' ); ?>
        </button>
        <div class="st-test-result ratul-ads-conversion-tracker-test-response" id="ratul-ads-conversion-tracker-test-response-tiktok"></div>
    </div>
</div>
