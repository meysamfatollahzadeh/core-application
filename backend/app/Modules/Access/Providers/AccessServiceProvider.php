<?php

namespace App\Modules\Access\Providers;

use App\Modules\Access\Console\Commands\BootstrapAccess;
use App\Modules\Access\Console\Commands\SyncPermissions;
use App\Modules\Login\Http\Middleware\VerifyProviderGrant;
use App\Modules\Organization\Http\Middleware\RequireLocationsReady;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AccessServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([SyncPermissions::class, BootstrapAccess::class]);
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        Route::middleware(['api', 'auth:sanctum', VerifyProviderGrant::class, RequireLocationsReady::class])
            ->prefix('api')->group(__DIR__.'/../Routes/api.php');
    }
}
