<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, Task $task): bool
    {
        return $task->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->canAuthenticate();
    }

    /**
     * A tasker may revise their own submission while it is still open work.
     * Once completed or cancelled the record is part of the production history
     * and only an admin may alter it -- otherwise yesterday's reported output
     * could be rewritten after it has been reported on.
     */
    public function update(User $user, Task $task): bool
    {
        if ($task->user_id !== $user->id) {
            return false;
        }

        return ! in_array($task->task_status, [TaskStatus::Completed, TaskStatus::Cancelled], true);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }
}
