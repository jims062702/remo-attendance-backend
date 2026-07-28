<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->admin = admin(['name' => 'Maria Santos']);
});

// ------------------------------------------------------------ Tasker management

it('authorises a tasker by email, with no password', function (): void {
    $this->actingAs($this->admin)->postJson('/api/admin/taskers', [
        'name' => 'Juan Dela Cruz',
        'email' => 'juan@test.local',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Juan Dela Cruz')
        ->assertJsonPath('data.role', 'tasker')
        ->assertJsonPath('data.status', 'active')
        // Authorised but not yet linked: they claim the account the first
        // time they sign in with the matching Google address.
        ->assertJsonPath('data.has_signed_in', false);

    expect(User::where('email', 'juan@test.local')->firstOrFail()->password)->toBeNull();
});

it('rejects a duplicate email', function (): void {
    tasker(['email' => 'juan@test.local']);

    $this->actingAs($this->admin)->postJson('/api/admin/taskers', [
        'name' => 'Impostor',
        'email' => 'juan@test.local',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('rejects a malformed email', function (): void {
    $this->actingAs($this->admin)->postJson('/api/admin/taskers', [
        'name' => 'Juan',
        'email' => 'not-an-email',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('unlinks the Google identity when an admin changes the email', function (): void {
    $tasker = tasker(['email' => 'old@test.local']);
    $tasker->forceFill(['google_id' => 'linked-google-id'])->save();

    $this->actingAs($this->admin)
        ->putJson("/api/admin/taskers/{$tasker->id}", ['email' => 'new@test.local'])
        ->assertOk();

    // Leaving the old identity attached would trip the mismatch check and
    // lock the person out of their own account.
    expect($tasker->refresh()->google_id)->toBeNull();
});

it('deactivates a tasker without destroying their history', function (): void {
    $tasker = tasker();
    Attendance::factory()->count(3)->for($tasker)->create();
    Task::factory()->count(2)->for($tasker)->create();

    $this->actingAs($this->admin)
        ->deleteJson("/api/admin/taskers/{$tasker->id}")
        ->assertOk();

    // Business rule 10: the records outlive the account.
    expect(Attendance::where('user_id', $tasker->id)->count())->toBe(3)
        ->and(Task::where('user_id', $tasker->id)->count())->toBe(2)
        ->and(User::find($tasker->id))->toBeNull()
        ->and(User::withTrashed()->find($tasker->id)->status)->toBe(UserStatus::Inactive);
});

it('reactivates a deactivated tasker', function (): void {
    $tasker = tasker();
    $this->actingAs($this->admin)->deleteJson("/api/admin/taskers/{$tasker->id}");

    $this->actingAs($this->admin)
        ->postJson("/api/admin/taskers/{$tasker->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    expect(User::find($tasker->id))->not->toBeNull();
});

it('stops an admin deactivating their own account', function (): void {
    $this->actingAs($this->admin)
        ->deleteJson("/api/admin/taskers/{$this->admin->id}")
        ->assertForbidden();
});

it('stops an admin removing their own admin role', function (): void {
    $this->actingAs($this->admin)
        ->putJson("/api/admin/taskers/{$this->admin->id}", ['role' => 'tasker'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');
});

it('renames a tasker without disturbing their Google link', function (): void {
    $tasker = tasker();
    $linkedId = $tasker->google_id;

    $this->actingAs($this->admin)
        ->putJson("/api/admin/taskers/{$tasker->id}", ['name' => 'Renamed'])
        ->assertOk();

    expect($tasker->refresh()->name)->toBe('Renamed')
        // Only an email change should unlink the identity.
        ->and($tasker->google_id)->toBe($linkedId);
});

it('searches taskers by name and email', function (): void {
    tasker(['name' => 'Juan Dela Cruz', 'email' => 'juan@test.local']);
    tasker(['name' => 'Ana Reyes', 'email' => 'ana@test.local']);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/admin/taskers?search=Dela')
        ->assertOk();

    expect($response->json('meta.pagination.total'))->toBe(1)
        ->and($response->json('data.0.name'))->toBe('Juan Dela Cruz');
});

// -------------------------------------------------------- Attendance correction

it('corrects a missed time out and recomputes hours', function (): void {
    $tasker = tasker();

    $attendance = Attendance::create([
        'user_id' => $tasker->id,
        'attendance_date' => '2026-07-26',
        'time_in' => CarbonImmutable::parse('2026-07-26 22:00'),
        'status' => AttendanceStatus::Incomplete,
    ]);

    $this->actingAs($this->admin)
        ->putJson("/api/admin/attendance/{$attendance->id}", [
            'time_out' => '2026-07-27 06:00:00',
            'reason' => 'Tasker forgot to clock out; confirmed with supervisor.',
        ])
        ->assertOk()
        // Recomputed server-side, not taken from the request.
        ->assertJsonPath('data.total_hours', 8)
        ->assertJsonPath('data.status', 'present');
});

/**
 * The browser sends an instant, not a wall clock.
 *
 * A datetime-local input is read in the browser's timezone and serialised with
 * toISOString(), so 10:00 PM in Manila arrives as "...T14:00:00.000Z". Carbon
 * parses that into a UTC instance, and Laravel's datetime cast writes whatever
 * wall clock the instance happens to carry into a timezone-less DATETIME
 * column -- storing 14:00 and silently dropping the +08:00 offset. Read back
 * and re-interpreted as Manila, 10:00 PM had become 2:00 PM.
 *
 * The automatic clock path never hit this because it uses now(), which is
 * already in the app timezone. Corrections are the only route that accepts a
 * client-supplied time, which is exactly why this needs pinning down.
 */
it('stores a corrected time in the application timezone, not the submitted offset', function (): void {
    $tasker = tasker();

    $attendance = Attendance::create([
        'user_id' => $tasker->id,
        'attendance_date' => '2026-07-26',
        'time_in' => CarbonImmutable::parse('2026-07-26 23:30'),
        'status' => AttendanceStatus::Late,
    ]);

    $this->actingAs($this->admin)
        ->putJson("/api/admin/attendance/{$attendance->id}", [
            // 10:00 PM Manila, exactly as the browser encodes it.
            'time_in' => '2026-07-26T14:00:00.000Z',
            'reason' => 'Tasker clocked in on the wrong machine; corrected to 10 PM.',
        ])
        ->assertOk();

    $stored = $attendance->fresh()->time_in;

    expect($stored->format('H:i'))->toBe('22:00')
        ->and($stored->format('g:i A'))->toBe('10:00 PM');
});

it('requires a reason for every correction', function (): void {
    $attendance = Attendance::factory()->create();

    $this->actingAs($this->admin)
        ->putJson("/api/admin/attendance/{$attendance->id}", [
            'time_out' => '2026-07-27 06:00:00',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');
});

it('rejects a correction that inverts the clock times', function (): void {
    $attendance = Attendance::create([
        'user_id' => tasker()->id,
        'attendance_date' => '2026-07-26',
        'time_in' => CarbonImmutable::parse('2026-07-26 22:00'),
        'status' => AttendanceStatus::Incomplete,
    ]);

    $this->actingAs($this->admin)
        ->putJson("/api/admin/attendance/{$attendance->id}", [
            'time_out' => '2026-07-26 21:00:00',
            'reason' => 'Testing an invalid correction.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('time_out');
});

it('rejects a correction exceeding the maximum shift length', function (): void {
    $attendance = Attendance::create([
        'user_id' => tasker()->id,
        'attendance_date' => '2026-07-26',
        'time_in' => CarbonImmutable::parse('2026-07-26 22:00'),
        'status' => AttendanceStatus::Incomplete,
    ]);

    $this->actingAs($this->admin)
        ->putJson("/api/admin/attendance/{$attendance->id}", [
            'time_out' => '2026-07-27 20:00:00', // 22 hours
            'reason' => 'Testing the upper bound.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('time_out');
});

it('rejects a time out on a record with no time in', function (): void {
    $attendance = Attendance::create([
        'user_id' => tasker()->id,
        'attendance_date' => '2026-07-26',
        'status' => AttendanceStatus::Absent,
    ]);

    $this->actingAs($this->admin)
        ->putJson("/api/admin/attendance/{$attendance->id}", [
            'time_out' => '2026-07-27 06:00:00',
            'reason' => 'Testing.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('time_out');
});

it('audits a correction with a before and after change set', function (): void {
    $attendance = Attendance::create([
        'user_id' => tasker()->id,
        'attendance_date' => '2026-07-26',
        'time_in' => CarbonImmutable::parse('2026-07-26 22:00'),
        'status' => AttendanceStatus::Incomplete,
    ]);

    $this->actingAs($this->admin)
        ->putJson("/api/admin/attendance/{$attendance->id}", [
            'time_out' => '2026-07-27 06:00:00',
            'reason' => 'Confirmed with supervisor.',
        ])->assertOk();

    $log = ActivityLog::where('action', 'attendance.corrected')->firstOrFail();

    expect($log->user_id)->toBe($this->admin->id)
        ->and($log->metadata['reason'])->toBe('Confirmed with supervisor.')
        ->and($log->metadata['before']['time_out'])->toBeNull()
        ->and($log->metadata['after']['time_out'])->toBe('2026-07-27 06:00:00');
});

it('marks a tasker absent for a date', function (): void {
    $tasker = tasker();

    $this->actingAs($this->admin)->postJson('/api/admin/attendance', [
        'user_id' => $tasker->id,
        'attendance_date' => '2026-07-26',
        'status' => 'absent',
        'notes' => 'No show.',
    ])->assertCreated()->assertJsonPath('data.status', 'absent');
});

// -------------------------------------------------------------------- Dashboard

it('summarises the dashboard', function (): void {
    $taskers = User::factory()->count(3)->tasker()->create();

    foreach ($taskers as $tasker) {
        Attendance::factory()->for($tasker)->create();
    }

    $response = $this->actingAs($this->admin)->getJson('/api/admin/dashboard')->assertOk();

    expect($response->json('data.summary.total_taskers'))->toBe(3)
        ->and($response->json('data.summary.active_taskers'))->toBe(3)
        ->and($response->json('data.summary'))->toHaveKeys([
            'currently_timed_in', 'total_hours_today', 'completed_tasks_today', 'pending_tasks_today',
        ]);
});

it('groups attendance analytics by day', function (): void {
    $tasker = tasker();

    Attendance::factory()->for($tasker)->on('2026-07-20')->create();
    Attendance::factory()->for($tasker)->on('2026-07-21')->create();

    $response = $this->actingAs($this->admin)
        ->getJson('/api/admin/analytics/attendance?from=2026-07-20&to=2026-07-21&group_by=day')
        ->assertOk();

    expect($response->json('data.series'))->toHaveCount(2)
        ->and($response->json('data.totals.total_hours'))->toBe(16);
});

it('rejects an inverted date range', function (): void {
    $this->actingAs($this->admin)
        ->getJson('/api/admin/attendance?from=2026-07-31&to=2026-07-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');
});
