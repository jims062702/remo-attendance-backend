<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Attendance;
use App\Services\AttendanceService;
use App\Services\ReportService;
use Illuminate\Support\Collection;

/**
 * Attendance report: one row per shift.
 *
 * Time in and time out carry their full date because the shift is overnight --
 * "10:05 PM / 6:02 AM" alone would leave a reader guessing which calendar day
 * the clock-out fell on.
 */
class AttendanceExport extends ReportExport
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        array $filters,
        private readonly ReportService $reports,
        private readonly AttendanceService $attendance,
    ) {
        parent::__construct($filters);
    }

    protected function reportTitle(): string
    {
        return 'Attendance Report';
    }

    /**
     * @return array<string, int>
     */
    protected function columnMap(): array
    {
        return [
            'Shift Date' => 14,
            'Tasker' => 26,
            'Email' => 30,
            'Time In' => 22,
            'Time Out' => 22,
            'Hours Rendered' => 16,
            'Committed Hours' => 16,
            'Variance' => 12,
            'Minutes Late' => 14,
            'Status' => 14,
            'Tasks' => 8,
            'Notes' => 30,
        ];
    }

    /**
     * @return Collection<int, Attendance>
     */
    protected function rows(): Collection
    {
        return $this->reports->attendanceQuery($this->filters)
            ->with('user')
            ->withCount('tasks')
            ->orderBy('attendance_date')
            ->orderBy('user_id')
            ->get();
    }

    /**
     * @param  Attendance  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        return [
            $row->attendance_date->format('Y-m-d'),
            $row->user?->name ?? 'Unknown',
            $row->user?->email ?? 'Unknown',
            $row->time_in?->format('Y-m-d g:i A') ?? 'N/A',
            $row->time_out?->format('Y-m-d g:i A') ?? 'N/A',
            $row->total_hours,
            $row->expected_hours,
            $row->variance(),
            $row->time_in ? $this->attendance->minutesLate($row->time_in, $row->attendance_date) : null,
            $row->status->label(),
            $row->tasks_count ?? 0,
            $this->na($row->notes),
        ];
    }

    /**
     * @return array<int, int>
     */
    protected function decimalColumns(): array
    {
        return [6, 7, 8]; // Hours, Committed, Variance
    }

    /**
     * @return array<int, mixed>
     */
    protected function totals(): array
    {
        $totals = $this->reports->attendanceTotals($this->filters);

        return [
            1 => 'TOTAL',
            5 => $totals['records'].' records',
            6 => $totals['total_hours'],
            7 => $totals['expected_hours'],
            8 => $totals['variance'],
            10 => $totals['late'].' late',
        ];
    }
}
