<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Services\AttendanceService;
use Illuminate\Support\Facades\Cache;

/**
 * The public floor figures.
 *
 * Unauthenticated by design, so the tests that matter most are not about the
 * arithmetic -- they are about what the payload is incapable of leaking.
 */
beforeEach(function (): void {
    Cache::flush();
    $this->businessDate = app(AttendanceService::class)->resolveBusinessDate()->toDateString();
});

function markAttendance(int $userId, AttendanceStatus $status, string $date, bool $open = false): void
{
    Attendance::create([
        'user_id' => $userId,
        'attendance_date' => $date,
        'time_in' => $status === AttendanceStatus::Absent ? null : now()->subHours(3),
        'time_out' => $open || $status === AttendanceStatus::Absent ? null : now(),
        'status' => $status,
    ]);
}

it('is reachable without signing in', function (): void {
    $this->getJson('/api/public/floor')->assertOk();
});

it('never exposes names, emails or identifiers', function (): void {
    $tasker = tasker(['name' => 'Juan Dela Cruz', 'email' => 'juan@test.local']);
    markAttendance($tasker->id, AttendanceStatus::Present, $this->businessDate);

    $body = $this->getJson('/api/public/floor')->assertOk()->getContent();

    /*
     * The security boundary, asserted against the whole serialised response
     * rather than against named keys -- a future field carrying a name would
     * slip past a per-key assertion but not past this.
     */
    expect($body)->not->toContain('Juan Dela Cruz')
        ->and($body)->not->toContain('juan@test.local')
        ->and($body)->not->toContain('user_id')
        ->and(array_keys($this->getJson('/api/public/floor')->json('data')))
        ->toEqualCanonicalizing([
            'business_date', 'active_taskers', 'currently_timed_in', 'roll_call',
        ]);
});

it('counts the roll call for tonight', function (): void {
    $present = tasker();
    $late = tasker();
    tasker(); // active, not yet in
    tasker(); // active, not yet in

    markAttendance($present->id, AttendanceStatus::Present, $this->businessDate, open: true);
    markAttendance($late->id, AttendanceStatus::Late, $this->businessDate);

    $data = $this->getJson('/api/public/floor')->assertOk()->json('data');

    expect($data['active_taskers'])->toBe(4)
        ->and($data['currently_timed_in'])->toBe(1)
        ->and($data['roll_call']['present']['count'])->toBe(1)
        ->and($data['roll_call']['late']['count'])->toBe(1)
        ->and($data['roll_call']['not_yet_in']['count'])->toBe(2)
        ->and($data['roll_call']['not_yet_in']['percent'])->toBe(50);
});

it('does not count another night as tonight', function (): void {
    $tasker = tasker();
    markAttendance($tasker->id, AttendanceStatus::Present, '2020-01-01');

    $data = $this->getJson('/api/public/floor')->assertOk()->json('data');

    expect($data['roll_call']['present']['count'])->toBe(0);
});

it('never reports a negative not-yet-in', function (): void {
    // More attendance filed than there are currently-active taskers, which
    // happens whenever somebody is deactivated mid-month. A negative here
    // would render as an inverted bar on the landing page.
    $a = tasker();
    $b = tasker();
    markAttendance($a->id, AttendanceStatus::Present, $this->businessDate);
    markAttendance($b->id, AttendanceStatus::Present, $this->businessDate);

    $extra = tasker();
    markAttendance($extra->id, AttendanceStatus::Late, $this->businessDate);
    $extra->delete();

    $data = $this->getJson('/api/public/floor')->assertOk()->json('data');

    expect($data['roll_call']['not_yet_in']['count'])->toBeGreaterThanOrEqual(0);
});

it('survives an empty system without dividing by zero', function (): void {
    $data = $this->getJson('/api/public/floor')->assertOk()->json('data');

    expect($data['active_taskers'])->toBe(0)
        ->and($data['roll_call']['present']['percent'])->toBe(0);
});
