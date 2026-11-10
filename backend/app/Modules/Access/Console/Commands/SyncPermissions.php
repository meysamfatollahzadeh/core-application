<?php

namespace App\Modules\Access\Console\Commands;

use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\Role;
use App\Modules\Access\Services\PermissionCatalog;
use App\Modules\Organization\Actions\AtomicChange;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncPermissions extends Command
{
    protected $signature = 'access:sync-permissions';

    protected $description = 'Synchronize the application permission catalog and bootstrap role without granting users access';

    public function handle(): int
    {
        AtomicChange::run(function () {
            $role = Role::firstOrCreate(['key' => 'system-administrator'], ['title' => 'مدیر سامانه']);
            foreach (PermissionCatalog::keys() as $key) {
                $permission = Permission::firstOrCreate(['key' => $key], ['title' => PermissionCatalog::title($key)]);
                // New sensitive capabilities require explicit assignment to existing roles.
                if (! $role->wasRecentlyCreated && in_array($key, ['personnel.create', 'personnel.members.manage'], true)) {
                    continue;
                }
                DB::table('role_permission')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permission->id]);
            }
        });
        $this->info('Catalog synchronized; no user grants created.');

        return self::SUCCESS;
    }
}
