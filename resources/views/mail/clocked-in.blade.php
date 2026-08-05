{{-- Sent the moment a tasker starts their clock. The exact time is the point:
     it is the record, and seeing it now is what lets someone say "that is
     wrong" while an admin can still do something about it. --}}
<x-mail.layout
    heading="You are clocked in"
    preheader="Your attendance for tonight has been recorded."
    action="View tonight's shift"
>
    <p style="margin:0 0 14px 0;">Hi {{ $firstName }},</p>

    <p style="margin:0 0 14px 0;">
        Your attendance for the shift of <strong>{{ $shiftDate }}</strong> has been recorded.
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
            <td style="padding:0 16px 12px 16px; font-size:14px; color:#6f6d67;">Status</td>
            <td style="padding:0 16px 12px 16px; font-size:14px; font-weight:600; text-align:right;
                       color:{{ $isLate ? '#a86b00' : '#2f6d3f' }};">
                {{ $statusLabel }}
            </td>
        </tr>
        @if ($workstation)
            <tr>
                <td style="padding:0 16px 12px 16px; font-size:14px; color:#6f6d67;">PC</td>
                <td style="padding:0 16px 12px 16px; font-size:14px; font-weight:600; color:#1c1b19; text-align:right;">
                    {{ $workstation }}
                </td>
            </tr>
        @endif
    </table>

    @if ($isLate)
        <p style="margin:0 0 14px 0;">
            This was recorded as late because the clock started after the grace period.
            If you believe that is wrong, tell your administrator &mdash; only they can
            correct a shift.
        </p>
    @endif

    <p style="margin:0;">
        Remember to file your tracker entry and time out before you leave. Your hours
        are worked out from the clock, so an unclosed shift has to be closed for you.
    </p>
</x-mail.layout>
