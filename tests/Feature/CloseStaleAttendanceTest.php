<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

afterEach(function (): void {
    Date::setTestNow();
});

it('flags an unclosed shift from a previous business date as incomplete', function (): void {
    $tasker = tasker();

    $stale = Attendance::create([
        'user_id' => $tasker->id,
        'attendance_date' => '2026-07-20',
        'time_in' => CarbonImmutable::parse('2026-07-20 22:00'),
        'status' => AttendanceStatus::Present,
    ]);

    Date::setTestNow(CarbonImmutable::parse('2026-07-26 17:00'));

    $this->artisan('attendance:close-stale')->assertSuccessful();

    expect($stale->refresh()->status)->toBe(AttendanceStatus::Incomplete)
        // The hours are genuinely unknown; inventing a value would corrupt
        // every average that includes this record.
        ->and($stale->total_hours)->toBeNull();
});

it('leaves the shift currently in progress alone', function (): void {
    $tasker = tasker();

    /*
     * Mid-shift: 02:00, three hours into the night that began at 22:00.
     *
     * This is the case that matters, and it is protected by the query rather
     * than by the schedule. The command only touches records STRICTLY EARLIER
     * than the current business date, and a running shift's date is by
     * definition the current one -- so it is safe whatever time the command is
     * run at, and whatever the cutoff is set to.
     *
     * The test previously proved something weaker: it ran at 17:00 against a
     * record from the day before and relied on the 18:00 cutoff still calling
     * that "today". That shift had actually ended at 06:00, eleven hours
     * earlier, so it was not in progress at all -- the assertion passed
     * because the cutoff was lagging, not because the command was careful.
     */
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 02:00'));

    $current = Attendance::create([
        'user_id' => $tasker->id,
        // Before noon, so the current business date is still the 25th.
        'attendance_date' => '2026-07-25',
        'time_in' => CarbonImmutable::parse('2026-07-25 22:00'),
        'status' => AttendanceStatus::Present,
    ]);

    $this->artisan('attendance:close-stale')->assertSuccessful();

    expect($current->refresh()->status)->toBe(AttendanceStatus::Present);
});

it('closes a shift that ended this morning once the business date has rolled', function (): void {
    $tasker = tasker();

    /*
     * Ten minutes past whatever the cutoff is set to, rather than a fixed
     * clock time.
     *
     * The point being tested is "once the business date has rolled", which is
     * defined by the cutoff -- so pinning it to 13:00 only tested that
     * sentence while the cutoff happened to be noon, and started failing the
     * moment operations moved it to the evening.
     */
    [$hour, $minute] = array_map('intval', explode(':', (string) config('attendance.business_day_cutoff')));

    Date::setTestNow(
        CarbonImmutable::parse('2026-07-26')->setTime($hour, $minute)->addMinutes(10),
    );

    $lastNight = Attendance::create([
        'user_id' => $tasker->id,
        'attendance_date' => '2026-07-25',
        'time_in' => CarbonImmutable::parse('2026-07-25 22:00'),
        'status' => AttendanceStatus::Present,
    ]);

    $this->artisan('attendance:close-stale')->assertSuccessful();

    expect($lastNight->refresh()->status)->toBe(AttendanceStatus::Incomplete)
        ->and($lastNight->total_hours)->toBeNull();
});

it('leaves closed shifts alone', function (): void {
    $tasker = tasker();

    $closed = Attendance::create([
        'user_id' => $tasker->id,
        'attendance_date' => '2026-07-20',
        'time_in' => CarbonImmutable::parse('2026-07-20 22:00'),
        'time_out' => CarbonImmutable::parse('2026-07-21 06:00'),
        'total_hours' => 8.0,
        'status' => AttendanceStatus::Present,
    ]);

    Date::setTestNow(CarbonImmutable::parse('2026-07-26 17:00'));

    $this->artisan('attendance:close-stale')->assertSuccessful();

    expect($closed->refresh()->status)->toBe(AttendanceStatus::Present)
        ->and($closed->total_hours)->toBe(8.0);
});

it('changes nothing on a dry run', function (): void {
    $tasker = tasker();

    $stale = Attendance::create([
        'user_id' => $tasker->id,
        'attendance_date' => '2026-07-20',
        'time_in' => CarbonImmutable::parse('2026-07-20 22:00'),
        'status' => AttendanceStatus::Present,
    ]);

    Date::setTestNow(CarbonImmutable::parse('2026-07-26 17:00'));

    $this->artisan('attendance:close-stale --dry-run')->assertSuccessful();

    expect($stale->refresh()->status)->toBe(AttendanceStatus::Present);
});

it('reports cleanly when there is nothing to close', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 17:00'));

    $this->artisan('attendance:close-stale')
        ->expectsOutputToContain('No stale shifts found.')
        ->assertSuccessful();
});
