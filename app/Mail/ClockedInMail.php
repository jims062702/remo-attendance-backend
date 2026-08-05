<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirms a clock-in, with the exact time it was recorded at.
 *
 * Queued, and that is not incidental. Sending inline would put a Gmail round
 * trip inside the request a tasker is waiting on at a desk, and would let an
 * SMTP outage fail a clock-in that had already succeeded.
 */
class ClockedInMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Attendance $attendance) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You are clocked in — '
                .$this->attendance->attendance_date->format('M j, Y'),
        );
    }

    public function content(): Content
    {
        $user = $this->attendance->user;

        return new Content(
            view: 'mail.clocked-in',
            with: [
                // First name only. The full name belongs on a record; a
                // greeting that opens "Hi Juan Dela Cruz," reads like a form
                // letter, which is what people stop opening.
                'firstName' => str($user?->name ?? 'there')->before(' ')->value(),
                'shiftDate' => $this->attendance->attendance_date->format('M j, Y'),
                'timeIn' => $this->attendance->time_in?->format('g:i A') ?? '—',
                'statusLabel' => $this->attendance->status->label(),
                'isLate' => $this->attendance->status === AttendanceStatus::Late,
                'workstation' => $this->attendance->workstation?->name,
            ],
        );
    }
}
