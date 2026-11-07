<?php

namespace App\Modules\Organization\Tests;

use App\Modules\Organization\Actions\ManageOrganization;
use App\Modules\Organization\Models\BuildingArea;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrganizationActionsTest extends TestCase
{
    use DatabaseMigrations;

    public function test_building_lifecycle_protects_default_and_restricts_nonempty_delete(): void
    {
        $service = new ManageOrganization;
        $building = $service->building(null, ['title' => 'Shared']);
        $default = BuildingArea::where('building_id', $building->id)->firstOrFail();
        $this->assertTrue($default->is_default);
        try {
            $service->delete($default);
            $this->fail('Default deleted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $extra = $service->area($building, null, ['title' => 'Floor 1']);
        try {
            $service->delete($building);
            $this->fail('Nonempty building deleted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $service->delete($extra);
        $service->delete($building);
        $this->assertDatabaseCount('buildings', 0);
    }

    public function test_chart_actions_reject_cycles_and_cross_company_parent(): void
    {
        $service = new ManageOrganization;
        $a = $service->company(null, ['title' => 'A']);
        $b = $service->company(null, ['title' => 'B']);
        $root = $service->position($a, null, ['title' => 'Root']);
        $child = $service->position($a, null, ['title' => 'Child', 'parent_position_id' => $root->id]);
        try {
            $service->position($b, null, ['title' => 'Invalid', 'parent_position_id' => $root->id]);
            $this->fail('Cross company');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        try {
            $service->position($a, $root, ['parent_position_id' => $child->id]);
            $this->fail('Cycle accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertNull($root->fresh()->parent_position_id);
    }
}
