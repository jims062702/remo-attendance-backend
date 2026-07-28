<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\GoogleAuthException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\GoogleAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Google sign-in, the only way into the application.
 *
 * These two routes live in routes/web.php rather than the API: they are full
 * browser navigations, and Socialite's CSRF `state` parameter is carried in
 * the session, which requires the web middleware group.
 *
 * The exchange with Google happens entirely server side, so the OAuth code and
 * access token never reach JavaScript.
 */
class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly GoogleAuthService $google,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * Send the browser to Google's consent screen.
     */
    public function redirect(): RedirectResponse
    {
        if (! $this->google->isConfigured()) {
            return $this->backToLogin('google.not_configured');
        }

        return Socialite::driver('google')
            // Always show the account chooser. Without this, a shared machine
            // silently reuses whoever signed in last -- which on an attendance
            // system means clocking in as a colleague.
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    /**
     * Handle Google's redirect back.
     *
     * Ends in a redirect to the SPA either way: authenticated on success, or
     * carrying an error code the login screen can explain on failure.
     */
    public function callback(Request $request): RedirectResponse
    {
        // The user dismissed the consent screen.
        if ($request->has('error')) {
            return $this->backToLogin('google.cancelled');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            report($e);

            return $this->backToLogin('google.failed');
        }

        try {
            $user = $this->google->resolve($googleUser);
        } catch (GoogleAuthException $e) {
            return $this->backToLogin($e->errorCode(), $e->getMessage());
        }

        $this->signIn($request, $user);

        return redirect()->to($this->frontendUrl('/'));
    }

    private function signIn(Request $request, User $user): void
    {
        Auth::guard('web')->login($user, remember: true);

        // Rotate the session id to defeat session fixation.
        $request->session()->regenerate();

        $this->logger->log('auth.login', "{$user->name} signed in with Google", $user, [], $user);
    }

    /**
     * Back to the SPA's login screen with a machine-readable reason.
     */
    private function backToLogin(string $code, ?string $message = null): RedirectResponse
    {
        $query = http_build_query(array_filter([
            'error' => $code,
            'message' => $message,
        ]));

        return redirect()->away($this->frontendUrl('/login?'.$query));
    }

    private function frontendUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }
}
