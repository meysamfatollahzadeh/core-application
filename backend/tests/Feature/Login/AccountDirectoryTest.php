<?php

namespace Tests\Feature\Login;

use App\Modules\Login\Models\UserSource;
use App\Modules\Login\Services\AccountDirectory;
use App\Modules\Login\Services\LoginFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccountDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function source(): UserSource
    {
        $source = UserSource::factory()->create();
        $source->update(['data' => [...$source->data, 'import_client_id' => 'reader', 'import_client_secret' => 'secret', 'admin_base_url' => 'https://directory.example/admin/realms/test']]);
        Http::preventStrayRequests();

        return $source;
    }

    public function test_search_passes_combined_search_and_pagination_without_excluding_disabled_accounts(): void
    {
        $source = $this->source();
        Http::fake(fn ($r) => str_ends_with($r->url(), '/token') ? Http::response(['access_token' => 'private']) : Http::response([
            ['id' => 'service', 'username' => 'service-account-reader', 'enabled' => true],
            ['id' => 'disabled', 'username' => 'alice', 'firstName' => 'Alice', 'lastName' => 'Smith', 'email' => 'alice@example.org', 'enabled' => false, 'credentials' => ['secret']],
            ['id' => 'more', 'username' => 'more'],
        ]));
        $result = app(AccountDirectory::class)->search($source, 'Alice Smith', 2, 2);
        $this->assertTrue($result['has_more']);
        $this->assertCount(1, $result['data']);
        $this->assertFalse($result['data'][0]['enabled']);
        $this->assertSame('alice@example.org', $result['data'][0]['email']);
        $this->assertArrayNotHasKey('credentials', $result['data'][0]);
        Http::assertSent(fn ($r) => isset($r['search']) && $r['search'] === 'Alice Smith' && (int) $r['first'] === 2 && (int) $r['max'] === 3 && ! isset($r['enabled']));
    }

    public function test_missing_remote_account_and_configuration_are_distinct_safe_failures(): void
    {
        $source = $this->source();
        Http::fake(fn ($r) => str_ends_with($r->url(), '/token') ? Http::response(['access_token' => 'private']) : Http::response([], 404));
        $this->assertNull(app(AccountDirectory::class)->account($source, 'gone'));
        $this->expectException(LoginFailure::class);
        app(AccountDirectory::class)->search(UserSource::factory()->create(), 'Alice', 1, 25);
    }

    public function test_service_accounts_and_mismatched_subjects_cannot_be_disguised_by_lookup(): void
    {
        $source = $this->source();
        Http::fake(fn ($r) => str_ends_with($r->url(), '/token') ? Http::response(['access_token' => 'private']) : Http::response(['id' => 'service', 'username' => 'robot', 'serviceAccountClientId' => 'reader', 'enabled' => true]));
        $this->assertTrue(app(AccountDirectory::class)->account($source, 'service')['is_service']);
        $this->expectException(LoginFailure::class);
        app(AccountDirectory::class)->account($source, 'forged');
    }
}
