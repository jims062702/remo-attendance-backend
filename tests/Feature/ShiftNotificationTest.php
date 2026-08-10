<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Mail\ClockedInMail;
use App\Mail\MarkedAbsentMail;
use App\Mail\ShiftNotClosedMail;
use App\Models\Attendance;
use App\Models\Project;
use App\Models\Site;
use App\Models\TrackerEntry;
use App\Models\Workstation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;

/**
 * The three messages a tasker gets about their own shift.
 *
 * Every assertion here is about WHO is told and WHEN -- a notification that
 * fires on the wrong event is worse than none, because people stop reading the
 * ones that matter.
 */
beforeEach(function (): void {
    Mail::fake();

    $this->site = Site::create(['name' => 'BEAMO 3F C', 'is_active' => true]);
    $this->pc = Workstation::create([
        'name' => 'PC-06 3F C', 'site_id' => $this->site->id, 'is_active' => true,
    ]);
    $this->project = Project::create(['code' => 'sky_feather', 'is_active' => true]);

    Date::setTestNow(CarbonImmutable::parse('2026-07-28 22:05'));
});

afterEach(function (): void {
    Date::setTestNow();
});

/** @return array<string, mixed> */
function activation(int $pcId): array
{
    return [
        'commitment_bracket' => '7_plus_hours',
        'tasking_statuses' => ['tasking'],
        'workstation_id' => $pcId,
        'pc_status' => 'used',
    ];
}

// ------------------------------------------------------------------ Clock in

it('confirms a clock-in to the tasker who made it', function (): void {
    $tasker = tasker();

    $this->actingAs($tasker)->postJson('/api/attendance/time-in')->assertCreated();

    Mail::assertQueued(
        ClockedInMail::class,
        fn (ClockedInMail $mail) => $mail->hasTo($tasker->email)
            && $mail->attendance->status === AttendanceStatus::Present,
    );
});

it('confirms a clock-in made through the activation flow', function (): void {
    $tasker = tasker();

    $this->actingAs($tasker)->postJson('/api/daily/activate', activation($this->pc->id))
        ->assertCreated();

    Mail::assertQueued(ClockedInMail::class, fn ($mail) => $mail->hasTo($tasker->email));
});

it('does not send a second confirmation when the tasker changes desk', function (): void {
    // Activation is re-submitted for every PC switch and every corrected
    // bracket. Only the submission that starts the clock is news.
    $other = Workstation::create([
        'name' => 'PC-07 3F C', 'site_id' => $this->site->id, 'is_active' => true,
    ]);

    $tasker = tasker();

    $this->actingAs($tasker)->postJson('/api/daily/activate', activation($this->pc->id));
    $this->actingAs($tasker)->postJson('/api/daily/activate', activation($other->id));
    $this->actingAs($tasker)->postJson('/api/daily/activate', activation($this->pc->id));

    Mail::assertQueuedCount(1);
});

it('tells a late tasker that they were late', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-28 23:30'));

    $tasker = tasker();

    $this->actingAs($tasker)->postJson('/api/attendance/time-in')->assertCreated();

    Mail::assertQueued(
        ClockedInMail::class,
        fn (ClockedInMail $mail) => $mail->attendance->status === AttendanceStatus::Late,
    );
});

// -------------------------------------------------------------------- Absence

it('emails only the taskers it marked absent', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-29 00:01'));

    $absent = tasker();
    $worked = tasker();

    Attendance::create([
        'user_id' => $worked->id,
        'attendance_date' => '2026-07-28',
        'time_in' => CarbonImmutable::parse('2026-07-28 22:05'),
        'status' => AttendanceStatus::Present,
    ]);

    $this->artisan('attendance:mark-absent')->assertSuccessful();

    Mail::assertQueued(MarkedAbsentMail::class, fn ($mail) => $mail->hasTo($absent->email));
    Mail::assertNotQueued(MarkedAbsentMail::class, fn ($mail) => $mail->hasTo($worked->email));
    Mail::assertQueuedCount(1);
});

it('sends nothing on an absence dry run', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-29 00:01'));

    tasker();

    $this->artisan('attendance:mark-absent', ['--dry-run' => true])->assertSuccessful();

    Mail::assertNothingQueued();
});

// ------------------------------------------------------- Forgotten time out

