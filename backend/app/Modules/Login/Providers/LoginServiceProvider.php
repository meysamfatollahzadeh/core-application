<?php

namespace App\Modules\Login\Providers;

use App\Modules\Login\Models\PersonalAccessToken;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class LoginServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        Route::middleware('api')->prefix('api/login')->name('login.')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
