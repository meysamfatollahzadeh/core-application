<?php

namespace Tests\Feature\Login;

use App\Modules\Login\Models\Member;
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
        $this->assertSame(['primary'], Member::pluck('user_source_slug')->unique()->values()->all());
    }

    public function test_unknown_numeric_source_identifier_is_not_accepted(): void
    {
        $source = UserSource::factory()->create(['slug' => 'primary']);
        Http::preventStrayRequests();
        $this->artisan('login:import-members', ['--source' => (string) $source->id])->assertFailed();
        Http::assertNothingSent();
    }
}
