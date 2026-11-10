<?php

namespace Tests\Feature\Login;

use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\Role;
use App\Modules\Access\Services\EffectivePermissions;
use App\Modules\Login\Http\Middleware\VerifyProviderGrant;
use App\Modules\Login\Models\Member;
use App\Modules\Login\Models\User;
use App\Modules\Login\Models\UserSource;
use App\Modules\Organization\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SourceManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['login.allowed_origins' => ['http://localhost:8081', 'http://keycloak:8080']]);
        Http::preventStrayRequests();
    }

    private function login(array $keys, ?int $company = null): void
    {
        $user = User::factory()->create();
        $role = Role::create(['key' => 'test-'.Role::count(), 'title' => 'Test']);
        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['title' => $key]);
            DB::table('role_permission')->insert(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }
        DB::table($company ? 'company_user_role' : 'user_role')->insert([
            'role_id' => $role->id, 'user_id' => $user->id, ...($company ? ['company_id' => $company] : []),
        ]);
        app(EffectivePermissions::class)->forget();
        $this->withoutMiddleware(VerifyProviderGrant::class);
        Sanctum::actingAs($user, ['login:access']);
    }

    private function input(string $slug = 'new-source'): array
    {
        return ['slug' => $slug, 'title' => 'سورس آزمایشی', 'data' => [
            'issuer' => 'http://localhost:8081/auth/realms/test',
            'internal_issuer' => 'http://keycloak:8080/auth/realms/test',
            'client_id' => 'application', 'client_secret' => ' private-test-secret ',
        ]];
    }

    private function token(UserSource $source)
    {
        $member = Member::factory()->create(['user_source_id' => $source->id]);

        return $member->user->tokens()->create([
            'name' => 'test', 'token' => hash('sha256', 'test-'.$source->slug), 'abilities' => ['login:access'],
            'member_id' => $member->id, 'provider_config_hash' => $source->fingerprint(),
            'provider_sid' => 'session', 'expires_at' => now()->addHour(), 'oidc_data' => [],
        ]);
    }

    public function test_routes_require_authentication_and_global_permissions(): void
    {
        $source = UserSource::factory()->create();
        $this->getJson('/api/login-sources')->assertUnauthorized();
        $company = Company::create(['title' => 'Company']);
        $this->login(['login_sources.view', 'login_sources.create', 'login_sources.update', 'login_sources.activate', 'login_sources.delete'], $company->id);
        $this->getJson('/api/login-sources')->assertForbidden();
        $this->getJson('/api/login-sources/'.$source->slug)->assertForbidden();
        $this->postJson('/api/login-sources', $this->input())->assertForbidden();
        $this->patchJson('/api/login-sources/'.$source->slug, ['title' => 'Changed'])->assertForbidden();
        $this->patchJson('/api/login-sources/'.$source->slug.'/activity', ['is_active' => false])->assertForbidden();
        $this->deleteJson('/api/login-sources/'.$source->slug)->assertForbidden();
    }

    public function test_create_is_inactive_derives_redirects_and_never_returns_secrets(): void
    {
        $this->login(['login_sources.create']);
        $response = $this->postJson('/api/login-sources', $this->input())->assertCreated()
            ->assertJsonPath('data.slug', 'new-source')->assertJsonPath('data.is_active', false)->assertJsonMissingPath('data.data');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('private-test-secret', $response->getContent());
        $source = UserSource::where('slug', 'new-source')->firstOrFail();
        $this->assertSame(' private-test-secret ', $source->data['client_secret']);
        $this->assertStringNotContainsString('private-test-secret', $source->getRawOriginal('data'));
        $this->assertSame(config('app.url').'/login/callback/new-source', $source->data['callback_url']);
        $this->getJson('/api/login-sources/new-source')->assertForbidden();
        $this->patchJson('/api/login-sources/new-source/activity', ['is_active' => true])->assertForbidden();
        $this->postJson('/api/login-sources', $this->input())->assertConflict()->assertJsonPath('error', 'source_conflict');
        Http::assertNothingSent();
    }

    public function test_list_and_detail_are_sanitized_searchable_and_paginated(): void
    {
        $source = UserSource::factory()->create(['slug' => 'known', 'is_active' => false]);
        Member::factory()->create(['user_source_id' => $source->id]);
        UserSource::factory()->create();
        $this->login(['login_sources.view']);
        $this->getJson('/api/login-sources?search=known&per_page=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.members_count', 1)->assertJsonPath('meta.total', 1)->assertJsonMissingPath('data.0.data');
        $detail = $this->getJson('/api/login-sources/known')->assertOk()
            ->assertJsonPath('data.client_secret_configured', true)->assertJsonPath('data.data.client_id', 'organization')
            ->assertJsonMissingPath('data.data.client_secret')->assertJsonMissingPath('data.id');
        $this->assertStringNotContainsString('test-secret', $detail->getContent());
        $this->patchJson('/api/login-sources/known', ['title' => 'Denied'])->assertForbidden();
        $this->deleteJson('/api/login-sources/known')->assertForbidden();
    }

    public function test_blank_and_omitted_secret_updates_preserve_credentials_and_title_keeps_tokens(): void
    {
        $source = UserSource::factory()->create();
        $token = $this->token($source);
        $this->login(['login_sources.update']);
        foreach ([['title' => 'Changed'], ['data' => ['client_secret' => '']]] as $input) {
            $this->patchJson('/api/login-sources/'.$source->slug, $input)->assertOk()->assertJsonMissingPath('data.data');
            $this->assertSame('test-secret', $source->fresh()->data['client_secret']);
            $this->assertNotNull($token->fresh());
        }
        $this->patchJson('/api/login-sources/'.$source->slug, ['data' => ['client_secret' => 'replacement']])->assertOk();
        $this->assertNull($token->fresh());
        $this->assertSame('replacement', $source->fresh()->data['client_secret']);
    }

    public function test_slug_and_existing_member_issuer_cannot_change_even_through_cli(): void
    {
        $source = UserSource::factory()->create();
        $this->token($source);
        $this->login(['login_sources.update']);
        $this->patchJson('/api/login-sources/'.$source->slug, ['slug' => 'renamed'])->assertUnprocessable();
        $this->patchJson('/api/login-sources/'.$source->slug, ['data' => ['issuer' => 'http://localhost:8081/auth/realms/replacement']])->assertUnprocessable()->assertInvalid('data.issuer');
        $path = tempnam(sys_get_temp_dir(), 'source-config-');
        try {
            file_put_contents($path, json_encode(['slug' => $source->slug, 'title' => $source->title, 'is_active' => true,
                'data' => [...$source->data, 'issuer' => 'http://localhost:8081/auth/realms/replacement']]));
            $this->artisan('login:sources:upsert', ['--config-file' => $path])->assertFailed();
            $this->assertDatabaseCount('personal_access_tokens', 1);
            $this->assertSame($source->data['issuer'], $source->fresh()->data['issuer']);
        } finally {
            unlink($path);
        }
    }

    public function test_deactivation_revokes_only_its_tokens_and_reactivation_does_not_restore_them(): void
    {
        $source = UserSource::factory()->create();
        $token = $this->token($source);
        $other = $this->token(UserSource::factory()->create());
        $this->login(['login_sources.activate']);
        $this->patchJson('/api/login-sources/'.$source->slug.'/activity', ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertNull($token->fresh());
        $this->assertNotNull($other->fresh());
        $this->getJson('/api/login/sources')->assertOk()->assertJsonMissing(['slug' => $source->slug]);
        $this->postJson('/api/login/sources/'.$source->slug.'/authorize', ['browser_binding' => str_repeat('b', 64)])->assertUnauthorized();
        $this->patchJson('/api/login-sources/'.$source->slug.'/activity', ['is_active' => true])->assertOk();
        $this->assertNull($token->fresh());
        $this->assertDatabaseCount('members', 2);
        Http::assertNothingSent();
    }

    public function test_delete_referenced_source_conflicts_and_empty_source_deletes_without_remote_calls(): void
    {
        $source = UserSource::factory()->create();
        $this->token($source);
        $empty = UserSource::factory()->create();
        $this->login(['login_sources.delete']);
        $this->deleteJson('/api/login-sources/'.$source->slug)->assertConflict()->assertJsonPath('error', 'source_has_members');
        $this->deleteJson('/api/login-sources/'.$empty->slug)->assertNoContent();
        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNotNull($source->fresh());
        $this->assertNull($empty->fresh());
        Http::assertNothingSent();
    }

    public function test_directory_group_is_complete_preserves_secret_and_can_be_removed_without_revocation(): void
    {
        $source = UserSource::factory()->create();
        $token = $this->token($source);
        $this->login(['login_sources.update']);
        $path = '/api/login-sources/'.$source->slug;
        $this->patchJson($path, ['data' => ['import_client_id' => 'directory']])->assertUnprocessable();
        $this->patchJson($path, ['data' => ['import_client_secret' => 'lonely-secret']])->assertUnprocessable();
        $this->patchJson($path, ['data' => ['import_client_id' => 'directory', 'import_client_secret' => 'directory-secret', 'admin_base_url' => 'http://keycloak:8080/auth/admin/realms/test']])->assertOk();
        $this->patchJson($path, ['data' => ['import_client_secret' => '']])->assertOk();
        $this->assertSame('directory-secret', $source->fresh()->data['import_client_secret']);
        $this->assertNotNull($token->fresh());
        $this->patchJson($path, ['remove_directory' => true])->assertOk();
        $this->assertArrayNotHasKey('import_client_secret', $source->fresh()->data);
        $this->assertArrayNotHasKey('admin_base_url', $source->fresh()->data);
        $this->assertNotNull($token->fresh());
    }

    public function test_untrusted_origins_credentials_redirects_and_invalid_settings_are_rejected(): void
    {
        $this->login(['login_sources.create']);
        foreach ([
            ['internal_issuer' => 'http://redis:6379/realm'],
            ['issuer' => 'http://user:private-test-secret@localhost:8081/realm'],
            ['issuer' => 'http://localhost:8081/realm?secret=private-test-secret'],
            ['allowed_algorithms' => ['HS256']], ['timeout' => 31], ['page_size' => 501],
            ['callback_url' => 'https://outside.example/callback'],
        ] as $changes) {
            $input = $this->input();
            $input['data'] = [...$input['data'], ...$changes];
            $response = $this->postJson('/api/login-sources', $input)->assertUnprocessable();
            $this->assertStringNotContainsString('private-test-secret', $response->getContent());
        }
        $input = $this->input();
        $input['is_active'] = true;
        $this->postJson('/api/login-sources', $input)->assertUnprocessable();
        $this->assertDatabaseCount('user_sources', 0);
    }

    public function test_legacy_endpoint_can_be_kept_but_new_endpoint_must_be_allowed(): void
    {
        $source = UserSource::factory()->create();
        $this->login(['login_sources.update']);
        $this->patchJson('/api/login-sources/'.$source->slug, ['title' => 'Legacy title'])->assertOk();
        $this->patchJson('/api/login-sources/'.$source->slug, ['data' => ['internal_issuer' => 'http://unlisted.example/realm']])->assertUnprocessable();
    }

    public function test_sync_prepares_admin_permissions_without_granting_users_or_custom_roles(): void
    {
        $admin = Role::create(['key' => 'system-administrator', 'title' => 'Admin']);
        $custom = Role::create(['key' => 'custom', 'title' => 'Custom']);
        $this->artisan('access:sync-permissions')->assertSuccessful();
        $this->artisan('access:sync-permissions')->assertSuccessful();
        $ids = Permission::where('key', 'like', 'login_sources.%')->pluck('id');
        $this->assertCount(5, $ids);
        $this->assertSame(5, DB::table('role_permission')->where('role_id', $admin->id)->whereIn('permission_id', $ids)->count());
        $this->assertSame(0, DB::table('role_permission')->where('role_id', $custom->id)->count());
        $this->assertDatabaseCount('user_role', 0);
    }
}
