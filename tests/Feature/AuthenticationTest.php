<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\GoogleAuthException;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\GoogleAuthService;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Google-only sign-in.
 *
 * The rule under test throughout: **signing in never creates an account.** A
 * Google identity only ever matches a user an administrator already
 * authorised, so holding a Google account grants nothing on its own.
 */

/**
 * A Google identity as Socialite would hand it back.
 */
function googleIdentity(
    string $email,
    string $id = '100000000000000000001',
    string $name = 'Juan Dela Cruz',
    bool $verified = true,
): SocialiteUser {
    $user = new SocialiteUser;
    $user->map([
        'id' => $id,
        'name' => $name,
        'email' => $email,
        'avatar' => 'https://lh3.googleusercontent.com/a/example',
    ]);
    $user->user = ['email_verified' => $verified];

    return $user;
}

/**
 * A tasker who has been authorised but never signed in, so the next Google
 * identity to present this address claims the account. The default tasker()
 * helper is already linked to a random Google id, which would trip the
 * identity-mismatch guard.
 */
function unlinkedTasker(array $attributes = []): User
{
    return User::factory()->tasker()->notSignedIn()->create($attributes);
}

beforeEach(function (): void {
    $this->google = app(GoogleAuthService::class);
});

// ------------------------------------------------------------ Identity rules

it('signs in an authorised user and links their Google account', function (): void {
    $user = unlinkedTasker(['email' => 'juan@test.local']);

    $resolved = $this->google->resolve(googleIdentity('juan@test.local'));

    expect($resolved->id)->toBe($user->id)
        ->and($resolved->google_id)->toBe('100000000000000000001')
        // Claiming the identity also verifies the address.
        ->and($resolved->email_verified_at)->not->toBeNull();
});

it('refuses a Google account nobody has authorised', function (): void {
    // The load-bearing test: without this, anyone with a Google account
    // could sign into the system.
    $this->google->resolve(googleIdentity('stranger@gmail.com'));
})->throws(GoogleAuthException::class, 'no account for');

it('creates no user when sign-in is refused', function (): void {
    try {
        $this->google->resolve(googleIdentity('stranger@gmail.com'));
    } catch (GoogleAuthException) {
        // expected
    }

    expect(User::withTrashed()->where('email', 'stranger@gmail.com')->exists())->toBeFalse();
});

it('refuses an address Google has not verified', function (): void {
    unlinkedTasker(['email' => 'juan@test.local']);

    $this->google->resolve(googleIdentity('juan@test.local', verified: false));
})->throws(GoogleAuthException::class, 'has not verified');

it('refuses a suspended account', function (): void {
    $user = unlinkedTasker(['email' => 'juan@test.local']);
    $user->forceFill(['status' => UserStatus::Suspended])->save();

    $this->google->resolve(googleIdentity('juan@test.local'));
})->throws(GoogleAuthException::class, 'Suspended');

it('refuses a deactivated account rather than reinstating it', function (): void {
    $user = unlinkedTasker(['email' => 'juan@test.local']);
    $user->delete();

    $this->google->resolve(googleIdentity('juan@test.local'));
})->throws(GoogleAuthException::class, 'no account for');

it('refuses a different Google identity claiming a linked address', function (): void {
    // Guards the case where a Workspace deletes an address and reissues it to
    // a new employee, who would otherwise inherit the previous holder's
    // attendance and production history.
    $user = tasker(['email' => 'juan@test.local']);
    $user->forceFill(['google_id' => 'original-google-id'])->save();

    $this->google->resolve(googleIdentity('juan@test.local', id: 'a-different-google-id'));
})->throws(GoogleAuthException::class, 'does not match');

it('matches the email case insensitively', function (): void {
    $user = unlinkedTasker(['email' => 'juan@test.local']);

    $resolved = $this->google->resolve(googleIdentity('JUAN@TEST.LOCAL'));

    expect($resolved->id)->toBe($user->id);
});

it('adopts the Google profile name only on first sign-in', function (): void {
    $user = unlinkedTasker(['name' => 'Provisional Name', 'email' => 'juan@test.local']);

    $this->google->resolve(googleIdentity('juan@test.local', name: 'Juan Dela Cruz'));
    expect($user->refresh()->name)->toBe('Juan Dela Cruz');

    // An admin renames them afterwards; a later sign-in must not overwrite it.
    $user->forceFill(['name' => 'Juan D. Cruz'])->save();
    $this->google->resolve(googleIdentity('juan@test.local', name: 'Juan Dela Cruz'));

    expect($user->refresh()->name)->toBe('Juan D. Cruz');
});

// --------------------------------------------------------------- Domain rule

it('refuses an address outside the allowed domains when configured', function (): void {
    config(['services.google.allowed_domains' => ['acme.com']]);
    unlinkedTasker(['email' => 'juan@test.local']);

    $this->google->resolve(googleIdentity('juan@test.local'));
})->throws(GoogleAuthException::class, 'not on an approved domain');

it('allows an address on an allowed domain', function (): void {
    config(['services.google.allowed_domains' => ['test.local']]);
    $user = unlinkedTasker(['email' => 'juan@test.local']);

    expect($this->google->resolve(googleIdentity('juan@test.local'))->id)->toBe($user->id);
});

// -------------------------------------------------------------------- Audit

it('records every refused sign-in attempt', function (): void {
    try {
        $this->google->resolve(googleIdentity('stranger@gmail.com'));
    } catch (GoogleAuthException) {
        // expected
    }

    $log = ActivityLog::where('action', 'auth.google_denied')->firstOrFail();

    expect($log->metadata['reason'])->toBe('google.not_registered')
        ->and($log->metadata['email'])->toBe('stranger@gmail.com');
});

