<?php

use App\Modules\Login\Http\Controllers\LoginController;
use Illuminate\Support\Facades\Route;

Route::get('/sources', [LoginController::class, 'sources'])->name('sources');
Route::middleware('auth:sanctum')->get('/me', [LoginController::class, 'me'])->name('me');
