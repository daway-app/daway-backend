<?php

namespace Tests\Feature\Api;

use App\Services\Auth\GoogleIdentityVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GoogleIdentityVerifierTest extends TestCase
{
    private string $privateKey;
    private array $jwk;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        $this->privateKey = $privateKey;
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $b64 = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $this->jwk = ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'test-key',
            'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])];
        Cache::forget('auth:google:oidc-jwks:v1');
        Http::fake(['https://www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [$this->jwk]])]);
        config(['services.google.client_ids' => 'web-client.example,other-client.example']);
    }

    private function token(array $overrides = []): string
    {
        $claims = array_merge(['iss' => 'https://accounts.google.com', 'aud' => 'web-client.example',
            'sub' => 'google-immutable-sub', 'iat' => time() - 1, 'exp' => time() + 300,
            'nonce' => 'server-challenge', 'email' => 'patient@example.test', 'email_verified' => true], $overrides);
        return JWT::encode($claims, $this->privateKey, 'RS256', 'test-key');
    }

    public function test_valid_signed_google_identity_is_verified(): void
    {
        $result = app(GoogleIdentityVerifier::class)->verify($this->token(), 'server-challenge');
        $this->assertSame('google-immutable-sub', $result['sub']);
        $this->assertTrue($result['email_verified']);
    }

    public function test_invalid_audience_issuer_nonce_and_expiry_are_rejected(): void
    {
        foreach ([['aud' => 'attacker'], ['iss' => 'https://attacker.example'],
            ['nonce' => 'other'], ['exp' => time() - 1], ['sub' => '']] as $claims) {
            try {
                app(GoogleIdentityVerifier::class)->verify($this->token($claims), 'server-challenge');
                $this->fail('Invalid identity accepted');
            } catch (RuntimeException $e) {
                $this->assertSame('Invalid or unavailable Google identity.', $e->getMessage());
            }
        }
    }

    public function test_tampered_signature_and_missing_client_configuration_are_rejected(): void
    {
        $token = $this->token();
        $parts = explode('.', $token);
        $parts[1] = rtrim(strtr(base64_encode('{"sub":"attacker"}'), '+/', '-_'), '=');
        try {
            app(GoogleIdentityVerifier::class)->verify(implode('.', $parts), 'server-challenge');
            $this->fail('Tampered identity accepted');
        } catch (RuntimeException $e) {
            $this->assertSame('Invalid or unavailable Google identity.', $e->getMessage());
        }
        config(['services.google.client_ids' => '']);
        $this->expectException(RuntimeException::class);
        app(GoogleIdentityVerifier::class)->verify($token, 'server-challenge');
    }
}
