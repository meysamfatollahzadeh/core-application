<?php

namespace App\Modules\Login\Services;

use App\Modules\Login\Models\PersonalAccessToken;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class GrantVerifier
{
    public function __construct(private ProviderClient $provider) {}

    public function verify(PersonalAccessToken $token): PersonalAccessToken
    {
        try {
            return Cache::lock('login:grant:'.$token->id, 60)->block(5, function () use ($token): PersonalAccessToken {
                $token = $token->fresh(['member.userSource']);
                if (! $token) {
                    throw new LoginFailure('unauthenticated');
                }
                $member = $token->member;
                $source = $member?->userSource;
                if (! $source || ! $source->is_active || $member->user_id !== $token->tokenable_id
                    || ! hash_equals($token->provider_config_hash, $source->fingerprint())) {
                    $token->delete();
                    throw new LoginFailure('source_or_membership_invalid');
                }
                try {
                    $grant = $token->oidc_data;
                    if (($grant['access_expires_at'] ?? 0) <= time() + 15) {
                        $refreshed = $this->provider->refresh($source, $grant['refresh_token']);
                        if (! is_string($refreshed['access_token'] ?? null) || (int) ($refreshed['expires_in'] ?? 0) <= 0) {
                            throw new LoginFailure('provider_unavailable', 503);
                        }
                        if (isset($refreshed['id_token'])) {
                            $claims = $this->provider->validateIdentity($source, $refreshed['id_token']);
                            if ($claims['sub'] !== $member->uuid || $claims['sid'] !== $token->provider_sid) {
                                throw new LoginFailure('provider_grant_invalid');
                            }
                            $grant['id_token'] = $refreshed['id_token'];
                        }
                        $grant['access_token'] = $refreshed['access_token'];
                        $grant['refresh_token'] = $refreshed['refresh_token'] ?? $grant['refresh_token'];
                        $grant['access_expires_at'] = time() + (int) $refreshed['expires_in'];
                        $token->oidc_data = $grant;
                        $token->save();
                    }
                    $active = $this->provider->introspect($source, $grant['access_token']);
                    if (($active['active'] ?? false) !== true || ($active['sub'] ?? null) !== $member->uuid
                        || (isset($active['iss']) && $active['iss'] !== $source->data['issuer'])
                        || (isset($active['client_id']) && $active['client_id'] !== $source->data['client_id'])
                        || (isset($active['sid']) && $active['sid'] !== $token->provider_sid)) {
                        throw new LoginFailure('provider_grant_invalid');
                    }
                } catch (LoginFailure $failure) {
                    if ($failure->httpStatus === 401) {
                        $token->delete();
                    }
                    throw $failure;
                }
                $currentSource = $source->fresh();
                if (! $currentSource?->is_active || ! hash_equals($token->provider_config_hash, $currentSource->fingerprint())
                    || ! $token->fresh()) {
                    throw new LoginFailure('unauthenticated');
                }

                return $token;
            });
        } catch (LockTimeoutException) {
            throw new LoginFailure('provider_busy', 503);
        }
    }
}
