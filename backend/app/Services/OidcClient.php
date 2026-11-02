<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OidcClient
{
    public function endpoint(string $endpoint, bool $internal = false): string
    {
        return rtrim(config($internal ? 'oidc.internal_issuer' : 'oidc.issuer'), '/').'/protocol/openid-connect/'.$endpoint;
    }

    /** @return array<string, mixed> */
    public function exchange(string $code, string $verifier): array
    {
        return Http::asForm()->timeout(15)->post($this->endpoint('token', true), [
            'grant_type' => 'authorization_code',
            'client_id' => config('oidc.client_id'),
            'client_secret' => config('oidc.client_secret'),
            'redirect_uri' => config('app.url').'/api/auth/callback',
            'code' => $code,
            'code_verifier' => $verifier,
        ])->throw()->json();
    }

    /** @return array<string, mixed> */
    public function validateIdentity(string $token, string $nonce): array
    {
        $jwks = Http::timeout(10)->get($this->endpoint('certs', true))->throw()->json();
        $claims = (array) JWT::decode($token, JWK::parseKeySet($jwks, 'RS256'));
        $audiences = (array) ($claims['aud'] ?? []);
        $client = config('oidc.client_id');

        if (($claims['iss'] ?? null) !== config('oidc.issuer')
            || ! in_array($client, $audiences, true)
            || (isset($claims['azp']) && $claims['azp'] !== $client)
            || (count($audiences) > 1 && ($claims['azp'] ?? null) !== $client)
            || ! is_string($claims['nonce'] ?? null)
            || ! hash_equals($nonce, $claims['nonce'])
            || ! is_string($claims['sub'] ?? null)
            || $claims['sub'] === ''
            || ! isset($claims['exp'])
            || ! isset($claims['iat'])
            || $claims['iat'] > time() + 30) {
            throw new RuntimeException('Invalid identity token claims.');
        }

        return $claims;
    }
}
