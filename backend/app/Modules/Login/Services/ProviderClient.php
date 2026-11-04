<?php

namespace App\Modules\Login\Services;

use App\Modules\Login\Models\UserSource;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class ProviderClient
{
    public function endpoint(UserSource $source, string $name, bool $internal = true): string
    {
        return rtrim($source->data[$internal ? 'internal_issuer' : 'issuer'], '/').'/protocol/openid-connect/'.$name;
    }

    public function request(UserSource $source): PendingRequest
    {
        return Http::acceptJson()->timeout($source->data['timeout'])->connectTimeout(5)->withoutRedirecting();
    }

    public function post(UserSource $source, string $endpoint, array $body, bool $import = false): array
    {
        $data = $source->data;
        $client = $data[$import ? 'import_client_id' : 'client_id'];
        $secret = $data[$import ? 'import_client_secret' : 'client_secret'];
        try {
            $response = $this->request($source)->asForm()->withBasicAuth($client, $secret)
                ->post($this->endpoint($source, $endpoint), $body);
        } catch (ConnectionException) {
            throw new LoginFailure('provider_unavailable', 503);
        }
        if ($response->serverError() || $response->redirect()) {
            throw new LoginFailure('provider_unavailable', 503);
        }
        if (! $response->successful()) {
            if ($response->status() === 401) {
                throw new LoginFailure('provider_configuration_error', 503);
            }
            throw new LoginFailure('provider_grant_invalid');
        }
        $result = $response->json();
        if (! is_array($result)) {
            throw new LoginFailure('provider_unavailable', 503);
        }

        return $result;
    }

    public function exchange(UserSource $source, string $code, string $verifier): array
    {
        return $this->post($source, 'token', [
            'grant_type' => 'authorization_code', 'code' => $code,
            'redirect_uri' => $source->data['callback_url'], 'code_verifier' => $verifier,
        ]);
    }

    public function refresh(UserSource $source, string $refreshToken): array
    {
        return $this->post($source, 'token', ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    public function introspect(UserSource $source, string $accessToken): array
    {
        return $this->post($source, 'token/introspect', ['token' => $accessToken]);
    }

    public function decode(UserSource $source, string $token): array
    {
        $cacheKey = 'login:jwks:'.$source->fingerprint();
        $load = function () use ($source): array {
            try {
                $response = $this->request($source)->get($this->endpoint($source, 'certs'));
            } catch (ConnectionException) {
                throw new LoginFailure('provider_unavailable', 503);
            }
            if (! $response->successful() || ! is_array($response->json('keys'))) {
                throw new LoginFailure('provider_unavailable', 503);
            }

            return ['keys' => array_values(array_filter($response->json('keys'), fn (array $key): bool => ($key['kty'] ?? null) === 'RSA' && ($key['alg'] ?? 'RS256') === 'RS256' && ($key['use'] ?? 'sig') === 'sig'))];
        };
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $jwks = Cache::remember($cacheKey, 300, $load);
            try {
                return (array) JWT::decode($token, JWK::parseKeySet($jwks, 'RS256'));
            } catch (Throwable) {
                Cache::forget($cacheKey);
            }
        }
        throw new LoginFailure('invalid_identity');
    }

    public function validateIdentity(UserSource $source, string $token, ?string $nonce = null): array
    {
        $claims = $this->decode($source, $token);
        $audiences = (array) ($claims['aud'] ?? []);
        $client = $source->data['client_id'];
        if (($claims['iss'] ?? null) !== $source->data['issuer']
            || ! in_array($client, $audiences, true)
            || (isset($claims['azp']) && $claims['azp'] !== $client)
            || (count($audiences) > 1 && ($claims['azp'] ?? null) !== $client)
            || ! is_string($claims['sub'] ?? null) || $claims['sub'] === ''
            || ! is_string($claims['sid'] ?? null) || $claims['sid'] === ''
            || ! is_numeric($claims['exp'] ?? null) || ! is_numeric($claims['iat'] ?? null)
            || $claims['iat'] > time() + 30
            || ($nonce !== null && (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])))) {
            throw new LoginFailure('invalid_identity');
        }

        return $claims;
    }

    public function adminUsers(UserSource $source, int $first, string $token): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $response = $this->request($source)->withToken($token)->get(rtrim($source->data['admin_base_url'], '/').'/users', [
                    'first' => $first, 'max' => $source->data['page_size'], 'briefRepresentation' => 'true',
                ]);
                if ($response->successful() && is_array($response->json())) {
                    return $response->json();
                }
                if (! $response->serverError() && $response->status() !== 429) {
                    break;
                }
            } catch (ConnectionException) {
                // Only read-only import pages may be retried safely.
            }
            if ($attempt < 2) {
                usleep(200000 * ($attempt + 1));
            }
        }
        throw new LoginFailure('provider_import_failed', 503);
    }
}
