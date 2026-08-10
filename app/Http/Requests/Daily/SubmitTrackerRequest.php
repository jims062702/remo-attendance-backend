<?php

declare(strict_types=1);

namespace App\Http\Requests\Daily;

use App\Enums\TaskComplexity;
use App\Enums\TaskerLevel;
use App\Enums\Tenurity;
use App\Services\DailyFlowService;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

            // Closure form for the same reason as the PC rule in
            // ActivateAttendanceRequest: ->where() serialises its value into
            // the rule string, and a boolean does not survive that intact.
            // `true` happens to come out as "1", which PostgreSQL accepts, so
            // this one was never broken -- but it is the same construction one
            // `false` away from being, and it should not be left as a trap.
            'support_team_id' => [
                'nullable', 'integer',
                Rule::exists('support_teams', 'id')->where(
                    fn (Builder $query) => $query->where('is_active', true),
                ),
            ],

            'items' => ['required', 'array', 'min:1'],
            'items.*.project_id' => ['required', 'integer', 'distinct', 'exists:projects,id'],

            // At least one. A project block declaring zero tasks is a block
            // that should not have been added -- and with task IDs now
            // required, zero would have to be matched by zero IDs, which is a
            // contradiction rather than a rule.
            'items.*.total_tasks' => ['required', 'integer', 'min:1', 'max:100000'],

            // Level is asked per project, not once per night.
            'items.*.tasker_level' => ['required', Rule::enum(TaskerLevel::class)],

            // Both required now. Stored verbatim: support validates against
            // exactly what was typed. The count of IDs is checked against
            // total_tasks in withValidator() below.
            'items.*.task_ids' => ['required', 'string', 'max:20000'],
            'items.*.task_complexity' => ['nullable', Rule::enum(TaskComplexity::class)],
            'items.*.screenshot_links' => ['required', 'string', 'max:20000'],

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

    /**
     * Every task declared has to be accounted for by an ID.
     *
     * "3 tasks" and a list naming two of them is a number nobody can check.
     * The IDs are what support traces a night's work against, so the count is
     * the thing that makes the total meaningful rather than typed.
     *
     * Counted the same way DailyFlowService::parseTaskIds() counts them --
     * split on commas, trimmed, blanks and "N/A" discarded -- because a rule
     * that counted differently from the figure it is validating would reject
     * correct entries.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items = $this->input('items');

            if (! is_array($items)) {
                return;
            }

            /** @var DailyFlowService $flow */
            $flow = app(DailyFlowService::class);

            foreach ($items as $index => $item) {
                $declared = (int) ($item['total_tasks'] ?? 0);
                $raw = $item['task_ids'] ?? null;

                // Nothing to compare against: the required rules above have
                // already objected, and a second message about the same field
                // only adds noise.
                if ($declared < 1 || ! is_string($raw) || trim($raw) === '') {
                    continue;
                }

                $supplied = $flow->parseTaskIds($raw)['total'];

                if ($supplied === $declared) {
                    continue;
                }

                $validator->errors()->add("items.{$index}.task_ids", sprintf(
                    'You declared %d task%s but listed %d ID%s. Separate every ID with a comma, '
                    .'so the two match.',
                    $declared,
                    $declared === 1 ? '' : 's',
                    $supplied,
                    $supplied === 1 ? '' : 's',
                ));
            }
        });
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
