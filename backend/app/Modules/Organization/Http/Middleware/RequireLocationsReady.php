<?php

namespace App\Modules\Organization\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RequireLocationsReady
{
    public function handle(Request $request, Closure $next)
    {
        $ready = DB::selectOne("SELECT attnotnull FROM pg_attribute WHERE attrelid='users'::regclass AND attname='building_area_id'");
        abort_unless($ready?->attnotnull, 503, 'User location preparation is required.');

        return $next($request);
    }
}
