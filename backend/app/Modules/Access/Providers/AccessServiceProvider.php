<?php

namespace App\Modules\Access\Providers;

use App\Modules\Login\Http\Middleware\VerifyProviderGrant;
use App\Modules\Organization\Http\Middleware\RequireLocationsReady;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AccessServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        Route::middleware(['api', 'auth:sanctum', VerifyProviderGrant::class, RequireLocationsReady::class])
            ->prefix('api')->group(__DIR__.'/../Routes/api.php');
    }
}
