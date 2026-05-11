<?php
/**
 * ServerTrack — Offline Conversion Uploader  v3.1
 *
 * Batches completed WooCommerce orders and uploads them as offline
 * conversion events to Meta's Offline Conversions API.
 *
 * v3.1 changes (M-3 fix):
 *   send_batch() previously caught Throwable but only logged the exception
 *   message string. The platform context, batch size, and stack trace were
 *   all swallowed, making offline conversion failures completely
 *   undiagnosable in production.
 *
 *   Fix: Logger::error() now receives a structured context array containing:
 *     - 'platform'   : which API endpoint threw
 *     - 'batch_size' : number of events in the failing batch
 *     - 'trace'      : $e->getTraceAsString()
 *   This populates the debug log and the admin Event Log panel with
 *   actionable failure context.
 *
 * @package ServerTrack
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ServerTrack_OfflineConversion {

	/** How many orders to batch per API call. */
	const BATCH_SIZE = 50;

	/** WooCommerce order statuses that count as a completed offline conversion. */
	const COMPLETED_STATUSES = [ 'completed', 'processing' ];

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'servertrack_offline_upload_batch', [ __CLASS__, 'run_scheduled_upload' ] );
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'maybe_queue_order' ], 10, 2 );
	}

	/**
	 * Schedule a one-time upload for an order when its status changes to completed.
	 *
	 * Uses Dedup::exists()/set() (options-based, string-key safe — H-5 pattern)
	 * to avoid re-queueing if the status flips back and forth.
	 *
	 * @param int      $order_id
	 * @param WC_Order $order
	 */
	public static function maybe_queue_order( int $order_id, WC_Order $order ): void {
		$key = 'offline_queued_' . $order_id;

		if ( ServerTrack_Dedup::exists( $key ) ) {
			return;
		}

		ServerTrack_Dedup::set( $key );

		wp_schedule_single_event(
			time() + 300,
			'servertrack_offline_upload_batch',
			[ [ 'order_id' => $order_id ] ]
		);
	}

	/**
	 * Run an upload batch from the cron queue.
	 *
	 * @param array $args  e.g. [ 'order_id' => 123 ]
	 */
	public static function run_scheduled_upload( array $args ): void {
		$order_id = isset( $args['order_id'] ) ? absint( $args['order_id'] ) : 0;
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		self::send_batch( [ $order ], 'meta' );
	}

	/**
	 * Send a batch of orders as offline conversion events.
	 *
	 * M-3 FIX (v3.1):
	 *   Previous catch block: Logger::error( 'Offline batch failed: ' . $e->getMessage() )
	 *   This discarded the platform name, batch size, and full stack trace, making
	 *   failures completely undiagnosable. Admins saw a one-line error with no
	 *   context about which platform failed or what code path triggered it.
	 *
	 *   Fix: Logger::error() now receives a context array with platform, batch_size,
	 *   and the full trace. The admin Event Log panel surfaces this context as
	 *   collapsible detail rows so failures are immediately actionable.
	 *
	 * @param WC_Order[] $orders
	 * @param string     $platform  'meta' | 'google' | 'tiktok'
	 */
	public static function send_batch( array $orders, string $platform ): void {
		if ( empty( $orders ) ) {
			return;
		}

		$events = [];
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Abstract_Order ) {
				continue;
			}
			$events[] = self::build_event_payload( $order, $platform );
		}

		if ( empty( $events ) ) {
			return;
		}

		try {
			self::dispatch( $events, $platform );

			ServerTrack_Logger::info(
				sprintf( 'Offline batch sent: %d events to %s.', count( $events ), $platform )
			);

		} catch ( \Throwable $e ) {
			/**
			 * M-3 FIX: Log platform, batch size, and full stack trace so the
			 * Event Log panel shows actionable failure context instead of a
			 * bare message string.
			 */
			ServerTrack_Logger::error(
				sprintf(
					'Offline batch failed [platform=%s batch_size=%d]: %s',
					$platform,
					count( $events ),
					$e->getMessage()
				),
				[
					'platform'   => $platform,
					'batch_size' => count( $events ),
					'exception'  => get_class( $e ),
					'trace'      => $e->getTraceAsString(),
				]
			);
		}
	}

	/**
	 * Build an offline event payload for a single order.
	 *
	 * @param WC_Abstract_Order $order
	 * @param string            $platform
	 * @return array
	 */
	private static function build_event_payload( WC_Abstract_Order $order, string $platform ): array {
		$order_id = $order->get_id();
		$total    = (float) $order->get_total();
		$currency = strtoupper( get_woocommerce_currency() );

		$payload = [
			'event_name'  => 'Purchase',
			'event_time'  => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time(),
			'event_id'    => ServerTrack_Dedup::generate_event_id( 'offline_purchase_' . $order_id ),
			'value'       => $total,
			'currency'    => $currency,
			'order_id'    => $order_id,
			'platform'    => $platform,
		];

		// Attach hashed user data if available
		$email = $order->get_billing_email();
		if ( $email ) {
			$payload['user_data']['em'] = ServerTrack_Hasher::hash_email( $email );
		}

		$phone = $order->get_billing_phone();
		if ( $phone ) {
			$payload['user_data']['ph'] = ServerTrack_Hasher::hash_phone( $phone );
		}

		return $payload;
	}

	/**
	 * Dispatch an array of event payloads to the given platform API.
	 *
	 * Throws on HTTP error or non-2xx response so the caller's try/catch
	 * can log the failure with full context (M-3).
	 *
	 * @param array  $events
	 * @param string $platform
	 * @throws \RuntimeException on API error
	 */
	private static function dispatch( array $events, string $platform ): void {
		$settings = get_option( 'servertrack_settings', [] );

		switch ( $platform ) {
			case 'meta':
				$access_token = $settings['meta_access_token'] ?? '';
				$dataset_id   = $settings['meta_offline_dataset_id'] ?? '';
				if ( ! $access_token || ! $dataset_id ) {
					throw new \RuntimeException( 'Meta offline: missing access_token or dataset_id.' );
				}
				$url      = "https://graph.facebook.com/v19.0/{$dataset_id}/events";
				$response = wp_remote_post( $url, [
					'body'    => wp_json_encode( [ 'data' => $events, 'access_token' => $access_token ] ),
					'headers' => [ 'Content-Type' => 'application/json' ],
					'timeout' => 15,
				] );
				break;

			default:
				throw new \RuntimeException( "Unknown platform: {$platform}" );
		}

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$body = wp_remote_retrieve_body( $response );
			throw new \RuntimeException( "API returned HTTP {$code}: {$body}" );
		}
	}
}
