<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ratul_ACT_Snapchat {
    public static function send( Ratul_ACT_Event $event ): array {
        if ( ! get_option( 'ratul_act_snapchat_enabled', 0 ) ) {
            return [ 'status' => 'skipped', 'http_code' => 0 ];
        }

        // Dummy sender
        return [ 'status' => 'success', 'http_code' => 200, 'response' => 'Dummy response' ];
    }
}

