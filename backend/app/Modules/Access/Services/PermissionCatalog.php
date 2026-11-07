<?php

namespace App\Modules\Access\Services;

final class PermissionCatalog
{
    public static function keys(): array
    {
        $keys = [];
        foreach (['companies', 'positions', 'buildings', 'roles', 'user_positions'] as $resource) {
            foreach (['view', 'create', 'update', 'delete'] as $operation) {
                $keys[] = $resource.'.'.$operation;
            }
        }

        return [...$keys, 'personnel.view', 'personnel.update', 'roles.permissions.update', 'permissions.view', 'access.assign'];
    }
}
