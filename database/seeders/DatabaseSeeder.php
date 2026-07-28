<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AttendanceStatus;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo data: one admin, several taskers, and three weeks of overnight shifts
 * with production submissions.
 *
 * Deliberately includes the awkward cases -- a forgotten clock-out, absences,
 * a deactivated account -- because those are exactly the states the dashboards
 * and reports need to be exercised against.
 */
class DatabaseSeeder extends Seeder
{
    private const TASK_NAMES = [
        'Data Validation',
        'Data Entry',
        'Image Tagging',
        'Transcription',
        'Lead Verification',
    ];

    public function run(): void
    {
        // Reference data first: attendance and tracker rows point at it.
        $this->call(OperationsLookupSeeder::class);

        $admin = $this->createUser('Maria Santos', 'admin@remoattendance.test', UserRole::Admin);

        $taskers = collect([
            $this->createUser('Juan Dela Cruz', 'juan@remoattendance.test', UserRole::Tasker),
            $this->createUser('Ana Reyes', 'ana@remoattendance.test', UserRole::Tasker),
            $this->createUser('Miguel Bautista', 'miguel@remoattendance.test', UserRole::Tasker),
            $this->createUser('Sofia Ramos', 'sofia@remoattendance.test', UserRole::Tasker),
        ]);

        // A deactivated account, to prove history survives deactivation.
        $former = $this->createUser('Carlo Mendoza', 'carlo@remoattendance.test', UserRole::Tasker);
        $former->status = UserStatus::Inactive;
        $former->save();

        $this->seedHistory($taskers->push($former));

        $this->command?->newLine();
        $this->command?->info('Demo data seeded. Sign-in is Google-only.');
        $this->command?->table(
            ['Role', 'Email'],
            [
                ['Admin', $admin->email],
                ['Tasker', 'juan@remoattendance.test'],
                ['Tasker', 'ana@remoattendance.test'],
                ['Tasker', 'miguel@remoattendance.test'],
                ['Tasker', 'sofia@remoattendance.test'],
                ['Tasker (inactive)', $former->email],
            ],
        );
        $this->command?->newLine();
        $this->command?->warn('These demo addresses are not real Google accounts, so none of them can sign in.');
        $this->command?->line('To give yourself access, authorise your own Google address:');
        $this->command?->line('    php artisan user:authorise "you@yourdomain.com" --name="Your Name" --admin');
    }

    /**
     * Seeded accounts carry no google_id: they have been authorised but never
     * signed in, which is the real state of a freshly provisioned account.
     */
    private function createUser(string $name, string $email, UserRole $role): User
    {
        $user = new User;
        $user->fill([
            'name' => $name,
            'email' => $email,
        ]);
        $user->role = $role;
        $user->status = UserStatus::Active;
        $user->save();

        return $user;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $taskers
     */
    private function seedHistory($taskers): void
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', (string) config('attendance.shift_start')));
        $grace = (int) config('attendance.grace_minutes');
        $standardHours = (float) config('attendance.standard_hours');

        // Yesterday backwards, so "today" stays clear for manual testing.
        for ($daysAgo = 21; $daysAgo >= 1; $daysAgo--) {
            $businessDate = CarbonImmutable::now()->subDays($daysAgo)->startOfDay();

            foreach ($taskers as $index => $tasker) {
                // Everyone misses the occasional night.
                if (random_int(1, 100) <= 8) {
                    Attendance::create([
                        'user_id' => $tasker->id,
                        'attendance_date' => $businessDate->toDateString(),
                        'status' => random_int(0, 1) === 1 ? AttendanceStatus::Absent : AttendanceStatus::OnLeave,
                        'notes' => 'Seeded absence.',
                    ]);

                    continue;
                }

                $offset = random_int(-8, 40);
                $timeIn = $businessDate->setTime($startHour, $startMinute)->addMinutes($offset);

                // One shift in twelve is never clocked out of. time_out and
                // total_hours stay NULL -- the hours are genuinely unknown.
                $forgotClockOut = random_int(1, 12) === 1;

                $timeOut = $forgotClockOut
                    ? null
                    : $timeIn->addMinutes(random_int(440, 520));

                $status = match (true) {
                    $forgotClockOut => AttendanceStatus::Incomplete,
                    $offset > $grace => AttendanceStatus::Late,
                    default => AttendanceStatus::Present,
                };

                $attendance = Attendance::create([
                    'user_id' => $tasker->id,
                    'attendance_date' => $businessDate->toDateString(),
                    'time_in' => $timeIn,
                    'time_out' => $timeOut,
                    'total_hours' => $timeOut
                        ? round(($timeOut->getTimestamp() - $timeIn->getTimestamp()) / 3600, 2)
                        : null,
                    'expected_hours' => $standardHours,
                    'status' => $status,
                ]);

                $this->seedTasks($attendance, $businessDate, $index);
            }
        }
    }

    private function seedTasks(Attendance $attendance, CarbonImmutable $businessDate, int $sequenceSeed): void
    {
        $count = random_int(1, 3);

        for ($i = 1; $i <= $count; $i++) {
            Task::create([
                'task_code' => sprintf(
                    'TASK-%s-%04d',
                    $businessDate->format('Ymd'),
                    ($sequenceSeed * 10) + $i,
                ),
                'external_task_id' => random_int(1, 3) === 1 ? 'REF-'.random_int(1000, 9999) : null,
                'user_id' => $attendance->user_id,
                'attendance_id' => $attendance->id,
                'task_date' => $businessDate->toDateString(),
                'task_name' => self::TASK_NAMES[array_rand(self::TASK_NAMES)],
                'task_description' => 'Seeded production record.',
                'output_count' => random_int(40, 350),
                'task_status' => $this->weightedTaskStatus(),
                'screenshot_link' => random_int(1, 4) === 1
                    ? 'https://drive.example.com/file/'.bin2hex(random_bytes(6))
                    : null,
            ]);
        }
    }

    private function weightedTaskStatus(): TaskStatus
    {
        $roll = random_int(1, 100);

        return match (true) {
            $roll <= 62 => TaskStatus::Completed,
            $roll <= 78 => TaskStatus::InProgress,
            $roll <= 90 => TaskStatus::Pending,
            $roll <= 96 => TaskStatus::OnHold,
            default => TaskStatus::Cancelled,
        };
    }
}
