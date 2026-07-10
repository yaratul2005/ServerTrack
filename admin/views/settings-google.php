<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin view — Google Ads tab
 *
 * Day 6 additions:
 *   - Inline OAuth 2.0 flow UI: "Connect with Google" button, redirect URI helper,
 *     and a clear-token button to revoke / re-authenticate.
 *   - Token status indicator: shows connected account + expiry or "Not connected".
 *   - Client-side toggle: credential fields hidden when Google is disabled.
 *   - Inline step-by-step guide for Google Cloud Console setup.
 */
?>
    <div class="st-settings-section">
<table class="form-table" role="presentation">

        <!-- Enable / Disable -->
        <tr>
            <th scope="row"><?php esc_html_e( 'Enable Google Ads', 'ratul-ads-conversion-tracker' ); ?></th>
            <td>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratul_act_google_enabled" value="1"
                        id="st-google-enabled"
                        <?php checked( 1, get_option( 'ratul_act_google_enabled', 0 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Send server-side conversion events to Google Ads (Enhanced Conversions).', 'ratul-ads-conversion-tracker' ); ?></span>
</label>
            </td>
        </tr>

    </table>

    <div id="st-google-fields" style="<?php echo get_option( 'ratul_act_google_enabled', 0 ) ? '' : 'display:none'; ?>">

        <!-- ── OAuth Status Card ─────────────────────────────────────────── -->
        <?php
        $access_token  = get_option( 'ratul_act_google_access_token', '' );
        $token_expires = (int) get_option( 'ratul_act_google_token_expires', 0 );
        $refresh_token = get_option( 'ratul_act_google_refresh_token', '' );
        $is_connected  = ! empty( $refresh_token );
        ?>
        <div class="st-oauth-card <?php echo $is_connected ? 'st-oauth-card--connected' : 'st-oauth-card--disconnected'; ?>">
            <div class="st-oauth-card__icon">
                <?php if ( $is_connected ) : ?>
                    <span class="st-badge st-badge--success">&#10003; <?php esc_html_e( 'Connected', 'ratul-ads-conversion-tracker' ); ?></span>
                <?php else : ?>
                    <span class="st-badge st-badge--warning">&#9679; <?php esc_html_e( 'Not connected', 'ratul-ads-conversion-tracker' ); ?></span>
                <?php endif; ?>
            </div>
            <div class="st-oauth-card__body">
                <?php if ( $is_connected ) : ?>
                    <p><?php esc_html_e( 'Google account is authorised. Refresh token is stored securely.', 'ratul-ads-conversion-tracker' ); ?>
                    <?php if ( $token_expires > 0 ) : ?>
                        &nbsp;<small><?php printf(
                            /* translators: %s = human-readable time */
                            esc_html__( 'Access token expires: %s', 'ratul-ads-conversion-tracker' ),
                            esc_html( human_time_diff( time(), $token_expires ) . ' ' . __( 'from now', 'ratul-ads-conversion-tracker' ) )
                        ); ?></small>
                    <?php endif; ?>
                    </p>
                    <p>
                        <a href="<?php echo esc_url( admin_url( 'options-general.php?page=ratul-ads-conversion-tracker&tab=google&st_google_action=revoke&_wpnonce=' . wp_create_nonce( 'st_google_revoke' ) ) ); ?>"
                           class="button button-secondary st-btn-revoke"
                           onclick="return confirm('<?php esc_attr_e( 'This will remove your Google OAuth tokens. You will need to re-connect. Continue?', 'ratul-ads-conversion-tracker' ); ?>');">
                            <?php esc_html_e( 'Disconnect Google Account', 'ratul-ads-conversion-tracker' ); ?>
                        </a>
                    </p>
                <?php else : ?>
                    <p><?php esc_html_e( 'Connect your Google account to authorise Ratul_ACT to send Enhanced Conversions on your behalf.', 'ratul-ads-conversion-tracker' ); ?></p>
                    <?php
                    // Build the OAuth URL only when client_id + client_secret are saved
                    $client_id     = get_option( 'ratul_act_google_client_id', '' );
                    $client_secret = get_option( 'ratul_act_google_client_secret', '' );
                    $redirect_uri  = admin_url( 'options-general.php?page=ratul-ads-conversion-tracker&tab=google' );

                    if ( $client_id && $client_secret ) :
                        $oauth_url = add_query_arg( [
                            'client_id'             => rawurlencode( $client_id ),
                            'redirect_uri'          => rawurlencode( $redirect_uri ),
                            'response_type'         => 'code',
                            'scope'                 => rawurlencode( 'https://www.googleapis.com/auth/adwords' ),
                            'access_type'           => 'offline',
                            'prompt'                => 'consent',
                        ], 'https://accounts.google.com/o/oauth2/v2/auth' );
                        ?>
                        <a href="<?php echo esc_url( $oauth_url ); ?>" class="button button-primary st-btn-save">
                            <?php esc_html_e( '&#9654; Connect with Google', 'ratul-ads-conversion-tracker' ); ?>
                        </a>
                    <?php else : ?>
                        <p><em><?php esc_html_e( 'Save your Client ID and Client Secret below first, then return here to connect.', 'ratul-ads-conversion-tracker' ); ?></em></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── How to get credentials ────────────────────────────────────── -->
        <div class="st-help-box">
            <p><strong><?php esc_html_e( 'How to set up Google OAuth credentials:', 'ratul-ads-conversion-tracker' ); ?></strong></p>
            <ol>
                <li><?php printf(
                    /* translators: %s = URL */
                    wp_kses( __( 'Go to <a href="%s" target="_blank" rel="noopener">Google Cloud Console → APIs &amp; Services → Credentials</a>.', 'ratul-ads-conversion-tracker' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ),
                    'https://console.cloud.google.com/apis/credentials'
                ); ?></li>
                <li><?php esc_html_e( 'Create an OAuth 2.0 Client ID of type "Web application".', 'ratul-ads-conversion-tracker' ); ?></li>
                <li><?php esc_html_e( 'Under "Authorised redirect URIs", add the following URI exactly:', 'ratul-ads-conversion-tracker' ); ?>
                    <code class="st-copy-uri"><?php echo esc_url( admin_url( 'options-general.php?page=ratul-ads-conversion-tracker&tab=google' ) ); ?></code>
                    <button type="button" class="button-link st-copy-btn" data-target=".st-copy-uri"><?php esc_html_e( 'Copy', 'ratul-ads-conversion-tracker' ); ?></button>
                </li>
                <li><?php esc_html_e( 'Enable the Google Ads API in the Cloud Console.', 'ratul-ads-conversion-tracker' ); ?></li>
                <li><?php esc_html_e( 'Paste your Client ID and Client Secret into the fields below, save, then click "Connect with Google".', 'ratul-ads-conversion-tracker' ); ?></li>
            </ol>
        </div>

        <table class="form-table" role="presentation">

            <!-- Customer ID -->
            <tr>
                <th scope="row"><label for="st_google_customer_id"><?php esc_html_e( 'Google Ads Customer ID', 'ratul-ads-conversion-tracker' ); ?></label></th>
                <td>
                    <input type="text" id="st_google_customer_id"
                           name="ratul_act_google_customer_id"
                           value="<?php echo esc_attr( get_option( 'ratul_act_google_customer_id', '' ) ); ?>"
                           class="regular-text st-field-input" placeholder="123-456-7890">
                    <p class="description"><?php esc_html_e( 'Your 10-digit Google Ads account ID (without dashes). Found in Google Ads → top-right menu.', 'ratul-ads-conversion-tracker' ); ?></p>
                </td>
            </tr>

            <!-- Conversion ID -->
            <tr>
                <th scope="row"><label for="st_google_conversion_id"><?php esc_html_e( 'Conversion Action ID', 'ratul-ads-conversion-tracker' ); ?></label></th>
                <td>
                    <input type="text" id="st_google_conversion_id"
                           name="ratul_act_google_conversion_id"
                           value="<?php echo esc_attr( get_option( 'ratul_act_google_conversion_id', '' ) ); ?>"
                           class="regular-text st-field-input" placeholder="e.g. 12345678">
                    <p class="description"><?php esc_html_e( 'Conversion action numeric ID from Google Ads → Goals → Conversions.', 'ratul-ads-conversion-tracker' ); ?></p>
                </td>
            </tr>

            <!-- Conversion Label -->
            <tr>
                <th scope="row"><label for="st_google_conversion_label"><?php esc_html_e( 'Conversion Label', 'ratul-ads-conversion-tracker' ); ?></label></th>
                <td>
                    <input type="text" id="st_google_conversion_label"
                           name="ratul_act_google_conversion_label"
                           value="<?php echo esc_attr( get_option( 'ratul_act_google_conversion_label', '' ) ); ?>"
                           class="regular-text st-field-input" placeholder="e.g. Aw2xL_..._Q">
                    <p class="description"><?php esc_html_e( 'The conversion action label. Found alongside the ID in Google Ads.', 'ratul-ads-conversion-tracker' ); ?></p>
                </td>
            </tr>

            <!-- Consent Mode v2 -->
            <tr>
                <th scope="row"><label><?php esc_html_e( 'Consent Mode v2', 'ratul-ads-conversion-tracker' ); ?></label></th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text"><span><?php esc_html_e( 'Consent Mode v2 defaults', 'ratul-ads-conversion-tracker' ); ?></span></legend>
                        <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratul_act_google_consent_ad_user_data" value="1"
                                <?php checked( 1, get_option( 'ratul_act_google_consent_ad_user_data', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Grant ad_user_data by default', 'ratul-ads-conversion-tracker' ); ?></span>
</label><br>
                        <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="ratul_act_google_consent_ad_personalization" value="1"
                                <?php checked( 1, get_option( 'ratul_act_google_consent_ad_personalization', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Grant ad_personalization by default', 'ratul-ads-conversion-tracker' ); ?></span>
</label>
                        <p class="description"><?php esc_html_e( 'If the user declines consent via your CMP, these signals will dynamically be set to DENIED in the CAPI payload regardless of these defaults.', 'ratul-ads-conversion-tracker' ); ?></p>
                    </fieldset>
                </td>
            </tr>

            <!-- Developer Token -->
            <tr>
                <th scope="row"><label for="st_google_developer_token"><?php esc_html_e( 'Developer Token', 'ratul-ads-conversion-tracker' ); ?></label></th>
                <td>
                    <input type="password" id="st_google_developer_token"
                           name="ratul_act_google_developer_token"
                           value="<?php echo esc_attr( get_option( 'ratul_act_google_developer_token', '' ) ); ?>"
                           class="regular-text st-field-input" autocomplete="new-password">
                    <p class="description"><?php esc_html_e( 'From Google Ads → API Centre. Required for all Ads API calls.', 'ratul-ads-conversion-tracker' ); ?></p>
                </td>
            </tr>

            <!-- Client ID -->
            <tr>
                <th scope="row"><label for="st_google_client_id"><?php esc_html_e( 'OAuth Client ID', 'ratul-ads-conversion-tracker' ); ?></label></th>
                <td>
                    <input type="text" id="st_google_client_id"
                           name="ratul_act_google_client_id"
                           value="<?php echo esc_attr( get_option( 'ratul_act_google_client_id', '' ) ); ?>"
                           class="regular-text st-field-input" placeholder="xxxxxx.apps.googleusercontent.com">
                </td>
            </tr>

            <!-- Client Secret -->
            <tr>
                <th scope="row"><label for="st_google_client_secret"><?php esc_html_e( 'OAuth Client Secret', 'ratul-ads-conversion-tracker' ); ?></label></th>
                <td>
                    <input type="password" id="st_google_client_secret"
                           name="ratul_act_google_client_secret"
                           value="<?php echo esc_attr( get_option( 'ratul_act_google_client_secret', '' ) ); ?>"
                           class="regular-text st-field-input" autocomplete="new-password">
                </td>
            </tr>

            <!-- Refresh Token (read-only display) -->
            <?php if ( $refresh_token ) : ?>
            <tr>
                <th scope="row"><?php esc_html_e( 'Refresh Token', 'ratul-ads-conversion-tracker' ); ?></th>
                <td>
                    <input type="text" value="<?php echo esc_attr( substr( $refresh_token, 0, 8 ) . str_repeat( '•', 20 ) ); ?>"
                           class="regular-text st-field-input" disabled readonly>
                    <p class="description"><?php esc_html_e( 'Stored securely. Use Disconnect to revoke.', 'ratul-ads-conversion-tracker' ); ?></p>
                </td>
            </tr>
            <?php endif; ?>

        </table>
</div><!-- /.st-settings-section -->
    </div><!-- /#st-google-fields -->

<script>
(function(){
    var toggle = document.getElementById('st-google-enabled');
    var fields = document.getElementById('st-google-fields');
    if ( toggle && fields ) {
        toggle.addEventListener('change', function(){
            fields.style.display = this.checked ? '' : 'none';
        });
    }
    // Copy URI helper
    document.querySelectorAll('.st-copy-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            var target = document.querySelector(btn.dataset.target);
            if ( ! target ) return;
            var text = target.textContent || target.innerText;
            navigator.clipboard.writeText(text.trim()).then(function(){
                btn.textContent = '<?php esc_html_e( 'Copied!', 'ratul-ads-conversion-tracker' ); ?>';
                setTimeout(function(){ btn.textContent = '<?php esc_html_e( 'Copy', 'ratul-ads-conversion-tracker' ); ?>'; }, 2000);
            });
        });
    });
})();
</script>


