<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OidcFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'app.url' => 'http://localhost:8081']);
        Http::preventStrayRequests();
    }

    public function test_anonymous_session_exposes_no_tokens(): void
    {
        $this->getJson('/api/auth/session')->assertOk()->assertJsonPath('user', null)
            ->assertJsonStructure(['csrfToken'])->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_login_uses_the_gateway_and_pkce(): void
    {
        $response = $this->get('/api/auth/login')->assertRedirect();
        $url = $response->headers->get('Location');
        $this->assertStringStartsWith('http://localhost:8081/auth/', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $pending = session('oidc');
        $expected = rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '=');
        $this->assertSame($expected, $query['code_challenge']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame($pending['state'], $query['state']);
        $this->assertSame('http://localhost:8081/api/auth/callback', $query['redirect_uri']);
    }

    public function test_invalid_state_is_rejected_before_token_exchange(): void
    {
        $this->withSession(['oidc' => $this->pending()])->get('/api/auth/callback?code=code&state=wrong')
            ->assertRedirect('http://localhost:8081/?auth_error=invalid_response')->assertSessionMissing('identity');
        Http::assertNothingSent();
    }

    public function test_expired_authorization_transaction_is_rejected(): void
    {
        $pending = $this->pending();
        $pending['created_at'] = time() - 301;
        $this->withSession(['oidc' => $pending])->get('/api/auth/callback?code=code&state=state')
            ->assertRedirect('http://localhost:8081/?auth_error=invalid_response');
        Http::assertNothingSent();
    }

    public function test_valid_signed_identity_creates_a_server_session_and_cannot_be_replayed(): void
    {
        $this->fakeProvider();
        $this->withSession(['oidc' => $this->pending()])->get('/api/auth/callback?code=code&state=state')
            ->assertRedirect('http://localhost:8081/')->assertSessionHas('identity.subject', 'test-user');
        $this->getJson('/api/auth/session')->assertOk()->assertJsonPath('user.username', 'demo')
            ->assertJsonMissingPath('id_token')->assertJsonMissingPath('user.id_token');
        $this->get('/api/auth/callback?code=code&state=state')->assertRedirect('http://localhost:8081/?auth_error=invalid_response');
        Http::assertSentCount(2);
    }

    public function test_identity_with_wrong_issuer_audience_nonce_or_expiration_is_rejected(): void
    {
        foreach ([['iss' => 'http://untrusted.example'], ['aud' => 'other-client'], ['nonce' => 'wrong'], ['exp' => time() - 100], ['azp' => 'other-client'], ['aud' => [config('oidc.client_id'), 'other-client']], ['iat' => time() + 60]] as $override) {
            $this->fakeProvider($override);
            $this->withSession(['oidc' => $this->pending()])->get('/api/auth/callback?code=code&state=state')
                ->assertRedirect('http://localhost:8081/?auth_error=invalid_identity')->assertSessionMissing('identity');
        }
    }

    public function test_expired_session_is_invalidated(): void
    {
        $this->withSession(['identity' => ['expires_at' => time() - 1]])->getJson('/api/auth/session')
            ->assertOk()->assertJsonPath('user', null)->assertSessionMissing('identity');
    }

    public function test_logout_clears_local_session_and_returns_gateway_end_session_url(): void
    {
        $response = $this->withSession(['identity' => ['id_token' => 'test-token']])
            ->postJson('/api/auth/logout')->assertOk()->assertSessionMissing('identity');
        $this->assertStringStartsWith('http://localhost:8081/auth/', $response->json('url'));
        parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);
        $this->assertSame('http://localhost:8081/', $query['post_logout_redirect_uri']);
    }

    /** @return array<string, mixed> */
    private function pending(): array
    {
        return ['state' => 'state', 'nonce' => 'nonce', 'verifier' => 'verifier', 'created_at' => time()];
    }

    /** @param array<string, mixed> $override */
    private function fakeProvider(array $override = []): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        $details = openssl_pkey_get_details($key);
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $jwks = ['keys' => [['kid' => 'test', 'kty' => 'RSA', 'alg' => 'RS256', 'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e'])]]];
        $claims = array_merge([
            'iss' => config('oidc.issuer'), 'aud' => config('oidc.client_id'), 'sub' => 'test-user',
            'nonce' => 'nonce', 'iat' => time(), 'exp' => time() + 300, 'preferred_username' => 'demo',
        ], $override);
        Http::fake([
            config('oidc.internal_issuer').'/protocol/openid-connect/token' => Http::response(['id_token' => JWT::encode($claims, $privateKey, 'RS256', 'test')]),
            config('oidc.internal_issuer').'/protocol/openid-connect/certs' => Http::response($jwks),
        ]);
    }
}
