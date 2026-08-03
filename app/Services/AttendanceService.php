<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Exceptions\AttendanceException;
use App\Models\Attendance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * All attendance business logic. Controllers call into this and do no
 * calculation of their own, so the Excel importer, the admin correction
 * endpoint and the tasker's Time In button all agree on the rules.
 *
 * Every timestamp originates here from the server clock. A caller may pass an
 * explicit $at, but only tests and the importer do -- request payloads never
 * reach it, so a tasker cannot backdate their own attendance.
 */
class AttendanceService
{
    public function __construct(private readonly ActivityLogger $logger) {}

    // ------------------------------------------------------------ Business date

    /**
     * Map a wall-clock moment onto the business date whose shift it belongs to.
     *
     * With a 22:00 -> 06:00 shift and an 18:00 cutoff, a single shift spans two
     * calendar dates but one business date. Anything before the cutoff is still
     * part of the previous day's shift:
     *
     *   Jul 26 22:05  ->  Jul 26   (on time)
     *   Jul 27 00:30  ->  Jul 26   (late for Jul 26's shift)
     *   Jul 27 05:00  ->  Jul 26   (near shift end)
     *   Jul 27 19:00  ->  Jul 27   (early for the next shift)
     */
    public function resolveBusinessDate(?CarbonInterface $at = null): CarbonImmutable
    {
        $moment = $at ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        [$hour, $minute] = $this->parseClockTime(config('attendance.business_day_cutoff'));

        $cutoff = $moment->setTime($hour, $minute, 0);

        return $moment->lessThan($cutoff)
            ? $moment->subDay()->startOfDay()
            : $moment->startOfDay();
    }

    /**
     * The moment a given business date's shift was scheduled to start.
     */
    public function scheduledStart(CarbonInterface $businessDate): CarbonImmutable
    {
        [$hour, $minute] = $this->parseClockTime(config('attendance.shift_start'));

        return CarbonImmutable::instance($businessDate)->startOfDay()->setTime($hour, $minute, 0);
    }

    /**
     * The moment a given business date's shift was scheduled to end. Rolls to
     * the next calendar day whenever the shift end is earlier than its start,
     * which is what makes this an overnight shift.
     */
    public function scheduledEnd(CarbonInterface $businessDate): CarbonImmutable
    {
        [$startHour, $startMinute] = $this->parseClockTime(config('attendance.shift_start'));
        [$endHour, $endMinute] = $this->parseClockTime(config('attendance.shift_end'));

        $end = CarbonImmutable::instance($businessDate)->startOfDay()->setTime($endHour, $endMinute, 0);

        $startsAfterEnds = ($startHour * 60 + $startMinute) >= ($endHour * 60 + $endMinute);

        return $startsAfterEnds ? $end->addDay() : $end;
    }

    /**
     * The next moment the business date rolls over — when a new shift opens.
     *
     * Exposed because it is the answer to the most common question a finished
     * tasker has: "when can I file another night?". Left to the interface to
     * guess, it becomes folklore; taken from the same config the resolver
     * uses, it cannot drift from the actual behaviour.
     */
    public function nextBusinessDateRollover(?CarbonInterface $at = null): CarbonImmutable
    {
        $moment = $at ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        [$hour, $minute] = $this->parseClockTime(config('attendance.business_day_cutoff'));

        $todaysCutoff = $moment->setTime($hour, $minute, 0);

        // At or past today's cutoff the date has already rolled, so the next
        // one is tomorrow's.
        return $moment->lessThan($todaysCutoff) ? $todaysCutoff : $todaysCutoff->addDay();
    }

    // ------------------------------------------------------------------ Queries

    /**
     * The shift record for the business date currently in progress, if any.
     */
    public function currentFor(User $user, ?CarbonInterface $at = null): ?Attendance
    {
        return Attendance::query()
            ->where('user_id', $user->id)
            ->where('attendance_date', $this->resolveBusinessDate($at)->toDateString())
            ->first();
    }

    // ------------------------------------------------------------------- Clock

