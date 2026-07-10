<?php
/**
 * General settings tab — view fragment only.
 * C1/C9: <form> and settings_fields() removed. render_page() owns the form.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="st-settings-section">
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e( 'Enable Plugin', 'ratuls-act' ); ?></th>
        <td>
            <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratuls_act_enabled" value="1"
                    <?php checked( 1, get_option( 'ratuls_act_enabled', 1 ) ); ?> />
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Activate server-side event sending', 'ratuls-act' ); ?></span>
</label>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e( 'Test Mode', 'ratuls-act' ); ?></th>
        <td>
            <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratuls_act_test_mode" value="1"
                    <?php checked( 1, get_option( 'ratuls_act_test_mode', 0 ) ); ?> />
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Send events to platform test/sandbox endpoints only', 'ratuls-act' ); ?></span>
</label>
            <p class="description"><?php esc_html_e( 'Enable this during development. Disable before going live.', 'ratuls-act' ); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ratuls_act_consent_mode"><?php esc_html_e( 'Consent Mode', 'ratuls-act' ); ?></label></th>
        <td>
            <select class="st-field-select" id="ratuls_act_consent_mode" name="ratuls_act_consent_mode">
                <?php
                $current = get_option( 'ratuls_act_consent_mode', 'none' );
                $options = [
                    'none'       => __( 'None (send all events, no consent check)', 'ratuls-act' ),
                    'cookie_yes' => __( 'CookieYes (read cookieyes-consent cookie)', 'ratuls-act' ),
                    'complianz'  => __( 'Complianz (read cmplz_marketing cookie)', 'ratuls-act' ),
                    'manual'     => __( 'Manual (use ratuls_act_consent_granted filter)', 'ratuls-act' ),
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
            <p class="description"><?php esc_html_e( 'Determines how the plugin checks for user consent before sending PII to ad platforms.', 'ratuls-act' ); ?></p>
        </td>
    </tr>
</table>
</div><!-- /.st-settings-section -->

