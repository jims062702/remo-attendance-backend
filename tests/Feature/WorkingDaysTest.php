<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * The roster: which nights the floor actually runs.
 *
 * A night is named by the date it STARTS. The shift beginning 10 PM Tuesday and
 * finishing 6 AM Wednesday is a Tuesday night, so Tuesday is what has to be on
 * the roster -- getting that backwards would shift the whole week by one.
 *
 * Before this existed every calendar date counted as a scheduled night, which
 * was wrong twice: the floor was marked absent on Sundays and Mondays, and the
 * attendance rate divided by seven nights when only five were ever expected.
 *
 * Dates in this file are real weekdays, checked rather than assumed:
 *   2026-08-02 Sunday    2026-08-03 Monday    2026-08-04 Tuesday
 *   2026-08-05 Wednesday 2026-08-06 Thursday  2026-08-07 Friday
 *   2026-08-08 Saturday
 */
beforeEach(function (): void {
    // Stated here rather than inherited: phpunit.xml runs the rest of the
    // suite with an empty roster (every night), so the days under test are
    // named by the test that tests them.
    config(['attendance.working_days' => [2, 3, 4, 5, 6]]);

    $this->service = app(AttendanceService::class);
});

afterEach(function (): void {
    Date::setTestNow();
});

it('agrees with the calendar about which dates are which weekday', function (): void {
    // The rest of this file is meaningless if these drift.
    expect(CarbonImmutable::parse('2026-08-02')->format('l'))->toBe('Sunday')
        ->and(CarbonImmutable::parse('2026-08-03')->format('l'))->toBe('Monday')
        ->and(CarbonImmutable::parse('2026-08-04')->format('l'))->toBe('Tuesday')
        ->and(CarbonImmutable::parse('2026-08-08')->format('l'))->toBe('Saturday');
});

it('runs Tuesday through Saturday and rests Sunday and Monday', function (): void {
    $working = fn (string $date): bool => $this->service
        ->isWorkingDate(CarbonImmutable::parse($date));

    expect($working('2026-08-02'))->toBeFalse()   // Sunday
        ->and($working('2026-08-03'))->toBeFalse()  // Monday
        ->and($working('2026-08-04'))->toBeTrue()   // Tuesday
        ->and($working('2026-08-05'))->toBeTrue()   // Wednesday
        ->and($working('2026-08-06'))->toBeTrue()   // Thursday
        ->and($working('2026-08-07'))->toBeTrue()   // Friday
        ->and($working('2026-08-08'))->toBeTrue();  // Saturday
});

it('counts five rostered nights in a full week', function (): void {
    expect($this->service->workingDatesBetween(
        CarbonImmutable::parse('2026-08-02'),   // Sunday
        CarbonImmutable::parse('2026-08-08'),   // Saturday
    ))->toBe(5);
});

// ------------------------------------------------------------ Marking absent

it('marks nobody absent on a Sunday night', function (): void {
    // 00:01 on Monday Aug 3 resolves to the Sunday Aug 2 business date -- the
    // night that would have started at 10 PM Sunday, if there were one.
    Date::setTestNow(CarbonImmutable::parse('2026-08-03 00:01'));

    $tasker = tasker();

    $this->artisan('attendance:mark-absent')->assertSuccessful();

    expect(Attendance::where('user_id', $tasker->id)->exists())->toBeFalse();
});

it('marks nobody absent on a Monday night either', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-08-04 00:01'));   // Monday Aug 3

    $tasker = tasker();

    $this->artisan('attendance:mark-absent')->assertSuccessful();

    expect(Attendance::where('user_id', $tasker->id)->exists())->toBeFalse();
});

it('still marks absent on a rostered night', function (): void {
    // Tuesday Aug 4. Proves the roster is a filter and not the command giving
    // up entirely.
    Date::setTestNow(CarbonImmutable::parse('2026-08-05 00:01'));

    $tasker = tasker();

    $this->artisan('attendance:mark-absent')->assertSuccessful();

    $record = Attendance::where('user_id', $tasker->id)->first();

    expect($record)->not->toBeNull()
        ->and($record->attendance_date->toDateString())->toBe('2026-08-04');
});

it('closes the whole flow on a rest night', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-08-02 22:05'));   // Sunday

    $this->actingAs(tasker())->getJson('/api/daily/state')
        ->assertOk()
        ->assertJsonPath('data.off_duty', true)
        ->assertJsonPath('data.can_time_out', false)
        ->assertJsonPath('data.steps.activation', false)
        ->assertJsonPath('data.steps.clocked_in', false)
        ->assertJsonPath('data.steps.tracker', false)
        ->assertJsonPath('data.steps.clocked_out', false);
});

it('refuses a clock-in on a rest night', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-08-02 22:05'));   // Sunday

    $tasker = tasker();

    $this->actingAs($tasker)->postJson('/api/attendance/time-in')
        ->assertStatus(409)
        ->assertJsonPath('code', 'attendance.not_rostered');

    expect(Attendance::where('user_id', $tasker->id)->exists())->toBeFalse();
});

it('refuses activation on a rest night too', function (): void {
    // Claiming a machine IS clocking in, so the second door needs the same lock.
    Date::setTestNow(CarbonImmutable::parse('2026-08-02 22:05'));   // Sunday

    $site = App\Models\Site::create(['name' => 'BEAMO 3F C', 'is_active' => true]);
    $pc = App\Models\Workstation::create([
        'name' => 'PC-06 3F C', 'site_id' => $site->id, 'is_active' => true,
    ]);

    $this->actingAs(tasker())->postJson('/api/daily/activate', [
        'commitment_bracket' => '7_plus_hours',
        'tasking_statuses' => ['tasking'],
        'workstation_id' => $pc->id,
        'pc_status' => 'used',
    ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'attendance.not_rostered');

    expect(Attendance::count())->toBe(0);
});

it('opens the flow again on a rostered night', function (): void {
    // Tuesday. Proves the guard is a roster and not a blanket refusal.
    Date::setTestNow(CarbonImmutable::parse('2026-08-04 22:05'));

    $tasker = tasker();

    $this->actingAs($tasker)->getJson('/api/daily/state')
        ->assertOk()
        ->assertJsonPath('data.off_duty', false);

    $this->actingAs($tasker)->postJson('/api/attendance/time-in')
        ->assertCreated()
        ->assertJsonPath('data.status', 'present');
});

// ---------------------------------------------------------- Attendance rate

it('measures the rate against rostered nights, not calendar days', function (): void {
    $tasker = tasker();

    // Every rostered night of the week worked: Tuesday to Saturday.
    foreach (['2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07', '2026-08-08'] as $date) {
        Attendance::create([
            'user_id' => $tasker->id,
            'attendance_date' => $date,
            'time_in' => CarbonImmutable::parse($date.' 22:05'),
            'time_out' => CarbonImmutable::parse($date.' 22:05')->addHours(8),
            'total_hours' => 8,
            'status' => App\Enums\AttendanceStatus::Present,
        ]);
    }

    $summary = $this->actingAs(admin())
        ->getJson("/api/admin/taskers/{$tasker->id}/summary?from=2026-08-02&to=2026-08-08")
        ->assertOk()
        ->json('data.summary.attendance');

    // Five of five, not five of seven -- which used to score about 71%.
    // Cast because a whole percentage round-trips through JSON as an int.
    expect($summary['days_worked'])->toBe(5)
        ->and((float) $summary['attendance_rate'])->toBe(100.0);
});
