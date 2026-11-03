<?php

namespace Tests\Feature\Login;

use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

class LoginApiTest extends TestCase
{
    public function test_login_api_has_no_session_middleware_or_stateful_sanctum_guard(): void
    {
        $route = Route::getRoutes()->getByName('login.me');
        $middleware = app('router')->gatherRouteMiddleware($route);
        $this->assertNotContains(StartSession::class, $middleware);
        $this->assertNotContains(EnsureFrontendRequestsAreStateful::class, $middleware);
        $this->assertSame([], config('sanctum.guard'));
        $this->getJson('/api/login/me')->assertUnauthorized();
        $this->withCookie('organization_session', 'old-session')->getJson('/api/login/me')->assertUnauthorized();
        $this->getJson('/api/auth/session')->assertNotFound();
    }
}
