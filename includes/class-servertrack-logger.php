<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ServerTrack_Logger  v2.3
 *
 * v2.3 fixes:
 *
 *   FIX BUG-FIX-1 — Added clear_logs() as a public alias of clear().
 *     Both ServerTrack_Admin::ajax_clear_log() and
 *     ServerTrack_Dashboard::ajax_clear_log() called Logger::clear_logs()
 *     which did not exist, causing a PHP fatal error every time an admin
 *     attempted to clear the log. The canonical method remains clear();
 *     clear_logs() simply delegates to it.
 *
 *   FIX BUG-FIX-2 — log() now writes 'event_name' alongside 'event_type'.
 *     Dashboard::render_log_rows() reads $entry['event_name'] and
 *     compute_breakdown() also keys on 'event_name'. Logger only stored
 *     'event_type', so every Event column in the dashboard was blank and
 *     the Top Event Types chart always had no data.
 *     Fix: log() writes both 'event_type' (preserved for any existing
 *     consumers) and 'event_name' (the key the Dashboard expects).
 *
 * v2.2 changes (L-1, L-2 fixes):
 *
 *   L-1 — error(), info(), warning() helpers were missing.
 *     log() has always been gated on debug_mode=1, but M-3's fix in
 *     ServerTrack_OfflineConversion called Logger::error() — a method
 *     that did not exist — causing a silent PHP fatal on every offline
 *     batch failure. Added three severity-aware wrappers:
 *       error()   — always written (bypasses debug_mode gate).
 *       warning() — always written.
 *       info()    — written only when debug_mode=1 (same as log()).
 *     This ensures critical errors are never silently swallowed.
 *
 *   L-2 — get_recent() memory inefficiency.
 *     Previous: array_reverse($logs) allocates a full 1000-entry copy,
 *     then array_slice picks $limit items. For $limit=10 this wastes
 *     990 entries of RAM on every admin panel load.
 *     Fix: array_slice($logs, -$limit) first, then reverse only the
 *     small slice needed.
 *
 * v2.1 changes:
 *   - log() fires do_action('servertrack_event_logged') after writing.
 *
 * v2.0 changes:
 *   - log() accepts optional $emq array; max entries raised to 1000.
 *
 * @package ServerTrack
 */
class ServerTrack_Logger {

	const OPTION_KEY  = 'servertrack_debug_log';
	const MAX_ENTRIES = 1000;

	// ── Severity helpers ─────────────────────────────────────────

	/**
	 * Log an error-level entry.
	 *
	 * L-1 FIX (v2.2): This method was missing. ServerTrack_OfflineConversion
	 * (M-3 fix) calls Logger::error() after every batch failure. Without this
	 * method PHP threw a fatal error on the call site, swallowing the very
	 * failure it was trying to record.
	 *
	 * Unlike log(), error() bypasses the debug_mode gate so critical failures
	 * are always persisted regardless of the admin setting.
	 *
	 * @param string $message  Human-readable error description.
	 * @param array  $context  Optional structured context (platform, trace, etc.).
	 */
	public static function error( string $message, array $context = [] ): void {
		self::write_entry( 'error', 'system', $message, $context, true );
	}

	/**
	 * Log a warning-level entry (always written, debug_mode-independent).
	 *
	 * @param string $message
	 * @param array  $context
	 */
	public static function warning( string $message, array $context = [] ): void {
		self::write_entry( 'warning', 'system', $message, $context, true );
	}

	/**
	 * Log an info-level entry (only written when debug_mode=1).
	 *
	 * @param string $message
	 * @param array  $context
	 */
	public static function info( string $message, array $context = [] ): void {
		self::write_entry( 'info', 'system', $message, $context, false );
	}

	// ── Core log method ──────────────────────────────────────────

