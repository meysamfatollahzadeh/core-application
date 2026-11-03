<?php

namespace App\Modules\Login\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    public function sources(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => ['user' => ['id' => $request->user()->id, 'name' => $request->user()->name]]]);
    }
}
