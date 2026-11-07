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

    public static function title(string $key): string
    {
        [$resource,$operation] = explode('.', $key, 2);
        $resources = ['companies' => 'شرکت‌ها', 'positions' => 'پوزیشن‌ها', 'buildings' => 'ساختمان‌ها و ناحیه‌ها', 'roles' => 'نقش‌ها', 'user_positions' => 'سمت‌های پرسنل', 'personnel' => 'پرسنل', 'permissions' => 'مجوزها', 'access' => 'دسترسی'];
        $operations = ['view' => 'مشاهده', 'create' => 'ایجاد', 'update' => 'ویرایش', 'delete' => 'حذف', 'permissions.update' => 'ویرایش مجوزهای', 'assign' => 'تخصیص'];

        return ($operations[$operation] ?? $operation).' '.($resources[$resource] ?? $resource);
    }
}
