<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Services\ActivityLogger;
use App\Services\AttendanceService;
use App\Services\DailyFlowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Closes shifts still running at the end of the night.
 *
 * Runs at `attendance.auto_time_out_at`, which is the scheduled shift end. A
 * tasker who worked the night and forgot the button is credited with the hours
 * they were rostered for -- not a zero, and not nothing at all.
 *
 * What it deliberately does NOT do:
 *
 *  - Change the status. Present stays present, late stays late. Forgetting to
 *    clock out says nothing about whether someone turned up on time, and it
 *    says nothing about whether they produced anything -- so the flow stays
 *    open and the tracker entry can still be filed afterwards.
 *
 *  - Guess. The time written is the scheduled end of that shift, taken from
 *    the same config the roster uses. It is an assumption, so it is recorded
 *    as one: the note on the row is what separates a measured shift from an
 *    assumed one when an admin reviews the night.
 *
 * `attendance:close-stale` still runs and still matters. This handles the
 * night in progress; that one is the safety net for nights where this never
 * ran, and for anyone who clocked in after it had already passed.
 */
class AutoTimeOutShifts extends Command
{
    protected $signature = 'attendance:auto-time-out
                            {--dry-run : Report what would be closed without writing}';

    protected $description = 'Close shifts still open at the end of the night, at the scheduled shift end';

    public function handle(AttendanceService $attendance, ActivityLogger $logger): int
    {
        $businessDate = $attendance->resolveBusinessDate();
        $date = $businessDate->toDateString();

        // The moment the shift was scheduled to end -- 06:00 on the calendar
        // day AFTER a 22:00 start, which is why this comes from the service
        // rather than from "today at 06:00".
        $closeAt = $attendance->scheduledEnd($businessDate);

        $open = Attendance::query()
            ->open()
            ->where('attendance_date', $date)
            ->with('user')
            ->get();

        if ($open->isEmpty()) {
            $this->info("No shift left open for {$date}.");

            return self::SUCCESS;
        }

        $this->warn("{$open->count()} shift(s) still running for {$date}:");

        foreach ($open as $record) {
            $this->line(sprintf(
                '  %s  in at %s',
                $record->user?->email ?? 'unknown',
                $record->time_in?->format('g:i A') ?? '-',
            ));
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        $closed = [];

        foreach ($open as $record) {
            // Someone who clocked in after the scheduled end -- a very late
            // arrival on a night already over -- would produce a negative
            // span. Left open for close-stale to flag rather than written with
            // a nonsensical clock.
            if ($record->time_in === null || $record->time_in->greaterThanOrEqualTo($closeAt)) {
                $this->line("  {$record->user?->email} clocked in past the shift end -- left open.");

                continue;
            }

            $record->forceFill([
                'time_out' => $closeAt,
                'total_hours' => $attendance->computeHours($record->time_in, $closeAt),
                'notes' => trim(($record->notes ? $record->notes.' ' : '')
                    .'Clock closed automatically at the scheduled shift end ('
                    .$closeAt->format('g:i A').'); no time out was filed.'),
            ])->save();

            $closed[] = $record->id;
        }

        if ($closed === []) {
            $this->info('Nothing could be closed.');

            return self::SUCCESS;
        }

        // Desks held by those shifts are free now, and the shared picker is
        // cached -- so it has to be told.
        Cache::forget(DailyFlowService::workstationCacheKey($date));

        $logger->log(
            'attendance.auto_timed_out',
            count($closed).' shift(s) closed automatically for '.$date,
            null,
            ['attendance_ids' => $closed, 'business_date' => $date, 'closed_at' => $closeAt->toDateTimeString()],
        );

        $this->info(count($closed).' shift(s) closed at '.$closeAt->format('g:i A').'.');

        return self::SUCCESS;
    }
}
