<?php

namespace App\Modules\Access\Tests;

use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\Role;
use App\Modules\Access\Services\EffectivePermissions;
use App\Modules\Login\Http\Middleware\VerifyProviderGrant;
use App\Modules\Login\Models\User;
use App\Modules\Organization\Actions\ManageOrganization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManagementApiTest extends TestCase
{
    use DatabaseMigrations;

    private function login(array $keys = [], ?int $company = null): User
    {
        $user = User::factory()->create();
        $role = Role::create(['key' => 'role-'.Role::count(), 'title' => 'Role']);
        foreach ($keys as $key) {
            $p = Permission::firstOrCreate(['key' => $key], ['title' => $key]);
            DB::table('role_permission')->insert(['role_id' => $role->id, 'permission_id' => $p->id]);
        }
        DB::table($company ? 'company_user_role' : 'user_role')->insert(['role_id' => $role->id, 'user_id' => $user->id, ...($company ? ['company_id' => $company] : [])]);
        $this->withoutMiddleware(VerifyProviderGrant::class);
        Sanctum::actingAs($user, ['login:access']);

        return $user;
    }

    public function test_business_routes_require_authentication(): void
    {
        $this->getJson('/api/companies')->assertUnauthorized();
        $this->getJson('/api/access/me')->assertUnauthorized();
    }

    public function test_company_grants_never_open_global_routes_or_other_companies(): void
    {
        $org = new ManageOrganization;
        $a = $org->company(null, ['title' => 'A']);
        $b = $org->company(null, ['title' => 'B']);
        $this->login(['positions.view', 'positions.create', 'personnel.view'], $a->id);
        $this->getJson('/api/companies')->assertForbidden();
        $this->getJson('/api/personnel')->assertForbidden();
        $this->getJson("/api/companies/$a->id/positions")->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson("/api/companies/$a->id/positions", ['title' => 'Position', 'company_id' => $b->id])->assertCreated()->assertJsonPath('data.company_id', $a->id);
        $this->getJson("/api/companies/$b->id/positions")->assertForbidden();
        $this->getJson('/api/access/me')->assertOk()->assertJsonCount(1, 'data.visible_companies');
    }

    public function test_nested_object_ownership_is_checked_even_with_global_grant(): void
    {
        $org = new ManageOrganization;
        $a = $org->company(null, ['title' => 'A']);
        $b = $org->company(null, ['title' => 'B']);
        $p = $org->position($b, null, ['title' => 'B position']);
        $this->login(['positions.view', 'positions.update']);
        $this->getJson("/api/companies/$a->id/positions/$p->id")->assertNotFound();
        $this->patchJson("/api/companies/$a->id/positions/$p->id", ['title' => 'Hijack'])->assertNotFound();
        $this->assertSame('B position', $p->fresh()->title);
    }

    public function test_building_api_creates_and_protects_default_area(): void
    {
        $this->login(['buildings.create', 'buildings.view', 'buildings.update', 'buildings.delete']);
        $id = $this->postJson('/api/buildings', ['title' => 'Shared'])->assertCreated()->json('data.id');
        $area = $this->getJson("/api/buildings/$id/areas")->assertOk()->assertJsonPath('data.0.title', 'نامشخص')->json('data.0.id');
        $this->patchJson("/api/buildings/$id/areas/$area", ['title' => 'Changed'])->assertConflict();
        $this->deleteJson("/api/buildings/$id/areas/$area")->assertConflict();
        $this->deleteJson("/api/buildings/$id")->assertNoContent();
    }

    public function test_company_personnel_is_distinct_union_of_positions_and_direct_roles(): void
    {
        $org = new ManageOrganization;
        $a = $org->company(null, ['title' => 'A']);
        $b = $org->company(null, ['title' => 'B']);
        $this->login(['personnel.view'], $a->id);
        $u = User::factory()->create();
        $v = User::factory()->create();
        $p = $org->position($a, null, ['title' => 'A']);
        $q = $org->position($b, null, ['title' => 'B']);
        DB::table('user_positions')->insert([['user_id' => $u->id, 'position_id' => $p->id], ['user_id' => $v->id, 'position_id' => $q->id]]);
        $role = Role::first();
        DB::table('company_user_role')->insert(['company_id' => $a->id, 'user_id' => $u->id, 'role_id' => $role->id]);
        $this->getJson("/api/companies/$a->id/personnel")->assertOk()->assertJsonPath('total', 2)->assertJsonMissing(['id' => $v->id, 'name' => $v->name]);
        $this->patchJson("/api/personnel/$u->id", ['name' => 'Denied'])->assertForbidden();
    }

    public function test_assignment_api_requires_reports_and_cannot_change_its_position(): void
    {
        $org = new ManageOrganization;
        $a = $org->company(null, ['title' => 'A']);
        $root = $org->position($a, null, ['title' => 'Root']);
        $child = $org->position($a, null, ['title' => 'Child', 'parent_position_id' => $root->id]);
        $actor = $this->login(['user_positions.create', 'user_positions.update', 'user_positions.view', 'user_positions.delete'], $a->id);
        $body = ['user_id' => $actor->id, 'position_id' => $child->id, 'report_position_ids' => []];
        $this->postJson("/api/companies/$a->id/user-positions", $body)->assertUnprocessable();
        $body['report_position_ids'] = [$root->id];
        $id = $this->postJson("/api/companies/$a->id/user-positions", $body)->assertCreated()->json('data.id');
        $this->putJson("/api/companies/$a->id/user-positions/$id", ['report_position_ids' => [$child->id]])->assertUnprocessable();
        $this->getJson("/api/companies/$a->id/user-positions/$id")->assertOk()->assertJsonPath('data.report_position_ids.0', $root->id);
        $this->putJson("/api/companies/$a->id/user-positions/$id", ['position_id' => $root->id, 'report_position_ids' => []])->assertUnprocessable();
        $this->deleteJson("/api/companies/$a->id/user-positions/$id")->assertNoContent();
    }

    public function test_article_contract_uses_test_only_routes_and_real_permission_checks(): void
    {
        $org = new ManageOrganization;
        $a = $org->company(null, ['title' => 'A']);
        $b = $org->company(null, ['title' => 'B']);
        Route::middleware('auth:sanctum')->get('/api/articles', function (Request $r) {
            app(EffectivePermissions::class)->authorize($r->user()->id, 'articles.view');

            return ['ok' => true];
        });
        Route::middleware('auth:sanctum')->get('/api/companies/{company}/articles', function (Request $r, int $company) {
            app(EffectivePermissions::class)->authorize($r->user()->id, 'articles.view', $company);

            return ['company_id' => $company];
        });
        $this->login(['articles.view'], $a->id);
        $this->getJson('/api/articles')->assertForbidden();
        $this->getJson("/api/companies/$a->id/articles")->assertOk();
        $this->getJson("/api/companies/$b->id/articles")->assertForbidden();
        $this->login(['articles.view']);
        $this->getJson('/api/articles')->assertOk();
        $this->getJson("/api/companies/$b->id/articles")->assertOk()->assertJsonPath('company_id', $b->id);
    }

    public function test_selected_option_labels_are_retrievable_without_bypassing_company_scope(): void
    {
        $org = new ManageOrganization;
        $a = $org->company(null, ['title' => 'A']);
        $b = $org->company(null, ['title' => 'B']);
        $this->login(['access.assign'], $a->id);
        DB::transaction(function () use ($a) {
            for ($i = 0; $i < 105; $i++) {
                DB::table('positions')->insert(['company_id' => $a->id, 'title' => 'Position '.$i]);
            }
        });
        $last = DB::table('positions')->where('company_id', $a->id)->max('id');
        $foreign = $org->position($b, null, ['title' => 'Foreign']);
        $this->getJson('/api/organization/company-position-options?company_id='.$a->id.'&selected='.$last)->assertOk()->assertJsonPath('data.0.id', $last);
        $this->getJson('/api/organization/company-position-options?company_id='.$a->id.'&selected='.$foreign->id)->assertOk()->assertJsonMissing(['id' => $foreign->id, 'title' => 'Foreign']);
        $this->getJson('/api/organization/company-position-options?company_id='.$a->id.'&selected=invalid')->assertUnprocessable();
    }

    public function test_assignment_paths_include_all_ancestors_and_cross_company_manager_context(): void
    {
        $org = new ManageOrganization;
        $a = $org->company(null, ['title' => 'شرکت الف']);
        $b = $org->company(null, ['title' => 'شرکت ب']);
        $root = $org->position($a, null, ['title' => 'مدیریت']);
        $middle = $org->position($a, null, ['title' => 'فناوری', 'parent_position_id' => $root->id]);
        $leaf = $org->position($a, null, ['title' => 'کارشناس', 'parent_position_id' => $middle->id]);
        $foreignRoot = $org->position($b, null, ['title' => 'مدیریت']);
        $manager = $org->position($b, null, ['title' => 'فناوری', 'parent_position_id' => $foreignRoot->id]);
        $actor = $this->login(['user_positions.create', 'user_positions.view', 'user_positions.update'], $a->id);
        $path = 'شرکت الف / مدیریت / فناوری / کارشناس';
        $managerPath = 'شرکت ب / مدیریت / فناوری';
        $body = ['user_id' => $actor->id, 'position_id' => $leaf->id, 'report_position_ids' => [$manager->id]];
        $created = $this->postJson("/api/companies/$a->id/user-positions", $body)->assertCreated()
            ->assertJsonPath('data.position_title', 'کارشناس')->assertJsonPath('data.position_path', $path)
            ->assertJsonPath('data.report_titles.0', 'شرکت ب / فناوری')->assertJsonPath('data.report_paths.0', $managerPath);
        $id = $created->json('data.id');
        $this->getJson("/api/companies/$a->id/user-positions")->assertOk()->assertJsonPath('data.0.position_path', $path)->assertJsonPath('data.0.report_paths.0', $managerPath);
        $this->getJson("/api/companies/$a->id/user-positions/$id")->assertOk()->assertJsonPath('data.position_path', $path);
        $this->putJson("/api/companies/$a->id/user-positions/$id", ['report_position_ids' => [$root->id]])->assertOk()->assertJsonPath('data.report_paths.0', 'شرکت الف / مدیریت');
        $this->getJson('/api/organization/company-position-options?company_id='.$a->id.'&search='.urlencode('کارشناس'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.position_path', $path);
        $this->getJson('/api/organization/reporting-position-options?selected='.$manager->id.'&search='.urlencode('فناوری'))->assertOk()->assertJsonPath('data.0.id', $manager->id)->assertJsonPath('data.0.position_path', $managerPath);
        $this->getJson("/api/companies/$b->id/user-positions")->assertForbidden();
    }

    public function test_paginated_position_options_resolve_ancestors_outside_the_page(): void
    {
        $org = new ManageOrganization;
        $a = $org->company(null, ['title' => 'Company']);
        $root = $org->position($a, null, ['title' => 'Root']);
        $this->login(['user_positions.create'], $a->id);
        DB::transaction(function () use ($a, $root) {
            for ($i = 0; $i < 101; $i++) {
                DB::table('positions')->insert(['company_id' => $a->id, 'title' => 'Leaf '.$i, 'parent_position_id' => $root->id]);
            }
        });
        $last = DB::table('positions')->where('company_id', $a->id)->max('id');
        $this->getJson('/api/organization/company-position-options?company_id='.$a->id.'&page=2')->assertOk()->assertJsonPath('data.0.position_path', 'Company / Root / Leaf 99');
        $this->getJson('/api/organization/company-position-options?company_id='.$a->id.'&selected='.$last)->assertOk()->assertJsonPath('data.0.position_path', 'Company / Root / Leaf 100');
        $this->getJson('/api/organization/reporting-position-options?page=2')->assertOk()->assertJsonPath('data.0.position_path', 'Company / Root / Leaf 99');
    }

    public function test_position_directory_and_edits_return_complete_position_and_parent_paths(): void
    {
        $org = new ManageOrganization;
        $a = $org->company(null, ['title' => 'Company']);
        $b = $org->company(null, ['title' => 'Other']);
        $this->login(['positions.view', 'positions.create', 'positions.update'], $a->id);
        $base = "/api/companies/$a->id/positions";
        $root = $this->postJson($base, ['title' => 'Root'])->assertCreated()->assertJsonPath('data.position_path', 'Company / Root')->assertJsonPath('data.parent_path', null)->json('data.id');
        $middle = $this->postJson($base, ['title' => 'Division', 'parent_position_id' => $root])->assertCreated()->json('data.id');
        $leaf = $this->postJson($base, ['title' => 'Specialist', 'parent_position_id' => $middle])->assertCreated()->assertJsonPath('data.title', 'Specialist')
            ->assertJsonPath('data.position_path', 'Company / Root / Division / Specialist')->assertJsonPath('data.parent_path', 'Company / Root / Division')->json('data.id');
        $this->getJson($base.'?search=Specialist&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.position_path', 'Company / Root / Division / Specialist')->assertJsonPath('data.0.parent_path', 'Company / Root / Division');
        $this->getJson($base.'?per_page=1&page=3')->assertOk()->assertJsonPath('data.0.parent_path', 'Company / Root / Division');
        $this->getJson($base.'?selected='.$middle.'&per_page=1')->assertOk()->assertJsonPath('data.0.position_path', 'Company / Root / Division');
        $this->patchJson($base.'/'.$root, ['title' => 'Renamed'])->assertOk()->assertJsonPath('data.title', 'Renamed')->assertJsonPath('data.parent_path', null);
        $this->getJson($base.'/'.$leaf)->assertOk()->assertJsonPath('data.position_path', 'Company / Renamed / Division / Specialist')->assertJsonPath('data.parent_path', 'Company / Renamed / Division');
        $this->patchJson($base.'/'.$leaf, ['title' => 'Lead', 'parent_position_id' => $root])->assertOk()->assertJsonPath('data.title', 'Lead')->assertJsonPath('data.position_path', 'Company / Renamed / Lead')->assertJsonPath('data.parent_path', 'Company / Renamed');
        $this->getJson("/api/companies/$b->id/positions")->assertForbidden();
        $this->getJson("/api/companies/$b->id/positions/$leaf")->assertForbidden();
    }
}
