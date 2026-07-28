<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\AttendanceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Admin correction of an attendance record -- fixing a missed clock-out,
 * marking someone absent, adjusting a commitment.
 *
 * This is the one path where clock times are accepted from a request rather
 * than the server clock, which is why it is admin-only, audited, and validated
 * against the same hour bounds the automatic path enforces.
 */
class CorrectAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route-bound: can:update,attendance
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'time_in' => ['sometimes', 'nullable', 'date'],
            'time_out' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::enum(AttendanceStatus::class)],
            'expected_hours' => [
                'sometimes', 'nullable', 'numeric',
                'min:'.config('attendance.commitment.min'),
                'max:'.config('attendance.commitment.max'),
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * Cross-field rules that mirror what AttendanceService enforces on the
     * automatic path, so a manual correction cannot introduce a record the
     * system would have rejected.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $attendance = $this->route('attendance');

            $timeIn = $this->resolveMoment('time_in', $attendance?->time_in);
            $timeOut = $this->resolveMoment('time_out', $attendance?->time_out);

            if ($timeOut !== null && $timeIn === null) {
                $validator->errors()->add('time_out', 'A time out cannot be recorded without a time in.');

                return;
            }

            if ($timeIn === null || $timeOut === null) {
                return;
            }

            if ($timeOut->lessThanOrEqualTo($timeIn)) {
                $validator->errors()->add('time_out', 'Time out must be later than time in.');

                return;
            }

            $hours = ($timeOut->getTimestamp() - $timeIn->getTimestamp()) / 3600;
            $max = (float) config('attendance.max_shift_hours');

            if ($hours > $max) {
                $validator->errors()->add(
                    'time_out',
                    sprintf('This span is %.2f hours, which exceeds the %.2f hour maximum for a single shift.', $hours, $max),
                );
            }
        });
    }

    /**
     * The effective value of a clock field: the submitted one when present,
     * otherwise what is already stored. Without this, validating a submitted
     * time_out against an omitted time_in would wrongly pass.
     */
    private function resolveMoment(string $field, mixed $existing): ?CarbonImmutable
    {
        if ($this->has($field)) {
            $value = $this->input($field);

            return $value === null ? null : CarbonImmutable::parse((string) $value);
        }

        return $existing === null ? null : CarbonImmutable::instance($existing);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required so the correction can be audited.',
        ];
    }
}
