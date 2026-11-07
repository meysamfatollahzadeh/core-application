<?php

use App\Modules\Access\Http\Controllers\AccessController as C;
use Illuminate\Support\Facades\Route;

Route::get('access/me', [C::class, 'me']);
Route::get('access/role-options', [C::class, 'roleOptions']);
Route::get('roles', [C::class, 'roles']);
Route::post('roles', [C::class, 'create']);
Route::get('roles/{role}', [C::class, 'role']);
Route::patch('roles/{role}', [C::class, 'update']);
Route::delete('roles/{role}', [C::class, 'delete']);
Route::get('permissions', [C::class, 'permissions']);
Route::put('roles/{role}/permissions', [C::class, 'replacePermissions']);
Route::get('personnel/{user}/global-roles', [C::class, 'globalRoles']);
Route::put('personnel/{user}/global-roles', [C::class, 'replaceGlobalRoles']);
Route::get('companies/{company}/users/{user}/roles', [C::class, 'companyRoles']);
Route::put('companies/{company}/users/{user}/roles', [C::class, 'replaceCompanyRoles']);
Route::get('companies/{company}/positions/{position}/roles', [C::class, 'positionRoles']);
Route::put('companies/{company}/positions/{position}/roles',[C::class, 'replacePositionRoles']);