    /**
     * Record a clock-in on server time.
     *
     * @throws AttendanceException when the account is inactive, or a shift for
     *                             this business date has already been started.
     */
    public function timeIn(User $user, ?CarbonInterface $at = null): Attendance
    {
        $this->assertActive($user);

        $now = $at ? CarbonImmutable::instance($at) : CarbonImmutable::now();
        $businessDate = $this->resolveBusinessDate($now);

        $attendance = DB::transaction(function () use ($user, $now, $businessDate): Attendance {
            // A record may already exist without a clock-in -- an admin marking
            // someone absent, for instance. Lock it so two concurrent requests
            // cannot both see time_in as null.
            $existing = Attendance::query()
                ->where('user_id', $user->id)
                ->where('attendance_date', $businessDate->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->time_in !== null) {
                    throw AttendanceException::alreadyTimedIn($businessDate);
                }

                // A record with no clock-in means the night was already settled
                // as non-attendance -- by `attendance:mark-absent` at the
                // cutoff, or by an admin. Clocking in here would overwrite that
                // decision with a `late`, which is how an absence quietly
                // disappears: the roll call, the absence-warning counter and
                // any report already filed would all silently disagree with
                // each other afterwards.
                if (! $existing->status->isWorked()) {
                    throw AttendanceException::markedAbsent(
                        $businessDate,
                        $existing->status->label(),
                    );
                }

                $existing->forceFill([
                    'time_in' => $now,
                    'status' => $this->resolveClockInStatus($now, $businessDate),
                ])->save();

                return $existing;
            }

            try {
                return Attendance::create([
                    'user_id' => $user->id,
                    'attendance_date' => $businessDate->toDateString(),
                    'time_in' => $now,
                    'status' => $this->resolveClockInStatus($now, $businessDate),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Lost a race with a concurrent clock-in. The unique index on
                // (user_id, attendance_date) is the real guarantee here; this
                // just translates it into the business-level error.
                throw AttendanceException::alreadyTimedIn($businessDate);
            }
        });

        $this->logger->log(
            'attendance.time_in',
            "Timed in for {$businessDate->toDateString()}",
            $attendance,
            ['time_in' => $attendance->time_in?->toDateTimeString(), 'status' => $attendance->status->value],
            $user,
        );

        return $attendance;
    }

    /**
     * Record a clock-out on server time and compute hours rendered.
     *
     * @throws AttendanceException when there is no open shift, it was already
     *                             closed, or the span is implausibly long.
     */
    public function timeOut(User $user, ?CarbonInterface $at = null): Attendance
    {
        $this->assertActive($user);

        $now = $at ? CarbonImmutable::instance($at) : CarbonImmutable::now();
        $businessDate = $this->resolveBusinessDate($now);

        $attendance = DB::transaction(function () use ($user, $now, $businessDate): Attendance {
            $attendance = Attendance::query()
                ->where('user_id', $user->id)
                ->where('attendance_date', $businessDate->toDateString())
                ->lockForUpdate()
                ->first();

            if ($attendance === null || $attendance->time_in === null) {
                throw AttendanceException::notTimedIn($businessDate);
            }

            if ($attendance->time_out !== null) {
                throw AttendanceException::alreadyTimedOut($businessDate);
            }

            $hours = $this->computeHours($attendance->time_in, $now);

            $attendance->forceFill([
                'time_out' => $now,
                'total_hours' => $hours,
            ])->save();

            return $attendance;
        });

        $this->logger->log(
            'attendance.time_out',
            "Timed out for {$businessDate->toDateString()}",
            $attendance,
            [
                'time_out' => $attendance->time_out?->toDateTimeString(),
                'total_hours' => $attendance->total_hours,
            ],
            $user,
        );

        return $attendance;
    }

    /**
     * Record the tasker's production commitment for the current shift.
     *
     * Stored on the shift rather than on each task, so "expected vs actual"
     * has exactly one answer per day.
     */
    public function setCommitment(User $user, float $hours, ?CarbonInterface $at = null): Attendance
    {
        $this->assertActive($user);

        $businessDate = $this->resolveBusinessDate($at);

        $attendance = $this->currentFor($user, $at);

        if ($attendance === null || $attendance->time_in === null) {
            throw AttendanceException::noShiftForCommitment($businessDate);
        }

        $previous = $attendance->expected_hours;

        $attendance->forceFill(['expected_hours' => $hours])->save();

        $this->logger->log(
            'attendance.commitment_set',
            "Set production commitment to {$hours} hours for {$businessDate->toDateString()}",
            $attendance,
            ['from' => $previous, 'to' => $hours],
            $user,
        );

        return $attendance;
    }

    // -------------------------------------------------------------- Calculation

    /**
     * Hours between two moments, rounded to two decimals.
     *
     * Uses raw timestamps rather than Carbon's diff helpers so the result is
     * unambiguously signed and unaffected by Carbon version differences. Because
     * both sides are full datetimes, a shift crossing midnight needs no special
     * handling -- it is the same subtraction as any other.
     *
     * @throws AttendanceException when time out precedes time in, or the span
     *                             exceeds the configured maximum.
     */
    public function computeHours(CarbonInterface $timeIn, CarbonInterface $timeOut): float
    {
        $seconds = $timeOut->getTimestamp() - $timeIn->getTimestamp();

        if ($seconds <= 0) {
            throw AttendanceException::timeOutBeforeTimeIn();
        }

        $hours = round($seconds / 3600, 2);
        $max = (float) config('attendance.max_shift_hours');

        if ($hours > $max) {
            throw AttendanceException::shiftTooLong($hours, $max);
        }

        return $hours;
    }

    /**
     * Present or late, measured against the shift start of the record's own
     * business date -- never against "today", which would misjudge anyone who
     * clocked in after midnight.
     */
    public function resolveClockInStatus(CarbonInterface $timeIn, CarbonInterface $businessDate): AttendanceStatus
    {
        $graceEnd = $this->scheduledStart($businessDate)
            ->addMinutes((int) config('attendance.grace_minutes'));

        return $timeIn->greaterThan($graceEnd)
            ? AttendanceStatus::Late
            : AttendanceStatus::Present;
    }

    /**
     * Minutes late, or 0 when within grace. Used for reporting.
     */
    public function minutesLate(CarbonInterface $timeIn, CarbonInterface $businessDate): int
    {
        $scheduled = $this->scheduledStart($businessDate);

        // Nothing is reported while the arrival is still within grace. Measuring
        // from the scheduled start alone would report a 22:05 clock-in as "5m
        // late" beside a Present badge -- two contradictory statements about
        // the same record. Arriving at 22:00 is never late, and neither is
        // anything up to the end of the grace window.
        $graceEnd = $scheduled->addMinutes((int) config('attendance.grace_minutes'));

        if ($timeIn->lessThanOrEqualTo($graceEnd)) {
            return 0;
        }

        // Past grace: report the full lateness from the scheduled start, which
        // is what the operation means by "how late were they".
        return (int) round(($timeIn->getTimestamp() - $scheduled->getTimestamp()) / 60);
    }

    // ------------------------------------------------------------------ Helpers

    private function assertActive(User $user): void
    {
        if (! $user->canAuthenticate()) {
            throw AttendanceException::accountNotActive();
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseClockTime(mixed $value): array
    {
        $parts = explode(':', (string) $value);

        return [(int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0)];
    }

}
