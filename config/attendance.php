<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Shift Window
    |--------------------------------------------------------------------------
    |
    | The scheduled shift. This deployment runs an overnight ("graveyard")
    | shift of 22:00 -> 06:00, so a single shift spans two calendar dates.
    | Times are 24-hour "H:i" strings interpreted in the application timezone.
    |
    */

    'shift_start' => env('ATTENDANCE_SHIFT_START', '22:00'),
    'shift_end' => env('ATTENDANCE_SHIFT_END', '06:00'),

    /*
    |--------------------------------------------------------------------------
    | Business Day Cutoff
    |--------------------------------------------------------------------------
    |
    | The clock time at which the "business date" rolls over to a new day.
    | A timestamp at or after the cutoff belongs to that calendar date; a
    | timestamp before it belongs to the *previous* calendar date.
    |
    | This is what keeps an overnight shift on one attendance record. It is
    | also, in practice, the answer to "when can I file a new night?" -- a
    | tasker who has finished cannot start another shift until the business
    | date rolls over.
    |
    | With a cutoff of 19:50 and a 22:00 shift start:
    |
    |   Jul 26 10:05 PM -> business date Jul 26 (on time)
    |   Jul 27 12:30 AM -> business date Jul 26 (late for Jul 26's shift)
    |   Jul 27 05:00 AM -> business date Jul 26 (very late, near shift end)
    |   Jul 27 07:49 PM -> business date Jul 26 (still the night just worked)
    |   Jul 27 07:50 PM -> business date Jul 27 (a new night opens)
    |   Jul 27 10:00 PM -> business date Jul 27 (on time for that shift)
    |
    | The cutoff must sit between shift_end and shift_start -- here 06:00 and
    | 22:00 -- in the dead hours when nobody is clocked in. Moving it into the
    | shift window would split a single shift across two business dates, which
    | is precisely what the unique index on (user_id, attendance_date) cannot
    | protect against.
    |
    | 19:50 puts the rollover ten minutes before the shift opens, so the new
    | night becomes fileable just as people arrive and the previous night stays
    | editable for the whole day after it.
    |
    | The default here matches the deployed .env deliberately. When the two
    | disagreed, a missing environment variable silently produced a different
    | shift boundary -- with nothing to warn anyone that attendance was being
    | filed against the wrong night.
    |
    | Changing this does NOT rewrite history. `attendance_date` is resolved
    | once, at write time, and stored -- existing records keep the business
    | date they were filed under, and only new timestamps follow the new value.
    |
    */

    'business_day_cutoff' => env('ATTENDANCE_BUSINESS_DAY_CUTOFF', '19:50'),

    /*
    |--------------------------------------------------------------------------
    | Lateness
    |--------------------------------------------------------------------------
    |
    | Minutes of grace after shift_start before a clock-in counts as late.
    | Lateness is always measured against the shift start of the record's
    | business date, never against "today".
    |
    */

    'grace_minutes' => (int) env('ATTENDANCE_GRACE_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Hours
    |--------------------------------------------------------------------------
    |
    | standard_hours   Expected length of a full shift. Used as the default
    |                  production commitment and to flag under/overtime.
    |
    | max_shift_hours  Upper bound on a single attendance record. A span longer
    |                  than this is treated as a data error (usually a forgotten
    |                  clock-out) rather than a real 20-hour shift, and is
    |                  rejected so it cannot poison hour totals.
    |
    */

    'standard_hours' => (float) env('ATTENDANCE_STANDARD_HOURS', 8),
    'max_shift_hours' => (float) env('ATTENDANCE_MAX_SHIFT_HOURS', 16),

    /*
    |--------------------------------------------------------------------------
    | Production Commitment
    |--------------------------------------------------------------------------
    |
    | Bounds for "how many hours can we expect you to commit today?". Stored on
    | the attendance record (once per shift), never on individual tasks.
    |
    */

    'commitment' => [
        'min' => (float) env('ATTENDANCE_COMMITMENT_MIN', 0.5),
        'max' => (float) env('ATTENDANCE_COMMITMENT_MAX', 16),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stale Shift Closing
    |--------------------------------------------------------------------------
    |
    | The `attendance:close-stale` command marks unclosed shifts from previous
    | business dates as "incomplete" so they stop counting as active. It runs
    | daily at this time, which must fall before the business day cutoff so it
    | can never touch a shift that is still legitimately running.
    |
    */

    'close_stale_at' => env('ATTENDANCE_CLOSE_STALE_AT', '17:00'),

    /*
    |--------------------------------------------------------------------------
    | Absence Warning
    |--------------------------------------------------------------------------
    |
    | When a tasker reaches `threshold` absences within the last `window_days`,
    | the admin screens flag them as at risk and recommend review for
    | discontinuation.
    |
    | The window ROLLS. It is deliberately not "this calendar month", which
    | would let someone be absent twice on the 30th and twice on the 2nd and
    | never trip the rule, and not "all time", which would brand a tasker
    | permanently for a bad fortnight two years ago. A rolling window is also
    | self-correcting: the flag clears on its own as old absences age out, so a
    | tasker who improves does not need an admin to un-flag them.
    |
    | Only records explicitly marked `absent` count. Present, late and
    | incomplete are all forms of having turned up, and on_leave is approved
    | non-attendance -- counting either toward a discontinuation recommendation
    | would be wrong.
    |
    | This NEVER deactivates anyone by itself. It surfaces a recommendation; a
    | human decides. Ending someone's employment is not a side effect a counter
    | should be trusted with, and the count is only as good as the absences an
    | admin remembered to record.
    |
    */

    'absence' => [
        'threshold' => (int) env('ATTENDANCE_ABSENCE_THRESHOLD', 4),
        'window_days' => (int) env('ATTENDANCE_ABSENCE_WINDOW_DAYS', 30),
    ],

];