it('emails the tasker whose clock it had to close', function (): void {
    $forgot = tasker();
    $closed = tasker();

    Attendance::create([
        'user_id' => $forgot->id,
        'attendance_date' => '2026-07-28',
        'time_in' => CarbonImmutable::parse('2026-07-28 22:05'),
        'status' => AttendanceStatus::Present,
    ]);

    Attendance::create([
        'user_id' => $closed->id,
        'attendance_date' => '2026-07-28',
        'time_in' => CarbonImmutable::parse('2026-07-28 22:05'),
        'time_out' => CarbonImmutable::parse('2026-07-29 05:00'),
        'total_hours' => 6.92,
        'status' => AttendanceStatus::Present,
    ]);

    Date::setTestNow(CarbonImmutable::parse('2026-07-29 06:00'));

    $this->artisan('attendance:auto-time-out')->assertSuccessful();

    Mail::assertQueued(ShiftNotClosedMail::class, fn ($mail) => $mail->hasTo($forgot->email));
    Mail::assertNotQueued(ShiftNotClosedMail::class, fn ($mail) => $mail->hasTo($closed->email));
});

it('renders the ignore-me line only when a tracker entry already exists', function (): void {
    $withEntry = tasker();
    $withoutEntry = tasker();

    foreach ([$withEntry, $withoutEntry] as $user) {
        Attendance::create([
            'user_id' => $user->id,
            'attendance_date' => '2026-07-28',
            'time_in' => CarbonImmutable::parse('2026-07-28 22:05'),
            'status' => AttendanceStatus::Present,
        ]);
    }

    TrackerEntry::create([
        'user_id' => $withEntry->id,
        'entry_date' => '2026-07-28',
        'tenurity' => 'expert',
    ]);

    Date::setTestNow(CarbonImmutable::parse('2026-07-29 06:00'));
    $this->artisan('attendance:auto-time-out')->assertSuccessful();

    // Rendered, not just constructed: the conditional lives in the Blade view,
    // so asserting on the model would prove nothing about what was sent.
    Mail::assertQueued(ShiftNotClosedMail::class, function (ShiftNotClosedMail $mail) use ($withEntry): bool {
        if (! $mail->hasTo($withEntry->email)) {
            return true;
        }

        return str_contains($mail->render(), 'already filed');
    });

    Mail::assertQueued(ShiftNotClosedMail::class, function (ShiftNotClosedMail $mail) use ($withoutEntry): bool {
        if (! $mail->hasTo($withoutEntry->email)) {
            return true;
        }

        return str_contains($mail->render(), 'have not filed a tracker entry');
    });
});

// ---------------------------------------------------------------- Every mail

it('carries the site link in every message', function (): void {
    // The link is the only actionable thing in any of these, and it is built
    // from config rather than written into the copy.
    config(['app.frontend_url' => 'https://remoattendancebeamo.vercel.app']);

    $tasker = tasker();

    $attendance = Attendance::create([
        'user_id' => $tasker->id,
        'attendance_date' => '2026-07-28',
        'time_in' => CarbonImmutable::parse('2026-07-28 22:05'),
        'time_out' => CarbonImmutable::parse('2026-07-29 06:00'),
        'total_hours' => 7.92,
        'status' => AttendanceStatus::Present,
    ]);

    foreach ([
        new ClockedInMail($attendance),
        new MarkedAbsentMail($attendance),
        new ShiftNotClosedMail($attendance),
    ] as $mail) {
        expect($mail->render())->toContain('https://remoattendancebeamo.vercel.app');
    }
});

it('shows the logo, and the product name when images are blocked', function (): void {
    // Remote images are blocked by default in a lot of clients, so the alt
    // text is not decoration -- it is what most first-time recipients actually
    // see. It says the product name for that reason, never "logo".
    config(['app.frontend_url' => 'https://remoattendancebeamo.vercel.app']);

    $attendance = Attendance::create([
        'user_id' => tasker()->id,
        'attendance_date' => '2026-07-28',
        'time_in' => CarbonImmutable::parse('2026-07-28 22:05'),
        'status' => AttendanceStatus::Present,
    ]);

    $html = (new ClockedInMail($attendance))->render();

    expect($html)
        ->toContain('https://remoattendancebeamo.vercel.app/logo.png')
        ->toContain('alt="'.config('app.name').'"')
        // Dimensions as attributes, not only as CSS: Outlook ignores the CSS
        // and draws the file at its natural width.
        ->toContain('width="150"');
});

it('does not fail a clock-in when the mailer is broken', function (): void {
    // The shift is recorded before the message is attempted. A mail server
    // having a bad minute must not hand back an error for something that has
    // already succeeded.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP unreachable'));

    $tasker = tasker();

    $this->actingAs($tasker)->postJson('/api/attendance/time-in')->assertCreated();

    expect(Attendance::where('user_id', $tasker->id)->exists())->toBeTrue();
});
