<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Requests\Admin\TaskIndexRequest;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Services\ReportService;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Daily production submissions.
 *
 * Taskers see and touch only their own rows; admins see everything. The
 * distinction is enforced twice -- by scoping the list query and by TaskPolicy
 * on each individual record.
 */
class TaskController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly TaskService $tasks,
        private readonly ReportService $reports,
    ) {}

    public function index(TaskIndexRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $filters = $request->filters();

        // A tasker's list is pinned to their own id regardless of what the
        // query string asked for.
        if (! $user->isAdmin()) {
            $filters['user_id'] = $user->id;
        }

        $records = $this->reports->taskQuery($filters)
            ->with('user')
            ->orderByDesc('task_date')
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 15))
            ->withQueryString();

        return $this->ok(
            TaskResource::collection($records->items())->resolve(),
            null,
            ['pagination' => $this->paginationMeta($records)],
        );
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $task = $this->tasks->create($user, $request->validated());

        return $this->created(
            TaskResource::make($task)->resolve(),
            "Task {$task->task_code} submitted.",
        );
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        return $this->ok(TaskResource::make($task->load('user'))->resolve());
    }

    public function update(UpdateTaskRequest $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $updated = $this->tasks->update($task, $request->validated(), $request->user());

        return $this->ok(
            TaskResource::make($updated)->resolve(),
            "Task {$updated->task_code} updated.",
        );
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->authorize('delete', $task);

        $this->tasks->delete($task, $request->user());

        return $this->ok(null, "Task {$task->task_code} removed.");
    }
}
