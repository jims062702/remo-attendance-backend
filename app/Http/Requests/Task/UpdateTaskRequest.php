<?php

declare(strict_types=1);

namespace App\Http\Requests\Task;

use App\Enums\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorisation is handled by TaskPolicy::update via the route's
 * `can:update,task` binding: a tasker may revise only their own still-open
 * submissions, while an admin may correct any of them.
 */
class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'task_name' => ['sometimes', 'required', 'string', 'max:255'],
            'task_description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'output_count' => ['sometimes', 'required', 'integer', 'min:0', 'max:1000000'],
            'task_status' => ['sometimes', 'required', Rule::enum(TaskStatus::class)],
            'external_task_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'screenshot_link' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['screenshot_link', 'external_task_id'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);

            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);

            if ($trimmed === '' || strcasecmp($trimmed, 'n/a') === 0 || strcasecmp($trimmed, 'na') === 0) {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'screenshot_link.url' => 'The screenshot link must be a valid URL, or left blank / "N/A".',
        ];
    }
}
