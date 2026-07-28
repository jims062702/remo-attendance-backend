<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-checks account status on every authenticated request.
 *
 * Without this, deactivating or suspending someone would not take effect until
 * their session expired -- they would keep working, and keep clocking in, on a
 * cookie issued before the change. Here the very next request is rejected and
 * the session is torn down.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->canAuthenticate()) {
            $this->logout($request);

            return response()->json([
                'message' => 'Your account is no longer active. Please contact an administrator.',
                'code' => 'auth.account_inactive',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    private function logout(Request $request): void
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
