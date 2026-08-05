{{--
    The shell every notification is drawn in.

    Written as a table with inline styles, which is not a stylistic choice:
    Outlook renders with Word's engine and Gmail strips <style> blocks, so a
    flex layout or a class in the head is a layout that arrives broken. Tables
    and inline attributes are what actually survive.

    Colours are stated literally rather than pulled from the application's
    tokens. An email is read months later in a client that has never heard of
    this design system, and a CSS variable that fails to resolve there renders
    as black on black.
--}}
@props([
    'heading',
    'subjectLine' => null,
    'preheader' => null,
    'action' => 'Open the attendance app',
])

@php
    // The one place the site address is resolved. Every message carries it,
    // and a link that has drifted from the deployed frontend is worse than no
    // link at all.
    $siteUrl = rtrim((string) config('app.frontend_url'), '/');
    $subjectLine ??= $heading;
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f2; -webkit-text-size-adjust:100%;">

{{-- Preheader: the grey line a client shows next to the subject. Hidden in the
     body itself, because repeating it visibly reads as a stutter. --}}
<div style="display:none; max-height:0; overflow:hidden; opacity:0;">{{ $preheader ?? '' }}</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background-color:#f4f4f2; padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:560px; background-color:#ffffff; border:1px solid #e1e0d9; border-radius:12px;">

                <tr>
                    <td style="padding:24px 28px 0 28px;">
                        <p style="margin:0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
                                  font-size:12px; font-weight:700; letter-spacing:0.1em; text-transform:uppercase;
                                  color:#6f6d67;">
                            {{ config('app.name') }}
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:12px 28px 0 28px;">
                        <h1 style="margin:0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
                                   font-size:22px; line-height:1.3; font-weight:600; color:#1c1b19;">
                            {{ $heading }}
                        </h1>
                    </td>
                </tr>

                <tr>
                    <td style="padding:16px 28px 0 28px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
                               font-size:15px; line-height:1.6; color:#3d3b37;">
                        {{ $slot }}
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px 28px 4px 28px;">
                        <a href="{{ $siteUrl }}"
                           style="display:inline-block; padding:11px 20px; border-radius:8px;
                                  background-color:#3d2fa8; color:#ffffff; text-decoration:none;
                                  font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
                                  font-size:15px; font-weight:600;">
                            {{ $action ?? 'Open the attendance app' }}
                        </a>
                    </td>
                </tr>

                {{-- The bare URL as well as the button. A button is a link a
                     text-only client cannot show, and this is the one thing in
                     the message the reader may actually need. --}}
                <tr>
                    <td style="padding:8px 28px 24px 28px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
                               font-size:13px; line-height:1.5; color:#6f6d67;">
                        Or open it directly:<br>
                        <a href="{{ $siteUrl }}" style="color:#3d2fa8;">{{ $siteUrl }}</a>
                    </td>
                </tr>

                <tr>
                    <td style="padding:16px 28px 24px 28px; border-top:1px solid #e1e0d9;
                               font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
                               font-size:12px; line-height:1.5; color:#8b8983;">
                        This is an automated message from {{ config('app.name') }}. Please do not reply
                        to it &mdash; nobody reads this mailbox. Speak to your administrator if
                        anything here looks wrong.
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

</body>
</html>
