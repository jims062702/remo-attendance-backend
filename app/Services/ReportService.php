<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\Task;
use App\Models\TrackerEntry;
use App\Models\TrackerItem;
use App\Models\User;
use App\Support\Sql;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every aggregate figure the system reports.
 *
 * The dashboard, the analytics charts and the Excel exports all read from here
 * so that a total shown on screen and the same total in a downloaded report can
 * never disagree.
 *
 * Date filters compare directly against the DATE columns rather than wrapping
 * them in DATE(), which would prevent the indexes from being used.
 */
class ReportService
{
    public function __construct(private readonly AttendanceService $attendance) {}

    // -------------------------------------------------------------- Dashboard

    /**
     * Summary cards for the admin dashboard, scoped to the business date
     * currently in progress.
     *
     * @return array<string, mixed>
     */
    public function adminDashboard(): array
    {
        $businessDate = $this->attendance->resolveBusinessDate()->toDateString();

        $taskerCounts = User::query()
            ->where('role', UserRole::Tasker)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw(Sql::countIf('status = ?').' AS active', [UserStatus::Active->value])
            ->first();

        /*
         * Tonight's attendance, counted per status rather than in total.
         *
         * The totals used to be `records` and `late` alone, which left the
         * dashboard deriving "present" as records minus late. That silently
         * counted every absence as an attendance: one tasker marked absent
         * produced "Present 1" and a 100% attendance rate, because an absence
         * IS a record. The fix is to have the database answer the question
         * being asked instead of having the client infer it from a total that
         * never meant what it was being used for.
         */
        $attendanceToday = Attendance::query()
            ->where('attendance_date', $businessDate)
            ->selectRaw('COUNT(*) AS records')
            ->selectRaw(Sql::countIf('time_in IS NOT NULL AND time_out IS NULL').' AS currently_in')
            ->selectRaw(Sql::countIf('status = ?').' AS present', [AttendanceStatus::Present->value])
            ->selectRaw(Sql::countIf('status = ?').' AS late', [AttendanceStatus::Late->value])
            ->selectRaw(Sql::countIf('status = ?').' AS incomplete', [AttendanceStatus::Incomplete->value])
            ->selectRaw(Sql::countIf('status = ?').' AS absent', [AttendanceStatus::Absent->value])
            ->selectRaw(Sql::countIf('status = ?').' AS on_leave', [AttendanceStatus::OnLeave->value])
            ->selectRaw('COALESCE(SUM(total_hours), 0) AS total_hours')
            ->first();

        /*
         * Tonight's production, from the NIGHTLY TRACKER.
         *
         * This is the fix for a dashboard that reported zero submissions and
         * zero output on a night when people had plainly been working. The two
         * production figures were read from the `tasks` table -- the separate,
         * optional "Extra Tasks" page -- while the tracker entry filed through
         * the nightly flow, which is how essentially all production is actually
         * recorded, was never counted at all.
         *
         * The product's own vocabulary settles which source is right: the admin
         * "Submissions" screen lists tracker entries. A dashboard tile labelled
         * Submissions therefore has to mean the same thing, or the number
         * disagrees with the page it links to.
         *
         * Extra tasks are still reported, under their own name, so nothing that
         * was being measured has stopped being measured.
         */
        $trackerToday = TrackerEntry::query()
            ->where('entry_date', $businessDate)
            ->selectRaw('COUNT(*) AS entries')
            ->selectRaw('COALESCE(SUM(task_id_count), 0) AS task_ids')
            ->selectRaw('COALESCE(SUM(sbq_count), 0) AS sbq')
            ->first();

        // Declared tasks live on the per-project blocks, so they are summed
        // through the join rather than off the entry.
        $trackerTasks = (int) TrackerItem::query()
            ->whereIn(
                'tracker_entry_id',
                TrackerEntry::query()->where('entry_date', $businessDate)->select('id'),
            )
            ->sum('total_tasks');

        $tasksToday = Task::query()
            ->where('task_date', $businessDate)
            ->selectRaw('COUNT(*) AS submissions')
            ->selectRaw('COALESCE(SUM(output_count), 0) AS output')
            ->selectRaw(Sql::countIf('task_status = ?').' AS completed', [TaskStatus::Completed->value])
            ->selectRaw(Sql::countIf('task_status = ?').' AS pending', [TaskStatus::Pending->value])
            ->selectRaw(Sql::countIf('task_status = ?').' AS in_progress', [TaskStatus::InProgress->value])
            ->first();

        return [
            'business_date' => $businessDate,
            'shift_start' => $this->attendance->scheduledStart(CarbonImmutable::parse($businessDate))->toDateTimeString(),
            'shift_end' => $this->attendance->scheduledEnd(CarbonImmutable::parse($businessDate))->toDateTimeString(),
            'total_taskers' => (int) ($taskerCounts->total ?? 0),
            'active_taskers' => (int) ($taskerCounts->active ?? 0),
            // Every record filed for tonight, whatever it says. Kept because
            // "how many rows exist" is still a real question -- it is just not
            // the same question as "how many people turned up".
            'attendance_today' => (int) ($attendanceToday->records ?? 0),
            'currently_timed_in' => (int) ($attendanceToday->currently_in ?? 0),

            'present_today' => (int) ($attendanceToday->present ?? 0),
            'late_today' => (int) ($attendanceToday->late ?? 0),
            'incomplete_today' => (int) ($attendanceToday->incomplete ?? 0),
            'absent_today' => (int) ($attendanceToday->absent ?? 0),
            'on_leave_today' => (int) ($attendanceToday->on_leave ?? 0),

            // The three statuses that mean the person actually worked -- the
            // honest numerator for an attendance rate. See
            // AttendanceStatus::isWorked(), which this mirrors.
            'worked_today' => (int) ($attendanceToday->present ?? 0)
                + (int) ($attendanceToday->late ?? 0)
                + (int) ($attendanceToday->incomplete ?? 0),

            'total_hours_today' => round((float) ($attendanceToday->total_hours ?? 0), 2),

            // Production, as filed through the nightly flow.
            'submissions_today' => (int) ($trackerToday->entries ?? 0),
            'output_today' => $trackerTasks,
            'task_ids_today' => (int) ($trackerToday->task_ids ?? 0),
            'sbq_today' => (int) ($trackerToday->sbq ?? 0),

            // The optional Extra Tasks page, kept distinct so the two are never
            // silently added together.
            'extra_tasks_today' => (int) ($tasksToday->submissions ?? 0),
            'extra_output_today' => (int) ($tasksToday->output ?? 0),
            'completed_tasks_today' => (int) ($tasksToday->completed ?? 0),
            'pending_tasks_today' => (int) ($tasksToday->pending ?? 0),
            'in_progress_tasks_today' => (int) ($tasksToday->in_progress ?? 0),
        ];
    }

