<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Provision an account.
 *
 * No password: sign-in is Google-only, so adding someone means authorising
 * their email address. They gain access the first time they sign in with the
 * matching Google account -- there is no credential to issue or transmit.
 */
class StoreTaskerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A provisional name, shown until their first sign-in replaces it
            // with the one on their Google profile.
            'name' => ['required', 'string', 'max:255'],

            // The address that must match their Google account exactly.
            // Uniqueness ignores the soft-delete flag because a deactivated
            // user still occupies the address in the unique index.
            'email' => [
                'required', 'string', 'email:rfc', 'max:255',
                Rule::unique('users', 'email')->withoutTrashed(),
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
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'A user with this email address already exists.',
            'email.email' => 'Enter the Google address this person will sign in with.',
        ];
    }
}
