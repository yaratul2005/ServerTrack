<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ratul_ACT_Admin_Attribution
 *
 * Handles the calculation and rendering of the Multi-Touch Attribution Engine.
 */
class Ratul_ACT_Admin_Attribution {

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ratul-ads-conversion-tracker' ) );
        }

        $logs = [];
        if ( class_exists( 'Ratul_ACT_Logger' ) ) {
            $logs = Ratul_ACT_Logger::get_recent( 1000 );
        }

        // 1. Group purchase events by Order ID to prevent multi-platform duplicates
        $purchases = [];
        foreach ( $logs as $log ) {
            $name = $log['event_name'] ?? $log['event_type'] ?? '';
            if ( ( $name === 'Purchase' || $name === 'purchase' ) && ! empty( $log['order_id'] ) ) {
                $order_id = (int) $log['order_id'];
                if ( ! isset( $purchases[ $order_id ] ) ) {
                    $purchases[ $order_id ] = $log;
                }
            }
        }

        // Tally variables
        $total_purchases = count( $purchases );
        $attributed_count = 0;
        $total_value = 0.0;
        $attributed_value = 0.0;
        $currency = get_woocommerce_currency();

        $first_touch = [];
        $last_touch  = [];
        $linear      = [];

        foreach ( $purchases as $order_id => $log ) {
            $order_val = 0.0;
            if ( function_exists( 'wc_get_order' ) ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    $order_val = (float) $order->get_total();
                    $currency  = $order->get_currency();
                }
            }
            $total_value += $order_val;

            $has_attribution = ! empty( $log['first_utm_source'] ) || ! empty( $log['last_utm_source'] );
            if ( $has_attribution ) {
                $attributed_count++;
                $attributed_value += $order_val;

                $first_src = ! empty( $log['first_utm_source'] ) ? $log['first_utm_source'] : 'direct / none';
                $last_src  = ! empty( $log['last_utm_source'] ) ? $log['last_utm_source'] : 'direct / none';

                // Tally First-Touch
                if ( ! isset( $first_touch[ $first_src ] ) ) {
                    $first_touch[ $first_src ] = [ 'count' => 0.0, 'value' => 0.0 ];
                }
                $first_touch[ $first_src ]['count'] += 1.0;
                $first_touch[ $first_src ]['value'] += $order_val;

                // Tally Last-Touch
                if ( ! isset( $last_touch[ $last_src ] ) ) {
                    $last_touch[ $last_src ] = [ 'count' => 0.0, 'value' => 0.0 ];
                }
                $last_touch[ $last_src ]['count'] += 1.0;
                $last_touch[ $last_src ]['value'] += $order_val;

                // Tally Linear
                $path = [];
                if ( ! empty( $log['utm_path'] ) ) {
                    $path = json_decode( $log['utm_path'], true ) ?: [];
                }
                if ( empty( $path ) ) {
                    $path = [ [ 'utm_source' => $first_src ] ];
                }

                $sources = array_unique( array_filter( array_map( function( $t ) {
                    return ! empty( $t['utm_source'] ) ? $t['utm_source'] : '';
                }, $path ) ) );

                if ( empty( $sources ) ) {
                    $sources = [ 'direct / none' ];
                }

                $divisor = count( $sources );
                foreach ( $sources as $src ) {
                    if ( ! isset( $linear[ $src ] ) ) {
                        $linear[ $src ] = [ 'count' => 0.0, 'value' => 0.0 ];
                    }
                    $linear[ $src ]['count'] += ( 1.0 / $divisor );
                    $linear[ $src ]['value'] += ( $order_val / $divisor );
                }
            } else {
                // Direct traffic
                foreach ( [ 'first_touch' => &$first_touch, 'last_touch' => &$last_touch, 'linear' => &$linear ] as $model => &$arr ) {
                    if ( ! isset( $arr['direct / none'] ) ) {
                        $arr['direct / none'] = [ 'count' => 0.0, 'value' => 0.0 ];
                    }
                    $arr['direct / none']['count'] += 1.0;
                    $arr['direct / none']['value'] += $order_val;
                }
            }
        }

        // Sort arrays by value descending
        uasort( $first_touch, function( $a, $b ) { return $b['value'] <=> $a['value']; } );
        uasort( $last_touch,  function( $a, $b ) { return $b['value'] <=> $a['value']; } );
        uasort( $linear,      function( $a, $b ) { return $b['value'] <=> $a['value']; } );

        $coverage = $total_purchases > 0 ? round( ( $attributed_count / $total_purchases ) * 100, 1 ) : 0;
        $currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $currency ) : $currency;
        ?>
        <div class="wrap" id="ratul-ads-conversion-tracker-wrap">
            <header class="st-header" style="margin-bottom: 24px;">
                <div class="st-header-main">
                    <div>
                        <span class="st-badge on">Active</span>
                        <h1 class="st-title" style="margin: 4px 0 0;"><?php esc_html_e( 'First-Party Attribution Engine', 'ratul-ads-conversion-tracker' ); ?></h1>
                        <p class="st-subtitle" style="margin: 2px 0 0; color: var(--st-text-muted);"><?php esc_html_e( 'Compare marketing touchpoints using first-party UTM conversion metrics.', 'ratul-ads-conversion-tracker' ); ?></p>
                    </div>
                </div>
            </header>

            <!-- Metrics Cards -->
            <div class="st-kpi-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px;">
                <div class="st-panel" style="padding: 20px; background: #fff; border: 1px solid var(--st-border); border-radius: var(--st-radius);">
                    <div style="font-size: 11px; color: var(--st-text-muted); text-transform: uppercase; font-weight: 600;"><?php esc_html_e( 'Total Purchases Tracked', 'ratul-ads-conversion-tracker' ); ?></div>
                    <div style="font-size: 24px; font-weight: 800; color: var(--st-text); margin-top: 4px;"><?php echo esc_html( $total_purchases ); ?></div>
                </div>
                <div class="st-panel" style="padding: 20px; background: #fff; border: 1px solid var(--st-border); border-radius: var(--st-radius);">
                    <div style="font-size: 11px; color: var(--st-text-muted); text-transform: uppercase; font-weight: 600;"><?php esc_html_e( 'Attributed Purchases', 'ratul-ads-conversion-tracker' ); ?></div>
                    <div style="font-size: 24px; font-weight: 800; color: var(--st-text); margin-top: 4px;"><?php echo esc_html( $attributed_count ); ?></div>
                </div>
                <div class="st-panel" style="padding: 20px; background: #fff; border: 1px solid var(--st-border); border-radius: var(--st-radius);">
                    <div style="font-size: 11px; color: var(--st-text-muted); text-transform: uppercase; font-weight: 600;"><?php esc_html_e( 'Attribution Coverage', 'ratul-ads-conversion-tracker' ); ?></div>
                    <div style="font-size: 24px; font-weight: 800; color: var(--st-brand); margin-top: 4px;"><?php echo esc_html( $coverage ); ?>%</div>
                </div>
                <div class="st-panel" style="padding: 20px; background: #fff; border: 1px solid var(--st-border); border-radius: var(--st-radius);">
                    <div style="font-size: 11px; color: var(--st-text-muted); text-transform: uppercase; font-weight: 600;"><?php esc_html_e( 'Total Attributed Value', 'ratul-ads-conversion-tracker' ); ?></div>
                    <div style="font-size: 24px; font-weight: 800; color: var(--st-text); margin-top: 4px;"><?php echo esc_html( $currency_symbol . number_format( $attributed_value, 2 ) ); ?></div>
                </div>
            </div>

            <!-- Comparison Models -->
            <div class="st-row" style="display: flex; gap: 16px; flex-wrap: wrap;">
                <?php
                $models = [
                    'first' => [ 'title' => __( 'First-Touch Model', 'ratul-ads-conversion-tracker' ), 'desc' => __( 'Credits 100% of purchase to first touchpoint.', 'ratul-ads-conversion-tracker' ), 'data' => $first_touch ],
                    'last'  => [ 'title' => __( 'Last-Touch Model', 'ratul-ads-conversion-tracker' ), 'desc' => __( 'Credits 100% of purchase to final touchpoint.', 'ratul-ads-conversion-tracker' ), 'data' => $last_touch ],
                    'linear' => [ 'title' => __( 'Linear Path Model', 'ratul-ads-conversion-tracker' ), 'desc' => __( 'Distributes credits equally across all touchpoints.', 'ratul-ads-conversion-tracker' ), 'data' => $linear ],
                ];

                foreach ( $models as $key => $m ) :
                ?>
                <div class="st-panel" style="flex: 1; min-width: 300px; background: #fff; border: 1px solid var(--st-border); border-radius: var(--st-radius); padding: 20px;">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: var(--st-text);"><?php echo esc_html( $m['title'] ); ?></h3>
                    <p style="margin: 4px 0 16px; font-size: 12px; color: var(--st-text-muted);"><?php echo esc_html( $m['desc'] ); ?></p>

                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <?php if ( empty( $m['data'] ) ) : ?>
                            <p style="font-size: 13px; color: var(--st-text-muted);"><?php esc_html_e( 'No conversion data available.', 'ratul-ads-conversion-tracker' ); ?></p>
                        <?php else :
                            $max_val = 1.0;
                            foreach ( $m['data'] as $d ) {
                                if ( $d['value'] > $max_val ) {
                                    $max_val = $d['value'];
                                }
                            }

                            foreach ( $m['data'] as $source => $stat ) :
                                $percent = round( ( $stat['value'] / $max_val ) * 100 );
                        ?>
                            <div>
                                <div style="display: flex; justify-content: space-between; font-size: 12px; font-weight: 600; color: var(--st-text); margin-bottom: 4px;">
                                    <span style="font-family: monospace;"><?php echo esc_html( $source ); ?></span>
                                    <span><?php echo esc_html( round( $stat['count'], 1 ) . ' ord' ); ?> / <?php echo esc_html( $currency_symbol . number_format( $stat['value'], 2 ) ); ?></span>
                                </div>
                                <div style="height: 6px; background: var(--st-divider); border-radius: 3px; overflow: hidden;">
                                    <div style="height: 100%; width: <?php echo esc_attr( $percent ); ?>%; background: var(--st-brand); border-radius: 3px;"></div>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}
