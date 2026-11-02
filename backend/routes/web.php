<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/api/health', HealthController::class)->name('health');
Route::prefix('/api/auth')->name('auth.')->group(function (): void {
    Route::get('/session', [AuthController::class, 'session'])->name('session');
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::get('/callback', [AuthController::class, 'callback'])->name('callback');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
