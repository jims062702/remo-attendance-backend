<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exports\AttendanceExport;
use App\Exports\ProductivityExport;
use App\Exports\TaskerListExport;
use App\Exports\TaskerSummaryExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AttendanceIndexRequest;
use App\Services\ActivityLogger;
use App\Services\AttendanceService;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel export.
 *
 * Every report is built from the same ReportService queries that power the
 * on-screen views, so a downloaded workbook always agrees with the dashboard
 * it was exported from.
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly AttendanceService $attendance,
        private readonly ActivityLogger $logger,
    ) {}

    public function __invoke(AttendanceIndexRequest $request, string $type): BinaryFileResponse
    {
        $filters = $request->filters();

        $export = match ($type) {
            'attendance' => new AttendanceExport($filters, $this->reports, $this->attendance),
            'productivity' => new ProductivityExport($filters, $this->reports),
            'tasker-summary' => new TaskerSummaryExport($filters, $this->reports),
            'taskers' => new TaskerListExport($filters),
        };

        $filename = sprintf(
            '%s_%s.xlsx',
            str_replace('-', '_', $type),
            CarbonImmutable::now()->format('Ymd_His'),
        );

        $this->logger->log(
            'report.exported',
            "Exported the {$type} report",
            null,
            ['type' => $type, 'filters' => $filters],
            $request->user(),
        );

        return Excel::download($export, $filename);
    }
}
