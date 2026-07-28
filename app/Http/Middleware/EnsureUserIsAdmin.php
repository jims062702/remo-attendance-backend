<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level guard on the whole /api/admin surface.
 *
 * This is the coarse half of a two-layer defence: it stops a tasker reaching an
 * admin route at all, while policies still authorise each individual record.
 * A route added to the admin group is protected even if its controller forgets
 * to authorise.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (! $user->isAdmin()) {
            return response()->json([
                'message' => 'This action requires administrator access.',
                'code' => 'auth.forbidden',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
