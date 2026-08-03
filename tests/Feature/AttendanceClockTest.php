<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * The clock rules, exercised through the HTTP API.
 *
 * Time is frozen inside the shift window for each test, because "now" decides
 * which business date a clock event belongs to.
 */
beforeEach(function (): void {
    $this->tasker = tasker();
    $this->service = app(AttendanceService::class);
});

afterEach(function (): void {
    Date::setTestNow();
});

it('records a time in on server time', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 22:05'));

    $response = $this->actingAs($this->tasker)->postJson('/api/attendance/time-in');

    $response->assertCreated()
        ->assertJsonPath('data.attendance_date', '2026-07-26')
        ->assertJsonPath('data.status', 'present')
        ->assertJsonPath('data.is_open', true);

    expect(Attendance::where('user_id', $this->tasker->id)->count())->toBe(1);
});

it('ignores any client supplied time', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 22:05'));

    // A tasker trying to backdate their arrival to look on time.
    $this->actingAs($this->tasker)
        ->postJson('/api/attendance/time-in', [
            'time_in' => '2026-07-26 21:00:00',
            'attendance_date' => '2026-07-01',
            'status' => 'present',
        ])
        ->assertCreated();

    $attendance = Attendance::firstOrFail();

    expect($attendance->time_in->format('Y-m-d H:i'))->toBe('2026-07-26 22:05')
        ->and($attendance->attendance_date->toDateString())->toBe('2026-07-26');
});

it('refuses a second time in on the same shift', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 22:05'));
    $this->actingAs($this->tasker)->postJson('/api/attendance/time-in')->assertCreated();

    // Later the same night -- still the same business date.
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 23:30'));

    $this->actingAs($this->tasker)->postJson('/api/attendance/time-in')
        ->assertStatus(409)
        ->assertJsonPath('code', 'attendance.already_timed_in');

    expect(Attendance::count())->toBe(1);
});

it('refuses a second time in even after midnight', function (): void {
    // The case a naive calendar-date implementation gets wrong: 01:00 is a new
    // calendar day but the SAME shift.
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 22:05'));
    $this->actingAs($this->tasker)->postJson('/api/attendance/time-in')->assertCreated();

    Date::setTestNow(CarbonImmutable::parse('2026-07-27 01:00'));

    $this->actingAs($this->tasker)->postJson('/api/attendance/time-in')
        ->assertStatus(409)
        ->assertJsonPath('code', 'attendance.already_timed_in');

    expect(Attendance::count())->toBe(1);
});

it('refuses a time out without a time in', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-27 06:00'));

    $this->actingAs($this->tasker)->postJson('/api/attendance/time-out')
        ->assertStatus(409)
        ->assertJsonPath('code', 'attendance.not_timed_in');
});

it('records a time out and computes hours across midnight', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 22:00'));
    $this->actingAs($this->tasker)->postJson('/api/attendance/time-in')->assertCreated();

    Date::setTestNow(CarbonImmutable::parse('2026-07-27 06:00'));
    $response = $this->actingAs($this->tasker)->postJson('/api/attendance/time-out');

    $response->assertOk()
        ->assertJsonPath('data.total_hours', 8)
        ->assertJsonPath('data.is_open', false)
        // The record still belongs to the night it started.
        ->assertJsonPath('data.attendance_date', '2026-07-26');
});

it('refuses a second time out', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 22:00'));
    $this->actingAs($this->tasker)->postJson('/api/attendance/time-in');

    Date::setTestNow(CarbonImmutable::parse('2026-07-27 06:00'));
    $this->actingAs($this->tasker)->postJson('/api/attendance/time-out')->assertOk();

    Date::setTestNow(CarbonImmutable::parse('2026-07-27 06:30'));
    $this->actingAs($this->tasker)->postJson('/api/attendance/time-out')
        ->assertStatus(409)
        ->assertJsonPath('code', 'attendance.already_timed_out');
});

it('marks a late clock-in as late', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 22:45'));

    $this->actingAs($this->tasker)->postJson('/api/attendance/time-in')
        ->assertCreated()
        ->assertJsonPath('data.status', 'late')
        ->assertJsonPath('data.minutes_late', 45);
});

