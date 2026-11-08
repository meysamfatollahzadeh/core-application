<?php

namespace App\Modules\Access\Services;

use Illuminate\Support\Facades\DB;

final class EffectivePermissions
{
    private array $memo = [];

    public function forget(): void
    {
        $this->memo = [];
    }

    public function grants(int $user): array
    {
        return $this->memo[$user] ??= array_map(fn ($r) => ['key' => $r->key, 'company_id' => $r->company_id === null ? null : (int) $r->company_id], DB::select(<<<'SQL'
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
        $required = ['roles.view', 'roles.update', 'roles.permissions.update', 'permissions.view', 'access.assign'];
        $slots = implode(',', array_fill(0, count($required), '?'));
        $result = DB::selectOne("WITH global_grants AS (
            SELECT user_id, role_id FROM user_role
            UNION ALL SELECT up.user_id, pr.role_id FROM user_positions up JOIN position_role pr ON pr.position_id=up.position_id WHERE pr.scope='global'
        ) SELECT EXISTS(SELECT 1 FROM global_grants g JOIN role_permission rp ON rp.role_id=g.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.key IN ({$slots}) GROUP BY g.user_id HAVING count(DISTINCT p.key)=?) AS present", [...$required, count($required)]);

        return (bool) $result->present;
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
