<?php

namespace App\Modules\Login\Actions;

use App\Modules\Login\Models\PersonalAccessToken;
use App\Modules\Login\Services\LoginFailure;
use App\Modules\Login\Services\ProviderClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class Logout
{
    public function __construct(private ProviderClient $provider) {}

    public function execute(PersonalAccessToken $token, string $binding): array
    {
        $source = $token->member?->userSource;
        $grant = $token->oidc_data;
        $token->delete();
        if (! $source || ! hash_equals($token->provider_config_hash, $source->fingerprint())) {
            return ['handoff' => null, 'remote_logout' => 'unavailable'];
        }
        $handoff = Str::random(96);
        $state = Str::random(64);
        Cache::put('login:logout:'.$handoff, Crypt::encryptString(json_encode([
            'binding_hash' => hash('sha256', $binding),
            'endpoint' => $this->provider->endpoint($source, 'logout', false),
            'fields' => ['id_token_hint' => $grant['id_token'], 'client_id' => $source->data['client_id'],
                'post_logout_redirect_uri' => $source->data['post_logout_redirect_uri'], 'state' => $state],
        ], JSON_THROW_ON_ERROR)), 120);

        return ['handoff' => $handoff, 'state' => $state, 'remote_logout' => 'pending'];
    }

    public function consume(string $handoff, string $binding): array
    {
        return Cache::lock('login:logout-lock:'.hash('sha256', $handoff), 10)->block(3, function () use ($handoff, $binding): array {
            $key = 'login:logout:'.$handoff;
            $encrypted = Cache::get($key);
            if (! is_string($encrypted)) {
                throw new LoginFailure('logout_handoff_expired');
            }
            $payload = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
            if (! hash_equals($payload['binding_hash'], hash('sha256', $binding))) {
                throw new LoginFailure('logout_handoff_invalid');
            }
            Cache::forget($key);
            unset($payload['binding_hash']);

            return $payload;
        });
    }
}
