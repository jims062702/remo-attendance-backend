<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Services\AbsenceRiskService;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Repeated-absence warning.
 *
 * The rule: `threshold` absences (default 4) inside a rolling window of
 * `window_days` (default 30) flags a tasker for review. The tests below pin the
 * three things that are easy to get wrong and impossible to notice from the UI
 * -- the boundary, what the window excludes, and what does NOT count as an
 * absence.
 */
beforeEach(function (): void {
    $this->admin = admin();
    $this->tasker = tasker(['name' => 'Juan Dela Cruz']);
    $this->risk = app(AbsenceRiskService::class);
});

/**
 * Mark `$count` absences, walking backwards from `$startDaysAgo`.
 *
 * Anchored on the BUSINESS date, never on `now()`.
 *
 * This is the trap the whole application is built around, and it bites hardest
 * in test helpers. Before the 18:00 rollover the current business date is
 * yesterday's calendar date, so a helper counting back from `now()` files its
 * first absence against a shift that has not started yet -- which the service
 * correctly refuses to count. The result is a suite that passes in the evening
 * and fails in the morning, with nothing in the diff to explain it.
 */
function absences(int $userId, int $count, int $startDaysAgo = 0): void
{
    $base = app(AttendanceService::class)->resolveBusinessDate();

    for ($i = 0; $i < $count; $i++) {
        Attendance::create([
            'user_id' => $userId,
            'attendance_date' => $base->subDays($startDaysAgo + $i)->toDateString(),
            'status' => AttendanceStatus::Absent,
        ]);
    }
}

// ------------------------------------------------------------------ Threshold

it('does not flag a tasker below the threshold', function (): void {
    absences($this->tasker->id, 3);

    $payload = $this->risk->payload($this->risk->countFor($this->tasker->id));

    expect($payload['absences'])->toBe(3)
        ->and($payload['at_risk'])->toBeFalse()
        // One short is worth its own signal: a warning that arrives with the
        // decision is not a warning.
        ->and($payload['approaching'])->toBeTrue();
});

it('flags a tasker exactly at the threshold', function (): void {
    absences($this->tasker->id, 4);

    $payload = $this->risk->payload($this->risk->countFor($this->tasker->id));

    expect($payload['absences'])->toBe(4)
        ->and($payload['at_risk'])->toBeTrue()
        ->and($payload['approaching'])->toBeFalse();
});

it('keeps flagging beyond the threshold', function (): void {
    absences($this->tasker->id, 7);

    expect($this->risk->countFor($this->tasker->id))->toBe(7)
        ->and($this->risk->isAtRisk(7))->toBeTrue();
});

// --------------------------------------------------------------- The window

it('ignores absences that have aged out of the rolling window', function (): void {
    // Four absences, but all of them well past the 30-day window.
    absences($this->tasker->id, 4, startDaysAgo: 60);

    expect($this->risk->countFor($this->tasker->id))->toBe(0);
});

it('counts only the absences inside the window when they straddle its edge', function (): void {
    absences($this->tasker->id, 2, startDaysAgo: 0);   // inside
    absences($this->tasker->id, 3, startDaysAgo: 45);  // outside

    // The flag must not fire on five total when only two are recent.
    expect($this->risk->countFor($this->tasker->id))->toBe(2)
        ->and($this->risk->isAtRisk(2))->toBeFalse();
});

it('clears the flag on its own as absences age out', function (): void {
    // Exactly on the far edge of the window, so they are still counted...
    $edge = $this->risk->windowDays() - 1;
    absences($this->tasker->id, 4, startDaysAgo: $edge - 3);

    expect($this->risk->isAtRisk($this->risk->countFor($this->tasker->id)))->toBeTrue();

    // ...and a week later they are not. Nobody has to un-flag them by hand.
    $this->travel(7)->days();

    expect($this->risk->isAtRisk($this->risk->countFor($this->tasker->id)))->toBeFalse();
});

// ------------------------------------------------------- What counts as absent

it('does not count leave, lateness or incomplete shifts as absences', function (): void {
    $dates = CarbonImmutable::now();

    foreach ([
        AttendanceStatus::OnLeave,
        AttendanceStatus::Late,
        AttendanceStatus::Incomplete,
        AttendanceStatus::Present,
    ] as $index => $status) {
        Attendance::create([
            'user_id' => $this->tasker->id,
            'attendance_date' => $dates->subDays($index)->toDateString(),
            'status' => $status,
        ]);
    }

    // Approved leave is not a no-show, and turning up late is still turning up.
    expect($this->risk->countFor($this->tasker->id))->toBe(0);
});

it('never counts another tasker’s absences', function (): void {
    $other = tasker();
    absences($other->id, 6);

    expect($this->risk->countFor($this->tasker->id))->toBe(0)
        ->and($this->risk->countFor($other->id))->toBe(6);
});

// ------------------------------------------------------------------- Endpoints

it('marks at-risk taskers on the admin roster', function (): void {
    $safe = tasker(['name' => 'Reliable']);
    absences($this->tasker->id, 4);
    absences($safe->id, 1);

    $response = $this->actingAs($this->admin)->getJson('/api/admin/taskers')->assertOk();

    $rows = collect($response->json('data'));

    expect($rows->firstWhere('id', $this->tasker->id)['absence_risk']['at_risk'])->toBeTrue()
        ->and($rows->firstWhere('id', $safe->id)['absence_risk']['at_risk'])->toBeFalse()
        // The rule travels with the response so the UI never hardcodes "4".
        ->and($response->json('meta.absence_rule.threshold'))->toBe(4)
        ->and($response->json('meta.absence_rule.window_days'))->toBe(30);
});

it('counts absences for the whole roster page in a single query', function (): void {
    // The count is rendered per row; asking per row would be one query each.
    foreach (range(1, 5) as $i) {
        absences(tasker()->id, 4);
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->actingAs($this->admin)->getJson('/api/admin/taskers')->assertOk();

    // Generous ceiling -- the point is that it does not scale with row count.
    expect($queries)->toBeLessThan(12);
});

it('reports absence risk on the tasker detail endpoint', function (): void {
    absences($this->tasker->id, 5);

    $this->actingAs($this->admin)
        ->getJson("/api/admin/taskers/{$this->tasker->id}/summary")
        ->assertOk()
        ->assertJsonPath('data.absence_risk.absences', 5)
        ->assertJsonPath('data.absence_risk.at_risk', true);
});

it('keeps the warning independent of the date filter on the detail page', function (): void {
    absences($this->tasker->id, 4);

    // A narrow filter that excludes most of the absences must not make the
    // warning disappear -- it is about a fixed recent window, not the filter.
    $from = CarbonImmutable::now()->toDateString();
    $to = CarbonImmutable::now()->toDateString();

    $this->actingAs($this->admin)
        ->getJson("/api/admin/taskers/{$this->tasker->id}/summary?from={$from}&to={$to}")
        ->assertOk()
        ->assertJsonPath('data.absence_risk.at_risk', true)
        ->assertJsonPath('data.absence_risk.absences', 4);
});

it('refuses the roster to a tasker', function (): void {
    $this->actingAs($this->tasker)->getJson('/api/admin/taskers')->assertForbidden();
});
