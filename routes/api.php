<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminAttendanceController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\LookupController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\TaskerController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\DailyFlowController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\MetaController;
use App\Http\Controllers\PublicStatsController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Authentication is Sanctum's SPA (cookie) mode. Signing IN is not here: it is
| a browser redirect through Google, handled in routes/web.php. Everything
| below `auth:sanctum` reads the resulting session cookie, never a token.
|
| Two layers guard the admin surface: the `admin` middleware rejects the
| request outright, and policies authorise each individual record.
|
*/

// ------------------------------------------------------------------- Public

// Whether Google sign-in is configured, and where to send the browser.
Route::get('auth/status', [AuthController::class, 'status'])->name('auth.status');

// Enum options for populating select controls. No user data, so it is safe
// before authentication and lets the login screen render without a round trip.
Route::get('meta/options', [MetaController::class, 'options'])->name('meta.options');

// Aggregate floor figures for the marketing page. Counts only -- no names, no
// identifiers. Throttled because it is the one endpoint reachable by anyone
// with the URL, and the response is cached so the limit is generous.
Route::get('public/floor', [PublicStatsController::class, 'floor'])
    ->middleware('throttle:60,1')
    ->name('public.floor');

// ------------------------------------------------------------ Authenticated

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {

    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('me', [AuthController::class, 'me'])->name('me');

    // ------------------------------------------------------------ Attendance
    Route::prefix('attendance')->name('attendance.')->group(function (): void {
        Route::post('time-in', [AttendanceController::class, 'timeIn'])
            ->middleware('throttle:clock')->name('time-in');

        Route::post('time-out', [AttendanceController::class, 'timeOut'])
            ->middleware('throttle:clock')->name('time-out');

        Route::post('commitment', [AttendanceController::class, 'setCommitment'])->name('commitment');

        Route::get('today', [AttendanceController::class, 'today'])->name('today');
        Route::get('history', [AttendanceController::class, 'history'])->name('history');
        Route::get('summary', [AttendanceController::class, 'summary'])->name('summary');
    });

    // ----------------------------------------------------- Guided daily flow
    // Activation -> PC claim -> tracker entry. Time out stays on the
    // attendance routes above, since it is the same clock.
    Route::prefix('daily')->name('daily.')->group(function (): void {
        Route::get('state', [DailyFlowController::class, 'state'])->name('state');
        Route::get('options', [DailyFlowController::class, 'options'])->name('options');
        // Live claim state, split from the static lists above so it can be
        // fetched only while a PC is actually being picked.
        Route::get('workstations', [DailyFlowController::class, 'workstations'])->name('workstations');
        Route::post('activate', [DailyFlowController::class, 'activate'])
            ->middleware('throttle:clock')->name('activate');
        Route::post('tracker', [DailyFlowController::class, 'submitTracker'])->name('tracker');
        Route::get('tracker/history', [DailyFlowController::class, 'history'])->name('tracker.history');
    });

    // ----------------------------------------------------------------- Tasks
    // Taskers see only their own rows (scoped in the controller); admins see
    // all. Record-level access is enforced by TaskPolicy.
    Route::apiResource('tasks', TaskController::class);

    // ----------------------------------------------------------------- Admin
    Route::prefix('admin')->name('admin.')->middleware('admin')->group(function (): void {

        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('analytics/attendance', [DashboardController::class, 'analytics'])->name('analytics.attendance');

        // Tasker management. `restore` takes a raw id because the model it
        // targets is soft deleted and would not resolve through route binding.
        Route::post('taskers/{tasker}/restore', [TaskerController::class, 'restore'])
            ->whereNumber('tasker')->name('taskers.restore');
        Route::get('taskers/{tasker}/summary', [TaskerController::class, 'summary'])->name('taskers.summary');
        Route::apiResource('taskers', TaskerController::class);

        // Attendance oversight and correction.
        Route::get('attendance', [AdminAttendanceController::class, 'index'])->name('attendance.index');
        Route::post('attendance', [AdminAttendanceController::class, 'store'])->name('attendance.store');
        Route::get('attendance/{attendance}', [AdminAttendanceController::class, 'show'])->name('attendance.show');
        Route::put('attendance/{attendance}', [AdminAttendanceController::class, 'update'])->name('attendance.update');

        // Reporting.
        Route::prefix('reports')->name('reports.')->middleware('throttle:reports')->group(function (): void {
            Route::get('attendance', [ReportController::class, 'attendance'])->name('attendance');
            Route::get('productivity', [ReportController::class, 'productivity'])->name('productivity');
            Route::get('tasker-summary', [ReportController::class, 'taskerSummary'])->name('tasker-summary');
        });

        // The nightly tracker submissions the operation reviews each morning.
        Route::get('tracker-entries', [ReportController::class, 'trackerEntries'])->name('tracker-entries');

        Route::get('activity-logs', [ReportController::class, 'activityLogs'])->name('activity-logs');

        // Reference lists the daily flow depends on. One route set for all
        // four; `type` selects which. Retiring sets is_active = false rather
        // than deleting, so historical entries still resolve.
        Route::prefix('lookups/{type}')
            ->whereIn('type', ['projects', 'workstations', 'sites', 'support-teams'])
            ->name('lookups.')
            ->group(function (): void {
                Route::get('/', [LookupController::class, 'index'])->name('index');
                Route::post('/', [LookupController::class, 'store'])->name('store');
                Route::put('{id}', [LookupController::class, 'update'])->whereNumber('id')->name('update');
                Route::delete('{id}', [LookupController::class, 'destroy'])->whereNumber('id')->name('destroy');
            });

        // Excel export. `type` selects the report; filters come from the query
        // string and are stamped onto the generated workbook.
        Route::get('exports/{type}', ExportController::class)
            ->whereIn('type', ['attendance', 'productivity', 'taskers', 'tasker-summary'])
            ->middleware('throttle:reports')
            ->name('exports');

        // Excel import, in two phases: validate and preview, then commit.
        Route::post('imports/attendance/preview', [ImportController::class, 'preview'])->name('imports.attendance.preview');
        Route::post('imports/attendance/commit', [ImportController::class, 'commit'])->name('imports.attendance.commit');
        Route::get('imports/attendance/template', [ImportController::class, 'template'])->name('imports.attendance.template');
    });
});
