<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Repeated-absence detection.
 *
 * Answers one question: has this tasker been absent often enough recently that
 * somebody should look at whether they are still working here?
 *
 * It answers it as a RECOMMENDATION and nothing more. Nothing in this class
 * deactivates an account, and nothing calls it from a write path. Two reasons.
 * The count is only as complete as the absences an admin actually recorded --
 * `absent` is a hand-assigned status, so a quiet month of nobody marking
 * anything looks identical to perfect attendance. And ending someone's
 * engagement is not a side effect a counter should be trusted with, especially
 * one that cannot distinguish a no-show from an unrecorded emergency.
 */
class AbsenceRiskService
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function threshold(): int
    {
        return max(1, (int) config('attendance.absence.threshold', 4));
    }

    public function windowDays(): int
    {
        return max(1, (int) config('attendance.absence.window_days', 30));
    }

    /**
     * First business date inside the rolling window.
     *
     * Anchored on the business date rather than the calendar date, so a query
     * run at 2 AM counts the window from the night that is currently running
     * rather than from a "today" that started two hours ago.
     */
    public function windowStart(?CarbonInterface $at = null): CarbonImmutable
    {
        return $this->attendance
            ->resolveBusinessDate($at)
            ->subDays($this->windowDays() - 1);
    }

    /**
     * Absence counts for a set of taskers, as one grouped query.
     *
     * Takes the whole page of user ids at once and returns a map, rather than
     * exposing a per-user count that a caller would naturally reach for inside
     * a loop. That shape is the difference between one query per page and one
     * per row on a screen that renders twenty of them.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, int>  user id => absences inside the window
     */
    public function countsFor(array $userIds, ?CarbonInterface $at = null): array
    {
        if ($userIds === []) {
            return [];
        }

        $from = $this->windowStart($at)->toDateString();
        $to = $this->attendance->resolveBusinessDate($at)->toDateString();

        return Attendance::query()
            ->selectRaw('user_id, COUNT(*) AS absences')
            ->whereIn('user_id', $userIds)
            ->where('status', AttendanceStatus::Absent->value)
            ->whereBetween('attendance_date', [$from, $to])
            ->groupBy('user_id')
            ->pluck('absences', 'user_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /** Absences inside the window for one tasker. */
    public function countFor(int $userId, ?CarbonInterface $at = null): int
    {
        return $this->countsFor([$userId], $at)[$userId] ?? 0;
    }

    public function isAtRisk(int $absences): bool
    {
        return $absences >= $this->threshold();
    }

    /**
     * The risk block attached to a tasker in API responses.
     *
     * Ships the threshold and window alongside the count so the client can
     * phrase the warning without hardcoding "4" and "30" -- both are
     * configurable, and a client that assumed them would start lying the moment
     * an operation changed them.
     *
     * @return array<string, mixed>
     */
    public function payload(int $absences, ?CarbonInterface $at = null): array
    {
        return [
            'absences' => $absences,
            'threshold' => $this->threshold(),
            'window_days' => $this->windowDays(),
            'window_start' => $this->windowStart($at)->toDateString(),
            'at_risk' => $this->isAtRisk($absences),
            // One short below the threshold. Worth surfacing separately: the
            // point of a warning is to arrive before the decision, not with it.
            'approaching' => ! $this->isAtRisk($absences) && $absences === $this->threshold() - 1,
        ];
    }
}
