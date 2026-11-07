<?php

namespace App\Modules\Organization\Providers;

use App\Modules\Login\Http\Middleware\VerifyProviderGrant;
use App\Modules\Organization\Console\Commands\PrepareLocations;
use App\Modules\Organization\Console\Commands\RequireLocations;
use App\Modules\Organization\Http\Middleware\RequireLocationsReady;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class OrganizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([PrepareLocations::class, RequireLocations::class]);
        if ($this->app->environment('testing')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/ContractMigrations');
        }
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        Route::middleware(['api', 'auth:sanctum', VerifyProviderGrant::class, RequireLocationsReady::class])
            ->prefix('api')->group(__DIR__.'/../Routes/api.php');
    }
}
