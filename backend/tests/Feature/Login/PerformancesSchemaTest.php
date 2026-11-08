<?php

namespace Tests\Feature\Login;

use App\Modules\Login\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PerformancesSchemaTest extends TestCase
{
    use DatabaseMigrations;

    public function test_existing_and_new_users_receive_auto_without_changing_identity_or_location(): void
    {
        $user = User::factory()->create();
        $migration = require app_path('Modules/Login/Database/Migrations/2026_11_08_000006_add_user_performances.php');
        $migration->down();
        $before = DB::table('users')->where('id', $user->id)->first();
        $migration->up();
        $fresh = $user->fresh();
        $this->assertSame(['theme' => 'auto'], $fresh->performances);
        $this->assertSame($before->name, $fresh->name);
        $this->assertSame($before->building_area_id, $fresh->building_area_id);
        $this->assertSame(['theme' => 'auto'], User::factory()->create()->fresh()->performances);
    }

    public function test_database_rejects_a_missing_or_invalid_theme(): void
    {
        $user = User::factory()->create();
        foreach (['{}', '{"theme":null}', '{"theme":"system"}', '[]'] as $invalid) {
            try {
                DB::table('users')->where('id', $user->id)->update(['performances' => $invalid]);
                $this->fail('Invalid performance accepted');
            } catch (QueryException) {
                $this->assertSame(['theme' => 'auto'], $user->fresh()->performances);
            }
        }
    }

    public function test_anonymous_preference_updates_are_rejected(): void
    {
        $this->patchJson('/api/login/performances', ['theme' => 'dark'])->assertUnauthorized();
    }
}
