<?php if ( ! defined( 'ABSPATH' ) ) exit;
$logs = get_option( 'servertrack_debug_log', [] );
?>
<div id="servertrack-debug-panel">
    <div class="servertrack-debug-toolbar">
        <h2 style="margin:0;"><?php esc_html_e( 'Debug Log', 'servertrack' ); ?></h2>
        <div>
            <button type="button" class="button button-secondary" id="servertrack-refresh-log">
                &#x21bb; <?php esc_html_e( 'Refresh', 'servertrack' ); ?>
            </button>
            <button type="button" class="button button-secondary" id="servertrack-clear-log" style="margin-left:6px; color:#d63638;">
                <?php esc_html_e( 'Clear Log', 'servertrack' ); ?>
            </button>
        </div>
    </div>

    <table class="widefat striped" id="servertrack-log-table" style="margin-top:12px; font-family: monospace; font-size: 12px;">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Time', 'servertrack' ); ?></th>
                <th><?php esc_html_e( 'Platform', 'servertrack' ); ?></th>
                <th><?php esc_html_e( 'Status', 'servertrack' ); ?></th>
                <th><?php esc_html_e( 'Message / Code', 'servertrack' ); ?></th>
                <th><?php esc_html_e( 'Response', 'servertrack' ); ?></th>
            </tr>
        </thead>
        <tbody id="servertrack-log-body">
            <?php if ( empty( $logs ) ) : ?>
                <tr><td colspan="5"><?php esc_html_e( 'No log entries yet.', 'servertrack' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $logs as $entry ) : ?>
                    <tr class="servertrack-log-row servertrack-status-<?php echo esc_attr( $entry['status'] ?? '' ); ?>">
                        <td><?php echo esc_html( $entry['timestamp'] ?? '' ); ?></td>
                        <td><strong><?php echo esc_html( strtoupper( $entry['platform'] ?? '' ) ); ?></strong></td>
                        <td><?php echo esc_html( $entry['status'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $entry['message'] ?? '' ); ?></td>
                        <td style="max-width:300px; word-break:break-all;"><?php echo esc_html( $entry['response'] ?? '' ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
