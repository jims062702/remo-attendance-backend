<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AttendanceIndexRequest;
use App\Http\Requests\Admin\CorrectAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Services\ActivityLogger;
use App\Services\AttendanceService;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAttendanceController extends Controller
{
    use ApiResponses;

    private const SORTABLE = ['attendance_date', 'time_in', 'time_out', 'total_hours', 'status'];

    public function __construct(
        private readonly ReportService $reports,
        private readonly AttendanceService $attendance,
        private readonly ActivityLogger $logger,
    ) {}

    public function index(AttendanceIndexRequest $request): JsonResponse
    {
        $filters = $request->filters();

        $sort = in_array((string) $request->query('sort'), self::SORTABLE, true)
            ? (string) $request->query('sort')
            : 'attendance_date';

        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $records = $this->reports->attendanceQuery($filters)
            ->with('user')
            ->withCount('tasks')
            ->orderBy($sort, $direction)
            ->orderBy('id', 'desc')
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

    public function show(Attendance $attendance): JsonResponse
    {
        return $this->ok(
            AttendanceResource::make($attendance->load(['user', 'tasks']))->resolve(),
        );
    }

    /**
     * Correct an attendance record.
     *
     * The only path where clock times come from a request rather than the
     * server clock, so it is admin-only, requires a written reason, and records
     * a full before/after change set in the audit log.
     *
     * Hours are recomputed here rather than accepted from the client -- a
     * correction must obey the same arithmetic as an automatic clock-out.
     */
    public function update(CorrectAttendanceRequest $request, Attendance $attendance): JsonResponse
    {
        $this->authorize('update', $attendance);

        $data = $request->validated();
        $before = $attendance->only(['time_in', 'time_out', 'total_hours', 'expected_hours', 'status', 'notes']);

        if (array_key_exists('time_in', $data)) {
            $attendance->time_in = $this->toAppTimezone($data['time_in']);
        }

        if (array_key_exists('time_out', $data)) {
            $attendance->time_out = $this->toAppTimezone($data['time_out']);
        }

        if (array_key_exists('expected_hours', $data)) {
            $attendance->expected_hours = $data['expected_hours'] === null
                ? null
                : (float) $data['expected_hours'];
        }

        if (array_key_exists('notes', $data)) {
            $attendance->notes = $data['notes'];
        }

        // Recompute rather than trust: total_hours is derived data.
        $attendance->total_hours = ($attendance->time_in !== null && $attendance->time_out !== null)
            ? $this->attendance->computeHours($attendance->time_in, $attendance->time_out)
            : null;

        // An explicit status wins; otherwise re-derive it from the corrected
        // clock times so present/late stays consistent with the data.
        if (isset($data['status'])) {
            $attendance->status = AttendanceStatus::from($data['status']);
        } elseif ($attendance->time_in !== null) {
            $attendance->status = $attendance->time_out === null
                ? AttendanceStatus::Incomplete
                : $this->attendance->resolveClockInStatus($attendance->time_in, $attendance->attendance_date);
        }

        $attendance->save();

        $this->logger->log(
            'attendance.corrected',
            "Corrected attendance for {$attendance->user->email} on {$attendance->attendance_date->toDateString()}",
            $attendance,
            [
                'reason' => $data['reason'],
                'before' => $this->auditable($before),
                'after' => $this->auditable($attendance->only(array_keys($before))),
            ],
            $request->user(),
        );

        return $this->ok(
            AttendanceResource::make($attendance->refresh()->load('user'))->resolve(),
            'Attendance record corrected.',
        );
    }

    /**
     * Record a non-attendance (absent / on leave) for a tasker on a date.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'attendance_date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', 'in:absent,on_leave'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $attendance = Attendance::updateOrCreate(
            [
                'user_id' => $validated['user_id'],
                'attendance_date' => $validated['attendance_date'],
            ],
            [
                'status' => AttendanceStatus::from($validated['status']),
                'notes' => $validated['notes'] ?? null,
            ],
        );

        $this->logger->log(
            'attendance.marked',
            "Marked {$validated['status']} for {$attendance->attendance_date->toDateString()}",
            $attendance,
            $validated,
            $request->user(),
        );

        return $this->created(
            AttendanceResource::make($attendance->load('user'))->resolve(),
            'Attendance recorded.',
        );
    }

    /**
     * Normalise a client-supplied clock time into the application timezone.
     *
     * This is load-bearing, not tidiness. A browser reads a datetime-local
     * input in its own timezone and serialises it with toISOString(), so
     * 10:00 PM Manila arrives as "2026-07-26T14:00:00.000Z". Carbon parses that
     * into a UTC instance -- correct as an instant -- but Laravel's datetime
     * cast then writes whatever wall clock the instance carries into a
     * timezone-less DATETIME column. Stored as 14:00 and read back as Manila,
     * a 10:00 PM correction resurfaces as 2:00 PM.
     *
     * Converting first means the wall clock written to the column is always
     * the app-timezone one, which is the convention every other write in this
     * system already follows (they all originate from now()).
     *
     * A naive string with no offset is unaffected: Carbon already parses it in
     * the app timezone, so the conversion is a no-op.
     */
    private function toAppTimezone(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse((string) $value)
            ->setTimezone(config('app.timezone'));
    }

    /**
     * Flatten Carbon and enum values so the audit metadata is plain JSON.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function auditable(array $values): array
    {
        return array_map(static function (mixed $value): mixed {
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }

            if ($value instanceof \BackedEnum) {
                return $value->value;
            }

            return $value;
        }, $values);
    }
}
