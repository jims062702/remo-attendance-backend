<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Requires a single cron entry on the server:
|
|   * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
|
| On Windows/XAMPP, use Task Scheduler to run `php artisan schedule:run` every
| minute against this directory.
|
*/

// Flag shifts that were never clocked out of. Runs at the configured time,
// which sits before the business-day cutoff so a live shift is never touched.
Schedule::command('attendance:close-stale')
    ->dailyAt((string) config('attendance.close_stale_at'))
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

// Mark active taskers who never clocked in as absent for the night in
// progress. Runs INSIDE the shift window, unlike the job above -- at the
// configured time the business date still resolves to the night being marked,
// which is the whole reason the time has to stay between shift start and shift
// end. See the "Automatic Absence" block in config/attendance.php.
Schedule::command('attendance:mark-absent')
    ->dailyAt((string) config('attendance.absent_at'))
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onOneServer();
