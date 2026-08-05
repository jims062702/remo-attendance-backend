<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Attendance;
use App\Models\TrackerEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a tasker their clock was closed for them at the shift end.
 *
 * Someone who forgets to time out has usually not filed a tracker entry
 * either, so the message covers both -- but it checks before it asks. Telling
 * a person to file something they already filed is how a notification trains
 * its readers to delete it unread, and the one after that is the one that
 * mattered.
 */
class ShiftNotClosedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Attendance $attendance) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your shift was closed for you — '
                .$this->attendance->attendance_date->format('M j, Y'),
        );
    }

    public function content(): Content
    {
        $user = $this->attendance->user;

        // Checked at send time rather than passed in, so an entry filed
        // between the clock closing and the message going out is still seen.
        $hasTracker = TrackerEntry::query()
            ->where('user_id', $this->attendance->user_id)
            ->where('entry_date', $this->attendance->attendance_date->toDateString())
            ->exists();

        return new Content(
            view: 'mail.shift-not-closed',
            with: [
                'firstName' => str($user?->name ?? 'there')->before(' ')->value(),
                'shiftDate' => $this->attendance->attendance_date->format('M j, Y'),
                'timeIn' => $this->attendance->time_in?->format('g:i A') ?? '—',
                'timeOut' => $this->attendance->time_out?->format('g:i A') ?? '—',
                'totalHours' => $this->attendance->total_hours !== null
                    ? number_format((float) $this->attendance->total_hours, 2).' hours'
                    : '—',
                'statusLabel' => $this->attendance->status->label(),
                'hasTracker' => $hasTracker,
            ],
        );
    }
}
