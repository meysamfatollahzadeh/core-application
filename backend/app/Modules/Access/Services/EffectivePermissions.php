<?php

namespace App\Modules\Access\Services;

use Illuminate\Support\Facades\DB;

final class EffectivePermissions
{
    public function grants(int $user): array
    {
        return array_map(fn ($r) => ['key' => $r->key, 'company_id' => $r->company_id === null ? null : (int) $r->company_id], DB::select(<<<'SQL'
SELECT DISTINCT p.key, grants.company_id FROM (
 SELECT role_id, NULL::bigint AS company_id FROM user_role WHERE user_id=?
 UNION ALL SELECT role_id, company_id FROM company_user_role WHERE user_id=?
 UNION ALL SELECT pr.role_id, CASE WHEN pr.scope='global' THEN NULL::bigint ELSE pos.company_id END
 FROM user_positions up JOIN positions pos ON pos.id=up.position_id JOIN position_role pr ON pr.position_id=pos.id WHERE up.user_id=?
) grants JOIN role_permission rp ON rp.role_id=grants.role_id JOIN permissions p ON p.id=rp.permission_id
SQL, [$user, $user, $user]));
    }

    public function allows(int $user, string $permission, ?int $company = null): bool
    {
        foreach ($this->grants($user) as $grant) {
            if ($grant['key'] === $permission && ($grant['company_id'] === null || ($company !== null && $grant['company_id'] === $company))) {
                return true;
            }
        }

        return false;
    }

    public function authorize(int $user, string $permission, ?int $company = null): void
    {
        abort_unless($this->allows($user, $permission, $company), 403, 'Permission denied.');
    }

    public function hasAdministrator(): bool
    {
        foreach (DB::table('users')->pluck('id') as $id) {
            $global = array_column(array_filter($this->grants($id), fn ($g) => $g['company_id'] === null), 'key');
            if (array_diff(['roles.view', 'roles.update', 'roles.permissions.update', 'permissions.view', 'access.assign'], $global) === []) {
                return true;
            }
        }

        return false;
    }

    public function protectAdministrator(bool $previouslyPresent): void
    {
        abort_if($previouslyPresent && ! $this->hasAdministrator(), 409, 'Cannot remove the last effective global access administrator.');
    }

    public function canDelegate(int $actor, int $role, ?int $company): bool
    {
        if (! $this->allows($actor, 'access.assign', $company)) {
            return false;
        }
        $keys = DB::table('permissions')->join('role_permission', 'permission_id', '=', 'permissions.id')->where('role_id', $role)->pluck('key');
        foreach ($keys as $key) {
            if (! $this->allows($actor, $key, $company)) {
                return false;
            }
        }

        return true;
    }

    public function authorizePositionOccupancy(int $actor, int $position): void
    {
        $company = (int) DB::table('positions')->where('id', $position)->value('company_id');
        foreach (DB::table('position_role')->where('position_id', $position)->get() as $grant) {
            abort_unless($this->canDelegate($actor, $grant->role_id, $grant->scope === 'global' ? null : $company), 403, 'Assigning this position would delegate unauthorized access.');
        }
    }
}
