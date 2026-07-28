<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AttendanceIndexRequest;
use App\Http\Requests\Admin\TaskIndexRequest;
use App\Http\Resources\ActivityLogResource;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\TaskResource;
use App\Models\ActivityLog;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON reporting. The Excel equivalents in ExportController read from the same
 * ReportService methods, so an on-screen total and a downloaded one cannot
 * disagree.
 */
class ReportController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly ReportService $reports) {}

    public function attendance(AttendanceIndexRequest $request): JsonResponse
    {
        $filters = $request->filters();

        $records = $this->reports->attendanceQuery($filters)
            ->with('user')
            ->orderBy('attendance_date')
            ->paginate((int) $request->query('per_page', 50))
            ->withQueryString();

        return $this->ok(
            AttendanceResource::collection($records->items())->resolve(),
            null,
            [
                'pagination' => $this->paginationMeta($records),
                'totals' => $this->reports->attendanceTotals($filters),
                'filters' => $filters,
            ],
        );
    }

    public function productivity(TaskIndexRequest $request): JsonResponse
    {
        $filters = $request->filters();

        $records = $this->reports->taskQuery($filters)
            ->with(['user', 'attendance'])
            ->orderBy('task_date')
            ->paginate((int) $request->query('per_page', 50))
            ->withQueryString();

        return $this->ok(
            TaskResource::collection($records->items())->resolve(),
            null,
            [
                'pagination' => $this->paginationMeta($records),
                'filters' => $filters,
            ],
        );
    }

    /**
     * Per-tasker rollup across the selected range.
     */
    public function taskerSummary(AttendanceIndexRequest $request): JsonResponse
    {
        $filters = $request->filters();

        return $this->ok(
            $this->reports->taskerSummaryReport($filters)->values()->all(),
            null,
            ['filters' => $filters],
        );
    }

    /**
     * Nightly tracker submissions across all taskers.
     *
     * This is what the operation actually reviews each morning: who filed,
     * what they produced on which project, and how it compares to their clock.
     */
    public function trackerEntries(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $entries = \App\Models\TrackerEntry::query()
            ->with(['user', 'items.project', 'site', 'supportTeam', 'attendance'])
            ->betweenDates($validated['from'] ?? null, $validated['to'] ?? null)
            ->forUser($validated['user_id'] ?? null)
            ->when(
                $validated['project_id'] ?? null,
                fn ($q, $projectId) => $q->whereHas('items', fn ($i) => $i->where('project_id', $projectId)),
            )
            ->when(
                $validated['search'] ?? null,
                fn ($q, $term) => $q->whereHas('user', fn ($u) => $u->search($term)),
            )
            ->orderByDesc('entry_date')
            ->orderBy('user_id')
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->withQueryString();

        return $this->ok(
            \App\Http\Resources\TrackerEntryResource::collection($entries->items())->resolve(),
            null,
            ['pagination' => $this->paginationMeta($entries)],
        );
    }

    /**
     * The admin audit trail (business rule 16).
     */
    public function activityLogs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $logs = ActivityLog::query()
            ->with('user')
            ->forUser($validated['user_id'] ?? null)
            ->forAction($validated['action'] ?? null)
            ->betweenDates($validated['from'] ?? null, $validated['to'] ?? null)
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->withQueryString();

        return $this->ok(
            ActivityLogResource::collection($logs->items())->resolve(),
            null,
            ['pagination' => $this->paginationMeta($logs)],
        );
    }
}
