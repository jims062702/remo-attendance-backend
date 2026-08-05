<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            //
            // withoutTrashed() so a deactivated account does not report the
            // generic "already been taken" -- that case is caught in
            // withValidator() below and answered with what to do about it.
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
     * Catch the address that belongs to a deactivated account.
     *
     * Deactivating soft deletes, and `users.email` is unique at the database
     * level -- a trashed row still occupies the address. So creating the person
     * again passed validation and then hit the unique index, which surfaced as
     * a 500 with nothing in it an administrator could act on.
     *
     * The account is not recreated here. Restoring keeps the attendance and
     * task history attached to the same row; a fresh account would leave all of
     * it stranded against an invisible user, which is exactly what deactivating
     * rather than deleting was meant to avoid.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $email = (string) $this->input('email');

            if ($email === '' || $validator->errors()->has('email')) {
                return;
            }

            $deactivated = User::onlyTrashed()->where('email', $email)->first();

            if ($deactivated === null) {
                return;
            }

            $validator->errors()->add('email', sprintf(
                'This address belongs to a deactivated account (%s). Tick "Show deactivated" '
                .'on the roster and restore it instead — that keeps their attendance and task '
                .'history attached to the same person.',
                $deactivated->name,
            ));
        });
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
