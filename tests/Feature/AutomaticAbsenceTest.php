<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\UserStatus;
use App\Models\Attendance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * `attendance:mark-absent`, which settles the night for everyone who never
 * arrived.
 *
 * Time is frozen at the configured cutoff for each test, because the command
 * marks the business date that "now" resolves to -- and at 00:00 that is the
 * night which began at 22:00 on the PREVIOUS calendar date. Getting that wrong
 * would mark the whole floor absent for a shift that has not started.
 */
beforeEach(function (): void {
    // 00:00 on Jul 27 is inside the shift that began 22:00 on Jul 26.
    Date::setTestNow(CarbonImmutable::parse('2026-07-27 00:00'));
});

afterEach(function (): void {
    Date::setTestNow();
});

it('marks an active tasker who never clocked in as absent', function (): void {
    $tasker = tasker();

    $this->artisan('attendance:mark-absent')->assertSuccessful();

    $record = Attendance::where('user_id', $tasker->id)->first();

    expect($record)->not->toBeNull();
    expect($record->status)->toBe(AttendanceStatus::Absent);
    expect($record->attendance_date->toDateString())->toBe('2026-07-26');
    expect($record->time_in)->toBeNull();
});

it('leaves a tasker who clocked in alone', function (): void {
    $tasker = tasker();

    Attendance::create([
        'user_id' => $tasker->id,
        'attendance_date' => '2026-07-26',
        'time_in' => CarbonImmutable::parse('2026-07-26 22:05'),
        'status' => AttendanceStatus::Present,
    ]);

    $this->artisan('attendance:mark-absent')->assertSuccessful();

    expect(Attendance::count())->toBe(1);
    expect(Attendance::first()->status)->toBe(AttendanceStatus::Present);
});

it('never marks an administrator absent', function (): void {
    // Admins are not on the roster. Marking them would put supervisors into the
    // discontinuation-risk report.
    $admin = admin();

    $this->artisan('attendance:mark-absent')->assertSuccessful();

    expect(Attendance::where('user_id', $admin->id)->exists())->toBeFalse();
});

it('skips taskers who are not active', function (): void {
    $suspended = tasker(['status' => UserStatus::Suspended]);

    $this->artisan('attendance:mark-absent')->assertSuccessful();

    expect(Attendance::where('user_id', $suspended->id)->exists())->toBeFalse();
});

it('is safe to run twice', function (): void {
    $tasker = tasker();

    $this->artisan('attendance:mark-absent')->assertSuccessful();
    $this->artisan('attendance:mark-absent')->assertSuccessful();

    // Not two rows, and not an error: everyone the second run would mark
    // already has a record. The unique index on (user_id, attendance_date) is
    // the real guarantee, and a restart re-running the schedule must be a
    // no-op rather than a hazard.
    expect(Attendance::where('user_id', $tasker->id)->count())->toBe(1);
});

it('writes nothing on a dry run', function (): void {
    tasker();

    $this->artisan('attendance:mark-absent', ['--dry-run' => true])->assertSuccessful();

    expect(Attendance::count())->toBe(0);
});

it('closes the daily flow once the tasker is absent', function (): void {
    $tasker = tasker();

    $this->artisan('attendance:mark-absent')->assertSuccessful();

    // Every step reads false and `settled` explains why. The tasker is not
    // asked to activate, claim a PC, file a tracker entry or time out of a
    // shift they did not work -- which is what the screen did before, and
    // which is a contradiction rather than a to-do list.
    $this->actingAs($tasker)->getJson('/api/daily/state')
        ->assertOk()
        ->assertJsonPath('data.settled', true)
        ->assertJsonPath('data.can_time_out', false)
        ->assertJsonPath('data.steps.activation', false)
        ->assertJsonPath('data.steps.clocked_in', false)
        ->assertJsonPath('data.steps.tracker', false)
        ->assertJsonPath('data.steps.clocked_out', false)
        ->assertJsonPath('data.attendance.status', 'absent');
});

it('leaves the flow open for a tasker who worked', function (): void {
    $tasker = tasker();

    Attendance::create([
        'user_id' => $tasker->id,
        'attendance_date' => '2026-07-26',
        'time_in' => CarbonImmutable::parse('2026-07-26 22:05'),
        'status' => AttendanceStatus::Present,
    ]);

    $this->actingAs($tasker)->getJson('/api/daily/state')
        ->assertOk()
        ->assertJsonPath('data.settled', false)
        ->assertJsonPath('data.steps.clocked_in', true);
});

it('counts an absence as absent on the admin dashboard, not present', function (): void {
    $tasker = tasker();

    $this->artisan('attendance:mark-absent')->assertSuccessful();

    // The regression this guards: `attendance_today` counts records of every
    // status, so deriving "present" from it by subtracting lateness reported
    // one absent tasker as present, on a 100% attendance rate.
    $this->actingAs(admin())->getJson('/api/admin/dashboard')
        ->assertOk()
        ->assertJsonPath('data.summary.absent_today', 1)
        ->assertJsonPath('data.summary.present_today', 0)
        ->assertJsonPath('data.summary.worked_today', 0)
        ->assertJsonPath('data.summary.attendance_today', 1);

    expect(Attendance::where('user_id', $tasker->id)->value('status'))
        ->toBe(AttendanceStatus::Absent);
});
