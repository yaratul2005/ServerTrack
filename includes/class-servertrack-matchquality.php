<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_MatchQuality  v1.0
 *
 * Feature #3 — Real-Time Event Match Quality (EMQ) Scorer.
 *
 * Meta's Event Match Quality score is the single biggest lever for CAPI
 * performance. A score of 6+ means Meta can match the event to a real user
 * and attribute it. A score below 4 means the event is effectively invisible.
 *
 * No Stape.io container, no GTM tag, no other WordPress plugin computes or
 * surfaces this score before sending. ServerTrack does it server-side,
 * per-event, with a breakdown logged to the debug log.
 *
 * Scoring model (mirrors Meta's published EMQ weights):
 *   email          → +3.5 pts  (highest signal — always hash first)
 *   phone          → +2.5 pts
 *   fbc            → +2.0 pts  (from _fbc cookie or fbclid)
 *   fbp            → +1.5 pts  (from _fbp cookie)
 *   external_id    → +1.0 pts  (hashed WP user ID)
 *   first_name     → +0.5 pts
 *   last_name      → +0.5 pts
 *   city           → +0.3 pts
 *   state          → +0.3 pts
 *   zip            → +0.3 pts
 *   country        → +0.3 pts
 *   ip             → +0.5 pts  (raw, not hashed)
 *   user_agent     → +0.3 pts  (raw, not hashed)
 *
 * Max possible score: ~13.6 pts → normalised to 0–10 scale for the dashboard.
 *
 * Usage:
 *   $score = ServerTrack_MatchQuality::score( $event->user_data );
 *   // Returns: [ 'score' => 7.2, 'grade' => 'good', 'breakdown' => [...] ]
 *
 * The score is attached to the event as custom_data['_emq'] before logging,
 * so the debug log and dashboard can display it per-event.
 */
class ServerTrack_MatchQuality {

    /** Normalisation divisor — theoretical max sum of all weights. */
    const MAX_RAW = 13.6;

    /**
     * Weight table — mirrors Meta's published EMQ signal weights.
     * Keys match the user_data array keys used in ServerTrack_Event.
     */
    const WEIGHTS = [
        'email'       => 3.5,
        'phone'       => 2.5,
        'fbc'         => 2.0,
        'fbp'         => 1.5,
        'external_id' => 1.0,
        'first_name'  => 0.5,
        'last_name'   => 0.5,
        'ip'          => 0.5,
        'city'        => 0.3,
        'state'       => 0.3,
        'zip'         => 0.3,
        'country'     => 0.3,
        'user_agent'  => 0.3,
    ];

    /**
     * Score a user_data array and return a structured result.
     *
     * @param array $user_data  Keys from ServerTrack_Event::$user_data
     * @return array {
     *   score:     float  0.0–10.0 (normalised),
     *   grade:     string 'excellent'|'good'|'fair'|'poor',
     *   breakdown: array  [ field => weight ] for present fields,
     *   missing:   array  [ field => weight ] for absent high-value fields,
     * }
     */
    public static function score( array $user_data ): array {
        $raw       = 0.0;
        $breakdown = [];
        $missing   = [];

        foreach ( self::WEIGHTS as $field => $weight ) {
            if ( ! empty( $user_data[ $field ] ) ) {
                $raw              += $weight;
                $breakdown[$field] = $weight;
            } elseif ( $weight >= 1.0 ) {
                // Only flag missing fields that have significant impact
                $missing[$field] = $weight;
            }
        }

        // Normalise to 0–10
        $score = round( min( 10.0, ( $raw / self::MAX_RAW ) * 10 ), 1 );

        // Grade thresholds based on Meta's EMQ guidance
        if ( $score >= 7.5 ) {
            $grade = 'excellent';
        } elseif ( $score >= 5.5 ) {
            $grade = 'good';
        } elseif ( $score >= 3.5 ) {
            $grade = 'fair';
        } else {
            $grade = 'poor';
        }

        return [
            'score'     => $score,
            'grade'     => $grade,
            'breakdown' => $breakdown,
            'missing'   => $missing,
        ];
    }

    /**
     * Annotate an event with its EMQ score.
     * Attaches _emq to custom_data so the logger can record it.
     * Does NOT modify user_data or alter the payload sent to Meta.
     *
     * @param ServerTrack_Event $event
     * @return array The score result (also attached to event->custom_data['_emq'])
     */
    public static function annotate( ServerTrack_Event $event ): array {
        $result = self::score( $event->user_data );
        // Attach to custom_data for logging — stripped before API send
        $event->custom_data['_emq'] = $result;
        return $result;
    }

    /**
     * Get daily average EMQ scores from the debug log.
     * Used by the admin dashboard to display trend data.
     *
     * @param int $days  Number of past days to aggregate (default 7)
     * @return array [ 'date' => [ 'avg' => float, 'count' => int ], ... ]
     */
    public static function get_daily_averages( int $days = 7 ): array {
        $logs   = get_option( 'servertrack_debug_log', [] );
        $totals = [];
        $counts = [];
        $cutoff = strtotime( '-' . $days . ' days' );

        foreach ( $logs as $entry ) {
            if ( empty( $entry['timestamp'] ) ) continue;
            $ts = strtotime( $entry['timestamp'] );
            if ( $ts < $cutoff ) continue;
            $date = substr( $entry['timestamp'], 0, 10 );
            $emq  = $entry['emq_score'] ?? null;
            if ( null === $emq ) continue;
            $totals[$date] = ( $totals[$date] ?? 0 ) + (float) $emq;
            $counts[$date] = ( $counts[$date] ?? 0 ) + 1;
        }

        $result = [];
        foreach ( $totals as $date => $total ) {
            $result[$date] = [
                'avg'   => round( $total / $counts[$date], 1 ),
                'count' => $counts[$date],
            ];
        }
        ksort( $result );
        return $result;
    }
}
