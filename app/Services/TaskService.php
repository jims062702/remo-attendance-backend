<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attendance;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Daily production submissions.
 *
 * Owns the minting of `task_code`, which must be unique across the whole table
 * and is never accepted from a request.
 */
class TaskService
{
    /** Attempts to mint a unique code before giving up under heavy contention. */
    private const CODE_ATTEMPTS = 5;

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data, ?CarbonInterface $at = null): Task
    {
        $taskDate = isset($data['task_date'])
            ? CarbonImmutable::parse((string) $data['task_date'])->startOfDay()
            : $this->attendance->resolveBusinessDate($at);

        $task = DB::transaction(function () use ($user, $data, $taskDate): Task {
            // Tie the submission to the shift it was produced in, so output can
            // be measured against hours actually rendered.
            $attendanceId = Attendance::query()
                ->where('user_id', $user->id)
                ->where('attendance_date', $taskDate->toDateString())
                ->value('id');

            $payload = [
                'user_id' => $user->id,
                'attendance_id' => $attendanceId,
                'task_date' => $taskDate->toDateString(),
                'task_name' => $data['task_name'],
                'task_description' => $data['task_description'] ?? null,
                'output_count' => (int) ($data['output_count'] ?? 0),
                'task_status' => $data['task_status'],
                'external_task_id' => $this->normaliseOptional($data['external_task_id'] ?? null),
                'screenshot_link' => $this->normaliseOptional($data['screenshot_link'] ?? null),
                'notes' => $data['notes'] ?? null,
            ];

            return $this->createWithUniqueCode($payload, $taskDate);
        });

        $this->logger->log(
            'task.created',
            "Submitted task {$task->task_code}",
            $task,
            ['output_count' => $task->output_count, 'task_status' => $task->task_status->value],
            $user,
        );

        return $task;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Task $task, array $data, ?User $actor = null): Task
    {
        $before = $task->only([
            'task_name', 'task_description', 'output_count',
            'task_status', 'external_task_id', 'screenshot_link', 'notes',
        ]);

        $task->fill([
            'task_name' => $data['task_name'] ?? $task->task_name,
            'task_description' => array_key_exists('task_description', $data)
                ? $data['task_description'] : $task->task_description,
            'output_count' => array_key_exists('output_count', $data)
                ? (int) $data['output_count'] : $task->output_count,
            'task_status' => $data['task_status'] ?? $task->task_status,
            'external_task_id' => array_key_exists('external_task_id', $data)
                ? $this->normaliseOptional($data['external_task_id']) : $task->external_task_id,
            'screenshot_link' => array_key_exists('screenshot_link', $data)
                ? $this->normaliseOptional($data['screenshot_link']) : $task->screenshot_link,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $task->notes,
        ])->save();

        $this->logger->logChanges(
            'task.updated',
            "Updated task {$task->task_code}",
            $task,
            $before,
            $task->only(array_keys($before)),
            $actor,
        );

        return $task->refresh();
    }

    public function delete(Task $task, ?User $actor = null): void
    {
        $this->logger->log(
            'task.deleted',
            "Deleted task {$task->task_code}",
            $task,
            ['task_code' => $task->task_code],
            $actor,
        );

        // Soft delete: the code stays reserved and the record stays auditable.
        $task->delete();
    }

    // ------------------------------------------------------------------ Codes

    /**
     * Insert, retrying if a concurrent request claimed the same sequence.
     *
     * The unique index on task_code is the authority; this loop simply reacts
     * to it rather than trying to pre-empt every race.
     *
     * @param  array<string, mixed>  $payload
     */
    private function createWithUniqueCode(array $payload, CarbonInterface $taskDate): Task
    {
        for ($attempt = 1; $attempt <= self::CODE_ATTEMPTS; $attempt++) {
            try {
                // task_code is assigned directly rather than mass assigned:
                // keeping it out of $fillable is what stops a request payload
                // from ever choosing its own code.
                $task = new Task($payload);
                $task->task_code = $this->nextTaskCode($taskDate);
                $task->save();

                return $task;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt === self::CODE_ATTEMPTS) {
                    throw $e;
                }
                // Fall through and recompute the sequence.
            }
        }

        throw new RuntimeException('Unable to allocate a unique task code.');
    }

    /**
     * Next code in the per-day sequence, e.g. TASK-20260726-0001.
     *
     * Soft-deleted rows are included: their codes are still present in the
     * unique index, so skipping them would produce collisions.
     */
    private function nextTaskCode(CarbonInterface $taskDate): string
    {
        $prefix = 'TASK-'.$taskDate->format('Ymd').'-';

        $last = Task::withTrashed()
            ->where('task_code', 'like', $prefix.'%')
            ->orderByDesc('task_code')
            ->lockForUpdate()
            ->value('task_code');

        $sequence = $last === null
            ? 1
            : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    // ----------------------------------------------------------------- Helpers

    /**
     * The UI sends "N/A" for fields the tasker has nothing to put in. That is a
     * display convention, not data -- store NULL so URL validation and the
     * unique index keep their meaning.
     */
    private function normaliseOptional(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        if ($trimmed === '' || strcasecmp($trimmed, 'n/a') === 0 || strcasecmp($trimmed, 'na') === 0) {
            return null;
        }

        return $trimmed;
    }

}
