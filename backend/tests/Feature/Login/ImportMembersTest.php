<?php

namespace Tests\Feature\Login;

use App\Modules\Login\Models\Member;
use App\Modules\Login\Models\User;
use App\Modules\Login\Models\UserSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportMembersTest extends TestCase
{
    use RefreshDatabase;

    public function test_paginated_import_is_repeat_safe_and_dry_run_writes_nothing(): void
    {
        $source = UserSource::factory()->create(['slug' => 'primary']);
        $source->update(['data' => array_merge($source->data, ['page_size' => 1, 'import_client_id' => 'importer', 'import_client_secret' => 'secret', 'admin_base_url' => 'https://idp.example.org/admin/realms/test'])]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/token')) {
                return Http::response(['access_token' => 'admin-token']);
            }
            $first = $request['first'];

            return Http::response($first < 2 ? [['id' => 'subject-'.$first, 'username' => 'same-name', 'enabled' => true]] : []);
        });
        $this->artisan('login:import-members', ['--source' => 'primary', '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
        $this->artisan('login:import-members', ['--source' => 'primary'])->assertSuccessful();
        $this->artisan('login:import-members', ['--source' => 'primary'])->assertSuccessful();
        $this->assertDatabaseCount('members', 2);
        $this->assertDatabaseCount('users', 2);
        $this->assertSame([$source->id], Member::pluck('user_source_id')->unique()->values()->all());
    }

    public function test_explicit_mapping_links_existing_user_and_disabled_service_accounts_are_skipped(): void
    {
        $source = UserSource::factory()->create(['slug' => 'mapped']);
        $source->update(['data' => array_merge($source->data, ['import_client_id' => 'importer', 'import_client_secret' => 'secret', 'admin_base_url' => 'https://idp.example.org/admin/realms/test'])]);
        $user = User::factory()->create(['name' => 'Keep local name']);
        $path = tempnam(sys_get_temp_dir(), 'member-map-');
        file_put_contents($path, json_encode(['mapped-subject' => $user->id], JSON_THROW_ON_ERROR));
        Http::preventStrayRequests();
        Http::fake(fn ($request) => str_ends_with($request->url(), '/token') ? Http::response(['access_token' => 'import-token']) : Http::response([
            ['id' => 'mapped-subject', 'enabled' => true, 'username' => 'same-name'],
            ['id' => 'disabled-subject', 'enabled' => false, 'username' => 'disabled'],
            ['id' => 'service-subject', 'enabled' => true, 'username' => 'service-account-importer'],
        ]));
        try {
            $this->artisan('login:import-members', ['--source' => 'mapped', '--mapping-file' => $path])->assertSuccessful();
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('members', 1);
            $this->assertSame($user->id, Member::firstOrFail()->user_id);
            $this->assertSame('Keep local name', $user->fresh()->name);
        } finally {
            unlink($path);
        }
    }

    public function test_unknown_numeric_source_identifier_is_not_accepted(): void
    {
        $source = UserSource::factory()->create(['slug' => 'primary']);
        Http::preventStrayRequests();
        $this->artisan('login:import-members', ['--source' => (string) $source->id])->assertFailed();
        Http::assertNothingSent();
    }
}
