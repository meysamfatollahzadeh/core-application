<?php

namespace App\Modules\Login\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Login\Actions\BackchannelLogout;
use App\Modules\Login\Actions\CompleteLogin;
use App\Modules\Login\Actions\Logout;
use App\Modules\Login\Actions\StartLogin;
use App\Modules\Login\Actions\UpdatePerformances;
use App\Modules\Login\Http\Requests\BackchannelLogoutRequest;
use App\Modules\Login\Http\Requests\CompleteLoginRequest;
use App\Modules\Login\Http\Requests\StartLoginRequest;
use App\Modules\Login\Http\Requests\UpdatePerformancesRequest;
use App\Modules\Login\Http\Resources\LoginUserResource;
use App\Modules\Login\Models\UserSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    public function sources(): JsonResponse
    {
        return response()->json(['data' => UserSource::where('is_active', true)->orderBy('title')->get(['slug', 'title'])->map(fn ($source) => ['slug' => $source->slug, 'title' => $source->title])])->header('Cache-Control', 'no-store');
    }

    public function authorizeSource(StartLoginRequest $request, UserSource $source, StartLogin $action): JsonResponse
    {
        return response()->json($action->execute($source, $request->validated('browser_binding')))->header('Cache-Control', 'no-store');
    }

    public function callback(CompleteLoginRequest $request, UserSource $source, CompleteLogin $action): JsonResponse
    {
        return response()->json($action->execute($source, $request->validated('state'), $request->validated('code'), $request->validated('browser_binding')))->header('Cache-Control', 'no-store');
    }

    public function me(Request $request): LoginUserResource
    {
        return new LoginUserResource($request->user());
    }

    public function updatePerformances(UpdatePerformancesRequest $request, UpdatePerformances $action): JsonResponse
    {
        return response()->json(['data' => ['performances' => $action->execute($request->user(), $request->validated('theme'))]])->header('Cache-Control', 'no-store, private');
    }

    public function logout(StartLoginRequest $request, Logout $action): JsonResponse
    {
        return response()->json($action->execute($request->user()->currentAccessToken(), $request->validated('browser_binding')))->header('Cache-Control', 'no-store');
    }

    public function continueLogout(StartLoginRequest $request, Logout $action): JsonResponse
    {
        $handoff = $request->validate(['handoff' => ['required', 'string', 'size:96', 'alpha_num:ascii']])['handoff'];

        return response()->json($action->consume($handoff, $request->validated('browser_binding')))->header('Cache-Control', 'no-store');
    }

    public function backchannel(BackchannelLogoutRequest $request, UserSource $source, BackchannelLogout $action): JsonResponse
    {
        $action->execute($source, $request->validated('logout_token'));

        return response()->json(['status' => 'ok'])->header('Cache-Control', 'no-store');
    }
}
