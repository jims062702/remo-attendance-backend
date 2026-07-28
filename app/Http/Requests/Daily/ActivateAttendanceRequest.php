<?php

declare(strict_types=1);

namespace App\Http\Requests\Daily;

use App\Enums\CommitmentBracket;
use App\Enums\PcStatus;
use App\Enums\TaskingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Step 1 + 2: attendance activation and PC claim.
 *
 * The date, the tasker's identity and the clock-in time all come from the
 * server -- none of them are accepted here.
 */
class ActivateAttendanceRequest extends FormRequest
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
            'commitment_bracket' => ['required', Rule::enum(CommitmentBracket::class)],

            // At least one tasking status must be filed -- an unexplained
            // blank day is exactly what this system exists to eliminate.
            'tasking_statuses' => ['required', 'array', 'min:1'],
            'tasking_statuses.*' => [Rule::enum(TaskingStatus::class)],

            // Scoped to the tasker-selectable pool, so posting a support
            // PC's id directly is rejected rather than merely hidden in the UI.
            'workstation_id' => [
                'nullable', 'integer',
                Rule::exists('workstations', 'id')
                    ->where('is_active', true)
                    ->where('is_support', false),
            ],
            'pc_status' => ['nullable', Rule::enum(PcStatus::class)],
        ];
    }

    /**
     * A working commitment means somebody is at a machine, so the PC must be
     * named. A support-filed absence has no PC to name.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $bracket = CommitmentBracket::tryFrom((string) $this->input('commitment_bracket'));

            if ($bracket?->isWorking() && ! $this->filled('workstation_id')) {
                $validator->errors()->add('workstation_id', 'Select the PC you are using.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tasking_statuses.required' => 'Select at least one tasking status.',
            'commitment_bracket.required' => 'Tell us how many hours you can commit today.',
            'workstation_id.exists' => 'That PC is not available to taskers. Pick another one.',
        ];
    }
}
