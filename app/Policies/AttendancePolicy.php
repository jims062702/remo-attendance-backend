<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;

/**
 * Business rule 8: a tasker may only ever reach their own attendance.
 * Business rule 9: an admin may reach everyone's.
 */
class AttendancePolicy
{
    /**
     * Admins are permitted every attendance action. Returning null (rather
     * than false) for taskers lets the individual methods decide.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, Attendance $attendance): bool
    {
        return $attendance->user_id === $user->id;
    }

    /**
     * Taskers never edit attendance directly -- clock events are the only way
     * their own record changes. Corrections are an admin action, allowed by
     * before().
     */
    public function update(User $user, Attendance $attendance): bool
    {
        return false;
    }

    public function delete(User $user, Attendance $attendance): bool
    {
        return false;
    }
}
