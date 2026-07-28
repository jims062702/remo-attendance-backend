<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

enum AttendanceStatus: string
{
    use HasEnumValues;

    /** Clocked in within the grace window. */
    case Present = 'present';

    /** Clocked in after shift start + grace. */
    case Late = 'late';

    /** Clocked in but never clocked out, and the shift has since ended. */
    case Incomplete = 'incomplete';

    /** No attendance for a scheduled working day. Recorded by an admin. */
    case Absent = 'absent';

    /** Excused non-attendance. Recorded by an admin. */
    case OnLeave = 'on_leave';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Late => 'Late',
            self::Incomplete => 'Incomplete',
            self::Absent => 'Absent',
            self::OnLeave => 'On Leave',
        };
    }

    /**
     * Statuses that represent the tasker actually having worked, and so are
     * the ones that count toward attendance rate and hour totals.
     */
    public function isWorked(): bool
    {
        return in_array($this, [self::Present, self::Late, self::Incomplete], true);
    }

    /**
     * Statuses an admin may assign directly. Present/late/incomplete are
     * derived from real clock events and must not be set by hand.
     *
     * @return array<int, self>
     */
    public static function adminAssignable(): array
    {
        return [self::Absent, self::OnLeave];
    }
}
