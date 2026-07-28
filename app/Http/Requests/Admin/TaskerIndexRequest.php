<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Requests\Concerns\ValidatesDateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskerIndexRequest extends FormRequest
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
        return array_merge($this->paginationRules(), [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'include_deleted' => ['nullable', 'boolean'],
        ]);
    }
}
