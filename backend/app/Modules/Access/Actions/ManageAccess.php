<?php

namespace App\Modules\Access\Actions;

use App\Modules\Access\Models\Role;
use App\Modules\Access\Services\EffectivePermissions;
use App\Modules\Organization\Actions\AtomicChange;
use App\Modules\Organization\Models\Position;
use Illuminate\Support\Facades\DB;

final class ManageAccess
{
    public function __construct(private EffectivePermissions $access) {}

    public function permissions(int $actor, Role $role, array $ids): void
    {
        AtomicChange::run(function () use ($actor, $role, $ids) {
            $this->access->authorize($actor, 'roles.permissions.update');
            $keys = DB::table('permissions')->whereIn('id', $ids)->pluck('key');
            abort_unless(count($keys) === count($ids), 422, 'Unknown or duplicate permission.');
            foreach ($keys as $key) {
                $this->access->authorize($actor, $key);
            }
            $before = $this->access->hasAdministrator();
            DB::table('role_permission')->where('role_id', $role->id)->delete();
            foreach ($ids as $id) {
                DB::table('role_permission')->insert(['role_id' => $role->id, 'permission_id' => $id]);
            }
            $this->access->protectAdministrator($before);
        });
    }

    public function userRoles(int $actor, int $user, ?int $company, array $roles): void
    {
        AtomicChange::run(function () use ($actor, $user, $company, $roles) {
            $this->access->authorize($actor, 'access.assign', $company);
            $this->validateRoles($actor, $roles, $company);
            $before = $this->access->hasAdministrator();
            $table = $company === null ? 'user_role' : 'company_user_role';
            $q = DB::table($table)->where('user_id', $user);
            if ($company !== null) {
                $q->where('company_id', $company);
            }
            $q->delete();
            foreach ($roles as $role) {
                DB::table($table)->insert(['user_id' => $user, 'role_id' => $role, ...($company !== null ? ['company_id' => $company] : [])]);
            }
            $this->access->protectAdministrator($before);
        });
    }

    public function positionRoles(int $actor, Position $position, array $grants): void
    {
        AtomicChange::run(function () use ($actor, $position, $grants) {
            $company = (int) $position->company_id;
            $global = $this->access->allows($actor, 'access.assign');
            $this->access->authorize($actor, 'access.assign', $company);
            foreach ($grants as $grant) {
                abort_if($grant['scope'] === 'global' && ! $global, 403, 'Global position grants require global authority.');
                $this->validateRoles($actor, [$grant['role_id']], $grant['scope'] === 'global' ? null : $company);
            }
            $keys = array_map(fn ($g) => $g['role_id'].':'.$g['scope'], $grants);
            abort_unless(count($keys) === count(array_unique($keys)), 422, 'Duplicate role/scope.');
            $before = $this->access->hasAdministrator();
            $query = DB::table('position_role')->where('position_id', $position->id);
            if (! $global) {
                $query->where('scope', 'company');
            }
            $query->delete();
            foreach ($grants as $grant) {
                DB::table('position_role')->insert(['position_id' => $position->id, ...$grant]);
            }
            $this->access->protectAdministrator($before);
        });
    }

    private function validateRoles(int $actor, array $roles, ?int $company): void
    {
        abort_unless(DB::table('roles')->whereIn('id', $roles)->count() === count($roles), 422, 'Unknown or duplicate roles.');
        foreach ($roles as $role) {
            abort_unless($this->access->canDelegate($actor, $role, $company), 403, 'Cannot delegate permissions beyond your authority.');
        }
    }

    public function deleteRole(Role $role): void
    {
        AtomicChange::run(function () use ($role) {
            $before = $this->access->hasAdministrator();
            DB::table('role_permission')->where('role_id',$role->id)->delete();
            $role->delete();
            $this->access->protectAdministrator($before);
        });
    }
}
