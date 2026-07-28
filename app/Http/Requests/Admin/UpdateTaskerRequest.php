<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTaskerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route-bound: can:update,tasker
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $taskerId = $this->route('tasker')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes', 'required', 'string', 'email:rfc', 'max:255',
                Rule::unique('users', 'email')->ignore($taskerId)->withoutTrashed(),
            ],
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * Guard against an admin locking themselves -- and potentially everyone --
     * out of the administration area.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $target = $this->route('tasker');
            $actor = $this->user();

            if ($target === null || $actor === null || $target->id !== $actor->id) {
                return;
            }

            if ($this->filled('role') && $this->input('role') !== UserRole::Admin->value) {
                $validator->errors()->add('role', 'You cannot remove your own administrator role.');
            }

            if ($this->filled('status') && $this->input('status') !== UserStatus::Active->value) {
                $validator->errors()->add('status', 'You cannot deactivate your own account.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'A user with this email address already exists.',
        ];
    }
}