// ------------------------------------------------------------------- Routes

it('reports whether Google sign-in is configured', function (): void {
    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

    $this->getJson('/api/auth/status')
        ->assertOk()
        ->assertJsonPath('data.google_enabled', false);

    config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);

    $this->getJson('/api/auth/status')
        ->assertOk()
        ->assertJsonPath('data.google_enabled', true);
});

it('sends an unconfigured sign-in back to the login screen', function (): void {
    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

    $this->get('/auth/google/redirect')
        ->assertRedirectContains('error=google.not_configured');
});

it('redirects to Google when configured', function (): void {
    config([
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-secret',
        'services.google.redirect' => 'http://localhost:8001/auth/google/callback',
    ]);

    $this->get('/auth/google/redirect')
        ->assertRedirectContains('accounts.google.com')
        // Forces the account chooser so a shared machine cannot silently
        // reuse whoever signed in last.
        ->assertRedirectContains('prompt=select_account');
});

it('sends a cancelled consent back to the login screen', function (): void {
    $this->get('/auth/google/callback?error=access_denied')
        ->assertRedirectContains('error=google.cancelled');
});

it('returns the authenticated profile from /me', function (): void {
    $user = tasker(['name' => 'Juan Dela Cruz', 'email' => 'juan@test.local']);

    $this->actingAs($user)->getJson('/api/me')
        ->assertOk()
        // The name task forms display comes from here, not from a lookup by
        // a submitted email, so it cannot be spoofed.
        ->assertJsonPath('data.user.name', 'Juan Dela Cruz')
        ->assertJsonPath('data.shift.start', '22:00')
        ->assertJsonPath('data.shift.end', '06:00');
});

it('never exposes the password hash or Google id', function (): void {
    $user = tasker();

    $response = $this->actingAs($user)->getJson('/api/me')->assertOk();

    expect($response->json('data.user'))
        ->not->toHaveKey('password')
        ->not->toHaveKey('remember_token')
        ->not->toHaveKey('google_id')
        // Exposed as a boolean instead, so the UI can show "never signed in".
        ->toHaveKey('has_signed_in');
});

it('signs out and clears the session', function (): void {
    $user = tasker();

    $this->actingAs($user)->postJson('/api/logout')->assertOk();

    // Asserted against the web guard specifically: `auth:sanctum` promotes
    // itself to the default guard once it authenticates and caches its user
    // for the life of the process, but the session guard holds the login.
    $this->assertGuest('web');
});

it('locks out a session as soon as the account is deactivated', function (): void {
    $user = tasker();

    $this->actingAs($user)->getJson('/api/me')->assertOk();

    $user->forceFill(['status' => UserStatus::Suspended])->save();

    $this->actingAs($user)->getJson('/api/me')
        ->assertStatus(403)
        ->assertJsonPath('code', 'auth.account_inactive');
});

it('rejects unauthenticated requests', function (): void {
    $this->getJson('/api/me')->assertUnauthorized();
    $this->postJson('/api/attendance/time-in')->assertUnauthorized();
});

it('serves enum options without authentication', function (): void {
    $this->getJson('/api/meta/options')
        ->assertOk()
        ->assertJsonPath('data.shift.start', '22:00')
        ->assertJsonCount(5, 'data.attendance_statuses')
        ->assertJsonCount(5, 'data.task_statuses');
});

// ----------------------------------------------------- Console authorisation

it('authorises a first administrator from the console', function (): void {
    // The bootstrap path: with Google-only sign-in and no self-registration,
    // a fresh installation has nobody who can reach the admin screens.
    $this->artisan('user:authorise', [
        'email' => 'boss@test.local',
        '--name' => 'Maria Santos',
        '--admin' => true,
    ])->assertSuccessful();

    $user = User::where('email', 'boss@test.local')->firstOrFail();

    expect($user->role)->toBe(UserRole::Admin)
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->name)->toBe('Maria Santos')
        // Authorised, but not linked until they actually sign in.
        ->and($user->hasLinkedGoogleAccount())->toBeFalse();
});

it('derives a name from the address when none is given', function (): void {
    $this->artisan('user:authorise', ['email' => 'juan.dela.cruz@test.local'])
        ->assertSuccessful();

    expect(User::where('email', 'juan.dela.cruz@test.local')->firstOrFail()->name)
        ->toBe('Juan Dela Cruz');
});

it('unlinks a Google identity so a different account can claim the record', function (): void {
    $user = unlinkedTasker(['email' => 'juan@test.local']);
    $user->forceFill(['google_id' => 'stale-google-id'])->save();

    $this->artisan('user:authorise', ['email' => 'juan@test.local', '--unlink' => true])
        ->assertSuccessful();

    expect($user->refresh()->google_id)->toBeNull();

    // The locked-out user can now sign in again with their new Google account.
    expect($this->google->resolve(googleIdentity('juan@test.local', id: 'brand-new-id'))->id)
        ->toBe($user->id);
});

it('refuses to overwrite a deactivated account without --reactivate', function (): void {
    $user = tasker(['email' => 'juan@test.local']);
    $user->delete();

    $this->artisan('user:authorise', ['email' => 'juan@test.local'])->assertSuccessful();

    expect(User::where('email', 'juan@test.local')->exists())->toBeFalse();
});

it('reactivates a deactivated account when asked', function (): void {
    $user = tasker(['email' => 'juan@test.local']);
    $user->delete();

    $this->artisan('user:authorise', ['email' => 'juan@test.local', '--reactivate' => true])
        ->assertSuccessful();

    expect(User::where('email', 'juan@test.local')->exists())->toBeTrue();
});

it('rejects an invalid email address', function (): void {
    $this->artisan('user:authorise', ['email' => 'not-an-email'])->assertFailed();
});
