<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AttendanceIndexRequest;
use App\Http\Requests\Admin\StoreTaskerRequest;
use App\Http\Requests\Admin\TaskerIndexRequest;
use App\Http\Requests\Admin\UpdateTaskerRequest;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\TaskResource;
use App\Http\Resources\TrackerEntryResource;
use App\Http\Resources\UserResource;
use App\Models\TrackerEntry;
use App\Models\User;
use App\Services\AbsenceRiskService;
use App\Services\ActivityLogger;
use App\Services\ReportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskerController extends Controller
{
    use ApiResponses;

    /** Columns a client is permitted to sort by. */
    private const SORTABLE = ['name', 'email', 'status', 'role', 'created_at'];

    public function __construct(
        private readonly ReportService $reports,
        private readonly ActivityLogger $logger,
        private readonly AbsenceRiskService $risk,
    ) {}

    public function index(TaskerIndexRequest $request): JsonResponse
    {
        $sort = in_array((string) $request->query('sort'), self::SORTABLE, true)
            ? (string) $request->query('sort')
            : 'name';

        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        $users = User::query()
            ->when($request->boolean('include_deleted'), fn (Builder $q) => $q->withTrashed())
            ->when($request->query('role'), fn (Builder $q, $role) => $q->where('role', $role))
            ->when($request->query('status'), fn (Builder $q, $status) => $q->where('status', $status))
            ->search($request->query('search'))
            ->orderBy($sort, $direction)
            ->paginate((int) $request->query('per_page', 15))
            ->withQueryString();

        /** @var array<int, User> $rows */
        $rows = $users->items();

        // One grouped query for the whole page. Asking per row would be twenty
        // extra queries on a screen an admin filters repeatedly.
        $absences = $this->risk->countsFor(array_map(
            static fn (User $user): int => $user->id,
            $rows,
        ));

        $resolved = array_map(function (User $user) use ($absences): array {
            $data = UserResource::make($user)->resolve();
            $data['absence_risk'] = $this->risk->payload($absences[$user->id] ?? 0);

            return $data;
        }, $rows);

        return $this->ok(
            $resolved,
            null,
            [
                'pagination' => $this->paginationMeta($users),
                // The rule itself, so the client can label the column and the
                // filter without hardcoding a threshold that is configurable.
                'absence_rule' => [
                    'threshold' => $this->risk->threshold(),
                    'window_days' => $this->risk->windowDays(),
                ],
            ],
        );
    }

    public function store(StoreTaskerRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = new User;
        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);

        // role/status are not mass assignable; an admin sets them explicitly.
        $user->role = UserRole::from($data['role'] ?? UserRole::Tasker->value);
        $user->status = UserStatus::from($data['status'] ?? UserStatus::Active->value);
        $user->save();

        $this->logger->log(
            'tasker.created',
            "Authorised {$user->role->label()} access for {$user->email}",
            $user,
            ['role' => $user->role->value, 'status' => $user->status->value],
            $request->user(),
        );

        return $this->created(
            UserResource::make($user)->resolve(),
            "{$user->name} can now sign in with Google using {$user->email}.",
        );
    }

    public function show(Request $request, User $tasker): JsonResponse
    {
        $this->authorize('view', $tasker);

        return $this->ok(UserResource::make($tasker)->resolve());
    }

    public function update(UpdateTaskerRequest $request, User $tasker): JsonResponse
    {
        $this->authorize('update', $tasker);

        $data = $request->validated();
        $before = $tasker->only(['name', 'email', 'role', 'status']);

        $emailChanged = isset($data['email'])
            && strtolower($data['email']) !== strtolower($tasker->email);

        $tasker->fill(array_filter([
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
        ], static fn ($v) => $v !== null));

        // Repointing the account at a different address also repoints it at a
        // different Google identity. Clearing the linked id lets the new
        // address claim the account on its next sign-in; leaving it would
        // trip the identity-mismatch check and lock the person out entirely.
        if ($emailChanged) {
            $tasker->google_id = null;
        }

        if (isset($data['role'])) {
            $tasker->role = UserRole::from($data['role']);
        }

        if (isset($data['status'])) {
            $tasker->status = UserStatus::from($data['status']);
        }

        $tasker->save();

        $this->logger->logChanges(
            'tasker.updated',
            "Updated account {$tasker->email}",
            $tasker,
            $before,
            $tasker->only(['name', 'email', 'role', 'status']),
            $request->user(),
        );

        if ($emailChanged) {
            $this->logger->log(
                'tasker.google_unlinked',
                "Unlinked the Google account for {$tasker->email} after an email change",
                $tasker,
                [],
                $request->user(),
            );
        }

        return $this->ok(
            UserResource::make($tasker->refresh())->resolve(),
            "{$tasker->name} updated.",
        );
    }

    /**
     * Deactivate rather than delete.
     *
     * Attendance and task rows hold restrictive foreign keys to users, so a
     * hard delete of anyone with history would fail at the database anyway.
     * Soft deleting keeps that history attributable (business rule 10).
     */
    public function destroy(Request $request, User $tasker): JsonResponse
    {
        $this->authorize('delete', $tasker);

        DB::transaction(function () use ($tasker): void {
            $tasker->status = UserStatus::Inactive;
            $tasker->save();
            $tasker->delete();
        });

        $this->logger->log(
            'tasker.deactivated',
            "Deactivated account {$tasker->email}",
            $tasker,
            [],
            $request->user(),
        );

        return $this->ok(null, "{$tasker->name} deactivated. Their records have been kept.");
    }

    public function restore(Request $request, int $tasker): JsonResponse
    {
        $user = User::withTrashed()->findOrFail($tasker);

        $this->authorize('restore', $user);

        $user->restore();
        $user->status = UserStatus::Active;
        $user->save();

        $this->logger->log(
            'tasker.reactivated',
            "Reactivated account {$user->email}",
            $user,
            [],
            $request->user(),
        );

        return $this->ok(
            UserResource::make($user)->resolve(),
            "{$user->name} reactivated.",
        );
    }

    /**
     * The tasker detail page: profile, rollups, and recent history.
     */
    public function summary(AttendanceIndexRequest $request, User $tasker): JsonResponse
    {
        $this->authorize('view', $tasker);

        $filters = $request->filters();
        $filters['user_id'] = $tasker->id;

        $attendance = $this->reports->attendanceQuery($filters)
            ->orderByDesc('attendance_date')
            ->limit(30)
            ->get();

        $tasks = $this->reports->taskQuery($filters)
            ->orderByDesc('task_date')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        /*
         * The nightly tracker entries -- which is what this tasker actually
         * filed, and what was missing from this page entirely.
         *
         * The screen showed `tasks` under both "Productivity summary" and
         * "Recent submissions". That table is the separate, optional Extra
         * Tasks page; essentially all production is declared through the
         * nightly flow, which writes tracker_entries. So a tasker who had
         * worked and filed every night still read as having produced nothing.
         *
         * The same mistake was found and fixed on the admin dashboard (see
         * ReportService::adminDashboard); this page was missed. The product's
         * own vocabulary settles it: the admin "Submissions" screen lists
         * tracker entries, so a panel labelled "Recent submissions" has to
         * mean the same thing.
         */
        $entries = TrackerEntry::query()
            ->where('user_id', $tasker->id)
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('entry_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('entry_date', '<=', $to))
            ->with(['items.project', 'site', 'supportTeam'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return $this->ok([
            'user' => UserResource::make($tasker)->resolve(),
            'summary' => $this->reports->taskerSummary($tasker, $request->filters()),
            // Deliberately NOT derived from the filters above. The warning is
            // about a fixed recent window, so it has to mean the same thing
            // whatever range the admin happens to be looking at -- otherwise
            // widening a date picker would appear to put someone at risk.
            'absence_risk' => $this->risk->payload($this->risk->countFor($tasker->id)),
            'recent_attendance' => AttendanceResource::collection($attendance)->resolve(),
            'recent_entries' => TrackerEntryResource::collection($entries)->resolve(),
            'recent_tasks' => TaskResource::collection($tasks)->resolve(),
        ]);
    }
}
