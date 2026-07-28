<?php

declare(strict_types=1);

use App\Services\AttendanceService;
use Carbon\CarbonImmutable;

/**
 * Business-date resolution for the overnight 22:00 -> 06:00 shift.
 *
 * This is the single most load-bearing rule in the system: get it wrong and a
 * tasker who clocks in at 00:30 is filed under the wrong day, the unique index
 * fails to prevent a second clock-in for the same shift, and every hours total
 * that spans midnight is attributed to the wrong date.
 */
beforeEach(function (): void {
    $this->service = app(AttendanceService::class);
});

it('assigns an on-time clock-in to its own calendar date', function (): void {
    expect($this->service->resolveBusinessDate(CarbonImmutable::parse('2026-07-26 22:05'))->toDateString())
        ->toBe('2026-07-26');
});

it('assigns a clock-in just before midnight to that same date', function (): void {
    expect($this->service->resolveBusinessDate(CarbonImmutable::parse('2026-07-26 23:59'))->toDateString())
        ->toBe('2026-07-26');
});

it('assigns a clock-in after midnight to the PREVIOUS date', function (): void {
    // The defining case: 00:30 on the 27th is a late arrival for the 26th's shift.
    expect($this->service->resolveBusinessDate(CarbonImmutable::parse('2026-07-27 00:30'))->toDateString())
        ->toBe('2026-07-26');
});

it('assigns a clock-in near the end of the shift to the previous date', function (): void {
    expect($this->service->resolveBusinessDate(CarbonImmutable::parse('2026-07-27 05:00'))->toDateString())
        ->toBe('2026-07-26');
});

/*
 * The cutoff boundary.
 *
 * These set the cutoff explicitly rather than relying on whatever the
 * deployment happens to be configured with. The rule under test is "before the
 * cutoff belongs to yesterday, at or after it belongs to today" -- that must
 * hold at any legal cutoff, and a test that silently encodes one particular
 * value starts failing for the wrong reason the day operations moves it.
 */
it('rolls over to the new business date at the cutoff itself', function (string $cutoff, string $at, string $expected): void {
    config(['attendance.business_day_cutoff' => $cutoff]);

    expect($this->service->resolveBusinessDate(CarbonImmutable::parse($at))->toDateString())
        ->toBe($expected);
})->with([
    // The cutoff instant belongs to the NEW business date.
    'noon, at the cutoff' => ['12:00', '2026-07-27 12:00', '2026-07-27'],
    'noon, one minute before' => ['12:00', '2026-07-27 11:59', '2026-07-26'],
    'noon, morning after a shift' => ['12:00', '2026-07-27 09:30', '2026-07-26'],
    'noon, evening' => ['12:00', '2026-07-27 19:15', '2026-07-27'],

    // The previous setting, still correct under the same rule.
    'six pm, at the cutoff' => ['18:00', '2026-07-27 18:00', '2026-07-27'],
    'six pm, one minute before' => ['18:00', '2026-07-27 17:59', '2026-07-26'],
]);

it('opens a new business date the moment the configured cutoff passes', function (): void {
    /*
     * The behaviour a tasker actually feels: "when can I file another night?"
     *
     * Derived from whatever the cutoff is set to rather than asserting a
     * particular clock time, so tuning the deployment does not break the test
     * that describes the rule.
     */
    [$hour, $minute] = array_map('intval', explode(':', (string) config('attendance.business_day_cutoff')));

    $justBefore = CarbonImmutable::parse('2026-07-27')->setTime($hour, $minute)->subMinute();
    $atCutoff = CarbonImmutable::parse('2026-07-27')->setTime($hour, $minute);

    expect($this->service->resolveBusinessDate($justBefore)->toDateString())
        ->toBe('2026-07-26')
        ->and($this->service->resolveBusinessDate($atCutoff)->toDateString())
        ->toBe('2026-07-27');
});

it('keeps the cutoff outside the shift window', function (): void {
    /*
     * The load-bearing constraint. The cutoff has to fall in the dead hours
     * between shift end and shift start; inside the window it would split one
     * shift across two business dates, and the unique index on
     * (user_id, attendance_date) would stop guarding a single clock per shift.
     */
    [$cutoffH] = array_map('intval', explode(':', (string) config('attendance.business_day_cutoff')));
    [$endH] = array_map('intval', explode(':', (string) config('attendance.shift_end')));
    [$startH] = array_map('intval', explode(':', (string) config('attendance.shift_start')));

    expect($cutoffH)->toBeGreaterThanOrEqual($endH)
        ->and($cutoffH)->toBeLessThan($startH);
});

it('places the scheduled shift end on the following calendar day', function (): void {
    $end = $this->service->scheduledEnd(CarbonImmutable::parse('2026-07-26'));

    // 06:00 on the 27th, not the 26th -- the shift is overnight.
    expect($end->toDateTimeString())->toBe('2026-07-27 06:00:00');
});

it('places the scheduled shift start on the business date itself', function (): void {
    expect($this->service->scheduledStart(CarbonImmutable::parse('2026-07-26'))->toDateTimeString())
        ->toBe('2026-07-26 22:00:00');
});

it('keeps the shift end on the same day for a daytime shift', function (): void {
    // Guards the overnight branch: a 09:00 -> 17:00 shift must not roll over.
    config(['attendance.shift_start' => '09:00', 'attendance.shift_end' => '17:00']);

    expect($this->service->scheduledEnd(CarbonImmutable::parse('2026-07-26'))->toDateTimeString())
        ->toBe('2026-07-26 17:00:00');
});
