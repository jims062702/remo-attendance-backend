<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\AttendanceStatus;
use App\Http\Requests\Concerns\ValidatesDateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceIndexRequest extends FormRequest
{
    use ValidatesDateRange;

    public function authorize(): bool
    {
        return true; // Guarded by the `admin` middleware on the route group.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->dateRangeRules(), $this->paginationRules(), [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::enum(AttendanceStatus::class)],
            'search' => ['nullable', 'string', 'max:255'],
            'group_by' => ['nullable', 'in:day,week,month'],
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