it('enforces one shift per business date at the database level', function (): void {
    // Bypasses the service entirely to prove the unique index -- not just the
    // application check -- is what guarantees business rule 17.
    Attendance::create([
        'user_id' => $this->tasker->id,
        'attendance_date' => '2026-07-26',
        'status' => AttendanceStatus::Present,
    ]);

    Attendance::create([
        'user_id' => $this->tasker->id,
        'attendance_date' => '2026-07-26',
        'status' => AttendanceStatus::Present,
    ]);
})->throws(Illuminate\Database\UniqueConstraintViolationException::class);

it('refuses a clock-in once the tasker has been marked absent', function (): void {
    // Recorded either by attendance:mark-absent at the cutoff or by an admin.
    // Either way the night is settled: clocking in over the top would erase the
    // absence, and every figure already derived from it -- the roll call, the
    // rolling absence-warning counter, any report filed since -- would quietly
    // stop agreeing with the record.
    Attendance::create([
        'user_id' => $this->tasker->id,
        'attendance_date' => '2026-07-26',
        'status' => AttendanceStatus::Absent,
    ]);

    Date::setTestNow(CarbonImmutable::parse('2026-07-27 00:30'));

    $this->actingAs($this->tasker)->postJson('/api/attendance/time-in')
        ->assertStatus(409)
        ->assertJsonPath('code', 'attendance.marked_absent');

    expect(Attendance::count())->toBe(1);
    expect(Attendance::first()->status)->toBe(AttendanceStatus::Absent);
    expect(Attendance::first()->time_in)->toBeNull();
});

it('refuses a clock-in for a tasker recorded as on leave', function (): void {
    Attendance::create([
        'user_id' => $this->tasker->id,
        'attendance_date' => '2026-07-26',
        'status' => AttendanceStatus::OnLeave,
    ]);

    Date::setTestNow(CarbonImmutable::parse('2026-07-26 22:05'));

    $this->actingAs($this->tasker)->postJson('/api/attendance/time-in')
        ->assertStatus(409)
        ->assertJsonPath('code', 'attendance.marked_absent');
});

it('reports today with correct action availability', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 22:05'));

    $this->actingAs($this->tasker)->getJson('/api/attendance/today')
        ->assertOk()
        ->assertJsonPath('data', null)
        ->assertJsonPath('meta.can_time_in', true)
        ->assertJsonPath('meta.can_time_out', false);

    $this->actingAs($this->tasker)->postJson('/api/attendance/time-in');

    $this->actingAs($this->tasker)->getJson('/api/attendance/today')
        ->assertOk()
        ->assertJsonPath('meta.can_time_in', false)
        ->assertJsonPath('meta.can_time_out', true);
});

it('stores the production commitment on the shift', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 22:05'));
    $this->actingAs($this->tasker)->postJson('/api/attendance/time-in');

    $this->actingAs($this->tasker)
        ->postJson('/api/attendance/commitment', ['expected_hours' => 8])
        ->assertOk()
        ->assertJsonPath('data.expected_hours', 8);

    Date::setTestNow(CarbonImmutable::parse('2026-07-27 05:30'));

    // 7.42 actual against 8 committed.
    $this->actingAs($this->tasker)->postJson('/api/attendance/time-out')
        ->assertOk()
        ->assertJsonPath('data.variance', -0.58);
});

it('refuses a commitment before clocking in', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 21:00'));

    $this->actingAs($this->tasker)
        ->postJson('/api/attendance/commitment', ['expected_hours' => 8])
        ->assertStatus(409)
        ->assertJsonPath('code', 'attendance.no_shift_for_commitment');
});

it('validates the commitment against configured bounds', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 22:05'));
    $this->actingAs($this->tasker)->postJson('/api/attendance/time-in');

    $this->actingAs($this->tasker)
        ->postJson('/api/attendance/commitment', ['expected_hours' => 99])
        ->assertStatus(422)
        ->assertJsonValidationErrors('expected_hours');
});

it('blocks clock actions for a deactivated account', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 22:05'));

    $inactive = tasker()->fill([])->forceFill(['status' => App\Enums\UserStatus::Suspended]);
    $inactive->save();

    $this->actingAs($inactive)->postJson('/api/attendance/time-in')
        ->assertStatus(403)
        ->assertJsonPath('code', 'auth.account_inactive');
});
