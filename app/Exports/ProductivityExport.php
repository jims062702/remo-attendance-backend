<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Task;
use App\Services\ReportService;
use Illuminate\Support\Collection;

/**
 * Productivity report: one row per task submission, with the expected and
 * actual hours of the shift it belongs to so variance is readable in place.
 *
 * Because expected_hours lives on the shift rather than on each task, a day
 * with three submissions repeats the same commitment across three rows -- that
 * is presentation, not duplicated storage, and the totals row deliberately
 * sums hours only once per shift.
 */
class ProductivityExport extends ReportExport
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        array $filters,
        private readonly ReportService $reports,
    ) {
        parent::__construct($filters);
    }

    protected function reportTitle(): string
    {
        return 'Productivity Report';
    }

    /**
     * @return array<string, int>
     */
    protected function columnMap(): array
    {
        return [
            'Date' => 14,
            'Tasker' => 26,
            'Email' => 30,
            'Task Code' => 20,
            'Reference' => 18,
            'Task' => 28,
            'Description' => 36,
            'Output' => 10,
            'Committed Hours' => 16,
            'Actual Hours' => 14,
            'Variance' => 12,
            'Status' => 14,
            'Screenshot Link' => 34,
            'Notes' => 28,
        ];
    }

    /**
     * @return Collection<int, Task>
     */
    protected function rows(): Collection
    {
        return $this->reports->taskQuery($this->filters)
            ->with(['user', 'attendance'])
            ->orderBy('task_date')
            ->orderBy('user_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Task  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        return [
            $row->task_date->format('Y-m-d'),
            $row->user?->name ?? 'Unknown',
            $row->user?->email ?? 'Unknown',
            $row->task_code,
            $this->na($row->external_task_id),
            $row->task_name,
            $this->na($row->task_description),
            $row->output_count,
            $row->attendance?->expected_hours,
            $row->attendance?->total_hours,
            $row->attendance?->variance(),
            $row->task_status->label(),
            $this->na($row->screenshot_link),
            $this->na($row->notes),
        ];
    }

    /**
     * @return array<int, int>
     */
    protected function decimalColumns(): array
    {
        return [9, 10, 11];
    }

    /**
     * @return array<int, mixed>
     */
    protected function totals(): array
    {
        $rows = $this->resolveRows();

        // Hours belong to shifts, not tasks: de-duplicate by attendance id so a
        // day with three submissions contributes its hours once.
        $shifts = $rows->filter(fn (Task $t) => $t->attendance !== null)
            ->unique(fn (Task $t) => $t->attendance_id);

        return [
            1 => 'TOTAL',
            7 => $rows->count().' tasks',
            8 => $rows->filter(fn (Task $t) => $t->task_status->countsTowardProduction())
                ->sum('output_count'),
            9 => round((float) $shifts->sum(fn (Task $t) => $t->attendance?->expected_hours ?? 0), 2),
            10 => round((float) $shifts->sum(fn (Task $t) => $t->attendance?->total_hours ?? 0), 2),
        ];
    }
}
