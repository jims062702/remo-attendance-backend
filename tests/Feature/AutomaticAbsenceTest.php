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
    // 00:01 on Jul 27 is inside the shift that began 22:00 on Jul 26.
    Date::setTestNow(CarbonImmutable::parse('2026-07-27 00:01'));
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

it('never marks a deactivated tasker absent', function (): void {
    // Deactivating takes someone off the roster. Filing an absence against
    // them every night afterwards would grow a record of failing to attend a
    // shift nobody expected them at -- and would feed the discontinuation-risk
    // counter for a person who has already left.
    $working = tasker(['email' => 'here@test.local']);
    $gone = tasker(['email' => 'gone@test.local']);

    $this->actingAs(admin())->deleteJson("/api/admin/taskers/{$gone->id}")->assertOk();

    $this->artisan('attendance:mark-absent')->assertSuccessful();

    expect(Attendance::where('user_id', $gone->id)->exists())->toBeFalse()
        // And the roster that remains is still marked, so this is a filter and
        // not the command quietly doing nothing.
        ->and(Attendance::where('user_id', $working->id)->exists())->toBeTrue();
});

it('excludes a deactivated tasker even if their status still reads active', function (): void {
    // Two independent guards: the soft-delete scope and the status filter.
    // This pins the first one on its own, so a future change to how
    // deactivation sets status cannot quietly put ex-taskers back on the
    // absence list.
    $gone = tasker(['email' => 'gone@test.local']);

    $this->actingAs(admin())->deleteJson("/api/admin/taskers/{$gone->id}")->assertOk();

    App\Models\User::withTrashed()->whereKey($gone->id)
        ->update(['status' => UserStatus::Active]);

    $this->artisan('attendance:mark-absent')->assertSuccessful();

    expect(Attendance::where('user_id', $gone->id)->exists())->toBeFalse();
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

it('refuses activation once the tasker is absent', function (): void {
    // Activation is the second door into the same clock: claiming a machine IS
    // clocking in. The flow hides itself once settled, so nothing in the UI
    // offers this -- which is precisely why the service has to refuse it rather
    // than trust the screen.
    $tasker = tasker();
    $site = App\Models\Site::create(['name' => 'BEAMO 3F C', 'is_active' => true]);
    $pc = App\Models\Workstation::create([
        'name' => 'PC-01', 'site_id' => $site->id, 'is_active' => true,
    ]);

    $this->artisan('attendance:mark-absent')->assertSuccessful();

    $this->actingAs($tasker)->postJson('/api/daily/activate', [
        'commitment_bracket' => '7_plus_hours',
        'tasking_statuses' => ['tasking'],
        'workstation_id' => $pc->id,
        'pc_status' => 'used',
    ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'attendance.marked_absent');

    $record = Attendance::where('user_id', $tasker->id)->firstOrFail();

    expect($record->status)->toBe(AttendanceStatus::Absent);
    expect($record->time_in)->toBeNull();
    expect($record->workstation_id)->toBeNull();
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

it('leaves a late tasker who beat the cutoff fully enabled', function (): void {
    // 23:59 is one minute inside the window. Late, but present -- and the
    // distinction the whole rule turns on is whether a clock was started, not
    // whether it was started on time.
    $tasker = tasker();

    Attendance::create([
        'user_id' => $tasker->id,
        'attendance_date' => '2026-07-26',
        'time_in' => CarbonImmutable::parse('2026-07-26 23:59'),
        'status' => AttendanceStatus::Late,
    ]);

    $this->artisan('attendance:mark-absent')->assertSuccessful();

    expect(Attendance::where('user_id', $tasker->id)->first()->status)
        ->toBe(AttendanceStatus::Late);

    $this->actingAs($tasker)->getJson('/api/daily/state')
        ->assertOk()
        ->assertJsonPath('data.settled', false)
        ->assertJsonPath('data.can_time_out', true);
});

it('lets a tasker who clocked in keep filing after the cutoff', function (): void {
    // The cutoff settles the night for people who never arrived. It must not
    // touch anyone mid-shift: at 00:01 a tasker on the floor still has a
    // tracker entry to file and a clock to close.
    $site = App\Models\Site::create(['name' => 'BEAMO 3F C', 'is_active' => true]);
    $pc = App\Models\Workstation::create([
        'name' => 'PC-06 3F C', 'site_id' => $site->id, 'is_active' => true,
    ]);
    $project = App\Models\Project::create(['code' => 'sky_feather', 'is_active' => true]);

    $tasker = tasker();

    Attendance::create([
        'user_id' => $tasker->id,
        'workstation_id' => $pc->id,
        'attendance_date' => '2026-07-26',
        'time_in' => CarbonImmutable::parse('2026-07-26 22:05'),
        'status' => AttendanceStatus::Present,
        'commitment_bracket' => '7_plus_hours',
    ]);

    $this->artisan('attendance:mark-absent')->assertSuccessful();

    // Tracker entry, after the cutoff.
    $this->actingAs($tasker)->postJson('/api/daily/tracker', [
        'tenurity' => 'expert',
        'items' => [[
            'project_id' => $project->id,
            'tasker_level' => 'l8',
            'total_tasks' => 42,
            'task_ids' => 'TASK1, TASK2 (SBQ)',
            'task_complexity' => 'mid_scene_frames',
            'screenshot_links' => 'https://drive.example.com/a',
        ]],
        'remarks' => 'N/A',
    ])->assertCreated();

    // And the clock still closes.
    $this->actingAs($tasker)->postJson('/api/attendance/time-out')->assertOk();

    expect(Attendance::where('user_id', $tasker->id)->first()->time_out)->not->toBeNull();
});

it('re-enables everything once the business date rolls over', function (): void {
    // The absence settles ONE night. Nothing carries it forward, because the
    // flow is derived from the attendance row for the business date in
    // progress -- so 21:50 reopens it without anything having to reset.
    $tasker = tasker();

    $this->artisan('attendance:mark-absent')->assertSuccessful();

    $this->actingAs($tasker)->getJson('/api/daily/state')
        ->assertJsonPath('data.settled', true)
        ->assertJsonPath('data.business_date', '2026-07-26');

    // One minute before the rollover: still last night, still settled.
    Date::setTestNow(CarbonImmutable::parse('2026-07-27 21:49'));

    $this->actingAs($tasker)->getJson('/api/daily/state')
        ->assertJsonPath('data.settled', true)
        ->assertJsonPath('data.business_date', '2026-07-26');

    // At the rollover: a new night, an empty flow.
    Date::setTestNow(CarbonImmutable::parse('2026-07-27 21:50'));

    $this->actingAs($tasker)->getJson('/api/daily/state')
        ->assertJsonPath('data.settled', false)
        ->assertJsonPath('data.business_date', '2026-07-27')
        ->assertJsonPath('data.steps.activation', false)
        ->assertJsonPath('data.steps.clocked_in', false);
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
