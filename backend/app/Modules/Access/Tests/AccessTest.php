<?php

namespace App\Modules\Access\Tests;

use App\Modules\Access\Actions\ManageAccess;
use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\Role;
use App\Modules\Access\Services\EffectivePermissions;
use App\Modules\Login\Models\Member;
use App\Modules\Login\Models\User;
use App\Modules\Login\Models\UserSource;
use App\Modules\Organization\Actions\ManageOrganization;
use App\Modules\Personnel\Actions\ManagePersonnel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use DatabaseMigrations;

    private function role(array $keys): Role
    {
        $role = Role::create(['key' => 'r-'.Role::count(), 'title' => 'Role']);
        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['title' => $key]);
            DB::table('role_permission')->insert(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }

        return $role;
    }

    private function administrator(): User
    {
        $this->artisan('access:sync-permissions')->assertSuccessful();
        $user = User::factory()->create();
        DB::table('user_role')->insert(['user_id' => $user->id, 'role_id' => Role::where('key', 'system-administrator')->value('id')]);

        return $user;
    }

    public function test_effective_union_preserves_company_scope_and_does_not_inherit(): void
    {
        $u = User::factory()->create();
        $org = new ManageOrganization;
        $person = new ManagePersonnel;
        $a = $org->company(null, ['title' => 'A']);
        $b = $org->company(null, ['title' => 'B']);
        $p = $org->position($a, null, ['title' => 'Parent']);
        $child = $org->position($a, null, ['title' => 'Child', 'parent_position_id' => $p->id]);
        $person->assignment(null, $u, $child, [$p->id]);
        $read = $this->role(['articles.view']);
        $edit = $this->role(['articles.update']);
        DB::table('position_role')->insert(['position_id' => $p->id, 'role_id' => $read->id, 'scope' => 'global']);
        $access = new EffectivePermissions;
        $this->assertFalse($access->allows($u->id, 'articles.view'));
        DB::table('company_user_role')->insert(['company_id' => $a->id, 'user_id' => $u->id, 'role_id' => $read->id]);
        $this->assertTrue($access->allows($u->id, 'articles.view', $a->id));
        $this->assertFalse($access->allows($u->id, 'articles.view', $b->id));
        $this->assertFalse($access->allows($u->id, 'articles.view'));
        DB::table('position_role')->insert(['position_id' => $child->id, 'role_id' => $edit->id, 'scope' => 'global']);
        $this->assertTrue($access->allows($u->id, 'articles.update'));
        $this->assertTrue($access->allows($u->id, 'articles.update', $b->id));
        DB::table('position_role')->where('position_id', $child->id)->delete();
        $this->assertFalse($access->allows($u->id, 'articles.update'));
    }

    public function test_global_position_grant_cannot_be_edited_or_used_for_occupancy_by_company_admin(): void
    {
        $org = new ManageOrganization;
        $c = $org->company(null, ['title' => 'A']);
        $p = $org->position($c, null, ['title' => 'Powerful']);
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $grant = $this->role(['access.assign', 'user_positions.create']);
        $global = $this->role(['personnel.update']);
        DB::table('company_user_role')->insert(['company_id' => $c->id, 'user_id' => $actor->id, 'role_id' => $grant->id]);
        DB::table('position_role')->insert(['position_id' => $p->id, 'role_id' => $global->id, 'scope' => 'global']);
        $service = new ManageAccess(new EffectivePermissions);
        $service->positionRoles($actor->id, $p, []);
        $this->assertDatabaseHas('position_role', ['position_id' => $p->id, 'scope' => 'global']);
        try {
            $service->positionRoles($actor->id, $p, [['role_id' => $global->id, 'scope' => 'global']]);
            $this->fail('Escalation accepted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->expectException(HttpException::class);
        (new ManagePersonnel)->assignment(null, $target, $p, [], $actor->id);
    }

    public function test_nonmember_role_assignment_is_allowed_but_delegation_is_bounded(): void
    {
        $admin = $this->administrator();
        $target = User::factory()->create();
        $company = (new ManageOrganization)->company(null, ['title' => 'A']);
        $read = $this->role(['personnel.view']);
        $service = new ManageAccess(new EffectivePermissions);
        $service->userRoles($admin->id, $target->id, $company->id, [$read->id]);
        $this->assertDatabaseCount('user_positions', 0);
        $this->assertTrue((new EffectivePermissions)->allows($target->id, 'personnel.view', $company->id));
        $this->expectException(HttpException::class);
        $service->userRoles($target->id, $admin->id, $company->id, [$read->id]);
    }

    public function test_last_administrator_cannot_be_removed_through_role_or_assignment_mutations(): void
    {
        $admin = $this->administrator();
        $service = new ManageAccess(new EffectivePermissions);
        try {
            $service->userRoles($admin->id, $admin->id, null, []);
            $this->fail('Last admin removed');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $role = Role::where('key', 'system-administrator')->firstOrFail();
        try {
            $service->permissions($admin->id, $role, []);
            $this->fail('Last admin permissions removed');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertTrue((new EffectivePermissions)->hasAdministrator());
    }

    public function test_bootstrap_requires_exact_source_and_subject_and_dry_run_creates_no_grant(): void
    {
        $this->artisan('access:sync-permissions')->assertSuccessful();
        $source = UserSource::factory()->create();
        $member = Member::factory()->create(['user_source_id' => $source->id]);
        $args = ['--source' => $source->slug, '--subject' => $member->uuid];
        $this->artisan('access:bootstrap', [...$args, '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('user_role', 0);
        $this->artisan('access:bootstrap', $args)->assertSuccessful();
        $this->artisan('access:bootstrap',$args)->assertSuccessful();
        $this->assertDatabaseCount('user_role',1);
        $this->artisan('access:bootstrap',['--source' => $source->slug, '--subject' => 'unknown'])->assertFailed();
    }
}
