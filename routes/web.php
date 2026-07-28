<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\GoogleAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Only the Google sign-in handshake lives here. Everything else the SPA needs
| is in routes/api.php.
|
| These two routes are deliberately NOT under /api: they are full browser
| navigations, and Socialite's CSRF `state` parameter round-trips through the
| session, which needs the web middleware group that only these routes get.
|
| Rate limited because they are unauthenticated and reachable by anyone.
|
*/

Route::middleware('throttle:6,1')->group(function (): void {
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])
        ->name('auth.google.redirect');

    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])
        ->name('auth.google.callback');

    // Alias for the same two endpoints under /api.
    //
    // Google matches the redirect URI character for character, so whichever
    // form is registered in the Cloud Console has to exist here. Serving both
    // means a console entry of either shape works, and the one in use is
    // whichever GOOGLE_REDIRECT_URI names.
    //
    // These stay in web.php despite the /api path: they are full browser
    // navigations and Socialite's CSRF `state` parameter round-trips through
    // the session, which only the web middleware group provides.
    Route::get('/api/auth/google/redirect', [GoogleAuthController::class, 'redirect'])
        ->name('auth.google.redirect.api');

    Route::get('/api/auth/google/callback', [GoogleAuthController::class, 'callback'])
        ->name('auth.google.callback.api');
});

// In production the built SPA is served from public/index.html, so this only
// shows when the frontend has not been built yet.
Route::get('/', fn () => response()->json([
    'application' => config('app.name'),
    'message' => 'API is running. The user interface is served by the frontend application.',
    'sign_in' => url('/auth/google/redirect'),
]));
