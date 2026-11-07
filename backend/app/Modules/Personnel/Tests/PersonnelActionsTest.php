<?php

namespace App\Modules\Personnel\Tests;

use App\Modules\Login\Models\User;
use App\Modules\Organization\Actions\ManageOrganization;
use App\Modules\Personnel\Actions\ManagePersonnel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PersonnelActionsTest extends TestCase
{
    use DatabaseMigrations;

    public function test_shared_positions_allow_distinct_managers_and_atomic_replacement(): void
    {
        $org = new ManageOrganization;
        $people = new ManagePersonnel;
        $c = $org->company(null, ['title' => 'A']);
        $root = $org->position($c, null, ['title' => 'Root']);
        $child = $org->position($c, null, ['title' => 'Shared', 'parent_position_id' => $root->id]);
        $other = $org->position($org->company(null, ['title' => 'B']), null, ['title' => 'External']);
        $a = User::factory()->create();
        $b = User::factory()->create();
        $aa = $people->assignment(null, $a, $child, [$root->id]);
        $bb = $people->assignment(null, $b, $child, [$other->id]);
        try {
            $people->assignment($aa, $a, $child, []);
            $this->fail('Empty manager accepted');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertDatabaseHas('user_position_reports', ['user_position_id' => $aa->id, 'report_to_position_id' => $root->id]);
        $people->assignment($aa, $a, $child, [$other->id]);
        $this->assertSame(2, DB::table('user_positions')->count());
        $this->assertDatabaseHas('user_position_reports', ['user_position_id' => $bb->id, 'report_to_position_id' => $other->id]);
        $this->assertDatabaseMissing('user_position_reports', ['user_position_id' => $aa->id, 'report_to_position_id' => $root->id]);
    }
}
