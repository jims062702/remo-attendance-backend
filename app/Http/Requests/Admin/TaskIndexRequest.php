<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\TaskStatus;
use App\Http\Requests\Concerns\ValidatesDateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared by the admin task list and the tasker's own history. The tasker route
 * ignores user_id and forces the filter to the authenticated user.
 */
class TaskIndexRequest extends FormRequest
{
    use ValidatesDateRange;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->dateRangeRules(), $this->paginationRules(), [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'task_status' => ['nullable', Rule::enum(TaskStatus::class)],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->dateRangeMessages();
    }
}
