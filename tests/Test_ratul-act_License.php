<?php

use PHPUnit\Framework\TestCase;

class Test_Ratul_ACT_License extends TestCase {

    /**
     * Helper to Base64URL encode string.
     */
    private function base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Test offline license signature validation with valid signatures.
     */
    public function test_offline_signature_verification_success() {
        if ( ! extension_loaded( 'sodium' ) ) {
            $this->markTestSkipped( 'Sodium extension is not enabled.' );
        }

        // 1. Generate keys
        $keypair = sodium_crypto_sign_keypair();
        $pub = sodium_crypto_sign_publickey($keypair);
        $sec = sodium_crypto_sign_secretkey($keypair);

        // 2. Prepare payload
        $payload = [
            'license_id'  => 123,
            'software_id' => 1,
            'user_id'     => 45,
            'expires_at'  => date( 'Y-m-d H:i:s', time() + 3600 ) // expires in 1 hour
        ];

        $payload_json = json_encode( $payload );
        $signature = sodium_crypto_sign_detached( $payload_json, $sec );

        // 3. Construct license token key
        $token = $this->base64url_encode( $payload_json ) . '.' . $this->base64url_encode( $signature );

        // 4. Instantiate client with mock keys
        $client = new CRXSM_License_Client(
            'https://example.com/Tunnel/index.php',
            'sw_123',
            'sec_123',
            bin2hex($pub)
        );

        $verified_payload = $client->verify_signature_offline( $token );

        $this->assertNotFalse( $verified_payload );
        $this->assertEquals( 123, $verified_payload['license_id'] );
        $this->assertEquals( 1, $verified_payload['software_id'] );
        $this->assertEquals( 45, $verified_payload['user_id'] );
    }

    /**
     * Test offline signature validation with altered payloads (tampering).
     */
    public function test_offline_signature_verification_failure_on_tampering() {
        if ( ! extension_loaded( 'sodium' ) ) {
            $this->markTestSkipped( 'Sodium extension is not enabled.' );
        }

        $keypair = sodium_crypto_sign_keypair();
        $pub = sodium_crypto_sign_publickey($keypair);
        $sec = sodium_crypto_sign_secretkey($keypair);

        $payload = [ 'license_id' => 123 ];
        $payload_json = json_encode( $payload );
        $signature = sodium_crypto_sign_detached( $payload_json, $sec );

        // Tamper payload
        $tampered_payload = [ 'license_id' => 999 ];
        $tampered_json = json_encode( $tampered_payload );

        $token = $this->base64url_encode( $tampered_json ) . '.' . $this->base64url_encode( $signature );

        $client = new CRXSM_License_Client(
            'https://example.com/Tunnel/index.php',
            'sw_123',
            'sec_123',
            bin2hex($pub)
        );

        $result = $client->verify_signature_offline( $token );
        $this->assertFalse( $result );
    }

    /**
     * Test offline signature check with expired licenses.
     */
    public function test_offline_signature_verification_failure_on_expiration() {
        if ( ! extension_loaded( 'sodium' ) ) {
            $this->markTestSkipped( 'Sodium extension is not enabled.' );
        }

        $keypair = sodium_crypto_sign_keypair();
        $pub = sodium_crypto_sign_publickey($keypair);
        $sec = sodium_crypto_sign_secretkey($keypair);

        // Expired 1 hour ago
        $payload = [
            'license_id' => 123,
            'expires_at' => date( 'Y-m-d H:i:s', time() - 3600 )
        ];
        $payload_json = json_encode( $payload );
        $signature = sodium_crypto_sign_detached( $payload_json, $sec );

        $token = $this->base64url_encode( $payload_json ) . '.' . $this->base64url_encode( $signature );

        $client = new CRXSM_License_Client(
            'https://example.com/Tunnel/index.php',
            'sw_123',
            'sec_123',
            bin2hex($pub)
        );

        $result = $client->verify_signature_offline( $token );
        $this->assertFalse( $result, 'Expired licenses should be rejected.' );
    }
}