    // -------------------------------------------------------------- Analytics

    /**
     * Time series of attendance for the charts.
     *
     * @param  array<string, mixed>  $filters  from, to, user_id, group_by
     * @return array<string, mixed>
     */
    public function attendanceAnalytics(array $filters): array
    {
        $groupBy = in_array($filters['group_by'] ?? 'day', ['day', 'week', 'month'], true)
            ? $filters['group_by'] ?? 'day'
            : 'day';

        // $groupBy is already narrowed to day/week/month above, so nothing
        // caller-supplied reaches the SQL string.
        $bucket = Sql::dateBucket('attendance_date', $groupBy);

        $series = $this->attendanceQuery($filters)
            ->selectRaw("{$bucket} AS bucket")
            ->selectRaw('COUNT(*) AS records')
            ->selectRaw(Sql::countIf('status = ?').' AS present', [AttendanceStatus::Present->value])
            ->selectRaw(Sql::countIf('status = ?').' AS late', [AttendanceStatus::Late->value])
            ->selectRaw(Sql::countIf('status = ?').' AS incomplete', [AttendanceStatus::Incomplete->value])
            ->selectRaw(Sql::countIf('status = ?').' AS absent', [AttendanceStatus::Absent->value])
            ->selectRaw(Sql::countIf('time_in IS NOT NULL AND time_out IS NULL').' AS missing_time_out')
            ->selectRaw('COALESCE(SUM(total_hours), 0) AS total_hours')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->map(fn ($row) => [
                'bucket' => (string) $row->bucket,
                'records' => (int) $row->records,
                'present' => (int) $row->present,
                'late' => (int) $row->late,
                'incomplete' => (int) $row->incomplete,
                'absent' => (int) $row->absent,
                'missing_time_out' => (int) $row->missing_time_out,
                'total_hours' => round((float) $row->total_hours, 2),
            ]);

        return [
            'group_by' => $groupBy,
            'series' => $series,
            'totals' => $this->attendanceTotals($filters),
        ];
    }

