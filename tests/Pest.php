<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every test runs against a real MariaDB schema rather than SQLite: the
| attendance rules depend on genuine unique-index violations, which SQLite
| reports with different codes.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function admin(array $attributes = []): User
{
    return User::factory()->admin()->create($attributes);
}

function tasker(array $attributes = []): User
{
    return User::factory()->tasker()->create($attributes);
}

/**
 * A moment on a given business date's shift, expressed as an offset from the
 * scheduled start. Keeps tests readable: `shiftMoment('2026-07-26', 5)` is
 * "five minutes after the shift began".
 */
function shiftMoment(string $businessDate, int $minutesFromStart = 0): Carbon\CarbonImmutable
{
    [$hour, $minute] = array_map('intval', explode(':', (string) config('attendance.shift_start')));

    return Carbon\CarbonImmutable::parse($businessDate)
        ->startOfDay()
        ->setTime($hour, $minute)
        ->addMinutes($minutesFromStart);
}
