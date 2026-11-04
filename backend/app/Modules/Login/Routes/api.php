<?php

use App\Modules\Login\Http\Controllers\LoginController;
use App\Modules\Login\Http\Middleware\VerifyProviderGrant;
use Illuminate\Support\Facades\Route;

Route::get('/sources', [LoginController::class, 'sources'])->middleware('throttle:60,1')->name('sources');
Route::post('/sources/{source:slug}/authorize', [LoginController::class, 'authorizeSource'])->middleware('throttle:20,1')->name('authorize');
Route::post('/sources/{source:slug}/callback', [LoginController::class, 'callback'])->middleware('throttle:30,1')->name('callback');
Route::middleware(['auth:sanctum', VerifyProviderGrant::class])->get('/me', [LoginController::class, 'me'])->name('me');
Route::post('/logout', [LoginController::class, 'logout'])->middleware(['auth:sanctum', 'throttle:30,1'])->name('logout');
Route::post('/logout/continue', [LoginController::class, 'continueLogout'])->middleware('throttle:30,1')->name('logout.continue');
Route::post('/sources/{source:slug}/backchannel-logout', [LoginController::class, 'backchannel'])->middleware('throttle:120,1')->name('backchannel');
