<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GoogleAuthException;
use App\Models\User;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Resolves a Google identity to a local account.
 *
 * The governing rule: **signing in never creates an account.** A Google
 * identity is only ever matched to a user an administrator already added, so
 * possession of a Google account grants nothing by itself. Everything else
 * here exists to make that match trustworthy.
 */
class GoogleAuthService
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    public function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw GoogleAuthException::notConfigured();
        }
    }

    /**
     * Match a verified Google identity to an existing, active user.
     *
     * @throws GoogleAuthException when the identity cannot be trusted, is
     *                             unknown, or the account is not active.
     */
    public function resolve(SocialiteUser $googleUser): User
    {
        $email = strtolower(trim((string) $googleUser->getEmail()));

        if ($email === '') {
            throw GoogleAuthException::failed('Google returned no email address.');
        }

        // Google says the address exists but was never verified -- it does not
        // prove the person controls it, so it cannot be used to claim an account.
        if ($this->emailIsUnverified($googleUser)) {
            $this->deny('google.email_not_verified', $email);

            throw GoogleAuthException::emailNotVerified($email);
        }

        if (! $this->domainAllowed($email)) {
            $this->deny('google.domain_not_allowed', $email);

            throw GoogleAuthException::domainNotAllowed($email);
        }

        $user = User::withTrashed()->where('email', $email)->first();

        // Deactivated accounts are soft deleted, so a returning former employee
        // is "not registered" rather than silently reinstated.
        if ($user === null || $user->trashed()) {
            $this->deny('google.not_registered', $email);

            throw GoogleAuthException::notRegistered($email);
        }

        $googleId = (string) $googleUser->getId();

        // First sign-in claims the identity. Afterwards it must keep matching:
        // a Workspace can delete an address and reissue it to someone new, and
        // that person must not inherit the previous holder's records.
        if ($user->google_id !== null && $user->google_id !== $googleId) {
            $this->deny('google.identity_mismatch', $email, ['expected' => $user->google_id, 'received' => $googleId]);

            throw GoogleAuthException::identityMismatch($email);
        }

        if (! $user->canAuthenticate()) {
            $this->deny('google.account_not_active', $email, ['status' => $user->status->value]);

            throw GoogleAuthException::accountNotActive($email, $user->status->label());
        }

        $this->syncProfile($user, $googleUser, $googleId);

        return $user;
    }

    /**
     * Record the Google identity and refresh the cached profile details.
     *
     * The name is only adopted when the administrator has not set one that
     * differs -- an admin's chosen spelling should not be overwritten by
     * whatever the employee has in their personal Google profile.
     */
    private function syncProfile(User $user, SocialiteUser $googleUser, string $googleId): void
    {
        $isFirstSignIn = $user->google_id === null;

        $user->google_id = $googleId;
        $user->avatar_url = $googleUser->getAvatar() ?: $user->avatar_url;

        if ($user->email_verified_at === null) {
            $user->email_verified_at = now();
        }

        $name = trim((string) $googleUser->getName());

        if ($isFirstSignIn && $name !== '') {
            $user->name = $name;
        }

        if ($user->isDirty()) {
            $user->save();
        }

        if ($isFirstSignIn) {
            $this->logger->log(
                'auth.google_linked',
                "Linked the Google account for {$user->email}",
                $user,
                ['google_id' => $googleId],
                $user,
            );
        }
    }

    /**
     * Socialite exposes the raw claim only on the underlying payload. Absence
     * is treated as verified because some Workspace configurations omit it;
     * an explicit false is not.
     */
    private function emailIsUnverified(SocialiteUser $googleUser): bool
    {
        $raw = (array) ($googleUser->user ?? []);

        if (! array_key_exists('email_verified', $raw)) {
            return false;
        }

        return filter_var($raw['email_verified'], FILTER_VALIDATE_BOOLEAN) === false;
    }

    private function domainAllowed(string $email): bool
    {
        /** @var array<int, string> $allowed */
        $allowed = config('services.google.allowed_domains', []);

        if ($allowed === []) {
            return true;
        }

        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));

        return in_array($domain, array_map('strtolower', $allowed), true);
    }

    /**
     * Every refusal is logged. A burst of "not_registered" for one address is
     * how an administrator notices someone trying to get in.
     *
     * @param  array<string, mixed>  $context
     */
    private function deny(string $code, string $email, array $context = []): void
    {
        $this->logger->log(
            'auth.google_denied',
            "Refused Google sign-in for {$email}",
            null,
            ['reason' => $code, 'email' => $email] + $context,
        );
    }
}
