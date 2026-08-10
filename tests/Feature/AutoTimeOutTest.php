<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Project;
use App\Models\Site;
use App\Models\Workstation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * `attendance:auto-time-out`, which closes the clock for anyone who worked the
 * night and forgot the button.
 *
 * Frozen at 06:00 on Jul 27, which is the scheduled end of the shift that
 * began 22:00 on Jul 26. With the 21:50 cutoff that moment still resolves to
 * the Jul 26 business date -- the night being closed, not the one about to
 * open.
 */
beforeEach(function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-27 06:00'));
});

afterEach(function (): void {
    Date::setTestNow();
});

/** A shift clocked in and never clocked out. */
function openShift(App\Models\User $user, string $timeIn = '2026-07-26 22:05', ?int $pcId = null): Attendance
{
    return Attendance::create([
        'user_id' => $user->id,
        'workstation_id' => $pcId,
        'attendance_date' => '2026-07-26',
        'time_in' => CarbonImmutable::parse($timeIn),
        'status' => AttendanceStatus::Present,
    ]);
}

it('closes an open shift at the scheduled shift end', function (): void {
    $tasker = tasker();
    openShift($tasker);

    $this->artisan('attendance:auto-time-out')->assertSuccessful();

    $record = Attendance::where('user_id', $tasker->id)->firstOrFail();

    expect($record->time_out?->format('Y-m-d H:i'))->toBe('2026-07-27 06:00')
        // 22:05 to 06:00 is 7.92 hours -- computed, not invented.
        ->and((float) $record->total_hours)->toBe(7.92);
});

it('leaves the status alone so the tasker is still present', function (): void {
    // The whole point: forgetting a button says nothing about whether someone
    // turned up on time, and nothing about whether they produced anything.
    $onTime = tasker();
    $late = tasker();

    openShift($onTime);

    Attendance::create([
        'user_id' => $late->id,
        'attendance_date' => '2026-07-26',
        'time_in' => CarbonImmutable::parse('2026-07-26 23:30'),
        'status' => AttendanceStatus::Late,
    ]);

    $this->artisan('attendance:auto-time-out')->assertSuccessful();

    expect(Attendance::where('user_id', $onTime->id)->first()->status)
        ->toBe(AttendanceStatus::Present)
        ->and(Attendance::where('user_id', $late->id)->first()->status)
        ->toBe(AttendanceStatus::Late);
});

it('records that the clock was closed for them, not by them', function (): void {
    $tasker = tasker();
    openShift($tasker);

    $this->artisan('attendance:auto-time-out')->assertSuccessful();

    expect(Attendance::first()->notes)->toContain('automatically');
});

it('lets the tasker still file a tracker entry afterwards', function (): void {
    // Auto-closing must not settle the night. The flow stays open because the
    // status is still a worked one.
    $site = Site::create(['name' => 'BEAMO 3F C', 'is_active' => true]);
    $pc = Workstation::create(['name' => 'PC-06 3F C', 'site_id' => $site->id, 'is_active' => true]);
    $project = Project::create(['code' => 'sky_feather', 'is_active' => true]);

    $tasker = tasker();
    $shift = openShift($tasker, pcId: $pc->id);
    $shift->forceFill(['commitment_bracket' => '7_plus_hours'])->save();

    $this->artisan('attendance:auto-time-out')->assertSuccessful();

    $this->actingAs($tasker)->getJson('/api/daily/state')
        ->assertOk()
        ->assertJsonPath('data.settled', false)
        ->assertJsonPath('data.steps.clocked_out', true);

    $this->actingAs($tasker)->postJson('/api/daily/tracker', [
        'tenurity' => 'expert',
        'items' => [[
            'project_id' => $project->id,
            'tasker_level' => 'l8',
            'total_tasks' => 2,
            'task_ids' => 'TASK1, TASK2 (SBQ)',
            'task_complexity' => 'mid_scene_frames',
            'screenshot_links' => 'https://drive.example.com/a',
        ]],
        'remarks' => 'N/A',
    ])->assertCreated();
});

it('frees the desk it was holding', function (): void {
    $site = Site::create(['name' => 'BEAMO 3F C', 'is_active' => true]);
    $pc = Workstation::create(['name' => 'PC-06 3F C', 'site_id' => $site->id, 'is_active' => true]);

    openShift(tasker(), pcId: $pc->id);

    $this->artisan('attendance:auto-time-out')->assertSuccessful();

    $row = collect(
        $this->actingAs(tasker())->getJson('/api/daily/workstations')->json('data'),
    )->firstWhere('id', $pc->id);

    expect($row['is_claimed'])->toBeFalse();
});

it('leaves a shift that was already closed alone', function (): void {
    $tasker = tasker();

    Attendance::create([
        'user_id' => $tasker->id,
        'attendance_date' => '2026-07-26',
        'time_in' => CarbonImmutable::parse('2026-07-26 22:05'),
        'time_out' => CarbonImmutable::parse('2026-07-27 03:00'),
        'total_hours' => 4.92,
        'status' => AttendanceStatus::Present,
    ]);

    $this->artisan('attendance:auto-time-out')->assertSuccessful();

    $record = Attendance::first();

    expect($record->time_out?->format('H:i'))->toBe('03:00')
        ->and((float) $record->total_hours)->toBe(4.92)
        ->and($record->notes)->toBeNull();
});

it('never touches an absence, which has no clock to close', function (): void {
    $tasker = tasker();

    Attendance::create([
        'user_id' => $tasker->id,
        'attendance_date' => '2026-07-26',
        'status' => AttendanceStatus::Absent,
    ]);

    $this->artisan('attendance:auto-time-out')->assertSuccessful();

    expect(Attendance::first()->time_out)->toBeNull();
});

it('leaves an arrival past the shift end open rather than writing a backwards clock', function (): void {
    // 06:30 still resolves to the Jul 26 night, so this shift is real -- but
    // closing it at 06:00 would put time_out before time_in.
    Date::setTestNow(CarbonImmutable::parse('2026-07-27 06:30'));

    $tasker = tasker();
    openShift($tasker, '2026-07-27 06:15');

    $this->artisan('attendance:auto-time-out')->assertSuccessful();

    expect(Attendance::first()->time_out)->toBeNull();
});

it('writes nothing on a dry run', function (): void {
    openShift(tasker());

    $this->artisan('attendance:auto-time-out', ['--dry-run' => true])->assertSuccessful();

    expect(Attendance::first()->time_out)->toBeNull();
});
