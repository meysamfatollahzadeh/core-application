<?php

use App\Modules\Login\Http\Controllers\SourceManagementController as C;
use Illuminate\Support\Facades\Route;

Route::get('login-sources', [C::class, 'index'])->name('index');
Route::post('login-sources', [C::class, 'store'])->name('store');
Route::get('login-sources/{source:slug}', [C::class, 'show'])->name('show');
Route::patch('login-sources/{source:slug}', [C::class, 'update'])->name('update');
Route::patch('login-sources/{source:slug}/activity', [C::class, 'activity'])->name('activity');
Route::delete('login-sources/{source:slug}', [C::class, 'destroy'])->name('destroy');
