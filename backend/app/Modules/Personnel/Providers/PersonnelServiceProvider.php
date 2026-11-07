<?php

namespace App\Modules\Personnel\Providers;

use App\Modules\Login\Http\Middleware\VerifyProviderGrant;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PersonnelServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        Route::middleware(['api', 'auth:sanctum', VerifyProviderGrant::class])
            ->prefix('api')->group(__DIR__.'/../Routes/api.php');
    }
}
