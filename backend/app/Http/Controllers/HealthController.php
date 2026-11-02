<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
            $key = 'readiness:'.bin2hex(random_bytes(8));
            Cache::put($key, 'ready', 10);
            $ready = Cache::pull($key) === 'ready';

            return response()->json(['status' => $ready ? 'ok' : 'unavailable'], $ready ? 200 : 503);
        } catch (Throwable) {
            return response()->json(['status' => 'unavailable'], 503);
        }
    }
}
