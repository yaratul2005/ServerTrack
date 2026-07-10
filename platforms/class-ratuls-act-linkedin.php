<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ratuls_ACT_LinkedIn {
    public static function send( Ratuls_ACT_Event $event ): array {
        if ( ! get_option( 'ratuls_act_linkedin_enabled', 0 ) ) {
            return [ 'status' => 'skipped', 'http_code' => 0 ];
        }

        // Dummy sender
        return [ 'status' => 'success', 'http_code' => 200, 'response' => 'Dummy response' ];
    }
}

