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
