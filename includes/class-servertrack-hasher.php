<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServerTrack_Hasher {

    /**
     * General SHA-256 hash for generic strings.
     */
    public static function hash( string $value ): string {
        $normalized = strtolower( trim( $value ) );
        return hash( 'sha256', $normalized );
    }

    /**
     * Specific hashing rule for phone numbers.
     */
    public static function hash_phone( string $phone ): string {
        // Strip all non-numeric characters. Master prompt instructs to strip all non-numeric.
        $normalized = preg_replace( '/[^0-9]/', '', $phone );
        return self::hash( $normalized );
    }

    /**
     * Specific hashing rule for emails.
     */
    public static function hash_email( string $email ): string {
        return self::hash( $email );
    }
}
