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
    public static function hash_phone( string $phone, string $country_code = '1' ): string {
        // Strip all non-numeric characters. Master prompt instructs to strip all non-numeric.
        $normalized = preg_replace( '/[^0-9]/', '', $phone );
        
        if ( ! empty( $normalized ) && ! empty( $country_code ) && strpos( $normalized, $country_code ) !== 0 ) {
            $normalized = $country_code . $normalized;
        }
        
        return self::hash( $normalized );
    }

    /**
     * Specific hashing rule for emails.
     */
    public static function hash_email( string $email ): string {
        return self::hash( $email );
    }
}
