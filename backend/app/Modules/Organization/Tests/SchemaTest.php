<?php

namespace App\Modules\Organization\Tests;

use App\Modules\Login\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use DatabaseMigrations;

    private function company(): int
    {
        return DB::table('companies')->insertGetId(['title' => 'Company']);
    }

    private function position(int $company, ?int $parent = null): int
    {
        return DB::table('positions')->insertGetId(['company_id' => $company, 'title' => 'Position', 'parent_position_id' => $parent]);
    }

    public function test_chart_rejects_cross_company_parent_and_cycles(): void
    {
        $a = $this->position($this->company());
        $b = $this->position($this->company());
        try {
            DB::table('positions')->where('id', $b)->update(['parent_position_id' => $a]);
            $this->fail('Cross-company parent accepted');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        $child = $this->position(DB::table('positions')->where('id', $a)->value('company_id'), $a);
        $this->expectException(QueryException::class);
        DB::table('positions')->where('id', $a)->update(['parent_position_id' => $child]);
    }

    public function test_building_and_default_must_be_created_atomically(): void
    {
        $this->expectException(QueryException::class);
        DB::table('buildings')->insert(['title' => 'Missing default']);
    }

    public function test_locations_are_required_and_referenced_area_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        try {
            User::create(['name' => 'No location']);
            $this->fail('Null location accepted');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        $this->expectException(QueryException::class);
        DB::table('building_areas')->where('id', $user->building_area_id)->delete();
    }

    public function test_nonroot_assignment_requires_reports_but_atomic_cross_company_reporting_is_valid(): void
    {
        $user = User::factory()->create();
        $company = $this->company();
        $root = $this->position($company);
        $child = $this->position($company, $root);
        $external = $this->position($this->company());
        try {
            DB::table('user_positions')->insert(['user_id' => $user->id, 'position_id' => $child]);
            $this->fail('Missing reports accepted');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        DB::transaction(function () use ($user, $child, $external) {
            $assignment = DB::table('user_positions')->insertGetId(['user_id' => $user->id, 'position_id' => $child]);
            DB::table('user_position_reports')->insert(['user_position_id' => $assignment, 'report_to_position_id' => $external]);
        });
        $this->assertDatabaseCount('user_positions', 1);
    }

    public function test_reciprocal_reporting_and_another_self_held_position_are_allowed(): void
    {
        $user = User::factory()->create();
        $a = $this->position($this->company());
        $b = $this->position($this->company());
        DB::transaction(function () use ($user, $a, $b) {
            $aa = DB::table('user_positions')->insertGetId(['user_id' => $user->id, 'position_id' => $a]);
            $bb = DB::table('user_positions')->insertGetId(['user_id' => $user->id, 'position_id' => $b]);
            DB::table('user_position_reports')->insert([['user_position_id' => $aa, 'report_to_position_id' => $b], ['user_position_id' => $bb, 'report_to_position_id' => $a]]);
        });
        $this->assertDatabaseCount('user_position_reports', 2);
        $this->expectException(QueryException::class);
        DB::table('user_position_reports')->insert(['user_position_id' => DB::table('user_positions')->where('position_id', $a)->value('id'), 'report_to_position_id' => $a]);
    }

    public function test_location_preparation_dry_run_and_repeat_do_not_move_existing_users(): void
    {
        $user = User::factory()->create();
        $this->artisan('organization:prepare-locations', ['--building' => 'Proposed', '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseMissing('buildings', ['title' => 'Proposed']);
        $this->artisan('organization:prepare-locations', ['--building' => 'Proposed'])->assertSuccessful();
        $this->artisan('organization:prepare-locations', ['--building' => 'Proposed'])->assertSuccessful();
        $this->assertSame($user->building_area_id, $user->fresh()->building_area_id);
        $this->assertSame(1, DB::table('buildings')->where('title', 'Proposed')->count());
    }

    public function test_populated_location_upgrade_preserves_identity_and_refuses_incomplete_mapping(): void
    {
        $user = User::factory()->create();
        DB::statement('ALTER TABLE users ALTER COLUMN building_area_id DROP NOT NULL');
        DB::table('users')->where('id', $user->id)->update(['building_area_id' => null]);
        $this->artisan('organization:prepare-locations')->assertFailed();
        $this->assertNull($user->fresh()->building_area_id);
        $this->artisan('organization:prepare-locations', ['--fallback-area' => $user->building_area_id])->assertSuccessful();
        DB::statement('ALTER TABLE users ALTER COLUMN building_area_id SET NOT NULL');
        $this->assertSame($user->id, $user->fresh()->id);
        $this->assertSame($user->building_area_id,$user->fresh()->building_area_id);
    }
}
