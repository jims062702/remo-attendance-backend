<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Attendance;
use App\Services\AttendanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a tasker they were marked absent, and that they cannot undo it.
 *
 * Being explicit about the second part is the whole reason this is worth
 * sending. An absence the person discovers days later, from a report, is a
 * dispute; one they hear about the same night is a correction an admin can
 * still make while anyone remembers what happened.
 */
class MarkedAbsentMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Attendance $attendance) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You were marked absent — '
                .$this->attendance->attendance_date->format('M j, Y'),
        );
    }

    public function content(): Content
    {
        $user = $this->attendance->user;

        /** @var AttendanceService $service */
        $service = app(AttendanceService::class);

        return new Content(
            view: 'mail.marked-absent',
            with: [
                'firstName' => str($user?->name ?? 'there')->before(' ')->value(),
                'shiftDate' => $this->attendance->attendance_date->format('M j, Y'),
                // Read from config rather than written into the copy, so the
                // message cannot claim a cutoff the scheduler does not use.
                'cutoff' => $this->formatClockTime((string) config('attendance.absent_at')),
                'nextOpens' => $service->nextBusinessDateRollover()->format('g:i A'),
            ],
        );
    }

    /** "00:01" -> "12:01 AM". */
    private function formatClockTime(string $value): string
    {
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $value)), 2, 0);

        return now()->setTime($hour, $minute)->format('g:i A');
    }
}
