<?php

namespace App\Modules\Login\Http\Middleware;

use App\Modules\Login\Models\PersonalAccessToken;
use App\Modules\Login\Services\GrantVerifier;
use App\Modules\Login\Services\LoginFailure;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyProviderGrant
{
    public function __construct(private GrantVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || ! $token->can('login:access')) {
            throw new LoginFailure('unauthenticated');
        }
        $request->user()->withAccessToken($this->verifier->verify($token));

        return $next($request)->header('Cache-Control', 'no-store, private');
    }
}