    /**
     * Headline totals for a filtered attendance set.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function attendanceTotals(array $filters): array
    {
        $row = $this->attendanceQuery($filters)
            ->selectRaw('COUNT(*) AS records')
            ->selectRaw('COUNT(DISTINCT user_id) AS taskers')
            ->selectRaw('COALESCE(SUM(total_hours), 0) AS total_hours')
            ->selectRaw('AVG(total_hours) AS average_hours')
            ->selectRaw(Sql::countIf('status = ?').' AS late', [AttendanceStatus::Late->value])
            // Absence is the figure the screen was missing entirely: it could
            // report how many people were late but not how many never came,
            // which is the more consequential of the two.
            ->selectRaw(Sql::countIf('status = ?').' AS absent', [AttendanceStatus::Absent->value])
            ->selectRaw(Sql::countIf('status = ?').' AS on_leave', [AttendanceStatus::OnLeave->value])
            ->selectRaw(Sql::countIf('time_in IS NOT NULL AND time_out IS NULL').' AS missing_time_out')
            ->selectRaw('COALESCE(SUM(expected_hours), 0) AS expected_hours')
            ->first();

        $totalHours = round((float) ($row->total_hours ?? 0), 2);
        $expectedHours = round((float) ($row->expected_hours ?? 0), 2);

        return [
            'records' => (int) ($row->records ?? 0),
            'taskers' => (int) ($row->taskers ?? 0),
            'total_hours' => $totalHours,
            'average_hours' => $row->average_hours === null ? null : round((float) $row->average_hours, 2),
            'late' => (int) ($row->late ?? 0),
            'absent' => (int) ($row->absent ?? 0),
            'on_leave' => (int) ($row->on_leave ?? 0),
            'missing_time_out' => (int) ($row->missing_time_out ?? 0),
            'expected_hours' => $expectedHours,
            'variance' => round($totalHours - $expectedHours, 2),
        ];
    }

    // ---------------------------------------------------------- Tasker summary

    /**
     * Everything the tasker detail page needs, in a fixed number of queries.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function taskerSummary(User $user, array $filters = []): array
    {
        $filters['user_id'] = $user->id;

        $attendance = $this->attendanceQuery($filters)
            ->selectRaw('COUNT(*) AS records')
            ->selectRaw(Sql::countIf('status IN (?, ?, ?)').' AS days_worked', [
                AttendanceStatus::Present->value,
                AttendanceStatus::Late->value,
                AttendanceStatus::Incomplete->value,
            ])
            ->selectRaw(Sql::countIf('status = ?').' AS late_days', [AttendanceStatus::Late->value])
            ->selectRaw(Sql::countIf('status = ?').' AS absent_days', [AttendanceStatus::Absent->value])
            ->selectRaw(Sql::countIf('time_in IS NOT NULL AND time_out IS NULL').' AS missing_time_out')
            ->selectRaw('COALESCE(SUM(total_hours), 0) AS total_hours')
            ->selectRaw('AVG(total_hours) AS average_hours')
            ->selectRaw('COALESCE(SUM(expected_hours), 0) AS expected_hours')
            ->first();

        $tasks = $this->taskQuery($filters)
            ->selectRaw('COUNT(*) AS total_tasks')
            ->selectRaw(Sql::countIf('task_status = ?').' AS completed', [TaskStatus::Completed->value])
            ->selectRaw(Sql::countIf('task_status = ?').' AS pending', [TaskStatus::Pending->value])
            ->selectRaw(Sql::countIf('task_status = ?').' AS in_progress', [TaskStatus::InProgress->value])
            ->selectRaw(Sql::countIf('task_status = ?').' AS cancelled', [TaskStatus::Cancelled->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN task_status != ? THEN output_count ELSE 0 END), 0) AS total_output', [
                TaskStatus::Cancelled->value,
            ])
            ->selectRaw('COUNT(DISTINCT task_date) AS active_days')
            ->first();

        /*
         * The tasker's nightly-tracker production for the same range.
         *
         * Reported as its own block rather than folded into `productivity`.
         * That block's ratios -- completion rate above all -- are derived from
         * task STATUSES, which the tracker has no concept of. Mixing tracker
         * totals into the numerator while the denominator still came from the
         * tasks table would produce completion percentages that are quietly
         * wrong, which is worse than the zero this is fixing.
         */
        $production = TrackerEntry::query()
            ->where('user_id', $user->id)
            ->when(
                $filters['from'] ?? null,
                fn (Builder $q, $from) => $q->where('entry_date', '>=', $from),
            )
            ->when(
                $filters['to'] ?? null,
                fn (Builder $q, $to) => $q->where('entry_date', '<=', $to),
            )
            ->selectRaw('COUNT(*) AS nights')
            ->selectRaw('COALESCE(SUM(task_id_count), 0) AS task_ids')
            ->selectRaw('COALESCE(SUM(sbq_count), 0) AS sbq')
            ->selectRaw('COALESCE(SUM(declared_hours), 0) AS declared_hours')
            ->first();

