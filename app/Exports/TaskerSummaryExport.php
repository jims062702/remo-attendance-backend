<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\ReportService;
use Illuminate\Support\Collection;

/**
 * One row per tasker: the management view of who worked how much and produced
 * what over the selected range.
 */
class TaskerSummaryExport extends ReportExport
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
        return 'Tasker Summary Report';
    }

    /**
     * @return array<string, int>
     */
    protected function columnMap(): array
    {
        return [
            'Tasker' => 26,
            'Email' => 30,
            'Account Status' => 15,
            'Days Worked' => 13,
            'Days Late' => 11,
            'Total Hours' => 13,
            'Average Hours/Day' => 18,
            'Committed Hours' => 16,
            'Variance' => 12,
            // Nights filed and output are the nightly tracker: what almost
            // every tasker actually declares. The three Extra Tasks columns
            // that follow are the separate optional page, named so nobody
            // reads them as the same measure.
            'Nights Filed' => 13,
            'Total Output' => 13,
            'Task IDs' => 11,
            'SBQ' => 8,
            'Average Output/Day' => 18,
            'Extra Tasks' => 12,
            'Extra Completed' => 16,
            'Extra Completion %' => 18,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function rows(): Collection
    {
        return $this->reports->taskerSummaryReport($this->filters);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        return [
            $row['name'],
            $row['email'],
            ucfirst((string) $row['status']),
            $row['days_worked'],
            $row['late_days'],
            $row['total_hours'],
            $row['average_hours'],
            $row['expected_hours'],
            $row['variance'],
            $row['nights_filed'],
            $row['total_output'],
            $row['task_ids'],
            $row['sbq'],
            $row['average_daily_output'],
            $row['extra_tasks'],
            $row['extra_completed'],
            $row['completion_rate'],
        ];
    }

    /**
     * @return array<int, int>
     */
    protected function decimalColumns(): array
    {
        // 1-based, and they moved when the production columns were split:
        // 6 Total Hours, 7 Average Hours/Day, 8 Committed, 9 Variance,
        // 14 Average Output/Day, 17 Extra Completion %.
        return [6, 7, 8, 9, 14, 17];
    }

    /**
     * @return array<int, mixed>
     */
    protected function totals(): array
    {
        $rows = $this->resolveRows();

        return [
            1 => 'TOTAL ('.$rows->count().' taskers)',
            4 => $rows->sum('days_worked'),
            5 => $rows->sum('late_days'),
            6 => round((float) $rows->sum('total_hours'), 2),
            8 => round((float) $rows->sum('expected_hours'), 2),
            9 => round((float) $rows->sum('variance'), 2),
            10 => $rows->sum('nights_filed'),
            11 => $rows->sum('total_output'),
            12 => $rows->sum('task_ids'),
            13 => $rows->sum('sbq'),
            15 => $rows->sum('extra_tasks'),
            16 => $rows->sum('extra_completed'),
        ];
    }
}
