<?php

namespace App\Http\Controllers;

use App\Services\OidcClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class AuthController extends Controller
{
    public function __construct(private OidcClient $oidc) {}

    public function session(Request $request): JsonResponse
    {
        $identity = $request->session()->get('identity');
        if ($identity && ($identity['expires_at'] ?? 0) <= time()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $identity = null;
        }

        return response()->json([
            'user' => $identity ? [
                'name' => $identity['name'],
                'username' => $identity['username'],
            ] : null,
            'csrfToken' => csrf_token(),
        ])->header('Cache-Control', 'no-store');
    }

    public function login(Request $request): RedirectResponse
    {
        $state = Str::random(64);
        $nonce = Str::random(64);
        $verifier = Str::random(96);
        $request->session()->put('oidc', compact('state', 'nonce', 'verifier') + ['created_at' => time()]);

        return redirect()->away($this->oidc->endpoint('auth').'?'.http_build_query([
            'client_id' => config('oidc.client_id'),
            'redirect_uri' => config('app.url').'/api/auth/callback',
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'ui_locales' => 'fa',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull('oidc');
        if (! is_array($pending) || ! is_string($request->query('state'))
            || ! hash_equals($pending['state'], $request->query('state'))
            || time() - $pending['created_at'] > 300
            || ! is_string($request->query('code')) || $request->query('code') === '') {
            return redirect(config('app.url').'/?auth_error=invalid_response');
        }

        try {
            $tokens = $this->oidc->exchange($request->query('code'), $pending['verifier']);
            $claims = $this->oidc->validateIdentity($tokens['id_token'] ?? '', $pending['nonce']);
        } catch (Throwable) {
            return redirect(config('app.url').'/?auth_error=invalid_identity');
        }

        $request->session()->regenerate();
        $request->session()->put('identity', [
            'issuer' => $claims['iss'],
            'subject' => $claims['sub'],
            'name' => $claims['name'] ?? $claims['preferred_username'] ?? 'کاربر سازمان',
            'username' => $claims['preferred_username'] ?? '',
            'expires_at' => $claims['exp'],
            'id_token' => $tokens['id_token'],
        ]);

        return redirect(config('app.url').'/');
    }

    public function logout(Request $request): JsonResponse
    {
        $idToken = $request->session()->get('identity.id_token');
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $query = [
            'client_id' => config('oidc.client_id'),
            'post_logout_redirect_uri' => config('app.url').'/',
        ];
        if ($idToken) {
            $query['id_token_hint'] = $idToken;
        }

        return response()->json(['url' => $idToken
            ? $this->oidc->endpoint('logout').'?'.http_build_query($query)
            : config('app.url').'/'])->header('Cache-Control', 'no-store');
    }
}
