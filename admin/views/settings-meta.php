<?php
/**
 * Meta CAPI settings tab — view fragment only.
 * C1/C9: <form> and settings_fields() removed. render_page() owns the form.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="st-settings-section">
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e( 'Enable Meta CAPI', 'ratuls-act' ); ?></th>
        <td>
            <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratuls_act_meta_enabled" value="1"
                    <?php checked( 1, get_option( 'ratuls_act_meta_enabled', 0 ) ); ?> />
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Send events to Meta Conversions API', 'ratuls-act' ); ?></span>
</label>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ratuls_act_meta_pixel_id"><?php esc_html_e( 'Meta Pixel ID', 'ratuls-act' ); ?></label></th>
        <td>
            <input type="text"
                   id="ratuls_act_meta_pixel_id"
                   name="ratuls_act_meta_pixel_id"
                   value="<?php echo esc_attr( get_option( 'ratuls_act_meta_pixel_id', '' ) ); ?>"
                   class="regular-text st-field-input"
                   placeholder="e.g. 123456789012345"
                   autocomplete="off" />
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ratuls_act_meta_access_token"><?php esc_html_e( 'System User Access Token', 'ratuls-act' ); ?></label></th>
        <td>
            <input type="password"
                   id="ratuls_act_meta_access_token"
                   name="ratuls_act_meta_access_token"
                   value="<?php echo esc_attr( get_option( 'ratuls_act_meta_access_token', '' ) ); ?>"
                   class="regular-text st-field-input"
                   autocomplete="new-password" />
            <p class="description"><?php esc_html_e( 'Generate from Meta Events Manager → Settings → System User Token.', 'ratuls-act' ); ?></p>
        </td>
    </tr>
    <!-- Advanced Matching -->
    <tr>
        <th scope="row"><label><?php esc_html_e( 'Advanced Matching PII', 'ratuls-act' ); ?></label></th>
        <td>
            <fieldset>
                <legend class="screen-reader-text"><span><?php esc_html_e( 'Advanced Matching Signals', 'ratuls-act' ); ?></span></legend>
                <p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'Select which customer signals to hash and send to Meta to improve match quality.', 'ratuls-act' ); ?></p>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratuls_act_meta_am_email" value="1"
                        <?php checked( 1, get_option( 'ratuls_act_meta_am_email', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Email', 'ratuls-act' ); ?></span>
</label><br>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratuls_act_meta_am_phone" value="1"
                        <?php checked( 1, get_option( 'ratuls_act_meta_am_phone', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Phone Number', 'ratuls-act' ); ?></span>
</label><br>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratuls_act_meta_am_name" value="1"
                        <?php checked( 1, get_option( 'ratuls_act_meta_am_name', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'First & Last Name', 'ratuls-act' ); ?></span>
</label><br>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratuls_act_meta_am_city" value="1"
                        <?php checked( 1, get_option( 'ratuls_act_meta_am_city', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'City', 'ratuls-act' ); ?></span>
</label><br>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratuls_act_meta_am_state" value="1"
                        <?php checked( 1, get_option( 'ratuls_act_meta_am_state', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'State / Province', 'ratuls-act' ); ?></span>
</label><br>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratuls_act_meta_am_zip" value="1"
                        <?php checked( 1, get_option( 'ratuls_act_meta_am_zip', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'ZIP / Postal Code', 'ratuls-act' ); ?></span>
</label><br>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratuls_act_meta_am_country" value="1"
                        <?php checked( 1, get_option( 'ratuls_act_meta_am_country', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Country', 'ratuls-act' ); ?></span>
</label>
            </fieldset>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ratuls_act_meta_test_event_code"><?php esc_html_e( 'Test Event Code', 'ratuls-act' ); ?></label></th>
        <td>
            <input type="text"
                   id="ratuls_act_meta_test_event_code"
                   name="ratuls_act_meta_test_event_code"
                   value="<?php echo esc_attr( get_option( 'ratuls_act_meta_test_event_code', '' ) ); ?>"
                   class="regular-text st-field-input"
                   placeholder="TEST12345 (optional)"
                   autocomplete="off" />
            <p class="description"><?php esc_html_e( 'Only required when using Meta Test Events tool. Leave blank in production.', 'ratuls-act' ); ?></p>
        </td>
    </tr>
</table>
</div><!-- /.st-settings-section -->
<hr />
<h2><?php esc_html_e( 'Send Test Event', 'ratuls-act' ); ?></h2>
<p><?php esc_html_e( 'Sends a dummy Purchase event to Meta CAPI to verify your credentials.', 'ratuls-act' ); ?></p>
<button type="button" class="button button-secondary ratuls-act-test-btn" data-platform="meta">
    <?php esc_html_e( 'Send Test Event → Meta', 'ratuls-act' ); ?>
</button>
<div class="st-test-result ratuls-act-test-response" id="ratuls-act-test-response-meta"></div>

