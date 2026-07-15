<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ratul_ACT_Admin_Offline
 *
 * Handles parsing, normalising, and server-side uploading of offline CSV conversion events.
 */
class Ratul_ACT_Admin_Offline {

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ratul-ads-conversion-tracker' ) );
        }

        $notice = '';
        $notice_type = 'success';

        // Handle File Upload Form Submission
        if ( isset( $_POST['ratul_act_offline_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['ratul_act_offline_nonce'] ), 'ratul_act_upload_offline' ) ) {
            if ( ! empty( $_FILES['st_csv_file']['tmp_name'] ) ) {
                $file = sanitize_text_field( wp_unslash( $_FILES['st_csv_file']['tmp_name'] ) );
                $results = self::process_csv( $file );

                if ( is_wp_error( $results ) ) {
                    $notice = $results->get_error_message();
                    $notice_type = 'error';
                } else {
                    $notice = sprintf(
                        __( 'CSV upload processed successfully. Parsed: %d, Dispatched: %d, Failed: %d.', 'ratul-ads-conversion-tracker' ),
                        $results['parsed'],
                        $results['success'],
                        $results['failed']
                    );
                }
            } else {
                $notice = __( 'Please choose a valid CSV file to upload.', 'ratul-ads-conversion-tracker' );
                $notice_type = 'error';
            }
        }

        ?>
        <div class="wrap" id="ratul-ads-conversion-tracker-wrap">
            <header class="st-header" style="margin-bottom: 24px;">
                <div class="st-header-main">
                    <div>
                        <span class="st-badge on"><?php esc_html_e( 'CAPI Bridge', 'ratul-ads-conversion-tracker' ); ?></span>
                        <h1 class="st-title" style="margin: 4px 0 0;"><?php esc_html_e( 'Offline Conversions Uploader', 'ratul-ads-conversion-tracker' ); ?></h1>
                        <p class="st-subtitle" style="margin: 2px 0 0; color: var(--st-text-muted);"><?php esc_html_e( 'Upload offline sales/leads via CSV files to retroactively optimize conversion matches.', 'ratul-ads-conversion-tracker' ); ?></p>
                    </div>
                </div>
            </header>

            <?php if ( $notice ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible" style="margin: 0 0 20px; padding: 12px; border-radius: var(--st-radius-sm); border-left-width: 4px;">
                    <p style="margin: 0; font-weight: 600; font-size: 13px;"><?php echo esc_html( $notice ); ?></p>
                </div>
            <?php endif; ?>

            <div class="st-row" style="display: flex; gap: 16px; flex-wrap: wrap;">
                <!-- Upload Panel -->
                <div class="st-panel" style="flex: 1; min-width: 300px; background: #fff; border: 1px solid var(--st-border); border-radius: var(--st-radius); padding: 24px;">
                    <h3 style="margin: 0 0 8px; font-size: 16px; font-weight: 700; color: var(--st-text);"><?php esc_html_e( 'Upload CSV File', 'ratul-ads-conversion-tracker' ); ?></h3>
                    <p style="margin: 0 0 20px; font-size: 12px; color: var(--st-text-muted);"><?php esc_html_e( 'Select a CSV file containing offline transaction records. Events are sent securely in the background.', 'ratul-ads-conversion-tracker' ); ?></p>

                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field( 'ratul_act_upload_offline', 'ratul_act_offline_nonce' ); ?>
                        <div style="margin-bottom: 20px;">
                            <input type="file" name="st_csv_file" accept=".csv" required style="padding: 10px; border: 1px dashed var(--st-border); border-radius: var(--st-radius-sm); width: 100%; box-sizing: border-box; background: var(--st-bg);" />
                        </div>
                        <button type="submit" class="st-btn" style="background: var(--st-brand); color: #fff; border: none; padding: 10px 18px; border-radius: var(--st-radius-sm); font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            <?php esc_html_e( 'Start Upload Process', 'ratul-ads-conversion-tracker' ); ?>
                        </button>
                    </form>
                </div>

                <!-- CSV Specifications Panel -->
                <div class="st-panel" style="flex: 1; min-width: 300px; background: #fff; border: 1px solid var(--st-border); border-radius: var(--st-radius); padding: 24px;">
                    <h3 style="margin: 0 0 8px; font-size: 16px; font-weight: 700; color: var(--st-text);"><?php esc_html_e( 'CSV Template Guidelines', 'ratul-ads-conversion-tracker' ); ?></h3>
                    <p style="margin: 0 0 16px; font-size: 12px; color: var(--st-text-muted);"><?php esc_html_e( 'Ensure your CSV headers match the case-sensitive column layout below:', 'ratul-ads-conversion-tracker' ); ?></p>

                    <table class="wp-list-table widefat fixed striped" style="font-size: 12px; margin-bottom: 0;">
                        <thead>
                            <tr>
                                <th><strong>Column Header</strong></th>
                                <th><strong>Required</strong></th>
                                <th><strong>Description</strong></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>email</code></td>
                                <td>Yes</td>
                                <td>Plain email address (e.g. <code>customer@domain.com</code>)</td>
                            </tr>
                            <tr>
                                <td><code>phone</code></td>
                                <td>No</td>
                                <td>Digits with dial code (e.g. <code>12125551234</code>)</td>
                            </tr>
                            <tr>
                                <td><code>event_name</code></td>
                                <td>No</td>
                                <td>Defaults to <code>Purchase</code> (can be <code>Lead</code>, etc.)</td>
                            </tr>
                            <tr>
                                <td><code>value</code></td>
                                <td>No</td>
                                <td>Decimal numeric conversion value (e.g. <code>49.99</code>)</td>
                            </tr>
                            <tr>
                                <td><code>currency</code></td>
                                <td>No</td>
                                <td>Currency code (defaults to WC Store Currency)</td>
                            </tr>
                            <tr>
                                <td><code>timestamp</code></td>
                                <td>No</td>
                                <td>Unix timestamp or YYYY-MM-DD format</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    private static function process_csv( string $file_path ): array|WP_Error {
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            return new WP_Error( 'file_error', __( 'Unable to open the uploaded file.', 'ratul-ads-conversion-tracker' ) );
        }

        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            return new WP_Error( 'file_error', __( 'Error opening file stream.', 'ratul-ads-conversion-tracker' ) );
        }

        // Parse CSV headers
        $headers = fgetcsv( $handle );
        if ( ! $headers ) {
            fclose( $handle );
            return new WP_Error( 'empty_csv', __( 'The CSV file appears to be empty.', 'ratul-ads-conversion-tracker' ) );
        }

        $headers = array_map( 'trim', $headers );
        $email_idx = array_search( 'email', $headers );

        if ( $email_idx === false ) {
            fclose( $handle );
            return new WP_Error( 'missing_email', __( 'Required header "email" is missing from the CSV file.', 'ratul-ads-conversion-tracker' ) );
        }

        $phone_idx      = array_search( 'phone', $headers );
        $name_idx       = array_search( 'event_name', $headers );
        $value_idx      = array_search( 'value', $headers );
        $currency_idx   = array_search( 'currency', $headers );
        $timestamp_idx  = array_search( 'timestamp', $headers );

        $parsed  = 0;
        $success = 0;
        $failed  = 0;

        $store_currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            if ( empty( $row[ $email_idx ] ) ) {
                continue;
            }

            $parsed++;
            $email      = sanitize_text_field( trim( $row[ $email_idx ] ) );
            $phone      = $phone_idx !== false && ! empty( $row[ $phone_idx ] ) ? sanitize_text_field( trim( $row[ $phone_idx ] ) ) : '';
            $event_name = $name_idx !== false && ! empty( $row[ $name_idx ] ) ? sanitize_text_field( trim( $row[ $name_idx ] ) ) : 'Purchase';
            $value      = $value_idx !== false && ! empty( $row[ $value_idx ] ) ? (float) $row[ $value_idx ] : 0.0;
            $currency   = $currency_idx !== false && ! empty( $row[ $currency_idx ] ) ? sanitize_text_field( trim( $row[ $currency_idx ] ) ) : $store_currency;
            $timestamp  = $timestamp_idx !== false && ! empty( $row[ $timestamp_idx ] ) ? trim( $row[ $timestamp_idx ] ) : '';

            // Resolve timestamp to UNIX time
            if ( $timestamp ) {
                $ts = is_numeric( $timestamp ) ? (int) $timestamp : strtotime( $timestamp );
                if ( ! $ts ) {
                    $ts = time();
                }
            } else {
                $ts = time();
            }

            // Create Event object and set user/custom details
            $event_id = Ratul_ACT_Dedup::generate_event_id( $email . '_' . $ts . '_' . $event_name );

            $user_data = [];
            if ( class_exists( 'Ratul_ACT_Hasher' ) ) {
                $user_data['email'] = Ratul_ACT_Hasher::hash_email( $email );
                if ( $phone ) {
                    $user_data['phone'] = Ratul_ACT_Hasher::hash_phone( $phone );
                }
            }

            // Fallbacks for offline events
            $user_data['ip']         = '127.0.0.1'; // Loopback or local fallback for offline CAPI
            $user_data['user_agent'] = 'Offline CSV Uploader Client';

            $custom_data = [
                'value'             => $value,
                'currency'          => $currency,
                'event_source_url'  => home_url(),
                'offline_upload'    => true,
            ];

            $event = new Ratul_ACT_Event( $event_name, $event_id );
            $event->set_user_data( $user_data );
            $event->set_custom_data( $custom_data );

            $sent_to_any = false;
            $error_msg = '';

            // Send to Meta
            if ( get_option( 'ratul_act_meta_enabled', 0 ) && class_exists( 'Ratul_ACT_Meta' ) ) {
                $res = Ratul_ACT_Meta::send( $event );
                if ( ( $res['status'] ?? '' ) === 'success' ) {
                    $sent_to_any = true;
                } else {
                    $error_msg .= 'Meta error: ' . ( $res['message'] ?? 'Unknown' ) . ' ';
                }
            }

            // Send to TikTok
            if ( get_option( 'ratul_act_tiktok_enabled', 0 ) && class_exists( 'Ratul_ACT_TikTok' ) ) {
                $res = Ratul_ACT_TikTok::send( $event );
                if ( ( $res['status'] ?? '' ) === 'success' ) {
                    $sent_to_any = true;
                } else {
                    $error_msg .= 'TikTok error: ' . ( $res['message'] ?? 'Unknown' ) . ' ';
                }
            }

            // Send to Google
            if ( get_option( 'ratul_act_google_enabled', 0 ) && class_exists( 'Ratul_ACT_Google' ) ) {
                $res = Ratul_ACT_Google::send( $event );
                if ( ( $res['status'] ?? '' ) === 'success' ) {
                    $sent_to_any = true;
                } else {
                    $error_msg .= 'Google error: ' . ( $res['message'] ?? 'Unknown' ) . ' ';
                }
            }

            if ( $sent_to_any ) {
                $success++;
                if ( class_exists( 'Ratul_ACT_Logger' ) ) {
                    Ratul_ACT_Logger::log( 'success', 'all', 'Offline CAPI: ' . $event_name . ' sent successfully', '', $event_id, 0, $event_name );
                }
            } else {
                $failed++;
                if ( class_exists( 'Ratul_ACT_Logger' ) ) {
                    Ratul_ACT_Logger::log( 'error', 'all', 'Offline CAPI failed. ' . trim( $error_msg ), '', $event_id, 0, $event_name );
                }
            }
        }

        fclose( $handle );

        return [
            'parsed'  => $parsed,
            'success' => $success,
            'failed'  => $failed,
        ];
    }
}
