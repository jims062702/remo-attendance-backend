<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AttendanceService;
use App\Services\GoogleAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Session endpoints for the SPA.
 *
 * Signing IN happens entirely in GoogleAuthController via a browser redirect;
 * there is no password endpoint anywhere in this application. What remains
 * here is reading the current session and ending it.
 */
class AuthController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly AttendanceService $attendance,
        private readonly GoogleAuthService $google,
    ) {}

    /**
     * The authenticated profile.
     *
     * This is what satisfies "the name is fetched automatically, never typed":
     * task and attendance forms read the name from the session rather than
     * looking it up by a submitted email. It cannot be spoofed, and there is
     * no public endpoint that would confirm whether an address is registered.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->ok([
            'user' => UserResource::make($user)->resolve(),
            'shift' => [
                'business_date' => $this->attendance->resolveBusinessDate()->toDateString(),
                'start' => config('attendance.shift_start'),
                'end' => config('attendance.shift_end'),
                'standard_hours' => (float) config('attendance.standard_hours'),
                'grace_minutes' => (int) config('attendance.grace_minutes'),
                'timezone' => config('app.timezone'),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $this->logger->log('auth.logout', "{$user->name} signed out", $user, [], $user);
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->ok(null, 'Signed out successfully.');
    }

    /**
     * What the login screen needs before anyone has signed in: where to send
     * the browser, and whether sign-in is usable at all.
     *
     * Surfacing "not configured" here means a misconfigured server shows a
     * clear message instead of a button that silently fails.
     */
    public function status(): JsonResponse
    {
        return $this->ok([
            'google_enabled' => $this->google->isConfigured(),
            'google_redirect_url' => url('/auth/google/redirect'),
            'app_name' => config('app.name'),
        ]);
    }
}
