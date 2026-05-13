<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$webhook_enabled = (int) get_option( 'servertrack_webhook_enabled', 0 );
$webhook_url     = esc_attr( get_option( 'servertrack_webhook_url', '' ) );
$webhook_secret  = esc_attr( get_option( 'servertrack_webhook_secret', '' ) );
$webhook_events  = esc_attr( get_option( 'servertrack_webhook_events', '' ) );
?>
<div class="servertrack-settings-section">
    <h2><?php esc_html_e( 'Webhook Settings', 'servertrack' ); ?></h2>
    <p class="description">
        <?php esc_html_e( 'Send a signed HTTP POST payload to a custom endpoint whenever ServerTrack fires an event.', 'servertrack' ); ?>
    </p>

    <form method="post" action="options.php">
        <?php settings_fields( 'servertrack_webhook' ); ?>

        <table class="form-table" role="presentation">

            <tr>
                <th scope="row">
                    <label for="servertrack_webhook_enabled">
                        <?php esc_html_e( 'Enable Webhook', 'servertrack' ); ?>
                    </label>
                </th>
                <td>
                    <input
                        type="checkbox"
                        id="servertrack_webhook_enabled"
                        name="servertrack_webhook_enabled"
                        value="1"
                        <?php checked( 1, $webhook_enabled ); ?>
                    />
                    <p class="description">
                        <?php esc_html_e( 'Toggle outbound webhook delivery on or off.', 'servertrack' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="servertrack_webhook_url">
                        <?php esc_html_e( 'Endpoint URL', 'servertrack' ); ?>
                    </label>
                </th>
                <td>
                    <input
                        type="url"
                        id="servertrack_webhook_url"
                        name="servertrack_webhook_url"
                        value="<?php echo $webhook_url; ?>"
                        class="regular-text"
                        placeholder="https://example.com/webhook"
                    />
                    <p class="description">
                        <?php esc_html_e( 'The URL that will receive the POST payload for each tracked event.', 'servertrack' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="servertrack_webhook_secret">
                        <?php esc_html_e( 'Secret Key', 'servertrack' ); ?>
                    </label>
                </th>
                <td>
                    <input
                        type="password"
                        id="servertrack_webhook_secret"
                        name="servertrack_webhook_secret"
                        value="<?php echo $webhook_secret; ?>"
                        class="regular-text"
                        autocomplete="new-password"
                    />
                    <p class="description">
                        <?php esc_html_e( 'Used to generate the HMAC-SHA256 signature in the X-ServerTrack-Signature header. Leave blank to send unsigned payloads.', 'servertrack' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="servertrack_webhook_events">
                        <?php esc_html_e( 'Events Filter', 'servertrack' ); ?>
                    </label>
                </th>
                <td>
                    <input
                        type="text"
                        id="servertrack_webhook_events"
                        name="servertrack_webhook_events"
                        value="<?php echo $webhook_events; ?>"
                        class="regular-text"
                        placeholder="Purchase,ViewContent,Lead"
                    />
                    <p class="description">
                        <?php esc_html_e( 'Comma-separated list of event names to forward. Leave blank to forward all events.', 'servertrack' ); ?>
                    </p>
                </td>
            </tr>

        </table>

        <?php submit_button( __( 'Save Webhook Settings', 'servertrack' ) ); ?>
    </form>
</div>
