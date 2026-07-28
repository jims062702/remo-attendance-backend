<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "For today's production, how many hours can we expect you to commit?"
 *
 * Bounds come from config rather than being written into the rules, so the
 * limit can be changed without a code deploy.
 */
class SetCommitmentRequest extends FormRequest
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
            'expected_hours' => [
                'required',
                'numeric',
                'min:'.config('attendance.commitment.min'),
                'max:'.config('attendance.commitment.max'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expected_hours.min' => 'A production commitment must be at least :min hours.',
            'expected_hours.max' => 'A production commitment cannot exceed :max hours.',
        ];
    }
}
