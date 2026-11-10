<?php

namespace App\Modules\Access\Services;

final class PermissionCatalog
{
    public static function keys(): array
    {
        $keys = [];
        foreach (['companies', 'positions', 'buildings', 'roles', 'user_positions', 'login_sources'] as $resource) {
            foreach (['view', 'create', 'update', 'delete'] as $operation) {
                $keys[] = $resource.'.'.$operation;
            }
        }

        return [...$keys, 'login_sources.activate', 'personnel.view', 'personnel.create', 'personnel.update', 'personnel.members.manage', 'roles.permissions.update', 'permissions.view', 'access.assign'];
    }

    public static function title(string $key): string
    {
        if ($key === 'personnel.members.manage') {
            return 'مدیریت حساب‌های ورود پرسنل';
        }
        [$resource,$operation] = explode('.', $key, 2);
        $resources = ['login_sources' => 'سورس‌های ورود', 'companies' => 'شرکت‌ها', 'positions' => 'پوزیشن‌ها', 'buildings' => 'ساختمان‌ها و ناحیه‌ها', 'roles' => 'نقش‌ها', 'user_positions' => 'سمت‌های پرسنل', 'personnel' => 'پرسنل', 'permissions' => 'مجوزها', 'access' => 'دسترسی'];
        $operations = ['activate' => 'فعال و غیرفعال‌کردن', 'view' => 'مشاهده', 'create' => 'ایجاد', 'update' => 'ویرایش', 'delete' => 'حذف', 'permissions.update' => 'ویرایش مجوزهای', 'assign' => 'تخصیص'];

        return ($operations[$operation] ?? $operation).' '.($resources[$resource] ?? $resource);
    }
}
