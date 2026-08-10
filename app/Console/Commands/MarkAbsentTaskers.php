<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\MarkedAbsentMail;
use App\Models\Attendance;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AttendanceService;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Marks every active tasker who has not clocked in as absent for the night.
 *
 * Runs once per night at `attendance.absent_at`, part-way into the shift. Until
 * this existed an absence was only ever recorded if an admin noticed and typed
 * it, so "absent" and "nobody has looked yet" were the same empty state: the
 * roll call could not tell them apart, the rolling absence-warning counter only
 * saw the absences somebody remembered, and the tasker's own screen went on
 * asking them to file a shift they were never going to work.
 *
 * Marking is FINAL -- AttendanceService::timeIn() refuses a clock-in once a
 * record exists. A tasker who turns up afterwards needs an admin to correct the
 * record, which is the point: whether a shift joined hours late counts is a
 * decision for a person, not a side effect of a scheduler.
 *
 * Idempotent. Re-running it touches nobody, because everyone it would mark
 * already has a record.
 */
class MarkAbsentTaskers extends Command
{
    protected $signature = 'attendance:mark-absent
                            {--dry-run : Report who would be marked without writing}';

    protected $description = 'Mark active taskers with no clock-in as absent for the night in progress';

    public function handle(AttendanceService $attendance, ActivityLogger $logger): int
    {
        $businessDate = $attendance->resolveBusinessDate();
        $date = $businessDate->toDateString();

        // Nobody is expected on a rest night, so nobody is absent from it.
        // Without this the floor was marked absent every Sunday and Monday --
        // records of failing to attend a shift that was never scheduled, which
        // also fed the rolling absence-warning counter.
        if (! $attendance->isWorkingDate($businessDate)) {
            $this->info(sprintf(
                '%s is a %s, which is not a rostered night. Nobody to mark.',
                $date,
                $businessDate->format('l'),
            ));

            return self::SUCCESS;
        }

        // Admins are excluded. They are not on the roster, are not counted in
        // the roll call's denominator, and marking them absent would put
        // supervisors into the discontinuation-risk report.
        $missing = User::query()
            ->where('role', UserRole::Tasker)
            ->where('status', UserStatus::Active)
            ->whereNotExists(function ($query) use ($date): void {
                $query->selectRaw('1')
                    ->from('attendances')
                    ->whereColumn('attendances.user_id', 'users.id')
                    ->where('attendances.attendance_date', $date)
                    // Soft-deleted attendance is a record an admin removed on
                    // purpose; re-creating it here would undo that every night.
                    ->whereNull('attendances.deleted_at');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        if ($missing->isEmpty()) {
            $this->info("Every active tasker has attendance for {$date}. Nobody to mark.");

            return self::SUCCESS;
        }

        $this->warn("{$missing->count()} tasker(s) with no attendance for {$date}:");

        foreach ($missing as $tasker) {
            $this->line("  {$tasker->email}  ({$tasker->name})");
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        $marked = [];

        foreach ($missing as $tasker) {
            try {
                $record = Attendance::create([
                    'user_id' => $tasker->id,
                    'attendance_date' => $date,
                    'status' => AttendanceStatus::Absent,
                    'notes' => 'Marked absent automatically: no clock-in by '
                        .config('attendance.absent_at').'.',
                ]);

                $marked[] = $record->id;

                // Told the same night, while an admin can still correct it.
                // An absence someone discovers a week later in a report is a
                // dispute; one they hear about at 12:01 is a phone call.
                try {
                    $record->setRelation('user', $tasker);
                    Mail::to($tasker->email)->send(new MarkedAbsentMail($record));
                } catch (Throwable $e) {
                    report($e);
                }
            } catch (UniqueConstraintViolationException) {
                // Lost a race with a clock-in that landed between the query
                // above and this insert. The unique index on
                // (user_id, attendance_date) is what actually guarantees one
                // record per night; here it simply means the tasker turned up
                // after all, so there is nothing to mark and nothing wrong.
                $this->line("  {$tasker->email} clocked in just now -- skipped.");
            }
        }

        if ($marked === []) {
            $this->info('Everyone clocked in before they could be marked.');

            return self::SUCCESS;
        }

        $logger->log(
            'attendance.marked_absent',
            count($marked).' tasker(s) marked absent for '.$date,
            null,
            ['attendance_ids' => $marked, 'business_date' => $date],
        );

        $this->info(count($marked).' tasker(s) marked absent for '.$date.'.');

        return self::SUCCESS;
    }
}
