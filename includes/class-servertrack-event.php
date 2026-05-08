<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack Event DTO (Data Transfer Object)
 *
 * This is the shared event model that ALL platform senders depend on.
 * Build an instance here, pass it to any sender — they cannot function without it.
 * Satisfies BUILD ORDER constraint #1: this file is a dependency of every sender.
 */
class ServerTrack_Event {

    public string $event_id;
    public string $event_name;
    public array  $user_data   = [];
    public array  $custom_data = [];

    public function __construct( string $event_name, string $event_id ) {
        $this->event_name = $event_name;
        $this->event_id   = $event_id;
    }

    public function set_user_data( array $data ): self {
        $this->user_data = $data;
        return $this;
    }

    public function set_custom_data( array $data ): self {
        $this->custom_data = $data;
        return $this;
    }
}
