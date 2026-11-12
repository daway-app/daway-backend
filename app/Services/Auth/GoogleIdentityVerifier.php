<?php

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Verifies a Google ID token. Callers MUST obtain the nonce from an unexpired,
 * atomically consumed server-side challenge bound to the intended action/user.
 * This verifier does not create sessions, link users, or consume challenges.
 */
class GoogleIdentityVerifier
{
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const CACHE_KEY = 'auth:google:oidc-jwks:v1';

    /** @return array{sub: string, email: ?string, email_verified: bool} */
    public function verify(string $token, string $expectedNonce): array
    {
        $audiences = array_values(array_filter(array_map('trim', explode(',', (string) config('services.google.client_ids', '')))));
        if ($audiences === [] || $expectedNonce === '' || strlen($token) > 10000) {
            throw new RuntimeException('Google authentication unavailable or invalid.');
        }

        try {
            $keys = Cache::remember(self::CACHE_KEY, 300, function (): array {
                $response = Http::timeout(5)->acceptJson()->get(self::CERTS_URL);
                $response->throw();
                $body = $response->json();
                $keys = is_array($body) ? ($body['keys'] ?? null) : null;
                if (! is_array($keys)) {
                    throw new RuntimeException('Google keys unavailable.');
                }
                // Restrict accepted verification keys and algorithms.
                $keys = array_values(array_filter($keys, static fn ($key) => is_array($key) &&
                    ($key['kty'] ?? null) === 'RSA' && ($key['alg'] ?? null) === 'RS256' &&
                    is_string($key['kid'] ?? null) && ($key['use'] ?? 'sig') === 'sig'));
                if ($keys === []) {
                    throw new RuntimeException('Google keys unavailable.');
                }
                return ['keys' => $keys];
            });
            $claims = (array) JWT::decode($token, JWK::parseKeySet($keys, 'RS256'));
            if (! in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true) ||
                ! is_string($claims['aud'] ?? null) || ! in_array($claims['aud'], $audiences, true) ||
                ! is_int($claims['exp'] ?? null) || $claims['exp'] <= time() ||
                ! is_int($claims['iat'] ?? null) || $claims['iat'] > time() + 60 ||
                ! is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255 ||
                ! is_string($claims['nonce'] ?? null) || ! hash_equals($expectedNonce, $claims['nonce'])) {
                throw new RuntimeException('Invalid Google identity.');
            }
            return [
                'sub' => $claims['sub'],
                'email' => is_string($claims['email'] ?? null) ? $claims['email'] : null,
                'email_verified' => ($claims['email_verified'] ?? null) === true,
            ];
        } catch (Throwable) {
            // Never return or log the token, nonce, claims or low-level errors.
            throw new RuntimeException('Invalid or unavailable Google identity.');
        }
    }
}
