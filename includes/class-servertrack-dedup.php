<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServerTrack_Dedup {

    public static function generate_event_id( string $context_string ): string {
        return md5( $context_string . '_' . SECURE_AUTH_KEY );
    }

    public static function get_event_id( int $order_id ): string {
        // Check if event_id already exists in order meta
        $event_id = get_post_meta( $order_id, '_servertrack_event_id', true );
        
        if ( empty( $event_id ) ) {
            $event_id = self::generate_event_id( 'purchase_' . $order_id );
            self::store_event_id( $order_id, $event_id );
        }
        
        return $event_id;
    }

    public static function store_event_id( int $order_id, string $event_id ) {
        update_post_meta( $order_id, '_servertrack_event_id', $event_id );
    }

    public static function mark_as_sent( int $order_id, string $platform ) {
        $sent_platforms = get_post_meta( $order_id, '_servertrack_server_sent', true );
        if ( ! is_array( $sent_platforms ) ) {
            $sent_platforms = [];
        }
        $sent_platforms[] = $platform;
        update_post_meta( $order_id, '_servertrack_server_sent', array_unique( $sent_platforms ) );
    }

    public static function was_sent( int $order_id, string $platform ): bool {
        $sent_platforms = get_post_meta( $order_id, '_servertrack_server_sent', true );
        if ( ! is_array( $sent_platforms ) ) {
            return false;
        }
        return in_array( $platform, $sent_platforms, true );
    }
}