        $productionTasks = (int) TrackerItem::query()
            ->whereIn('tracker_entry_id', TrackerEntry::query()
                ->where('user_id', $user->id)
                ->when(
                    $filters['from'] ?? null,
                    fn (Builder $q, $from) => $q->where('entry_date', '>=', $from),
                )
                ->when(
                    $filters['to'] ?? null,
                    fn (Builder $q, $to) => $q->where('entry_date', '<=', $to),
                )
                ->select('id'))
            ->sum('total_tasks');

        $nights = (int) ($production->nights ?? 0);

        $daysWorked = (int) ($attendance->days_worked ?? 0);
        $totalHours = round((float) ($attendance->total_hours ?? 0), 2);
        $expectedHours = round((float) ($attendance->expected_hours ?? 0), 2);
        $totalTasks = (int) ($tasks->total_tasks ?? 0);
        $completed = (int) ($tasks->completed ?? 0);
        $cancelled = (int) ($tasks->cancelled ?? 0);
        $totalOutput = (int) ($tasks->total_output ?? 0);
        $activeDays = (int) ($tasks->active_days ?? 0);

        // Denominator excludes cancelled work so a cancelled task cannot count
        // against a tasker's completion rate.
        $completable = max($totalTasks - $cancelled, 0);

        return [
            'attendance' => [
                'records' => (int) ($attendance->records ?? 0),
                'days_worked' => $daysWorked,
                'late_days' => (int) ($attendance->late_days ?? 0),
                'absent_days' => (int) ($attendance->absent_days ?? 0),
                'missing_time_out' => (int) ($attendance->missing_time_out ?? 0),
                'total_hours' => $totalHours,
                'average_hours_per_day' => $daysWorked > 0 ? round($totalHours / $daysWorked, 2) : null,
                'expected_hours' => $expectedHours,
                'variance' => round($totalHours - $expectedHours, 2),
                'attendance_rate' => $this->attendanceRate($daysWorked, $filters),
            ],
            'productivity' => [
                'total_tasks' => $totalTasks,
                'completed_tasks' => $completed,
                'pending_tasks' => (int) ($tasks->pending ?? 0),
                'in_progress_tasks' => (int) ($tasks->in_progress ?? 0),
                'cancelled_tasks' => $cancelled,
                'total_output' => $totalOutput,
                'average_daily_output' => $activeDays > 0 ? round($totalOutput / $activeDays, 2) : null,
                'completion_rate' => $completable > 0
                    ? round($completed / $completable * 100, 2)
                    : null,
                'output_per_hour' => $totalHours > 0 ? round($totalOutput / $totalHours, 2) : null,
            ],
            // What the tasker actually filed through the nightly flow. For most
            // people this is all of their production; `productivity` above
            // covers only the optional Extra Tasks page.
            'production' => [
                'nights_filed' => $nights,
                'total_tasks' => $productionTasks,
                'task_ids' => (int) ($production->task_ids ?? 0),
                'sbq' => (int) ($production->sbq ?? 0),
                'declared_hours' => round((float) ($production->declared_hours ?? 0), 2),
                'average_tasks_per_night' => $nights > 0 ? round($productionTasks / $nights, 2) : null,
                'tasks_per_hour' => $totalHours > 0 ? round($productionTasks / $totalHours, 2) : null,
            ],
        ];
    }

    /**
     * Per-tasker rollup used by the "Tasker Summary" report.
     *
     * Aggregates attendance and tasks separately, then merges in PHP. Doing it
     * as one SQL join would multiply hours by the number of tasks per day and
     * inflate every total.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function taskerSummaryReport(array $filters): Collection
    {
        $attendance = $this->attendanceQuery($filters)
            ->selectRaw('user_id')
            ->selectRaw(Sql::countIf('status IN (?, ?, ?)').' AS days_worked', [
                AttendanceStatus::Present->value,
                AttendanceStatus::Late->value,
                AttendanceStatus::Incomplete->value,
            ])
            ->selectRaw(Sql::countIf('status = ?').' AS late_days', [AttendanceStatus::Late->value])
            ->selectRaw('COALESCE(SUM(total_hours), 0) AS total_hours')
            ->selectRaw('AVG(total_hours) AS average_hours')
            ->selectRaw('COALESCE(SUM(expected_hours), 0) AS expected_hours')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $tasks = $this->taskQuery($filters)
            ->selectRaw('user_id')
            ->selectRaw('COUNT(*) AS total_tasks')
            ->selectRaw(Sql::countIf('task_status = ?').' AS completed', [TaskStatus::Completed->value])
            ->selectRaw(Sql::countIf('task_status = ?').' AS cancelled', [TaskStatus::Cancelled->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN task_status != ? THEN output_count ELSE 0 END), 0) AS total_output', [
                TaskStatus::Cancelled->value,
            ])
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $userIds = $attendance->keys()->merge($tasks->keys())->unique()->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($attendance, $tasks): array {
                $a = $attendance->get($user->id);
                $t = $tasks->get($user->id);

                $daysWorked = (int) ($a->days_worked ?? 0);
                $totalHours = round((float) ($a->total_hours ?? 0), 2);
                $expectedHours = round((float) ($a->expected_hours ?? 0), 2);
                $totalTasks = (int) ($t->total_tasks ?? 0);
                $completed = (int) ($t->completed ?? 0);
                $cancelled = (int) ($t->cancelled ?? 0);
                $completable = max($totalTasks - $cancelled, 0);

                return [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status->value,
                    'days_worked' => $daysWorked,
                    'late_days' => (int) ($a->late_days ?? 0),
                    'total_hours' => $totalHours,
                    'average_hours' => $a?->average_hours === null ? null : round((float) $a->average_hours, 2),
                    'expected_hours' => $expectedHours,
                    'variance' => round($totalHours - $expectedHours, 2),
                    'total_tasks' => $totalTasks,
                    'completed_tasks' => $completed,
                    'total_output' => (int) ($t->total_output ?? 0),
                    'average_daily_output' => $daysWorked > 0
                        ? round((int) ($t->total_output ?? 0) / $daysWorked, 2)
                        : null,
                    'completion_rate' => $completable > 0
                        ? round($completed / $completable * 100, 2)
                        : null,
                ];
            });
    }

    // ------------------------------------------------------- Filtered queries

    /**
     * Base attendance query with all shared filters applied. Reused by the
     * table endpoint, the analytics endpoint and the exporters.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Attendance>
     */
    public function attendanceQuery(array $filters): Builder
    {
        return Attendance::query()
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->where('attendance_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->where('attendance_date', '<=', $to))
            ->when($filters['user_id'] ?? null, fn (Builder $q, $id) => $q->where('user_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn (Builder $q, $term) => $q->whereHas(
                'user',
                fn (Builder $u) => $u->search($term),
            ));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Task>
     */
    public function taskQuery(array $filters): Builder
    {
        return Task::query()
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->where('task_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->where('task_date', '<=', $to))
            ->when($filters['user_id'] ?? null, fn (Builder $q, $id) => $q->where('user_id', $id))
            ->when($filters['task_status'] ?? null, fn (Builder $q, $status) => $q->where('task_status', $status))
            ->when($filters['search'] ?? null, fn (Builder $q, $term) => $q->search($term));
    }

    // ----------------------------------------------------------------- Helpers

    /**
     * Days worked as a percentage of the business dates in the filtered range.
     *
     * Every date in the range is treated as a scheduled day; the system has no
     * roster, so weekends and holidays are not excluded. Returns null when the
     * range is open-ended, where the denominator would be meaningless.
     *
     * @param  array<string, mixed>  $filters
     */
    private function attendanceRate(int $daysWorked, array $filters): ?float
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        if ($from === null || $to === null) {
            return null;
        }

        $days = CarbonImmutable::parse((string) $from)->startOfDay()
            ->diffInDays(CarbonImmutable::parse((string) $to)->startOfDay()) + 1;

        return $days > 0 ? round($daysWorked / $days * 100, 2) : null;
    }

    /**
     * Distinct dates that actually have attendance, for chart axes that should
     * not invent empty buckets.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, string>
     */
    public function activeDates(array $filters): Collection
    {
        return $this->attendanceQuery($filters)
            ->select('attendance_date')
            ->distinct()
            ->orderBy('attendance_date')
            ->pluck('attendance_date')
            ->map(fn ($d) => CarbonImmutable::parse((string) $d)->toDateString());
    }

    /**
     * Raw DB facade access for the few places a subquery is clearer than
     * Eloquent. Kept here so callers do not import DB directly.
     */
    public function connection(): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection();
    }
}
