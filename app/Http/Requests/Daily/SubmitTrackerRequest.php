<?php

declare(strict_types=1);

namespace App\Http\Requests\Daily;

use App\Enums\TaskComplexity;
use App\Enums\TaskerLevel;
use App\Enums\Tenurity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 3: the Centralised Tracker entry.
 *
 * `items` is one block per project worked. A tasker on aloha and ego the same
 * night submits two blocks in one request, each with its own task IDs,
 * complexity and screenshot.
 */
class SubmitTrackerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // No defaults: the tasker must choose, so a wrong value is never
            // recorded simply because a form arrived pre-filled.
            'tenurity' => ['required', Rule::enum(Tenurity::class)],

            // Optional -- the server falls back to the single active site.
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],

            'support_team_id' => ['nullable', 'integer', Rule::exists('support_teams', 'id')->where('is_active', true)],

            'items' => ['required', 'array', 'min:1'],
            'items.*.project_id' => ['required', 'integer', 'distinct', 'exists:projects,id'],
            'items.*.total_tasks' => ['required', 'integer', 'min:0', 'max:100000'],
            // Level is asked per project, not once per night.
            'items.*.tasker_level' => ['required', Rule::enum(TaskerLevel::class)],
            // Stored verbatim: support validates against exactly what was typed.
            'items.*.task_ids' => ['nullable', 'string', 'max:20000'],
            'items.*.task_complexity' => ['nullable', Rule::enum(TaskComplexity::class)],
            'items.*.screenshot_links' => ['nullable', 'string', 'max:20000'],

            // "Total Work Hours Today" is derived from the clock (time in to
            // time out), never typed -- so it is not accepted here at all.
            // See DailyFlowService::renderedHoursFor().

            'remarks' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * "N/A" is the operational convention for the optional free-text fields.
     * Normalised away here so it is never stored as if it were data.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('remarks')) && $this->isBlank($this->input('remarks'))) {
            $this->merge(['remarks' => null]);
        }

        $items = $this->input('items');

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            foreach (['task_ids', 'screenshot_links'] as $field) {
                if (isset($item[$field]) && is_string($item[$field]) && $this->isBlank($item[$field])) {
                    $items[$index][$field] = null;
                }
            }
        }

        $this->merge(['items' => $items]);
    }

    private function isBlank(string $value): bool
    {
        $trimmed = trim($value);

        return $trimmed === '' || (bool) preg_match('/^n\/?a$/i', $trimmed);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tenurity.required' => 'Select your CB tenurity.',
            'items.*.tasker_level.required' => 'Select your level for each project.',
            'items.required' => 'Add at least one project.',
            'items.*.project_id.distinct' => 'The same project has been added twice — combine them into one block.',
            'items.*.project_id.required' => 'Choose a project for each block.',
        ];
    }
}
