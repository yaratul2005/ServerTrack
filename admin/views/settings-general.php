<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<form method="post" action="options.php">
    <?php settings_fields( 'servertrack_settings' ); ?>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php esc_html_e( 'Enable Plugin', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_enabled" value="1" <?php checked( 1, get_option( 'servertrack_enabled', 1 ) ); ?> />
                    <?php esc_html_e( 'Activate server-side event sending', 'servertrack' ); ?>
                </label>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e( 'Test Mode', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_test_mode" value="1" <?php checked( 1, get_option( 'servertrack_test_mode', 0 ) ); ?> />
                    <?php esc_html_e( 'Send events to platform test/sandbox endpoints only', 'servertrack' ); ?>
                </label>
                <p class="description"><?php esc_html_e( 'Enable this during development. Disable before going live.', 'servertrack' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="servertrack_consent_mode"><?php esc_html_e( 'Consent Mode', 'servertrack' ); ?></label></th>
            <td>
                <select id="servertrack_consent_mode" name="servertrack_consent_mode">
                    <?php
                    $servertrack_current = get_option( 'servertrack_consent_mode', 'none' );
                    $servertrack_options = [
                        'none'       => __( 'None (send all events, no consent check)', 'servertrack' ),
                        'cookie_yes' => __( 'CookieYes (read cookieyes-consent cookie)', 'servertrack' ),
                        'complianz'  => __( 'Complianz (read cmplz_marketing cookie)', 'servertrack' ),
                        'manual'     => __( 'Manual (use servertrack_consent_granted filter)', 'servertrack' ),
                    ];
                    foreach ( $servertrack_options as $servertrack_val => $servertrack_label ) {
                        printf(
                            '<option value="%s" %s>%s</option>',
                            esc_attr( $servertrack_val ),
                            selected( $servertrack_current, $servertrack_val, false ),
                            esc_html( $servertrack_label )
                        );
                    }
                    ?>
                </select>
                <p class="description"><?php esc_html_e( 'Determines how the plugin checks for user consent before sending PII to ad platforms.', 'servertrack' ); ?></p>
            </td>
        </tr>
    </table>
    <?php submit_button(); ?>
</form>
