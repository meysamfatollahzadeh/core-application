<?php

use App\Modules\Personnel\Http\Controllers\PersonnelController as C;
use App\Modules\Personnel\Http\Controllers\MemberConnectionController as M;
use Illuminate\Support\Facades\Route;

Route::get('personnel/options', [C::class, 'options']);
Route::get('personnel', [C::class, 'index']);
Route::post('personnel', [C::class, 'store']);
Route::get('personnel/{user}/members', [M::class, 'index']);
Route::post('personnel/{user}/members', [M::class, 'store']);
Route::delete('personnel/{user}/members/{member}', [M::class, 'destroy']);
Route::get('personnel/{user}/member-sources', [M::class, 'sources']);
Route::get('personnel/{user}/member-sources/{source:slug}/accounts', [M::class, 'search'])->withoutScopedBindings()->middleware('throttle:30,1');
Route::get('personnel/{user}', [C::class, 'show']);
Route::patch('personnel/{user}', [C::class, 'update']);
Route::get('personnel/{user}/positions', [C::class, 'userPositions']);
Route::get('companies/{company}/personnel', [C::class, 'companyPeople']);
Route::get('companies/{company}/user-positions', [C::class, 'assignments']);
Route::post('companies/{company}/user-positions', [C::class, 'create']);
Route::get('companies/{company}/user-positions/{assignment}', [C::class, 'assignment']);
Route::put('companies/{company}/user-positions/{assignment}', [C::class, 'replace']);
Route::delete('companies/{company}/user-positions/{assignment}', [C::class, 'delete']);
