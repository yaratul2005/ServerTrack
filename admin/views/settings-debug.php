<?php if ( ! defined( 'ABSPATH' ) ) exit;
$servertrack_logs = get_option( 'servertrack_debug_log', [] );
?>
<div id="servertrack-debug-panel">
    <div class="servertrack-debug-toolbar">
        <h2 class="servertrack-debug-heading"><?php esc_html_e( 'Debug Log', 'servertrack' ); ?></h2>
        <div>
            <button type="button" class="button button-secondary" id="servertrack-refresh-log">
                &#x21bb; <?php esc_html_e( 'Refresh', 'servertrack' ); ?>
            </button>
            <button type="button" class="button button-secondary servertrack-btn-danger" id="servertrack-clear-log">
                <?php esc_html_e( 'Clear Log', 'servertrack' ); ?>
            </button>
        </div>
    </div>

    <div class="servertrack-test-events-section">
        <h3 class="servertrack-debug-subheading"><?php esc_html_e( 'Send Test Event', 'servertrack' ); ?></h3>
        <p><?php esc_html_e( 'Test connectivity to platform APIs using your saved credentials.', 'servertrack' ); ?></p>
        <div class="servertrack-test-buttons-container">
            <button type="button" class="button button-primary servertrack-test-btn" data-platform="meta">Send Test Event → Meta</button>
            <button type="button" class="button button-primary servertrack-test-btn" data-platform="google">Send Test Event → Google</button>
            <button type="button" class="button button-primary servertrack-test-btn" data-platform="tiktok">Send Test Event → TikTok</button>
        </div>
        <pre class="servertrack-test-response" id="servertrack-test-response-meta"></pre>
        <pre class="servertrack-test-response" id="servertrack-test-response-google"></pre>
        <pre class="servertrack-test-response" id="servertrack-test-response-tiktok"></pre>
    </div>

    <table class="widefat striped servertrack-log-table" id="servertrack-log-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Time', 'servertrack' ); ?></th>
                <th><?php esc_html_e( 'Platform', 'servertrack' ); ?></th>
                <th><?php esc_html_e( 'Event', 'servertrack' ); ?></th>
                <th><?php esc_html_e( 'Event ID', 'servertrack' ); ?></th>
                <th><?php esc_html_e( 'Order ID', 'servertrack' ); ?></th>
                <th><?php esc_html_e( 'Status', 'servertrack' ); ?></th>
                <th><?php esc_html_e( 'HTTP', 'servertrack' ); ?></th>
                <th><?php esc_html_e( 'Message', 'servertrack' ); ?></th>
                <th><?php esc_html_e( 'Response', 'servertrack' ); ?></th>
            </tr>
        </thead>
        <tbody id="servertrack-log-body">
            <?php if ( empty( $servertrack_logs ) ) : ?>
                <tr><td colspan="5"><?php esc_html_e( 'No log entries yet.', 'servertrack' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $servertrack_logs as $servertrack_entry ) : ?>
                    <tr class="servertrack-log-row servertrack-status-<?php echo esc_attr( $servertrack_entry['status'] ?? '' ); ?>">
                        <td><?php echo esc_html( $servertrack_entry['timestamp'] ?? '' ); ?></td>
                        <td><strong><?php echo esc_html( strtoupper( $servertrack_entry['platform'] ?? '' ) ); ?></strong></td>
                        <td><?php echo esc_html( $servertrack_entry['event_name'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $servertrack_entry['event_id'] ?? '' ); ?></td>
                        <td><?php echo esc_html( empty( $servertrack_entry['order_id'] ) ? '' : $servertrack_entry['order_id'] ); ?></td>
                        <td><?php echo esc_html( $servertrack_entry['status'] ?? '' ); ?></td>
                        <td><?php echo esc_html( empty( $servertrack_entry['http_code'] ) ? '' : $servertrack_entry['http_code'] ); ?></td>
                        <td><?php echo esc_html( $servertrack_entry['message'] ?? '' ); ?></td>
                        <td class="servertrack-response-cell"><?php echo esc_html( $servertrack_entry['response'] ?? '' ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
