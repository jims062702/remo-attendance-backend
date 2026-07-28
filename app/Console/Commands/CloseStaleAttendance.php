<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Services\ActivityLogger;
use App\Services\AttendanceService;
use Illuminate\Console\Command;

/**
 * Marks shifts that were clocked into but never clocked out of.
 *
 * These records are left with total_hours NULL deliberately -- the hours are
 * genuinely unknown, and inventing a value (or a zero) would corrupt every
 * average that includes them. Flagging them as `incomplete` stops them being
 * counted as still in progress and surfaces them for an admin to correct.
 *
 * Scheduled before the business-day cutoff so it can never touch a shift that
 * is still legitimately running.
 */
class CloseStaleAttendance extends Command
{
    protected $signature = 'attendance:close-stale
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Flag unclosed shifts from previous business dates as incomplete';

    public function handle(AttendanceService $attendance, ActivityLogger $logger): int
    {
        $businessDate = $attendance->resolveBusinessDate();

        $stale = Attendance::query()
            ->open()
            ->where('attendance_date', '<', $businessDate->toDateString())
            ->with('user')
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stale shifts found.');

            return self::SUCCESS;
        }

        $this->warn("Found {$stale->count()} unclosed shift(s) before {$businessDate->toDateString()}:");

        foreach ($stale as $record) {
            $this->line(sprintf(
                '  %s  %s  in at %s',
                $record->attendance_date->toDateString(),
                $record->user?->email ?? 'unknown',
                $record->time_in?->format('Y-m-d g:i A') ?? '-',
            ));
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        $ids = $stale->pluck('id')->all();

        Attendance::whereIn('id', $ids)->update(['status' => AttendanceStatus::Incomplete]);

        $logger->log(
            'attendance.closed_stale',
            "Flagged {$stale->count()} unclosed shift(s) as incomplete",
            null,
            ['attendance_ids' => $ids, 'before_business_date' => $businessDate->toDateString()],
        );

        $this->info("Flagged {$stale->count()} shift(s) as incomplete.");

        return self::SUCCESS;
    }
}
