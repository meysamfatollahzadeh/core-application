<?php

namespace App\Modules\Login\Actions;

use App\Modules\Login\Models\PersonalAccessToken;
use App\Modules\Login\Models\UserSource;
use App\Modules\Login\Services\LoginFailure;
use App\Modules\Login\Services\ProviderClient;
use Illuminate\Support\Facades\Cache;

class BackchannelLogout
{
    public function __construct(private ProviderClient $provider) {}

    public function execute(UserSource $source, string $jwt): void
    {
        $claims = $this->provider->decode($source, $jwt);
        $events = (array) ($claims['events'] ?? []);
        $event = $events['http://schemas.openid.net/event/backchannel-logout'] ?? null;
        $sid = $claims['sid'] ?? null;
        $sub = $claims['sub'] ?? null;
        if (($claims['iss'] ?? null) !== $source->data['issuer']
            || ! in_array($source->data['client_id'], (array) ($claims['aud'] ?? []), true)
            || ! is_numeric($claims['iat'] ?? null) || $claims['iat'] < time() - 300 || $claims['iat'] > time() + 30
            || ! is_string($claims['jti'] ?? null) || $claims['jti'] === '' || strlen($claims['jti']) > 255
            || array_key_exists('nonce', $claims) || ! is_object($event) || count((array) $event) !== 0
            || ($sid === null && $sub === null)
            || ($sid !== null && (! is_string($sid) || $sid === '' || strlen($sid) > 255))
            || ($sub !== null && (! is_string($sub) || $sub === '' || strlen($sub) > 255))) {
            throw new LoginFailure('invalid_logout_token', 400);
        }
        $replay = 'login:logout-jti:'.hash('sha256', $source->slug.'|'.$source->fingerprint().'|'.$claims['jti']);
        if (! Cache::add($replay, true, 600)) {
            return;
        }
        PersonalAccessToken::where('provider_config_hash', $source->fingerprint())
            ->whereHas('member', function ($query) use ($source, $sub): void {
                $query->where('user_source_slug', $source->slug);
                if ($sub !== null) {
                    $query->where('uuid', $sub);
                }
            })->when($sid !== null, fn ($query) => $query->where('provider_sid', $sid))->delete();
    }
}
