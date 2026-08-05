{{-- Sent when the clock had to be closed for someone at the shift end.

     Two things are usually true at once: they forgot to time out, and they
     never filed a tracker entry either. The message says so, but the tracker
     paragraph is conditional -- telling somebody to file an entry they have
     already filed is how a notification teaches people to ignore it. --}}
<x-mail.layout
    heading="Your shift was closed for you"
    preheader="You did not time out, so the clock was closed at the shift end."
    action="{{ $hasTracker ? 'View tonight\'s shift' : 'File your tracker entry' }}"
>
    <p style="margin:0 0 14px 0;">Hi {{ $firstName }},</p>

    <p style="margin:0 0 14px 0;">
        You did not time out from the shift of <strong>{{ $shiftDate }}</strong>, so the
        clock was closed for you at the scheduled shift end.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
           style="margin:0 0 16px 0; border:1px solid #e1e0d9; border-radius:8px; background-color:#faf9f7;">
        <tr>
            <td style="padding:12px 16px; font-size:14px; color:#6f6d67;">Time in</td>
            <td style="padding:12px 16px; font-size:14px; font-weight:600; color:#1c1b19; text-align:right;">
                {{ $timeIn }}
            </td>
        </tr>
        <tr>
            <td style="padding:0 16px 12px 16px; font-size:14px; color:#6f6d67;">Time out (set automatically)</td>
            <td style="padding:0 16px 12px 16px; font-size:14px; font-weight:600; color:#1c1b19; text-align:right;">
                {{ $timeOut }}
            </td>
        </tr>
        <tr>
            <td style="padding:0 16px 12px 16px; font-size:14px; color:#6f6d67;">Hours credited</td>
            <td style="padding:0 16px 12px 16px; font-size:14px; font-weight:600; color:#1c1b19; text-align:right;">
                {{ $totalHours }}
            </td>
        </tr>
    </table>

    <p style="margin:0 0 14px 0;">
        Those hours are your scheduled shift length, not a measured one. If you left
        earlier or later, tell your administrator so the record matches what actually
        happened.
    </p>

    @if ($hasTracker)
        <p style="margin:0 0 14px 0;">
            <strong>Your tracker entry for the night is already filed</strong>, so there is
            nothing else you need to do &mdash; please ignore the rest of this message.
        </p>
    @else
        <p style="margin:0 0 14px 0;">
            <strong>You have not filed a tracker entry for this night yet.</strong> Please
            file it, so tonight's production is recorded against your shift. If you have
            already filed it since this message was sent, just ignore this.
        </p>
    @endif

    <p style="margin:0;">
        Your attendance is unaffected &mdash; you are still recorded as
        <strong>{{ $statusLabel }}</strong> for the night.
    </p>
</x-mail.layout>
