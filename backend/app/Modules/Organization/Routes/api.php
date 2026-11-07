<?php

use App\Modules\Organization\Http\Controllers\OrganizationController as C;
use Illuminate\Support\Facades\Route;

Route::get('organization/reporting-position-options', [C::class, 'reportingOptions']);
Route::get('organization/location-options', [C::class, 'locationOptions']);
foreach (['companies' => ['company', 'Company'], 'buildings' => ['building', 'Building']] as $path => [$param,$suffix]) {
    Route::get($path, [C::class, $path]);
    Route::post($path, [C::class, 'create'.$suffix]);
    Route::get($path.'/{'.$param.'}', [C::class, $param]);
    Route::patch($path.'/{'.$param.'}', [C::class, 'update'.$suffix]);
    Route::delete($path.'/{'.$param.'}', [C::class, 'delete'.$suffix]);
}
Route::get('companies/{company}/positions', [C::class, 'positions']);
Route::post('companies/{company}/positions', [C::class, 'createPosition']);
Route::get('companies/{company}/positions/{position}', [C::class, 'position']);
Route::patch('companies/{company}/positions/{position}', [C::class, 'updatePosition']);
Route::delete('companies/{company}/positions/{position}', [C::class, 'deletePosition']);
Route::get('buildings/{building}/areas', [C::class, 'areas']);
Route::post('buildings/{building}/areas', [C::class, 'createArea']);
Route::get('buildings/{building}/areas/{area}', [C::class, 'area']);
Route::patch('buildings/{building}/areas/{area}', [C::class, 'updateArea']);
Route::delete('buildings/{building}/areas/{area}',[C::class, 'deleteArea']);
