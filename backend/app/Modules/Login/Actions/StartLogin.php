<?php

namespace App\Modules\Login\Actions;

use App\Modules\Login\Models\UserSource;
use App\Modules\Login\Services\LoginFailure;
use App\Modules\Login\Services\ProviderClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class StartLogin
{
    public function __construct(private ProviderClient $provider) {}

    public function execute(UserSource $source, string $binding): array
    {
        if (! $source->is_active) {
            throw new LoginFailure('source_inactive');
        }
        $state = Str::random(64);
        $nonce = Str::random(64);
        $verifier = Str::random(96);
        Cache::put('login:transaction:'.$state, [
            'slug' => $source->slug, 'config' => $source->fingerprint(),
            'binding_hash' => hash('sha256', $binding), 'nonce' => $nonce,
            'verifier' => $verifier, 'created_at' => time(),
        ], 300);

        return ['authorization_url' => $this->provider->endpoint($source, 'auth', false).'?'.http_build_query([
            'client_id' => $source->data['client_id'], 'redirect_uri' => $source->data['callback_url'],
            'response_type' => 'code', 'scope' => 'openid profile email', 'ui_locales' => 'fa',
            'state' => $state, 'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ])];
    }
}
