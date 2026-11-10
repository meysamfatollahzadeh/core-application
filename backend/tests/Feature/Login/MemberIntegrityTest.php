<?php

namespace Tests\Feature\Login;

use App\Modules\Login\Models\Member;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemberIntegrityTest extends TestCase
{
    use DatabaseMigrations;

    public function test_second_identity_from_same_source_is_rejected_by_database(): void
    {
        $member = Member::factory()->create();
        $this->expectException(QueryException::class);
        Member::factory()->create(['user_id' => $member->user_id, 'user_source_id' => $member->user_source_id]);
    }

    public function test_migration_refuses_conflicts_without_removing_data_and_can_be_reapplied(): void
    {
        $migration = require app_path('Modules/Login/Database/Migrations/2026_11_10_000007_limit_members_per_user_source.php');
        $member = Member::factory()->create();
        $migration->down();
        $other = Member::factory()->create(['user_id' => $member->user_id, 'user_source_id' => $member->user_source_id]);
        try {
            $migration->up();
            $this->fail('Conflicting upgrade was allowed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reconcile explicitly', $e->getMessage());
            $this->assertDatabaseCount('members', 2);
        }
        $other->delete();
        $migration->up();
        $this->assertSame($member->uuid, $member->fresh()->uuid);
        $this->assertTrue(DB::table('pg_indexes')->where('indexname', 'members_user_source_unique')->exists());
    }
}
