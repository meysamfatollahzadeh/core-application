<?php

namespace App\Modules\Login\Actions;

use App\Modules\Login\Models\Member;
use App\Modules\Login\Models\UserSource;
use App\Modules\Login\Services\LoginFailure;
use App\Modules\Login\Services\ProviderClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CompleteLogin
{
    public function __construct(private ProviderClient $provider) {}

    public function execute(UserSource $source, string $state, string $code, string $binding): array
    {
        $transaction = Cache::lock('login:transaction-lock:'.$state, 10)->block(3, function () use ($source, $state, $binding): array {
            $key = 'login:transaction:'.$state;
            $pending = Cache::get($key);
            if (! $source->is_active || ! is_array($pending) || $pending['slug'] !== $source->slug
                || ! hash_equals($pending['config'], $source->fingerprint())
                || ! hash_equals($pending['binding_hash'], hash('sha256', $binding))
                || time() - $pending['created_at'] > 300) {
                throw new LoginFailure('invalid_login_transaction');
            }
            Cache::forget($key);

            return $pending;
        });
        $tokens = $this->provider->exchange($source, $code, $transaction['verifier']);
        $claims = $this->provider->validateIdentity($source, $tokens['id_token'] ?? '', $transaction['nonce']);
        if (! is_string($tokens['access_token'] ?? null) || ! is_string($tokens['refresh_token'] ?? null)
            || ! is_numeric($tokens['expires_in'] ?? null) || $tokens['expires_in'] <= 0) {
            throw new LoginFailure('invalid_provider_grant');
        }

        return DB::transaction(function () use ($source, $transaction, $tokens, $claims): array {
            $source = UserSource::where('slug', $source->slug)->lockForUpdate()->firstOrFail();
            if (! $source->is_active || ! hash_equals($transaction['config'], $source->fingerprint())) {
                throw new LoginFailure('source_changed');
            }
            $member = Member::with('user')->where('user_source_id', $source->id)->where('uuid', $claims['sub'])->lockForUpdate()->first();
            if (! $member) {
                throw new LoginFailure('member_not_imported', 403);
            }
            $plain = $member->user->generateTokenString();
            $ttl = min(86400, max(1, (int) ($tokens['refresh_expires_in'] ?? 86400) ?: 86400));
            $expires = now()->addSeconds($ttl);
            $token = $member->user->tokens()->create([
                'name' => 'login:'.$source->slug, 'token' => hash('sha256', $plain), 'abilities' => ['login:access'],
                'expires_at' => $expires, 'member_id' => $member->id,
                'provider_sid' => $claims['sid'], 'provider_config_hash' => $source->fingerprint(),
                'oidc_data' => [
                    'access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'],
                    'id_token' => $tokens['id_token'], 'access_expires_at' => time() + (int) $tokens['expires_in'],
                ],
            ]);

            return ['token' => $token->id.'|'.$plain, 'expires_at' => $expires->toIso8601String()];
        });
    }
}
