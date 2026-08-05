<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Exceptions\AttendanceException;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->service = app(AttendanceService::class);
});

it('computes hours across midnight without special casing', function (): void {
    $hours = $this->service->computeHours(
        CarbonImmutable::parse('2026-07-26 22:00'),
        CarbonImmutable::parse('2026-07-27 06:00'),
    );

    expect($hours)->toBe(8.0);
});

it('computes fractional hours to two decimals', function (): void {
    $hours = $this->service->computeHours(
        CarbonImmutable::parse('2026-07-26 22:05'),
        CarbonImmutable::parse('2026-07-27 06:20'),
    );

    // 8 h 15 m
    expect($hours)->toBe(8.25);
});

it('computes hours for a shift that does not cross midnight', function (): void {
    $hours = $this->service->computeHours(
        CarbonImmutable::parse('2026-07-26 22:00'),
        CarbonImmutable::parse('2026-07-26 23:30'),
    );

    expect($hours)->toBe(1.5);
});

it('rejects a time out that precedes time in', function (): void {
    $this->service->computeHours(
        CarbonImmutable::parse('2026-07-27 06:00'),
        CarbonImmutable::parse('2026-07-26 22:00'),
    );
})->throws(AttendanceException::class, 'cannot be earlier');

it('rejects a time out identical to time in', function (): void {
    $moment = CarbonImmutable::parse('2026-07-26 22:00');

    $this->service->computeHours($moment, $moment);
})->throws(AttendanceException::class);

it('rejects an implausibly long shift rather than recording it', function (): void {
    // A forgotten clock-out, caught before it can poison hour totals.
    $this->service->computeHours(
        CarbonImmutable::parse('2026-07-26 22:00'),
        CarbonImmutable::parse('2026-07-27 20:00'), // 22 hours
    );
})->throws(AttendanceException::class, 'exceeds');

it('accepts a shift exactly at the maximum length', function (): void {
    $max = (float) config('attendance.max_shift_hours');

    $hours = $this->service->computeHours(
        CarbonImmutable::parse('2026-07-26 22:00'),
        CarbonImmutable::parse('2026-07-26 22:00')->addHours((int) $max),
    );

    expect($hours)->toBe($max);
});

it('marks a clock-in within grace as present', function (): void {
    $businessDate = CarbonImmutable::parse('2026-07-26');

    $status = $this->service->resolveClockInStatus(
        CarbonImmutable::parse('2026-07-26 22:14'),
        $businessDate,
    );

    expect($status)->toBe(AttendanceStatus::Present);
});

it('marks a clock-in past the grace window as late', function (): void {
    $businessDate = CarbonImmutable::parse('2026-07-26');

    $status = $this->service->resolveClockInStatus(
        CarbonImmutable::parse('2026-07-26 22:31'),
        $businessDate,
    );

    expect($status)->toBe(AttendanceStatus::Late);
});

it('puts the boundary between 10:30 PM and 10:31 PM', function (): void {
    // The rule as an operator states it. Pinned to the minute rather than to
    // the config value so that changing grace_minutes without meaning to
    // breaks something.
    $businessDate = CarbonImmutable::parse('2026-07-26');

    expect(config('attendance.grace_minutes'))->toBe(30);

    $status = fn (string $at): AttendanceStatus => $this->service->resolveClockInStatus(
        CarbonImmutable::parse($at),
        $businessDate,
    );

    expect($status('2026-07-26 22:30'))->toBe(AttendanceStatus::Present)
        ->and($status('2026-07-26 22:31'))->toBe(AttendanceStatus::Late);
});

it('does not make someone late by seconds nobody can see', function (): void {
    // 22:30:45 reads as "10:30 PM" on every screen, badge and email. Marking
    // it late would be a decision made on a number the tasker was never shown.
    $businessDate = CarbonImmutable::parse('2026-07-26');

    expect($this->service->resolveClockInStatus(
        CarbonImmutable::parse('2026-07-26 22:30:45'),
        $businessDate,
    ))->toBe(AttendanceStatus::Present);

    // And the two figures agree: Present must never sit beside "1m late".
    expect($this->service->minutesLate(
        CarbonImmutable::parse('2026-07-26 22:30:45'),
        $businessDate,
    ))->toBe(0);

    // The first genuinely late second is the start of the next minute.
    expect($this->service->resolveClockInStatus(
        CarbonImmutable::parse('2026-07-26 22:31:00'),
        $businessDate,
    ))->toBe(AttendanceStatus::Late);
});

it('measures lateness against the shift start even after midnight', function (): void {
    $businessDate = CarbonImmutable::parse('2026-07-26');

    // 00:30 on the 27th is 2.5 hours late for a 22:00 start -- not "early".
    $status = $this->service->resolveClockInStatus(
        CarbonImmutable::parse('2026-07-27 00:30'),
        $businessDate,
    );

    expect($status)->toBe(AttendanceStatus::Late);
    expect($this->service->minutesLate(CarbonImmutable::parse('2026-07-27 00:30'), $businessDate))
        ->toBe(150);
});

it('reports zero minutes late for an early arrival', function (): void {
    expect($this->service->minutesLate(
        CarbonImmutable::parse('2026-07-26 21:45'),
        CarbonImmutable::parse('2026-07-26'),
    ))->toBe(0);
});

/**
 * Arriving at the scheduled start is never late, and neither is anything
 * inside the grace window. Reporting "5m late" beside a Present badge is two
 * contradictory statements about one record.
 */
it('reports nobody as late when they clock in exactly at shift start', function (): void {
    $businessDate = CarbonImmutable::parse('2026-07-26');

    expect($this->service->minutesLate(CarbonImmutable::parse('2026-07-26 22:00'), $businessDate))
        ->toBe(0)
        ->and($this->service->resolveClockInStatus(CarbonImmutable::parse('2026-07-26 22:00'), $businessDate))
        ->toBe(AttendanceStatus::Present);
});

it('reports no lateness anywhere inside the grace window', function (string $time): void {
    $businessDate = CarbonImmutable::parse('2026-07-26');

    expect($this->service->minutesLate(CarbonImmutable::parse($time), $businessDate))->toBe(0);
})->with(['2026-07-26 21:30', '2026-07-26 22:00', '2026-07-26 22:05', '2026-07-26 22:15']);

it('reports lateness from the shift start once past grace', function (): void {
    $businessDate = CarbonImmutable::parse('2026-07-26');

    // 22:45 is 30 minutes past a 22:00 start -- reported in full, not as the
    // 15 minutes past the grace end.
    expect($this->service->minutesLate(CarbonImmutable::parse('2026-07-26 22:45'), $businessDate))
        ->toBe(45);
});
