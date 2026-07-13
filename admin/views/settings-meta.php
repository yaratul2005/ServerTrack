<?php
/**
 * Meta CAPI settings tab — view fragment only.
 * C1/C9: <form> and settings_fields() removed. render_page() owns the form.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="st-settings-section">
    <div class="st-settings-section-header">
        <div class="st-section-icon" style="background:#eff6ff;color:#1877f2;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
        </div>
        <div class="st-settings-section-title">
            <h3 style="margin:0;"><?php esc_html_e( 'Meta Conversions API', 'ratul-ads-conversion-tracker' ); ?></h3>
            <p class="st-settings-section-desc"><?php esc_html_e( 'Configure server-side event tracking for Meta (Facebook/Instagram) Ads.', 'ratul-ads-conversion-tracker' ); ?></p>
        </div>
    </div>
    <div class="st-settings-section-body">
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e( 'Enable Meta CAPI', 'ratul-ads-conversion-tracker' ); ?></th>
        <td>
            <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratul_act_meta_enabled" value="1"
                    <?php checked( 1, get_option( 'ratul_act_meta_enabled', 0 ) ); ?> />
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Send events to Meta Conversions API', 'ratul-ads-conversion-tracker' ); ?></span>
</label>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ratul_act_meta_pixel_id"><?php esc_html_e( 'Meta Pixel ID', 'ratul-ads-conversion-tracker' ); ?></label></th>
        <td>
            <input type="text"
                   id="ratul_act_meta_pixel_id"
                   name="ratul_act_meta_pixel_id"
                   value="<?php echo esc_attr( get_option( 'ratul_act_meta_pixel_id', '' ) ); ?>"
                   class="regular-text st-field-input"
                   placeholder="e.g. 123456789012345"
                   autocomplete="off" />
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ratul_act_meta_access_token"><?php esc_html_e( 'System User Access Token', 'ratul-ads-conversion-tracker' ); ?></label></th>
        <td>
            <input type="password"
                   id="ratul_act_meta_access_token"
                   name="ratul_act_meta_access_token"
                   value="<?php echo esc_attr( get_option( 'ratul_act_meta_access_token', '' ) ); ?>"
                   class="regular-text st-field-input"
                   autocomplete="new-password" />
            <p class="description"><?php esc_html_e( 'Generate from Meta Events Manager → Settings → System User Token.', 'ratul-ads-conversion-tracker' ); ?></p>
        </td>
    </tr>
    <!-- Advanced Matching -->
    <tr>
        <th scope="row"><label><?php esc_html_e( 'Advanced Matching PII', 'ratul-ads-conversion-tracker' ); ?></label></th>
        <td>
            <fieldset>
                <legend class="screen-reader-text"><span><?php esc_html_e( 'Advanced Matching Signals', 'ratul-ads-conversion-tracker' ); ?></span></legend>
                <p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'Select which customer signals to hash and send to Meta to improve match quality.', 'ratul-ads-conversion-tracker' ); ?></p>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratul_act_meta_am_email" value="1"
                        <?php checked( 1, get_option( 'ratul_act_meta_am_email', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Email', 'ratul-ads-conversion-tracker' ); ?></span>
</label><br>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratul_act_meta_am_phone" value="1"
                        <?php checked( 1, get_option( 'ratul_act_meta_am_phone', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Phone Number', 'ratul-ads-conversion-tracker' ); ?></span>
</label><br>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratul_act_meta_am_name" value="1"
                        <?php checked( 1, get_option( 'ratul_act_meta_am_name', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'First & Last Name', 'ratul-ads-conversion-tracker' ); ?></span>
</label><br>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratul_act_meta_am_city" value="1"
                        <?php checked( 1, get_option( 'ratul_act_meta_am_city', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'City', 'ratul-ads-conversion-tracker' ); ?></span>
</label><br>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratul_act_meta_am_state" value="1"
                        <?php checked( 1, get_option( 'ratul_act_meta_am_state', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'State / Province', 'ratul-ads-conversion-tracker' ); ?></span>
</label><br>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratul_act_meta_am_zip" value="1"
                        <?php checked( 1, get_option( 'ratul_act_meta_am_zip', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'ZIP / Postal Code', 'ratul-ads-conversion-tracker' ); ?></span>
</label><br>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratul_act_meta_am_country" value="1"
                        <?php checked( 1, get_option( 'ratul_act_meta_am_country', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Country', 'ratul-ads-conversion-tracker' ); ?></span>
</label>
            </fieldset>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ratul_act_meta_test_event_code"><?php esc_html_e( 'Test Event Code', 'ratul-ads-conversion-tracker' ); ?></label></th>
        <td>
            <input type="text"
                   id="ratul_act_meta_test_event_code"
                   name="ratul_act_meta_test_event_code"
                   value="<?php echo esc_attr( get_option( 'ratul_act_meta_test_event_code', '' ) ); ?>"
                   class="regular-text st-field-input"
                   placeholder="TEST12345 (optional)"
                   autocomplete="off" />
            <p class="description"><?php esc_html_e( 'Only required when using Meta Test Events tool. Leave blank in production.', 'ratul-ads-conversion-tracker' ); ?></p>
        </td>
    </tr>
</table>
    </div>
</div><!-- /.st-settings-section -->

<div class="st-settings-section">
    <div class="st-settings-section-header">
        <div class="st-section-icon" style="background:#eff6ff;color:#1877f2;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        </div>
        <div class="st-settings-section-title">
            <h3 style="margin:0;"><?php esc_html_e( 'Send Test Event', 'ratul-ads-conversion-tracker' ); ?></h3>
            <p class="st-settings-section-desc"><?php esc_html_e( 'Sends a dummy Purchase event to Meta CAPI to verify your credentials.', 'ratul-ads-conversion-tracker' ); ?></p>
        </div>
    </div>
    <div class="st-settings-section-body">
        <button type="button" class="button button-secondary ratul-ads-conversion-tracker-test-btn" data-platform="meta">
            <?php esc_html_e( 'Send Test Event → Meta', 'ratul-ads-conversion-tracker' ); ?>
        </button>
        <div class="st-test-result ratul-ads-conversion-tracker-test-response" id="ratul-ads-conversion-tracker-test-response-meta"></div>
    </div>
</div>


