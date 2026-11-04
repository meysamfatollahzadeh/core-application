<?php

namespace Tests\Feature\Login;

use App\Modules\Login\Models\Member;
use App\Modules\Login\Models\User;
use App\Modules\Login\Models\UserSource;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SourceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_reference_source_ids_and_secrets_remain_encrypted(): void
    {
        $source = UserSource::factory()->create(['slug' => 'primary']);
        $user = User::factory()->create();
        $member = Member::factory()->create(['user_source_id' => $source->id, 'user_id' => $user->id]);
        $this->assertSame($source->id, $member->user_source_id);
        $this->assertFalse(Schema::hasColumn('members', 'user_source_slug'));
        $this->assertSame('primary', $member->userSource->slug);
        $this->assertSame($member->id, $source->members()->first()->id);
        $this->assertSame($user->id, $member->user->id);
        $this->assertSame('test-secret', $source->fresh()->data['client_secret']);
        $this->assertStringNotContainsString('test-secret', $source->getRawOriginal('data'));
        $this->assertArrayNotHasKey('data', $source->toArray());
        $this->assertArrayNotHasKey('id', $source->toArray());
        $this->assertSame('slug', $source->getRouteKeyName());
    }

    public function test_same_external_subject_in_different_sources_does_not_merge_users(): void
    {
        $a = UserSource::factory()->create();
        $b = UserSource::factory()->create();
        $one = Member::factory()->create(['user_source_id' => $a->id, 'uuid' => 'f:federation:subject']);
        $two = Member::factory()->create(['user_source_id' => $b->id, 'uuid' => 'f:federation:subject']);
        $this->assertNotSame($one->user_id, $two->user_id);
        $this->assertDatabaseCount('members', 2);
    }

    public function test_slug_cannot_be_renamed_after_creation(): void
    {
        $source = UserSource::factory()->create();
        $this->expectException(ValidationException::class);
        $source->update(['slug' => 'renamed']);
    }

    public function test_numeric_source_foreign_key_rejects_an_unknown_source(): void
    {
        $this->expectException(QueryException::class);
        Member::factory()->create(['user_source_id' => 9223372036854775807]);
    }

    public function test_members_require_a_source_id(): void
    {
        $this->expectException(QueryException::class);
        Member::factory()->create(['user_source_id' => null]);
    }

    public function test_source_migration_round_trip_preserves_members_users_and_token_relationships(): void
    {
        $source = UserSource::factory()->create(['slug' => 'round-trip']);
        $member = Member::factory()->create(['user_source_id' => $source->id, 'uuid' => 'preserved-subject']);
        $token = $member->user->tokens()->create([
            'name' => 'migration-check', 'token' => hash('sha256', 'test-token'), 'abilities' => ['login:access'],
            'expires_at' => now()->addHour(), 'member_id' => $member->id, 'provider_sid' => 'preserved-session',
            'provider_config_hash' => $source->fingerprint(), 'oidc_data' => ['access_token' => 'test-upstream-token'],
        ]);
        $migration = require app_path('Modules/Login/Database/Migrations/2026_11_04_121157_use_user_source_id_in_members.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('members', 'user_source_id'));
        $old = DB::table('members')->where('id', $member->id)->first();
        $this->assertSame($source->slug, $old->user_source_slug);
        $this->assertSame($member->uuid, $old->uuid);
        $this->assertSame($member->user_id, $old->user_id);
        $migration->up();
        $this->assertFalse(Schema::hasColumn('members', 'user_source_slug'));
        $this->assertSame($source->id, $member->fresh()->user_source_id);
        $this->assertSame($source->id, $source->members()->firstOrFail()->user_source_id);
        $this->assertSame($member->user_id, $member->fresh()->user->id);
        $this->assertSame($member->id, $token->fresh()->member->id);
        $this->assertSame('test-upstream-token', $token->fresh()->oidc_data['access_token']);
        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_source_and_subject_are_unique_in_the_database(): void
    {
        $member = Member::factory()->create();
        $this->expectException(QueryException::class);
        Member::factory()->create(['user_source_id' => $member->user_source_id, 'uuid' => $member->uuid]);
    }
}
