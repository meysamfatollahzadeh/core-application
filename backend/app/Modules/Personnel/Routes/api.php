<?php

use App\Modules\Personnel\Http\Controllers\PersonnelController as C;
use Illuminate\Support\Facades\Route;

Route::get('personnel/options', [C::class, 'options']);
Route::get('personnel', [C::class, 'index']);
Route::get('personnel/{user}', [C::class, 'show']);
Route::patch('personnel/{user}', [C::class, 'update']);
Route::get('personnel/{user}/positions', [C::class, 'userPositions']);
Route::get('companies/{company}/personnel', [C::class, 'companyPeople']);
Route::get('companies/{company}/user-positions', [C::class, 'assignments']);
Route::post('companies/{company}/user-positions', [C::class, 'create']);
Route::get('companies/{company}/user-positions/{assignment}', [C::class, 'assignment']);
Route::put('companies/{company}/user-positions/{assignment}', [C::class, 'replace']);
Route::delete('companies/{company}/user-positions/{assignment}', [C::class, 'delete']);