	/**
	 * Append a structured CAPI event log entry.
	 *
	 * Gated on debug_mode=1 (use error()/warning() for always-on logging).
	 *
	 * BUG-FIX-2 (v2.3): The entry now stores both 'event_type' AND 'event_name'
	 * with the same value. Dashboard::render_log_rows() and compute_breakdown()
	 * both read $entry['event_name']; previously only 'event_type' was stored,
	 * so the Event column was always blank and charts showed no event data.
	 *
	 * @param string $status      success|error|skipped|queued|dedup_blocked|webhook
	 * @param string $platform    meta|tiktok|google|all|identity|webhook
	 * @param string $message     Human-readable description
	 * @param string $response    Raw API response string (optional)
	 * @param string $event_id    UUID event ID (optional)
	 * @param int    $order_id    WC order ID (optional, 0 for non-order events)
	 * @param string $event_type  Purchase|ViewContent|AddToCart|... (optional)
	 * @param array  $emq         [ 'score' => float, 'grade' => string ] (optional)
	 */
	public static function log(
		string $status,
		string $platform,
		string $message,
		string $response   = '',
		string $event_id   = '',
		int    $order_id   = 0,
		string $event_type = '',
		array  $emq        = []
	): void {
		if ( ! get_option( 'servertrack_debug_mode', 0 ) ) {
			return;
		}

		$entry = [
			'timestamp'  => current_time( 'Y-m-d H:i:s' ),
			'status'     => $status,
			'platform'   => $platform,
			'message'    => $message,
			'event_id'   => $event_id,
			'order_id'   => $order_id,
			// BUG-FIX-2: store as both 'event_type' (legacy) and 'event_name'
			// (Dashboard::render_log_rows / compute_breakdown key).
			'event_type' => $event_type,
			'event_name' => $event_type,
		];

		if ( ! empty( $emq ) && isset( $emq['score'] ) ) {
			$entry['emq_score'] = $emq['score'];
			$entry['emq_grade'] = $emq['grade'] ?? '';
		}

		self::append_entry( $entry );

		/**
		 * Fires after a CAPI event is logged.
		 *
		 * @param string $platform
		 * @param string $event_type
		 * @param int    $order_id
		 * @param string $status
		 * @param array  $emq
		 */
		do_action( 'servertrack_event_logged', $platform, $event_type, $order_id, $status, $emq );
	}

	// ── Read / clear ──────────────────────────────────────────────

	/**
	 * Get recent log entries (most recent first).
	 *
	 * L-2 FIX (v2.2): Previous implementation reversed the entire log array
	 * (up to 1000 entries) and then sliced. For $limit=10 this allocated
	 * a 1000-entry copy just to discard 990 items.
	 *
	 * Fix: slice from the tail first (-$limit), then reverse only the
	 * small slice. Memory usage scales with $limit, not MAX_ENTRIES.
	 *
	 * @param int $limit  Max entries to return.
	 * @return array
	 */
	public static function get_recent( int $limit = 100 ): array {
		$logs = get_option( self::OPTION_KEY, [] );
		if ( empty( $logs ) ) {
			return [];
		}
		// L-2 FIX: slice tail first, reverse only the needed slice.
		return array_reverse( array_slice( $logs, -$limit ) );
	}

	/**
	 * Clear all log entries.
	 */
	public static function clear(): void {
		update_option( self::OPTION_KEY, [], false );
	}

	/**
	 * Alias of clear() — added in v2.3 (BUG-FIX-1).
	 *
	 * Both ServerTrack_Admin::ajax_clear_log() and
	 * ServerTrack_Dashboard::ajax_clear_log() call Logger::clear_logs().
	 * The method did not exist until v2.3, causing a PHP fatal every time
	 * an admin clicked "Clear log".
	 */
	public static function clear_logs(): void {
		self::clear();
	}

	// ── Internal helpers ───────────────────────────────────────

	/**
	 * Write a severity-level entry (used by error/warning/info helpers).
	 *
	 * @param string $status        'error'|'warning'|'info'
	 * @param string $platform      'system' for non-CAPI entries
	 * @param string $message
	 * @param array  $context       Arbitrary key-value context bag
	 * @param bool   $force_write   When true, bypasses debug_mode gate
	 */
	private static function write_entry(
		string $status,
		string $platform,
		string $message,
		array  $context,
		bool   $force_write
	): void {
		if ( ! $force_write && ! get_option( 'servertrack_debug_mode', 0 ) ) {
			return;
		}

		$entry = [
			'timestamp' => current_time( 'Y-m-d H:i:s' ),
			'status'    => $status,
			'platform'  => $platform,
			'message'   => $message,
		];

		if ( ! empty( $context ) ) {
			$entry['context'] = $context;
		}

		self::append_entry( $entry );
	}

	/**
	 * Append an entry to the log store with FIFO pruning.
	 *
	 * @param array $entry
	 */
	private static function append_entry( array $entry ): void {
		$logs   = get_option( self::OPTION_KEY, [] );
		$logs[] = $entry;

		if ( count( $logs ) > self::MAX_ENTRIES ) {
			$logs = array_slice( $logs, -self::MAX_ENTRIES );
		}

		update_option( self::OPTION_KEY, $logs, false );
	}
}
