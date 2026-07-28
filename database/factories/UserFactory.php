<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Accounts are Google-linked by default, since that is the state almost
     * every test needs. Use notSignedIn() for an account that has been
     * authorised but never used.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'google_id' => (string) fake()->unique()->numerify('##################'),
            'avatar_url' => null,
            // No password is ever set: there is no password sign-in path.
            'password' => null,
            'role' => UserRole::Tasker,
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Authorised by an admin but has not yet completed a Google sign-in.
     */
    public function notSignedIn(): static
    {
        return $this->state(fn () => [
            'google_id' => null,
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin]);
    }

    public function tasker(): static
    {
        return $this->state(fn () => ['role' => UserRole::Tasker]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Inactive]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Suspended]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
