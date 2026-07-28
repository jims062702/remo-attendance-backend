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
