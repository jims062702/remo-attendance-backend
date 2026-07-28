<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Generates realistic overnight shifts: clock-in around 10 PM on the business
 * date, clock-out around 6 AM the *following* calendar day.
 *
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    /**
     * Walks backwards one day per record.
     *
     * A random date would collide on the (user_id, attendance_date) unique
     * index as soon as a test created a handful of shifts for one tasker --
     * which is exactly what the index exists to prevent. Tests that care about
     * a specific date use the on() state instead.
     */
    private static int $dayOffset = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $businessDate = CarbonImmutable::now()
            ->subDays(1 + (self::$dayOffset++ % 300))
            ->startOfDay();

        [$startHour, $startMinute] = array_map('intval', explode(':', (string) config('attendance.shift_start')));

        // A few minutes either side of the scheduled start.
        $timeIn = $businessDate->setTime($startHour, $startMinute)
            ->addMinutes(fake()->numberBetween(-10, 25));

        $timeOut = $timeIn->addMinutes(fake()->numberBetween(450, 510)); // ~7.5-8.5 h

        $hours = round(($timeOut->getTimestamp() - $timeIn->getTimestamp()) / 3600, 2);

        $graceEnd = $businessDate->setTime($startHour, $startMinute)
            ->addMinutes((int) config('attendance.grace_minutes'));

        return [
            'user_id' => User::factory()->tasker(),
            'attendance_date' => $businessDate->toDateString(),
            'time_in' => $timeIn,
            'time_out' => $timeOut,
            'total_hours' => $hours,
            'expected_hours' => (float) config('attendance.standard_hours'),
            'status' => $timeIn->greaterThan($graceEnd) ? AttendanceStatus::Late : AttendanceStatus::Present,
            'notes' => null,
        ];
    }

    /**
     * A shift in progress: clocked in, not yet out.
     */
    public function open(): static
    {
        return $this->state(fn () => [
            'time_out' => null,
            'total_hours' => null,
        ]);
    }

    public function late(): static
    {
        return $this->state(function (array $attributes) {
            $businessDate = CarbonImmutable::parse((string) $attributes['attendance_date']);
            [$h, $m] = array_map('intval', explode(':', (string) config('attendance.shift_start')));

            $timeIn = $businessDate->setTime($h, $m)
                ->addMinutes((int) config('attendance.grace_minutes') + 30);
            $timeOut = $timeIn->addHours(8);

            return [
                'time_in' => $timeIn,
                'time_out' => $timeOut,
                'total_hours' => 8.0,
                'status' => AttendanceStatus::Late,
            ];
        });
    }

    public function absent(): static
    {
        return $this->state(fn () => [
            'time_in' => null,
            'time_out' => null,
            'total_hours' => null,
            'expected_hours' => null,
            'status' => AttendanceStatus::Absent,
        ]);
    }

    public function on(string $businessDate): static
    {
        return $this->state(function () use ($businessDate) {
            $date = CarbonImmutable::parse($businessDate)->startOfDay();
            [$h, $m] = array_map('intval', explode(':', (string) config('attendance.shift_start')));

            $timeIn = $date->setTime($h, $m);
            $timeOut = $timeIn->addHours(8);

            return [
                'attendance_date' => $date->toDateString(),
                'time_in' => $timeIn,
                'time_out' => $timeOut,
                'total_hours' => 8.0,
            ];
        });
    }
}
