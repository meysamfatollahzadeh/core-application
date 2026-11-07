<?php

namespace App\Modules\Organization\Tests;

use App\Modules\Login\Models\Member;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseUpgradeTest extends TestCase
{
    use DatabaseMigrations;

    public function test_populated_phase_rollback_and_upgrade_preserve_login_identity_and_grants(): void
    {
        $member = Member::factory()->create();
        $token = $member->user->tokens()->create(['name' => 'upgrade', 'token' => hash('sha256', 'upgrade-check'), 'abilities' => ['login:access'], 'expires_at' => now()->addHour(), 'member_id' => $member->id, 'provider_sid' => 'unchanged-session', 'provider_config_hash' => $member->userSource->fingerprint(), 'oidc_data' => ['access_token' => 'unchanged-upstream']]);
        $paths = [
            'Organization/Database/Migrations/2026_11_07_000001_create_organization.php',
            'Personnel/Database/Migrations/2026_11_07_000002_create_personnel.php',
            'Access/Database/Migrations/2026_11_07_000003_create_access.php',
            'Organization/Database/Migrations/2026_11_07_000004_organization_invariants.php',
            'Organization/Database/ContractMigrations/2026_11_07_000005_require_user_location.php',
        ];
        $migrations = array_map(fn ($p) => require app_path('Modules/'.$p), $paths);
        foreach (array_reverse($migrations) as $migration) {
            DB::transaction(fn () => $migration->down());
        }
        $this->assertSame($member->user_id, $token->fresh()->tokenable_id);
        foreach (array_slice($migrations, 0, 4) as $migration) {
            DB::transaction(fn () => $migration->up());
        }
        try {
            DB::transaction(fn () => $migrations[4]->up());
            $this->fail('Unassigned users accepted');
        } catch (\RuntimeException) {
            $this->assertTrue(true);
        }
        $this->artisan('organization:prepare-locations', ['--building' => 'Explicit upgrade location'])->assertSuccessful();
        DB::transaction(fn () => $migrations[4]->up());
        $this->assertSame($member->uuid, $member->fresh()->uuid);
        $this->assertSame($member->user_source_id, $member->fresh()->user_source_id);
        $this->assertSame($member->user_id, $member->fresh()->user_id);
        $this->assertSame('unchanged-upstream', $token->fresh()->oidc_data['access_token']);
        $this->assertSame('unchanged-session', $token->fresh()->provider_sid);
        $this->assertNotNull($member->user->fresh()->building_area_id);
    }
}
