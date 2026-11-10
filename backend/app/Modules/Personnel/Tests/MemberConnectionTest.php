<?php

namespace App\Modules\Personnel\Tests;

use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\Role;
use App\Modules\Login\Actions\ManageMemberConnections;
use App\Modules\Login\Http\Middleware\VerifyProviderGrant;
use App\Modules\Login\Models\Member;
use App\Modules\Login\Models\User;
use App\Modules\Login\Models\UserSource;
use App\Modules\Organization\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MemberConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function login(array $keys, ?int $company = null): User
    {
        $user = User::factory()->create();
        $role = Role::create(['key' => 'operator-'.Role::count(), 'title' => 'Operator']);
        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['title' => $key]);
            DB::table('role_permission')->insert(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }
        DB::table($company ? 'company_user_role' : 'user_role')->insert(['role_id' => $role->id, 'user_id' => $user->id, ...($company ? ['company_id' => $company] : [])]);
        $this->withoutMiddleware(VerifyProviderGrant::class);
        Sanctum::actingAs($user, ['login:access']);
        Http::preventStrayRequests();

        return $user;
    }

    private function source(string $slug = 'primary'): UserSource
    {
        $source = UserSource::factory()->create(['slug' => $slug]);
        $source->update(['data' => [...$source->data, 'import_client_id' => 'reader', 'import_client_secret' => 'private', 'admin_base_url' => 'https://directory.example/admin/realms/'.$slug]]);

        return $source;
    }

    private function remote(array $profile = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn ($r) => str_ends_with($r->url(), '/token') ? Http::response(['access_token' => 'private']) : Http::response(['id' => 'subject', 'username' => 'remote', 'firstName' => 'Remote', 'enabled' => true, ...$profile]));
    }

    private function token(Member $member, string $name): int
    {
        return $member->user->tokens()->create(['name' => $name, 'token' => hash('sha256', $name), 'abilities' => ['login:access'], 'member_id' => $member->id, 'provider_config_hash' => $member->userSource->fingerprint(), 'oidc_data' => [], 'expires_at' => now()->addHour()])->id;
    }

    public function test_creation_is_independent_and_rejects_invalid_profiles_without_partial_writes(): void
    {
        $actor = $this->login(['personnel.view', 'personnel.create']);
        foreach ([[], ['name' => '   ', 'building_area_id' => $actor->building_area_id], ['name' => 'Valid', 'building_area_id' => 999999], ['name' => str_repeat('x', 256), 'building_area_id' => $actor->building_area_id]] as $body) {
            $this->postJson('/api/personnel', $body)->assertUnprocessable();
        }
        $id = $this->postJson('/api/personnel', ['name' => ' Local Person ', 'building_area_id' => $actor->building_area_id])->assertCreated()->assertJsonPath('data.name', 'Local Person')->json('data.id');
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('members', 0);
        $this->assertSame(['theme' => 'auto'], User::findOrFail($id)->performances);
        $this->patchJson('/api/personnel/'.$id, ['name' => 'Denied'])->assertForbidden();
        $this->getJson('/api/personnel/'.$id.'/members')->assertForbidden();
        $this->getJson('/api/organization/location-options')->assertOk();
    }

    public function test_profile_edit_and_company_only_grants_do_not_authorize_new_operations(): void
    {
        $user = $this->login(['personnel.update']);
        $this->postJson('/api/personnel', ['name' => 'Denied', 'building_area_id' => $user->building_area_id])->assertForbidden();
        $this->getJson('/api/personnel/'.$user->id.'/member-sources')->assertForbidden();
        $company = Company::create(['title' => 'A']);
        $actor = $this->login(['personnel.create', 'personnel.members.manage'], $company->id);
        $this->postJson('/api/personnel', ['name' => 'Denied', 'building_area_id' => $actor->building_area_id])->assertForbidden();
        $source = $this->source();
        $this->getJson('/api/personnel/'.$actor->id.'/member-sources/'.$source->slug.'/accounts?search=remote')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_attach_preserves_local_profile_and_allows_different_sources_only(): void
    {
        $user = $this->login(['personnel.members.manage']);
        $name = $user->name;
        $this->source();
        $this->source('secondary');
        $this->remote();
        $base = '/api/personnel/'.$user->id;
        $this->postJson($base.'/members', ['source_slug' => 'primary', 'uuid' => 'subject'])->assertCreated()->assertJsonPath('data.source.slug', 'primary')->assertJsonMissingPath('data.source.id');
        $this->postJson($base.'/members', ['source_slug' => 'secondary', 'uuid' => 'subject'])->assertCreated();
        $this->postJson($base.'/members', ['source_slug' => 'primary', 'uuid' => 'other'])->assertConflict()->assertJsonPath('error', 'user_source_occupied');
        $this->getJson($base.'/member-sources')->assertOk()->assertJsonPath('data.0.can_search', false)->assertJsonPath('data.1.can_search', false);
        $this->assertSame($name, $user->fresh()->name);
        $this->assertDatabaseCount('members', 2);
        $other = User::factory()->create();
        $this->postJson('/api/personnel/'.$other->id.'/members', ['source_slug' => 'primary', 'uuid' => 'subject'])->assertConflict()->assertJsonPath('error', 'identity_already_connected');
    }

    public function test_occupied_and_inactive_sources_are_blocked_before_remote_search(): void
    {
        $user = $this->login(['personnel.members.manage']);
        $source = $this->source();
        Member::factory()->create(['user_id' => $user->id, 'user_source_id' => $source->id]);
        $base = '/api/personnel/'.$user->id;
        $this->getJson($base.'/member-sources/primary/accounts?search=remote')->assertConflict()->assertJsonPath('error', 'user_source_occupied');
        $source->update(['is_active' => false]);
        $this->getJson($base.'/member-sources/primary/accounts?search=remote')->assertConflict()->assertJsonPath('error', 'source_inactive');
        $this->getJson($base.'/members')->assertOk()->assertJsonPath('data.0.source.is_active', false)->assertJsonPath('data.0.profile_state', 'source_inactive');
        $this->getJson($base.'/member-sources')->assertOk()->assertJsonCount(0, 'data');
        Http::assertNothingSent();
    }

    public function test_search_exposes_owner_but_not_secrets_and_excludes_service_accounts(): void
    {
        $user = $this->login(['personnel.members.manage']);
        $source = $this->source();
        $owner = Member::factory()->create(['user_source_id' => $source->id, 'uuid' => 'owned']);
        Http::fake(fn ($r) => str_ends_with($r->url(), '/token') ? Http::response(['access_token' => 'private']) : Http::response([
            ['id' => 'owned', 'username' => 'owned', 'enabled' => true, 'attributes' => ['private']],
            ['id' => 'disabled', 'username' => 'disabled', 'enabled' => false],
            ['id' => 'service', 'username' => 'service-account-reader', 'enabled' => true],
            ['id' => 'free', 'username' => 'free', 'enabled' => true],
        ]));
        $this->getJson('/api/personnel/'.$user->id.'/member-sources/primary/accounts?search=account')->assertOk()
            ->assertJsonCount(3, 'data')->assertJsonPath('data.0.can_connect', false)->assertJsonPath('data.0.owner.name', $owner->user->name)
            ->assertJsonPath('data.1.reasons.0', 'account_disabled')->assertJsonPath('data.2.can_connect', true)->assertJsonMissingPath('data.0.attributes');
    }

    public function test_attach_rechecks_remote_eligibility_and_source_configuration(): void
    {
        $user = $this->login(['personnel.members.manage']);
        $source = $this->source();
        $url = '/api/personnel/'.$user->id.'/members';
        foreach ([['enabled' => false], ['username' => 'service-account-reader']] as $profile) {
            $this->remote($profile);
            $this->postJson($url, ['source_slug' => 'primary', 'uuid' => 'subject'])->assertUnprocessable()->assertJsonPath('error', 'account_ineligible');
        }
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($r) use ($source) {
            if (str_ends_with($r->url(), '/token')) {
                return Http::response(['access_token' => 'private']);
            }
            $source->update(['data' => [...$source->data, 'import_client_secret' => 'changed']]);

            return Http::response(['id' => 'subject', 'username' => 'valid', 'enabled' => true]);
        });
        $this->postJson($url, ['source_slug' => 'primary', 'uuid' => 'subject'])->assertConflict()->assertJsonPath('error', 'source_changed');
        $this->assertDatabaseCount('members', 0);
    }

    public function test_detach_is_local_selective_and_allows_last_member_and_inactive_source(): void
    {
        $user = $this->login(['personnel.members.manage']);
        $a = Member::factory()->create(['user_id' => $user->id]);
        $b = Member::factory()->create(['user_id' => $user->id]);
        $a->userSource->update(['is_active' => false]);
        $first = $this->token($a, 'first');
        $this->token($a, 'second');
        $survivor = $this->token($b, 'survivor');
        $base = '/api/personnel/'.$user->id.'/members/';
        $this->deleteJson($base.$a->id)->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $first]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $survivor]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->deleteJson($base.$b->id)->assertNoContent();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => $user->name]);
        $this->assertDatabaseHas('user_role', ['user_id' => $user->id]);
        $this->assertDatabaseCount('members', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Http::assertNothingSent();
    }

    public function test_detach_checks_nested_ownership_and_rolls_back_token_deletion_on_failure(): void
    {
        $user = $this->login(['personnel.members.manage']);
        $member = Member::factory()->create();
        $id = $this->token($member, 'preserved');
        $this->deleteJson('/api/personnel/'.$user->id.'/members/'.$member->id)->assertNotFound();
        Event::listen('eloquent.deleting: '.Member::class, fn () => throw new \RuntimeException('Injected deletion failure'));
        try {
            app(ManageMemberConnections::class)->detach($member->user, $member);
            $this->fail('Injected deletion failure not raised');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected deletion failure', $e->getMessage());
        } finally {
            Event::forget('eloquent.deleting: '.Member::class);
        }
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $id]);
        $this->assertDatabaseHas('members', ['id' => $member->id]);
    }

    public function test_existing_connection_remains_visible_when_remote_directory_fails(): void
    {
        $user = $this->login(['personnel.members.manage']);
        $member = Member::factory()->create(['user_id' => $user->id, 'user_source_id' => $this->source()->id]);
        Http::fake(fn () => Http::response([], 503));
        $this->getJson('/api/personnel/'.$user->id.'/members')->assertOk()->assertJsonPath('data.0.uuid', $member->uuid)->assertJsonPath('data.0.profile_state', 'unavailable');
        $this->deleteJson('/api/personnel/'.$user->id.'/members/'.$member->id)->assertNoContent();
    }

    public function test_missing_remote_account_and_inactive_attach_never_create_a_connection(): void
    {
        $user = $this->login(['personnel.members.manage']);
        $source = $this->source();
        $url = '/api/personnel/'.$user->id.'/members';
        $source->update(['is_active' => false]);
        $this->postJson($url, ['source_slug' => 'primary', 'uuid' => 'gone'])->assertConflict()->assertJsonPath('error', 'source_inactive');
        Http::assertNothingSent();
        $source->update(['is_active' => true]);
        Http::fake(fn ($r) => str_ends_with($r->url(), '/token') ? Http::response(['access_token' => 'private']) : Http::response([], 404));
        $this->postJson($url, ['source_slug' => 'primary', 'uuid' => 'gone'])->assertUnprocessable()->assertJsonPath('error', 'account_ineligible');
        $this->assertDatabaseCount('members', 0);
    }
}
