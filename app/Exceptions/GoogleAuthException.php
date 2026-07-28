<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * Reasons a Google sign-in was refused.
 *
 * Each carries a stable `code` that the login screen turns into a specific
 * message, so a user who is merely not set up yet is not told the same thing
 * as one whose account was suspended.
 */
final class GoogleAuthException extends DomainException
{
    /**
     * The Google account is valid, but nobody has been given access under that
     * address. Accounts are never created by signing in.
     */
    public static function notRegistered(string $email): self
    {
        return new self(
            "There is no account for {$email}. Please ask an administrator to add you first.",
            'google.not_registered',
            Response::HTTP_FORBIDDEN,
            ['email' => $email],
        );
    }

    public static function accountNotActive(string $email, string $status): self
    {
        return new self(
            "The account for {$email} is {$status}. Please contact an administrator.",
            'google.account_not_active',
            Response::HTTP_FORBIDDEN,
            ['email' => $email],
        );
    }

    /**
     * Google reports the address as unverified, so it does not prove ownership.
     */
    public static function emailNotVerified(string $email): self
    {
        return new self(
            "Google has not verified {$email}. Please verify the address with Google and try again.",
            'google.email_not_verified',
            Response::HTTP_FORBIDDEN,
            ['email' => $email],
        );
    }

    public static function domainNotAllowed(string $email): self
    {
        return new self(
            "{$email} is not on an approved domain for this workspace.",
            'google.domain_not_allowed',
            Response::HTTP_FORBIDDEN,
            ['email' => $email],
        );
    }

    /**
     * The stored Google subject id does not match the one presenting now.
     *
     * The address was almost certainly deleted and reissued to a different
     * person inside the Workspace. Refusing here stops the new holder from
     * inheriting the previous employee's attendance record.
     */
    public static function identityMismatch(string $email): self
    {
        return new self(
            "The Google account for {$email} does not match the one this account was set up with. Please contact an administrator.",
            'google.identity_mismatch',
            Response::HTTP_FORBIDDEN,
            ['email' => $email],
        );
    }

    public static function notConfigured(): self
    {
        return new self(
            'Google sign-in has not been configured on this server.',
            'google.not_configured',
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /**
     * Google returned an error, or the user dismissed the consent screen.
     */
    public static function failed(string $reason = ''): self
    {
        return new self(
            'Google sign-in could not be completed. Please try again.',
            'google.failed',
            Response::HTTP_BAD_REQUEST,
            $reason === '' ? [] : ['reason' => $reason],
        );
    }
}
