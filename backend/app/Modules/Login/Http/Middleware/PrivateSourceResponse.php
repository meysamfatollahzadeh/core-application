<?php

namespace App\Modules\Login\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PrivateSourceResponse
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request)->header('Cache-Control', 'private, no-store');
    }
}
