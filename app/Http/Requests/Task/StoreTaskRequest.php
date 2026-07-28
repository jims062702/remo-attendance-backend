<?php

declare(strict_types=1);

namespace App\Http\Requests\Task;

use App\Enums\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Daily production submission.
 *
 * Deliberately absent from the accepted input:
 *   task_date  derived from the server's business date, never the client's
 *   user_id    taken from the authenticated session
 *   task_code  minted by TaskService
 *
 * That is what makes business rules 6 and 7 hold: the tasker's identity and
 * the date come from the server, so neither can be spoofed.
 */
class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\Task::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'task_name' => ['required', 'string', 'max:255'],
            'task_description' => ['nullable', 'string', 'max:5000'],
            'output_count' => ['required', 'integer', 'min:0', 'max:1000000'],
            'task_status' => ['required', Rule::enum(TaskStatus::class)],

            // Optional client/legacy reference. "N/A" is accepted from the UI
            // and normalised to NULL by TaskService.
            'external_task_id' => ['nullable', 'string', 'max:100'],

            // Validated as a real URL when present. The "N/A" convention is
            // handled before this rule runs (see prepareForValidation).
            'screenshot_link' => ['nullable', 'url:http,https', 'max:2048'],

            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Translate the UI's "N/A" placeholder into an absent value so that the
     * url rule is not asked to validate the string "N/A".
     */
    protected function prepareForValidation(): void
    {
        foreach (['screenshot_link', 'external_task_id'] as $field) {
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
            'output_count.min' => 'Output cannot be negative.',
        ];
    }
}
