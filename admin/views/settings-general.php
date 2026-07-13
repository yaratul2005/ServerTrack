<?php
/**
 * General settings tab — view fragment only.
 * C1/C9: <form> and settings_fields() removed. render_page() owns the form.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="st-settings-section">
    <div class="st-settings-section-header">
        <div class="st-section-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>
        </div>
        <div class="st-settings-section-title">
            <h3 style="margin:0;"><?php esc_html_e( 'General Settings', 'ratul-ads-conversion-tracker' ); ?></h3>
            <p class="st-settings-section-desc"><?php esc_html_e( 'Core plugin behavior, consent mode and test mode configuration.', 'ratul-ads-conversion-tracker' ); ?></p>
        </div>
    </div>
    <div class="st-settings-section-body">
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e( 'Enable Plugin', 'ratul-ads-conversion-tracker' ); ?></th>
        <td>
            <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratul_act_enabled" value="1"
                    <?php checked( 1, get_option( 'ratul_act_enabled', 1 ) ); ?> />
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Activate server-side event sending', 'ratul-ads-conversion-tracker' ); ?></span>
</label>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e( 'Test Mode', 'ratul-ads-conversion-tracker' ); ?></th>
        <td>
            <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratul_act_test_mode" value="1"
                    <?php checked( 1, get_option( 'ratul_act_test_mode', 0 ) ); ?> />
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Send events to platform test/sandbox endpoints only', 'ratul-ads-conversion-tracker' ); ?></span>
</label>
            <p class="description"><?php esc_html_e( 'Enable this during development. Disable before going live.', 'ratul-ads-conversion-tracker' ); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ratul_act_consent_mode"><?php esc_html_e( 'Consent Mode', 'ratul-ads-conversion-tracker' ); ?></label></th>
        <td>
            <select class="st-field-select" id="ratul_act_consent_mode" name="ratul_act_consent_mode">
                <?php
                $current = get_option( 'ratul_act_consent_mode', 'none' );
                $options = [
                    'none'       => __( 'None (send all events, no consent check)', 'ratul-ads-conversion-tracker' ),
                    'cookie_yes' => __( 'CookieYes (read cookieyes-consent cookie)', 'ratul-ads-conversion-tracker' ),
                    'complianz'  => __( 'Complianz (read cmplz_marketing cookie)', 'ratul-ads-conversion-tracker' ),
                    'manual'     => __( 'Manual (use ratul_act_consent_granted filter)', 'ratul-ads-conversion-tracker' ),
                ];
                foreach ( $options as $val => $label ) :
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr( $val ),
                        selected( $current, $val, false ),
                        esc_html( $label )
                    );
                endforeach;
                ?>
            </select>
            <p class="description"><?php esc_html_e( 'Determines how the plugin checks for user consent before sending PII to ad platforms.', 'ratul-ads-conversion-tracker' ); ?></p>
        </td>
    </tr>
</table>
    </div>
</div><!-- /.st-settings-section -->




