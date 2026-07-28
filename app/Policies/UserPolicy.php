<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Tasker management is entirely an admin capability. Stated explicitly rather
 * than via a before() hook so that every allowance is visible in one place.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $target): bool
    {
        return $user->isAdmin() || $user->id === $target->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    /**
     * Deactivation, not deletion, is the supported way to remove someone: the
     * foreign keys on attendances and tasks are restrictive precisely so that
     * history cannot be orphaned (business rule 10).
     *
     * An admin may never delete their own account, which would allow locking
     * the organisation out of its last administrator.
     */
    public function delete(User $user, User $target): bool
    {
        return $user->isAdmin() && $user->id !== $target->id;
    }

    public function restore(User $user, User $target): bool
    {
        return $user->isAdmin();
    }
}
