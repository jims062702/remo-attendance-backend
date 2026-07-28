<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Requests\Admin\AttendanceIndexRequest;
use App\Http\Requests\Attendance\SetCommitmentRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\DailyFlowService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The tasker's own attendance. Every action is scoped to the authenticated
 * user -- no route here accepts a user id, so business rule 8 (a tasker cannot
 * reach another tasker's records) holds structurally rather than by a check
 * that could be forgotten.
 */
class AttendanceController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly ReportService $reports,
        private readonly DailyFlowService $daily,
    ) {}

    /**
     * Clock in. The timestamp is the server's, never the browser's.
     */
    public function timeIn(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $attendance = $this->attendance->timeIn($user);

        return $this->created(
            AttendanceResource::make($attendance)->resolve(),
            'Timed in at '.$attendance->time_in?->format('g:i A').'.',
        );
    }

    /**
     * Clock out and compute hours rendered.
     */
    public function timeOut(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $attendance = $this->attendance->timeOut($user);

        // Rendered hours come from the clock, so the tracker entry's figure is
        // only knowable now. Filling it here means the tasker never has to come
        // back and type a number they already earned.
        $this->daily->syncDeclaredHours($attendance);

        return $this->ok(
            AttendanceResource::make($attendance)->resolve(),
            sprintf(
                'Timed out at %s. Total hours rendered: %.2f.',
                $attendance->time_out?->format('g:i A'),
                $attendance->total_hours ?? 0,
            ),
        );
    }

    /**
     * The shift for the business date currently in progress.
     *
     * Returns null data rather than 404 when nothing has been recorded yet:
     * "you have not clocked in" is a normal state for this screen, not an error.
     */
    public function today(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $attendance = $this->attendance->currentFor($user);
        $businessDate = $this->attendance->resolveBusinessDate();

        return $this->ok(
            $attendance ? AttendanceResource::make($attendance)->resolve() : null,
            null,
            [
                'business_date' => $businessDate->toDateString(),
                'scheduled_start' => $this->attendance->scheduledStart($businessDate)->toIso8601String(),
                'scheduled_end' => $this->attendance->scheduledEnd($businessDate)->toIso8601String(),
                'can_time_in' => $attendance === null || $attendance->time_in === null,
                'can_time_out' => $attendance !== null && $attendance->isOpen(),
            ],
        );
    }

    /**
     * Record the production commitment for the current shift.
     */
    public function setCommitment(SetCommitmentRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $attendance = $this->attendance->setCommitment(
            $user,
            (float) $request->validated('expected_hours'),
        );

        return $this->ok(
            AttendanceResource::make($attendance)->resolve(),
            'Production commitment saved.',
        );
    }

    /**
     * The tasker's own attendance history.
     */
    public function history(AttendanceIndexRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // user_id is forced to the authenticated user, so a supplied one is
        // ignored rather than trusted.
        $filters = $request->filters();
        $filters['user_id'] = $user->id;

        $records = $this->reports->attendanceQuery($filters)
            ->withCount('tasks')
            ->orderByDesc('attendance_date')
            ->paginate((int) $request->query('per_page', 15))
            ->withQueryString();

        return $this->ok(
            AttendanceResource::collection($records->items())->resolve(),
            null,
            [
                'pagination' => $this->paginationMeta($records),
                'totals' => $this->reports->attendanceTotals($filters),
            ],
        );
    }

    /**
     * Attendance + productivity rollup for the authenticated tasker.
     */
    public function summary(AttendanceIndexRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->ok($this->reports->taskerSummary($user, $request->filters()));
    }
}
