<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Project;
use App\Models\Site;
use App\Models\TrackerEntry;
use App\Models\TrackerItem;
use App\Models\Workstation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * Removing a shift or a submission.
 *
 * Both are real deletes, and that is the design rather than an oversight.
 * `attendances` is unique on (user_id, attendance_date) and `tracker_entries`
 * on (user_id, entry_date); a soft-deleted row keeps holding its pair, so
 * merely hiding the record would stop the tasker filing that night ever again
 * -- and the refusal they would meet is "You have already timed in", which
 * explains nothing.
 */
beforeEach(function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 22:05'));

    $this->admin = admin();
    $this->tasker = tasker();

    $this->site = Site::create(['name' => 'BEAMO 3F C', 'is_active' => true]);
    $this->pc = Workstation::create([
        'name' => 'PC-06 3F C', 'site_id' => $this->site->id, 'is_active' => true,
    ]);
    $this->project = Project::create(['code' => 'sky_feather', 'is_active' => true]);
});

afterEach(function (): void {
    Date::setTestNow();
});

/** A filed shift with no production against it. */
function bareShift(App\Models\User $user): Attendance
{
    return Attendance::create([
        'user_id' => $user->id,
        'attendance_date' => '2026-07-26',
        'time_in' => CarbonImmutable::parse('2026-07-26 22:05'),
        'status' => AttendanceStatus::Present,
    ]);
}

// ------------------------------------------------------------------- Shifts

it('deletes a shift with nothing filed against it', function (): void {
    $shift = bareShift($this->tasker);

    $this->actingAs($this->admin)
        ->deleteJson("/api/admin/attendance/{$shift->id}")
        ->assertOk();

    // Gone for real, not hidden -- withTrashed finds nothing either.
    expect(Attendance::withTrashed()->find($shift->id))->toBeNull();
});

it('frees the night so the tasker can file it again', function (): void {
    // The reason this is a force delete. A soft-deleted row still occupies
    // (user_id, attendance_date), and the next clock-in would collide with a
    // record nobody can see.
    $shift = bareShift($this->tasker);

    $this->actingAs($this->admin)
        ->deleteJson("/api/admin/attendance/{$shift->id}")
        ->assertOk();

    $this->actingAs($this->tasker)->postJson('/api/daily/activate', [
        'commitment_bracket' => '7_plus_hours',
        'tasking_statuses' => ['tasking'],
        'workstation_id' => $this->pc->id,
        'pc_status' => 'used',
    ])->assertCreated();
});

it('refuses to delete a shift that has a submission filed against it', function (): void {
    $shift = bareShift($this->tasker);

    TrackerEntry::create([
        'user_id' => $this->tasker->id,
        'attendance_id' => $shift->id,
        'entry_date' => '2026-07-26',
        'tenurity' => 'expert',
    ]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/admin/attendance/{$shift->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'attendance.has_production');

    // Refused means refused: the shift is still there.
    expect(Attendance::find($shift->id))->not->toBeNull();
});

it('refuses a tasker the delete entirely', function (): void {
    $shift = bareShift($this->tasker);

    $this->actingAs($this->tasker)
        ->deleteJson("/api/admin/attendance/{$shift->id}")
        ->assertForbidden();

    expect(Attendance::find($shift->id))->not->toBeNull();
});

// -------------------------------------------------------------- Submissions

it('deletes a submission and its project blocks', function (): void {
    $shift = bareShift($this->tasker);

    $entry = TrackerEntry::create([
        'user_id' => $this->tasker->id,
        'attendance_id' => $shift->id,
        'entry_date' => '2026-07-26',
        'tenurity' => 'expert',
    ]);

    TrackerItem::create([
        'tracker_entry_id' => $entry->id,
        'project_id' => $this->project->id,
        'total_tasks' => 42,
    ]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/admin/tracker-entries/{$entry->id}")
        ->assertOk();

    expect(TrackerEntry::withTrashed()->find($entry->id))->toBeNull()
        // Cascaded by the database: a block describes one project inside one
        // submission and means nothing on its own.
        ->and(TrackerItem::where('tracker_entry_id', $entry->id)->count())->toBe(0);
});

it('lets the shift be deleted once its submission is gone', function (): void {
    // The order of operations the 409 asks for, end to end.
    $shift = bareShift($this->tasker);

    $entry = TrackerEntry::create([
        'user_id' => $this->tasker->id,
        'attendance_id' => $shift->id,
        'entry_date' => '2026-07-26',
        'tenurity' => 'expert',
    ]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/admin/attendance/{$shift->id}")
        ->assertStatus(409);

    $this->actingAs($this->admin)
        ->deleteJson("/api/admin/tracker-entries/{$entry->id}")
        ->assertOk();

    $this->actingAs($this->admin)
        ->deleteJson("/api/admin/attendance/{$shift->id}")
        ->assertOk();

    expect(Attendance::withTrashed()->count())->toBe(0);
});

it('refuses a tasker the submission delete', function (): void {
    $entry = TrackerEntry::create([
        'user_id' => $this->tasker->id,
        'entry_date' => '2026-07-26',
        'tenurity' => 'expert',
    ]);

    $this->actingAs($this->tasker)
        ->deleteJson("/api/admin/tracker-entries/{$entry->id}")
        ->assertForbidden();

    expect(TrackerEntry::find($entry->id))->not->toBeNull();
});
