<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AttendanceIndexRequest;
use App\Http\Resources\AttendanceResource;
use App\Services\AttendanceService;
use App\Services\DailyFlowService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly ReportService $reports,
        private readonly AttendanceService $attendance,
        private readonly DailyFlowService $daily,
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
    /**
     * The floor, exactly as a tasker sees it.
     *
     * Same service, same payload, same component on the other side: an
     * administrator asking "which desks are free" and a tasker asking "where do
     * I sit" are reading the same room, and two answers to that would be one
     * answer too many.
     *
     * Not a copy of the tasker endpoint -- an admin route, because this is an
     * admin screen and the authorisation should say so rather than relying on
     * a tasker route happening to accept an admin's session.
     */
    public function floor(): JsonResponse
    {
        return $this->ok($this->daily->availableWorkstations()->all());
    }

    public function analytics(AttendanceIndexRequest $request): JsonResponse
    {
        return $this->ok($this->reports->attendanceAnalytics($request->filters()));
    }
}
