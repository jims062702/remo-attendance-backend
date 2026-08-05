{{-- Sent when the cutoff passes with no attendance filed. Deliberately states
     what can and cannot be done about it: the tasker cannot clear this, and
     saying so up front is kinder than letting them hunt for a button that was
     never there. --}}
<x-mail.layout
    heading="You were marked absent tonight"
    preheader="No attendance was filed for tonight's shift."
    action="Open the attendance app"
>
    <p style="margin:0 0 14px 0;">Hi {{ $firstName }},</p>

    <p style="margin:0 0 14px 0;">
        No attendance was filed for the shift of <strong>{{ $shiftDate }}</strong> by
        {{ $cutoff }}, so you have been marked <strong>absent</strong> for that night.
    </p>

    <p style="margin:0 0 14px 0;">
        Tonight's shift is now closed for you. There is nothing left to file &mdash;
        no attendance, no PC, no tracker entry and no time out.
    </p>

    <p style="margin:0 0 14px 0;">
        <strong>If this is wrong and you did work tonight</strong>, speak to your
        administrator. Only they can correct the record; it cannot be cleared from
        your own screen, which is what keeps an absence meaningful.
    </p>

    <p style="margin:0;">
        Your next shift opens at {{ $nextOpens }}, and everything is available again then.
    </p>
</x-mail.layout>
