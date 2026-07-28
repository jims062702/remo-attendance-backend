<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Validation\Rules\Password;

/**
 * Authorise a Google address to sign in, from the command line.
 *
 * This is the bootstrap and the break-glass path. Because sign-in is
 * Google-only and accounts are never self-created, a fresh installation has
 * nobody who can reach the admin screens -- this command creates that first
 * administrator. It is equally the way back in if every admin account is ever
 * locked out or deactivated.
 *
 * Server shell access is the privilege being relied on here, which is the same
 * privilege that could edit the database directly.
 */
class AuthoriseUser extends Command
{
    protected $signature = 'user:authorise
                            {email : The Google address this person will sign in with}
                            {--name= : Display name, shown until their first sign-in}
                            {--admin : Grant administrator access}
                            {--reactivate : Restore and re-enable a deactivated account}
                            {--unlink : Clear the linked Google identity so a different Google account can claim this record}';

    protected $description = 'Authorise a Google address to sign in (creates the first admin on a new installation)';

    public function handle(ActivityLogger $logger): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("\"{$email}\" is not a valid email address.");

            return self::FAILURE;
        }

        $role = $this->option('admin') ? UserRole::Admin : UserRole::Tasker;

        $user = User::withTrashed()->where('email', $email)->first();

        if ($user === null) {
            $user = $this->createUser($email, $role);
            $action = 'created';
        } else {
            $action = $this->updateUser($user, $role);
        }

        $logger->log(
            'user.authorised_via_console',
            "Authorised {$email} as {$user->role->label()} from the console",
            $user,
            ['action' => $action, 'role' => $user->role->value],
        );

        $this->newLine();
        $this->info(match ($action) {
            'created' => "Authorised {$email} as {$user->role->label()}.",
            default => "Updated {$email} ({$user->role->label()}).",
        });

        $this->table(
            ['Field', 'Value'],
            [
                ['Name', $user->name],
                ['Email', $user->email],
                ['Role', $user->role->label()],
                ['Status', $user->status->label()],
                ['Google linked', $user->hasLinkedGoogleAccount() ? 'yes' : 'not yet'],
            ],
        );

        $this->newLine();
        $this->line('They can now sign in at '.rtrim((string) config('app.frontend_url'), '/').'/login');
        $this->line('using the Google account for '.$user->email.'.');

        if (! $this->isGoogleConfigured()) {
            $this->newLine();
            $this->warn('Google sign-in is NOT configured yet -- set GOOGLE_CLIENT_ID and');
            $this->warn('GOOGLE_CLIENT_SECRET in .env before anyone can actually sign in.');
        }

        return self::SUCCESS;
    }

    private function createUser(string $email, UserRole $role): User
    {
        $name = (string) ($this->option('name') ?: $this->deriveName($email));

        $user = new User;
        $user->fill(['name' => $name, 'email' => $email]);
        $user->role = $role;
        $user->status = UserStatus::Active;
        $user->save();

        return $user;
    }

    private function updateUser(User $user, UserRole $role): string
    {
        if ($user->trashed()) {
            if (! $this->option('reactivate')) {
                $this->warn("{$user->email} exists but is deactivated.");
                $this->line('Re-run with --reactivate to restore the account and its history.');

                return 'skipped';
            }

            $user->restore();
            $user->status = UserStatus::Active;
        }

        if ($this->option('admin')) {
            $user->role = $role;
        }

        if ($this->option('reactivate')) {
            $user->status = UserStatus::Active;
        }

        // Lets a different Google account claim the record -- for when someone
        // is locked out by the identity-mismatch check after an address was
        // reissued inside the Workspace.
        if ($this->option('unlink')) {
            $user->google_id = null;
            $this->warn('Cleared the linked Google identity; the next successful sign-in will claim this account.');
        }

        if ($name = $this->option('name')) {
            $user->name = (string) $name;
        }

        $user->save();

        return 'updated';
    }

    /**
     * "juan.dela.cruz@acme.com" -> "Juan Dela Cruz". Replaced by the real
     * Google profile name on first sign-in anyway.
     */
    private function deriveName(string $email): string
    {
        $local = (string) strstr($email, '@', true);

        return str($local)->replace(['.', '_', '-'], ' ')->squish()->title()->value();
    }

    private function isGoogleConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }
}
