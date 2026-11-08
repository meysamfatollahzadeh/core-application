<?php

namespace App\Modules\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Actions\ManageAccess;
use App\Modules\Access\Http\Requests\PermissionSetRequest;
use App\Modules\Access\Http\Requests\PositionRoleSetRequest;
use App\Modules\Access\Http\Requests\RoleRequest;
use App\Modules\Access\Http\Requests\RoleSetRequest;
use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\Role;
use App\Modules\Access\Services\EffectivePermissions;
use App\Modules\Login\Models\User;
use App\Modules\Organization\Actions\AtomicChange;
use App\Modules\Organization\Models\Company;
use App\Modules\Organization\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccessController extends Controller
{
    public function __construct(private EffectivePermissions $access, private ManageAccess $change) {}

    private function allow(Request $r, string $key, ?int $company = null): void
    {
        $this->access->authorize($r->user()->id, $key, $company);
    }

    public function me(Request $r)
    {
        $grants = $this->access->grants($r->user()->id);
        $global = array_values(array_unique(array_column(array_filter($grants, fn ($g) => $g['company_id'] === null), 'key')));
        $companies = [];
        foreach ($grants as $g) {
            if ($g['company_id'] !== null) {
                $companies[$g['company_id']][] = $g['key'];
            }
        }
        $q = Company::select('id', 'title');
        if ($global === []) {
            $q->whereIn('id', array_keys($companies));
        }

        return response()->json(['data' => ['global' => $global, 'companies' => (object) $companies, 'visible_companies' => $q->orderBy('id')->get()]]);
    }

    private function listQuery(Request $r, $model)
    {
        $d = $r->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $q = $model::query();
        if (! empty($d['search'])) {
            $q->where('title', 'ilike', '%'.$d['search'].'%');
        }

        return $q->orderBy('id')->paginate($d['per_page'] ?? 25);
    }

    public function roles(Request $r)
    {
        $this->allow($r, 'roles.view');

        return response()->json($this->listQuery($r, Role::class));
    }

    public function role(Request $r, Role $role)
    {
        $this->allow($r, 'roles.view');

        return response()->json(['data' => [...$role->toArray(), 'permission_ids' => DB::table('role_permission')->where('role_id', $role->id)->pluck('permission_id')]]);
    }

    public function permissions(Request $r)
    {
        $this->allow($r, 'permissions.view');

        return response()->json($this->listQuery($r, Permission::class));
    }

    public function create(RoleRequest $r)
    {
        $this->allow($r, 'roles.create');

        return response()->json(['data' => AtomicChange::run(fn () => Role::create($r->validated()))], 201);
    }

    public function update(RoleRequest $r, Role $role)
    {
        $this->allow($r, 'roles.update');

        return response()->json(['data' => AtomicChange::run(fn () => tap($role, fn ($m) => $m->fill($r->safe()->only('title'))->save()))]);
    }

    public function delete(Request $r, Role $role)
    {
        $this->allow($r, 'roles.delete');
        $this->change->deleteRole($role);

        return response()->noContent();
    }

    public function replacePermissions(PermissionSetRequest $r, Role $role)
    {
        $this->change->permissions($r->user()->id, $role, array_map('intval', $r->validated('permission_ids')));

        return response()->json(['data' => ['permission_ids' => $r->validated('permission_ids')]]);
    }

    public function globalRoles(Request $r, User $user)
    {
        $this->allow($r, 'access.assign');

        return response()->json(['data' => ['role_ids' => DB::table('user_role')->where('user_id', $user->id)->pluck('role_id')]]);
    }

    public function replaceGlobalRoles(RoleSetRequest $r, User $user)
    {
        $this->change->userRoles($r->user()->id, $user->id, null, array_map('intval', $r->validated('role_ids')));

        return response()->json(['data' => ['role_ids' => $r->validated('role_ids')]]);
    }

    public function companyRoles(Request $r, Company $company, User $user)
    {
        $this->allow($r, 'access.assign', $company->id);

        return response()->json(['data' => ['role_ids' => DB::table('company_user_role')->where('user_id', $user->id)->where('company_id', $company->id)->pluck('role_id')]]);
    }

    public function replaceCompanyRoles(RoleSetRequest $r, Company $company, User $user)
    {
        $this->change->userRoles($r->user()->id, $user->id, $company->id, array_map('intval', $r->validated('role_ids')));

        return response()->json(['data' => ['role_ids' => $r->validated('role_ids')]]);
    }

    public function positionRoles(Request $r, Company $company, Position $position)
    {
        $this->allow($r, 'access.assign', $company->id);
        abort_unless($position->company_id === $company->id, 404);
        $q = DB::table('position_role')->where('position_id', $position->id);
        if (! $this->access->allows($r->user()->id, 'access.assign')) {
            $q->where('scope', 'company');
        }

        return response()->json(['data' => ['grants' => $q->get(['role_id', 'scope'])]]);
    }

    public function replacePositionRoles(PositionRoleSetRequest $r, Company $company, Position $position)
    {
        abort_unless($position->company_id === $company->id, 404);
        $grants = array_map(fn ($g) => ['role_id' => (int) $g['role_id'], 'scope' => $g['scope']], $r->validated('grants'));
        $this->change->positionRoles($r->user()->id, $position, $grants);

        return response()->json(['data' => ['grants' => $grants]]);
    }

    public function roleOptions(Request $r)
    {
        $d = $r->validate(['company_id' => ['nullable', 'integer', 'exists:companies,id'], 'page' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100']]);
        $company = isset($d['company_id']) ? (int) $d['company_id'] : null;
        $this->allow($r, 'access.assign', $company);
        $q = Role::query();
        if (! empty($d['search'])) {
            $q->where('title', 'ilike', '%'.$d['search'].'%');
        }
        $ids = $q->pluck('id')->filter(fn ($id) => $this->access->canDelegate($r->user()->id, $id, $company))->values();

        return response()->json(Role::whereIn('id', $ids)->select('id', 'title', 'key')->orderBy('id')->paginate(100));
    }
}
