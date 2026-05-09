<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<form method="post" action="options.php">
    <?php settings_fields( 'servertrack_settings' ); ?>
    <h2><?php esc_html_e( 'Event Sources', 'servertrack' ); ?></h2>
    <p><?php esc_html_e( 'Enable or disable each event source independently. A source must also have an active platform to fire events.', 'servertrack' ); ?></p>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php esc_html_e( 'WooCommerce', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_source_woo_enabled" value="1" <?php checked( 1, get_option( 'servertrack_source_woo_enabled', 1 ) ); ?> />
                    <?php esc_html_e( 'Track WooCommerce orders, cart, checkout, product views, and new registrations', 'servertrack' ); ?>
                </label>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e( 'Contact Form 7', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_source_cf7_enabled" value="1" <?php checked( 1, get_option( 'servertrack_source_cf7_enabled', 0 ) ); ?> />
                    <?php esc_html_e( 'Track CF7 form submissions as Lead events', 'servertrack' ); ?>
                </label>
                <?php if ( ! function_exists( 'wpcf7' ) ) : ?>
                    <p class="description" style="color:#d63638;"><?php esc_html_e( 'Contact Form 7 plugin is not active.', 'servertrack' ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e( 'Easy Digital Downloads', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_source_edd_enabled" value="1" <?php checked( 1, get_option( 'servertrack_source_edd_enabled', 0 ) ); ?> />
                    <?php esc_html_e( 'Track EDD purchases and registrations', 'servertrack' ); ?>
                </label>
                <?php if ( ! function_exists( 'EDD' ) ) : ?>
                    <p class="description" style="color:#d63638;"><?php esc_html_e( 'Easy Digital Downloads plugin is not active.', 'servertrack' ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <?php submit_button(); ?>
</form>

<?php
// ── CF7 Field Mapping UI ──────────────────────────────────────────────────────
// Only shown when CF7 is active. Lets the admin map each CF7 field tag name
// to the standard tracking fields (email, phone, name) per form.
if ( function_exists( 'wpcf7' ) ) :
    $servertrack_forms    = WPCF7_ContactForm::find();
    $servertrack_mappings = get_option( 'servertrack_cf7_mappings', [] );
    if ( ! is_array( $servertrack_mappings ) ) $servertrack_mappings = [];
?>

<hr />
<h2><?php esc_html_e( 'Contact Form 7 — Field Mappings', 'servertrack' ); ?></h2>
<p>
    <?php esc_html_e( 'Map each CF7 form\'s field tag names to the tracking fields below.', 'servertrack' ); ?>
    <?php esc_html_e( 'Use the exact tag name as written in your form shortcode (e.g. "your-email").', 'servertrack' ); ?>
</p>

<?php if ( empty( $servertrack_forms ) ) : ?>
    <p class="description"><?php esc_html_e( 'No Contact Form 7 forms found.', 'servertrack' ); ?></p>
<?php else : ?>
    <form method="post" action="options.php">
        <?php settings_fields( 'servertrack_settings' ); ?>
        <table class="widefat striped" style="max-width:900px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Form', 'servertrack' ); ?></th>
                    <th><?php esc_html_e( 'Email Field Tag', 'servertrack' ); ?></th>
                    <th><?php esc_html_e( 'Phone Field Tag', 'servertrack' ); ?></th>
                    <th><?php esc_html_e( 'Name Field Tag', 'servertrack' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $servertrack_forms as $servertrack_form ) :
                    $servertrack_form_id  = $servertrack_form->id();
                    $servertrack_form_map = $servertrack_mappings[ $servertrack_form_id ] ?? [];
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $servertrack_form->title() ); ?></strong>
                            <br /><span style="color:#888; font-size:11px;">ID: <?php echo esc_html( $servertrack_form_id ); ?></span>
                        </td>
                        <td>
                            <input type="text"
                                   name="servertrack_cf7_mappings[<?php echo esc_attr( $servertrack_form_id ); ?>][email]"
                                   value="<?php echo esc_attr( $servertrack_form_map['email'] ?? 'your-email' ); ?>"
                                   class="regular-text"
                                   placeholder="your-email" />
                        </td>
                        <td>
                            <input type="text"
                                   name="servertrack_cf7_mappings[<?php echo esc_attr( $servertrack_form_id ); ?>][phone]"
                                   value="<?php echo esc_attr( $servertrack_form_map['phone'] ?? 'your-phone' ); ?>"
                                   class="regular-text"
                                   placeholder="your-phone" />
                        </td>
                        <td>
                            <input type="text"
                                   name="servertrack_cf7_mappings[<?php echo esc_attr( $servertrack_form_id ); ?>][name]"
                                   value="<?php echo esc_attr( $servertrack_form_map['name'] ?? 'your-name' ); ?>"
                                   class="regular-text"
                                   placeholder="your-name" />
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description" style="margin-top:8px;">
            <?php esc_html_e( 'Leave fields as the default if your form uses standard CF7 tag names. Only change if your form uses custom names.', 'servertrack' ); ?>
        </p>
        <?php submit_button( __( 'Save CF7 Mappings', 'servertrack' ) ); ?>
    </form>
<?php endif; ?>
<?php endif; ?>
