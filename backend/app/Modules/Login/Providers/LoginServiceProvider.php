<?php

namespace App\Modules\Login\Providers;

use App\Modules\Login\Console\Commands\ImportLoginMembers;
use App\Modules\Login\Console\Commands\UpsertLoginSource;
use App\Modules\Login\Models\PersonalAccessToken;
use App\Modules\Login\Services\LoginFailure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class LoginServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([UpsertLoginSource::class, ImportLoginMembers::class]);
        $this->app->make(ExceptionHandler::class)->renderable(
            fn (LoginFailure $exception) => response()->json(['error' => $exception->errorCode], $exception->httpStatus)->header('Cache-Control', 'no-store')
        );
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        Route::middleware('api')->prefix('api/login')->name('login.')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
