<?php

declare(strict_types=1);

namespace App\Exceptions;

use Carbon\CarbonInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Violations of the attendance business rules.
 *
 * State conflicts (already clocked in, not clocked in, already clocked out)
 * return 409: the request was well formed, it just contradicts the current
 * state of the shift. Bad data returns 422.
 */
final class AttendanceException extends DomainException
{
    public static function alreadyTimedIn(CarbonInterface $businessDate): self
    {
        return new self(
            "You have already timed in for the shift of {$businessDate->format('M j, Y')}.",
            'attendance.already_timed_in',
            Response::HTTP_CONFLICT,
            ['business_date' => $businessDate->toDateString()],
        );
    }

    public static function notTimedIn(CarbonInterface $businessDate): self
    {
        return new self(
            "You have not timed in for the shift of {$businessDate->format('M j, Y')}, so there is nothing to time out from.",
            'attendance.not_timed_in',
            Response::HTTP_CONFLICT,
            ['business_date' => $businessDate->toDateString()],
        );
    }

    public static function alreadyTimedOut(CarbonInterface $businessDate): self
    {
        return new self(
            "You have already timed out for the shift of {$businessDate->format('M j, Y')}.",
            'attendance.already_timed_out',
            Response::HTTP_CONFLICT,
            ['business_date' => $businessDate->toDateString()],
        );
    }

    /**
     * Guards against a forgotten clock-out being recorded as a real 20-hour
     * shift, which would silently corrupt every hour total that includes it.
     */
    public static function shiftTooLong(float $hours, float $maxHours): self
    {
        return new self(
            sprintf(
                'This shift spans %.2f hours, which exceeds the %.2f hour maximum. This usually means a missed time out -- please ask an administrator to correct the record.',
                $hours,
                $maxHours,
            ),
            'attendance.shift_too_long',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['hours' => $hours, 'max_hours' => $maxHours],
        );
    }

    public static function timeOutBeforeTimeIn(): self
    {
        return new self(
            'Time out cannot be earlier than time in.',
            'attendance.time_out_before_time_in',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function accountNotActive(): self
    {
        return new self(
            'Your account is not active. Please contact an administrator.',
            'attendance.account_not_active',
            Response::HTTP_FORBIDDEN,
        );
    }

    /**
     * The night has already been settled as non-attendance.
     *
     * Reached either by `attendance:mark-absent` at the configured cutoff or by
     * an admin recording the absence by hand. Both are final: letting a late
     * arrival clock in over the top would quietly erase the record, and whether
     * a shift joined hours late counts at all is a judgement for a person, not
     * a consequence of the tasker pressing a button.
     */
    public static function markedAbsent(CarbonInterface $businessDate, string $statusLabel): self
    {
        return new self(
            "You were marked {$statusLabel} for the shift of {$businessDate->format('M j, Y')}, so this shift is closed. Ask an administrator if this is wrong.",
            'attendance.marked_absent',
            Response::HTTP_CONFLICT,
            ['business_date' => $businessDate->toDateString(), 'status' => $statusLabel],
        );
    }

    /**
     * The shift still has production filed against it.
     *
     * `tracker_entries.attendance_id` and `tasks.attendance_id` are both
     * nullOnDelete, so removing the shift would not fail -- it would quietly
     * detach the night's work and leave it in the Submissions list belonging
     * to nobody's shift. Refusing puts the order of operations in the admin's
     * hands: remove the submission, then remove the shift.
     */
    public static function hasProduction(int $entries, int $tasks): self
    {
        $parts = [];

        if ($entries > 0) {
            $parts[] = $entries.' tracker '.($entries === 1 ? 'entry' : 'entries');
        }

        if ($tasks > 0) {
            $parts[] = $tasks.' extra '.($tasks === 1 ? 'task' : 'tasks');
        }

        return new self(
            'This shift cannot be deleted because '.implode(' and ', $parts)
            .' are filed against it. Delete those from Submissions first, '
            .'or the work would be left attached to no shift at all.',
            'attendance.has_production',
            Response::HTTP_CONFLICT,
            ['tracker_entries' => $entries, 'tasks' => $tasks],
        );
    }

    /**
     * The production commitment attaches to a shift, so a shift must exist.
     */
    public static function noShiftForCommitment(CarbonInterface $businessDate): self
    {
        return new self(
            "You need to time in for {$businessDate->format('M j, Y')} before setting your production commitment.",
            'attendance.no_shift_for_commitment',
            Response::HTTP_CONFLICT,
            ['business_date' => $businessDate->toDateString()],
        );
    }
}
