<?php

namespace Tests\Feature\Login;

use App\Modules\Login\Actions\ManageMemberConnections;
use App\Modules\Login\Models\Member;
use App\Modules\Login\Models\PersonalAccessToken;
use App\Modules\Login\Models\User;
use App\Modules\Login\Models\UserSource;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private UserSource $source;

    private string $privateKey = '';

    private array $jwk;

    private string $binding;

    private array $identity;

    private bool $upstreamActive = true;

    private bool $upstreamUnavailable = false;

    private bool $refreshInvalid = false;

    private int $refreshes = 0;

    private int $accessTtl = 300;

    private ?\Closure $duringIntrospection = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->source = UserSource::factory()->create(['slug' => 'primary']);
        $this->binding = Str::random(64);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $encode = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $this->jwk = ['kty' => 'RSA', 'kid' => 'test', 'alg' => 'RS256', 'use' => 'sig', 'n' => $encode($rsa['n']), 'e' => $encode($rsa['e'])];
        $this->identity = ['iss' => $this->source->data['issuer'], 'aud' => 'organization', 'sub' => 'external-subject', 'sid' => 'external-session', 'iat' => time(), 'exp' => time() + 300];
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/certs')) {
                return Http::response(['keys' => [$this->jwk]]);
            }
            if ($this->upstreamUnavailable) {
                return Http::response([], 503);
            }
            if (str_ends_with($request->url(), '/introspect')) {
                if ($this->duringIntrospection) {
                    ($this->duringIntrospection)();
                }

                return Http::response(['active' => $this->upstreamActive, 'sub' => 'external-subject', 'sid' => 'external-session', 'client_id' => 'organization']);
            }
            if ($request['grant_type'] === 'refresh_token') {
                $this->refreshes++;
                if ($this->refreshInvalid) {
                    return Http::response(['error' => 'invalid_grant'], 400);
                }
            }

            return Http::response(['id_token' => $this->sign($this->identity), 'access_token' => 'access-secret', 'refresh_token' => 'refresh-secret', 'expires_in' => $this->accessTtl, 'refresh_expires_in' => 1800]);
        });
    }

    private function sign(array $claims): string
    {
        return JWT::encode($claims, $this->privateKey, 'RS256', 'test');
    }

    private function start(): string
    {
        $result = $this->postJson('/api/login/sources/primary/authorize', ['browser_binding' => $this->binding])->assertOk();
        parse_str(parse_url($result->json('authorization_url'), PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->identity['nonce'] = $query['nonce'];

        return $query['state'];
    }

    private function complete(string $state, ?string $binding = null, string $source = 'primary'): TestResponse
    {
        return $this->postJson('/api/login/sources/'.$source.'/callback', ['state' => $state, 'code' => 'authorization-code', 'browser_binding' => $binding ?? $this->binding]);
    }

    private function login(): string
    {
        Member::factory()->create(['user_source_id' => $this->source->id, 'uuid' => 'external-subject']);

        return $this->complete($this->start())->assertOk()->json('token');
    }

    public function test_callback_issues_only_hashed_finite_bearer_for_imported_member_and_is_single_use(): void
    {
        Member::factory()->create(['user_source_id' => $this->source->id, 'uuid' => 'external-subject']);
        $state = $this->start();
        $token = $this->complete($state)->assertOk()->json('token');
        $stored = PersonalAccessToken::firstOrFail();
        $this->assertNotSame($token, $stored->token);
        $this->assertStringNotContainsString('refresh-secret', $stored->getRawOriginal('oidc_data'));
        $this->assertTrue($stored->expires_at->isFuture());
        $this->complete($state)->assertUnauthorized();
        $this->withToken($token)->getJson('/api/login/me')->assertOk()->assertJsonPath('data.source.slug', 'primary')->assertJsonMissingPath('data.source.id');
        Http::assertSent(fn ($request) => isset($request['code_verifier']) && $request['grant_type'] === 'authorization_code');
    }

    public function test_unknown_member_is_denied_without_creating_local_users(): void
    {
        $this->complete($this->start())->assertForbidden()->assertJsonPath('error', 'member_not_imported');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_detaching_one_member_revokes_only_its_tokens_and_reconnection_does_not_revive_them(): void
    {
        $bearer = $this->login();
        $member = Member::where('uuid', 'external-subject')->firstOrFail();
        $otherSource = UserSource::factory()->create();
        $other = Member::factory()->create(['user_id' => $member->user_id, 'user_source_id' => $otherSource->id, 'uuid' => 'external-subject']);
        $otherToken = $other->user->tokens()->create(['name' => 'survivor', 'token' => hash('sha256', 'surviving-secret'), 'abilities' => ['login:access'], 'expires_at' => now()->addHour(), 'member_id' => $other->id, 'provider_sid' => 'external-session', 'provider_config_hash' => $otherSource->fingerprint(), 'oidc_data' => ['access_token' => 'access-secret', 'access_expires_at' => time() + 300]]);
        app(ManageMemberConnections::class)->detach($member->user, $member);
        $this->withToken($bearer)->getJson('/api/login/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken->id.'|surviving-secret')->getJson('/api/login/me')->assertOk();
        $this->complete($this->start())->assertForbidden();
        Member::create(['uuid' => 'external-subject', 'user_id' => $other->user_id, 'user_source_id' => $this->source->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($bearer)->getJson('/api/login/me')->assertUnauthorized();
        $this->complete($this->start())->assertOk();
    }

    public function test_detach_during_upstream_check_cannot_authorize_a_stale_token(): void
    {
        $bearer = $this->login();
        $member = Member::where('uuid', 'external-subject')->firstOrFail();
        $this->duringIntrospection = function () use ($member): void {
            $this->duringIntrospection = null;
            app(ManageMemberConnections::class)->detach($member->user, $member);
        };
        $this->withToken($bearer)->getJson('/api/login/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('members', 0);
    }

    public function test_binding_wrong_source_nonce_and_changed_configuration_are_rejected(): void
    {
        $state = $this->start();
        UserSource::factory()->create(['slug' => 'secondary']);
        $this->complete($state, source: 'secondary')->assertUnauthorized();
        $this->complete($state, Str::random(64))->assertUnauthorized();
        $this->identity['nonce'] = 'wrong';
        $this->complete($state)->assertUnauthorized();
        $state = $this->start();
        $this->source->update(['data' => array_merge($this->source->data, ['client_id' => 'changed'])]);
        $this->complete($state)->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_wrong_issuer_audience_signature_and_expired_transactions_are_rejected(): void
    {
        Member::factory()->create(['user_source_id' => $this->source->id, 'uuid' => 'external-subject']);
        $original = $this->identity;
        foreach (['iss' => 'https://untrusted.example/realm', 'aud' => 'other-client', 'exp' => time() - 60] as $claim => $value) {
            $this->identity = $original;
            $state = $this->start();
            $this->identity[$claim] = $value;
            $this->complete($state)->assertUnauthorized();
        }
        $this->identity = $original;
        $state = $this->start();
        $forgedKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($forgedKey, $this->privateKey);
        $this->complete($state)->assertUnauthorized();
        $state = $this->start();
        Cache::forget('login:transaction:'.$state);
        $this->complete($state)->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_expired_local_tokens_and_changed_source_settings_cannot_authorize(): void
    {
        $bearer = $this->login();
        PersonalAccessToken::firstOrFail()->update(['expires_at' => now()->subMinute()]);
        $this->withToken($bearer)->getJson('/api/login/me')->assertUnauthorized();
        PersonalAccessToken::firstOrFail()->update(['expires_at' => now()->addHour()]);
        $this->source->update(['data' => array_merge($this->source->data, ['client_secret' => 'rotated-secret'])]);
        $this->withToken($bearer)->getJson('/api/login/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_refresh_and_current_validity_are_checked_without_positive_cache(): void
    {
        $this->accessTtl = 1;
        $token = $this->login();
        $this->accessTtl = 300;
        $this->withToken($token)->getJson('/api/login/me')->assertOk();
        $this->withToken($token)->getJson('/api/login/me')->assertOk();
        $this->assertSame(1, $this->refreshes);
        $this->upstreamUnavailable = true;
        $this->withToken($token)->getJson('/api/login/me')->assertStatus(503);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->upstreamUnavailable = false;
        $this->upstreamActive = false;
        $this->withToken($token)->getJson('/api/login/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_invalid_refresh_revokes_and_inactive_source_cannot_authorize(): void
    {
        $this->accessTtl = 1;
        $token = $this->login();
        $this->refreshInvalid = true;
        $this->withToken($token)->getJson('/api/login/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->source->update(['is_active' => false]);
        $this->postJson('/api/login/sources/primary/authorize', ['browser_binding' => $this->binding])->assertUnauthorized();
    }

    public function test_signed_logout_is_source_scoped_and_replay_cannot_revoke_later_grants(): void
    {
        $this->login();
        $other = UserSource::factory()->create(['slug' => 'secondary']);
        $member = Member::factory()->create(['user_source_id' => $other->id, 'uuid' => 'external-subject']);
        $member->user->tokens()->create(['name' => 'other', 'token' => hash('sha256', 'other'), 'abilities' => ['login:access'], 'expires_at' => now()->addHour(), 'member_id' => $member->id, 'provider_sid' => 'external-session', 'provider_config_hash' => $other->fingerprint(), 'oidc_data' => []]);
        $claims = ['iss' => $this->source->data['issuer'], 'aud' => 'organization', 'sid' => 'external-session', 'iat' => time(), 'jti' => Str::random(32), 'events' => ['http://schemas.openid.net/event/backchannel-logout' => (object) []]];
        $route = '/api/login/sources/primary/backchannel-logout';
        $this->postJson($route, ['logout_token' => $this->sign(array_merge($claims, ['nonce' => 'invalid']))])->assertStatus(400);
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $jwt = $this->sign($claims);
        $this->postJson($route, ['logout_token' => $jwt])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertSame($member->id, PersonalAccessToken::first()->member_id);
        $this->complete($this->start())->assertOk();
        $this->postJson($route, ['logout_token' => $jwt])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->postJson($route, ['logout_token' => $this->sign(array_merge($claims, ['jti' => Str::random(32), 'iss' => $other->data['issuer']]))])->assertStatus(400);
    }

    public function test_local_logout_revokes_even_during_outage_and_handoff_is_bound_and_single_use(): void
    {
        $token = $this->login();
        $this->upstreamUnavailable = true;
        $result = $this->withToken($token)->postJson('/api/login/logout', ['browser_binding' => $this->binding])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $handoff = $result->json('handoff');
        $this->postJson('/api/login/logout/continue', ['browser_binding' => Str::random(64), 'handoff' => $handoff])->assertUnauthorized();
        $form = $this->postJson('/api/login/logout/continue', ['browser_binding' => $this->binding, 'handoff' => $handoff])->assertOk();
        $this->assertStringNotContainsString('id_token_hint', $form->json('endpoint'));
        $this->assertSame($result->json('state'), $form->json('fields.state'));
        $this->postJson('/api/login/logout/continue', ['browser_binding' => $this->binding, 'handoff' => $handoff])->assertUnauthorized();
    }

    public function test_performances_updates_only_the_authenticated_user_preserve_other_keys_and_require_valid_upstream(): void
    {
        $bearer = $this->login();
        $member = Member::where('uuid', 'external-subject')->firstOrFail();
        $other = User::factory()->create();
        $member->user->forceFill(['performances' => ['theme' => 'auto', 'density' => 'comfortable']])->save();
        $this->withToken($bearer)->patchJson('/api/login/performances', ['theme' => 'dark'])->assertOk()->assertJsonPath('data.performances.theme', 'dark')->assertJsonPath('data.performances.density', 'comfortable');
        $this->assertSame(['theme' => 'auto'], $other->fresh()->performances);
        $this->withToken($bearer)->getJson('/api/login/me')->assertOk()->assertJsonPath('data.user.performances.theme', 'dark');
        $this->withToken($bearer)->patchJson('/api/login/performances', ['theme' => 'system'])->assertUnprocessable();
        $this->withToken($bearer)->patchJson('/api/login/performances', ['theme' => 'light', 'user_id' => $other->id])->assertUnprocessable();
        $this->assertSame('dark', $member->user->fresh()->performances['theme']);
        $this->upstreamActive = false;
        $this->withToken($bearer)->patchJson('/api/login/performances', ['theme' => 'light'])->assertUnauthorized();
        $this->assertSame('dark', $member->user->fresh()->performances['theme']);
    }
}
