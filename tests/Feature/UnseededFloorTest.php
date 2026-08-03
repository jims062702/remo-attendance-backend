<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Models\Workstation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * What a tasker meets when the reference data was never seeded.
 *
 * This is not a hypothetical. The deployed database had been migrated but not
 * seeded, so `workstations` was empty -- and activation validates the PC with
 * Rule::exists(), which nothing can satisfy against an empty table. Every
 * tasker who tried to clock in was refused, and because ValidationException
 * was falling through to the generic handler the refusal arrived as a 500
 * reading "An unexpected error occurred. Please try again."
 *
 * Two separate faults stacked into one unreadable failure: no desks to pick,
 * and no way to be told that was the problem. The entrypoint now seeds the
 * floor, and these tests pin the second half -- that an unseeded floor still
 * produces a 422 naming the field, never a 500.
 *
 * The whole suite runs with APP_DEBUG=false (see phpunit.xml), so this
 * exercises the same error handling production uses.
 */
beforeEach(function (): void {
    $this->tasker = tasker();

    // Deliberately no Site, no Workstation, no Project. This is a migrated
    // database that nobody seeded.
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 22:05'));
});

afterEach(function (): void {
    Date::setTestNow();
});

it('has no workstations to offer', function (): void {
    expect(Workstation::count())->toBe(0);
});

it('refuses activation with a 422 naming the PC, not a 500', function (): void {
    $this->actingAs($this->tasker)->postJson('/api/daily/activate', [
        'commitment_bracket' => '7_plus_hours',
        'tasking_statuses' => ['tasking'],
        'pc_status' => 'used',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('workstation_id');

    expect(Attendance::count())->toBe(0);
});

it('refuses a workstation id that does not exist, with a 422', function (): void {
    // What the client sends when it held a stale list, or when the table was
    // emptied under it.
    $this->actingAs($this->tasker)->postJson('/api/daily/activate', [
        'commitment_bracket' => '7_plus_hours',
        'tasking_statuses' => ['tasking'],
        'workstation_id' => 999,
        'pc_status' => 'used',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('workstation_id');
});

it('still serves the pickers, empty rather than broken', function (): void {
    // The screen has to render "no PCs available" rather than fail to load,
    // or an admin looking into the complaint sees a blank page instead of the
    // fact that the floor is empty.
    $this->actingAs($this->tasker)->getJson('/api/daily/workstations')
        ->assertOk()
        ->assertJsonPath('data', []);

    $this->actingAs($this->tasker)->getJson('/api/daily/options')
        ->assertOk()
        ->assertJsonPath('data.projects', [])
        ->assertJsonPath('data.sites', []);
});

it('lets the plain clock-in through, since it names no PC', function (): void {
    // /api/attendance/time-in takes no payload, so an unseeded floor never
    // blocked it. Worth pinning: it localises the outage to the guided flow
    // rather than to the clock itself.
    $this->actingAs($this->tasker)->postJson('/api/attendance/time-in')
        ->assertCreated()
        ->assertJsonPath('data.status', 'present');
});
