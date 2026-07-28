<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AttendanceIndexRequest;
use App\Http\Resources\AttendanceResource;
use App\Services\AttendanceService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly ReportService $reports,
        private readonly AttendanceService $attendance,
    ) {}

    /**
     * Summary cards plus who is currently on shift.
     */
    public function index(): JsonResponse
    {
        $businessDate = $this->attendance->resolveBusinessDate();

        $onShift = $this->reports->attendanceQuery([
            'from' => $businessDate->toDateString(),
            'to' => $businessDate->toDateString(),
        ])
            ->open()
            ->with('user')
            ->orderBy('time_in')
            ->limit(50)
            ->get();

        return $this->ok([
            'summary' => $this->reports->adminDashboard(),
            'currently_on_shift' => AttendanceResource::collection($onShift)->resolve(),
        ]);
    }

    /**
     * Time series for the attendance charts.
     */
    public function analytics(AttendanceIndexRequest $request): JsonResponse
    {
        return $this->ok($this->reports->attendanceAnalytics($request->filters()));
    }
}
