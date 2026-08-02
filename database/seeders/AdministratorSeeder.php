<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The administrators who must always be able to sign in.
 *
 * Sign-in is Google-only and accounts are never self-created, so a freshly
 * migrated database has nobody who can reach the admin screens -- and no way to
 * create anyone, because creating users is itself an admin screen. Normally
 * `php artisan user:authorise` breaks that circle from a shell, but Render
 * gives free instances no shell at all.
 *
 * Listing the address here instead means the account is part of the code: the
 * container start-up runs this seeder after migrating, so the account exists on
 * every deploy without anyone having to type anything anywhere.
 *
 * Two consequences worth understanding before relying on it:
 *
 *  - This is a break-glass account. Deactivating it from the admin screens will
 *    not stick -- the next deploy restores and re-promotes it. Removing this
 *    person's access for real means deleting them from the list below and
 *    deploying that change.
 *
 *  - The list is committed to the repository, so these addresses are visible to
 *    anyone who can read it. An email address is not a credential -- entry
 *    still requires control of the Google account -- but if the repository is
 *    public, the address is public too, spam included.
 *
 * Safe to re-run: existing rows are updated in place, never duplicated, and a
 * name is only ever written when the account is first created.
 */
class AdministratorSeeder extends Seeder
{
    /**
     * Google address => display name.
     *
     * The name is a placeholder that only shows until the person's first
     * sign-in, at which point their real Google profile name replaces it. To
     * add another administrator, add a line here and deploy.
     *
     * @var array<string, string>
     */
    private const ADMINISTRATORS = [
        'gasang143x@gmail.com' => 'Administrator',
    ];

    public function run(): void
    {
        foreach (self::ADMINISTRATORS as $email => $name) {
            // Matched by email, so it has to be normalised the same way
            // GoogleAuthService normalises what Google sends back.
            $email = strtolower(trim($email));

            $user = User::withTrashed()->where('email', $email)->first();

            $action = 'unchanged';

            if ($user === null) {
                $user = new User;
                // The name is set here and only here. Overwriting it on every
                // deploy would replace the real profile name Google supplied
                // at sign-in with this placeholder.
                $user->fill(['name' => $name, 'email' => $email]);
                $action = 'created';
            } elseif ($user->trashed()) {
                // Deactivation soft deletes, which is what makes the account
                // "not registered" at sign-in. Restoring keeps the attendance
                // and task history attached to the same row.
                $user->restore();
                $action = 'restored';
            }

            if ($user->role !== UserRole::Admin || $user->status !== UserStatus::Active) {
                $action = $action === 'unchanged' ? 'promoted' : $action;
            }

            $user->role = UserRole::Admin;
            $user->status = UserStatus::Active;

            // google_id is deliberately left alone. It is claimed on first
            // sign-in and is what detects an address that a Workspace has
            // reissued to somebody new; clearing it here would defeat that.
            $user->save();

            $this->command?->info("Administrator {$email} ({$action}).");
        }
    }
}
