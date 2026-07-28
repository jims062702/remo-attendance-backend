<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Answer to "For today's production, how many hours can we expect you to commit?"
 *
 * A bracket rather than a number, because that is how the operation actually
 * asks it. The last three options are not commitments at all -- they are
 * non-attendance declarations filed by the support team on the tasker's
 * behalf, which is why they carry no hours and are excluded from production.
 */
enum CommitmentBracket: string
{
    use HasEnumValues;

    case OneToTwo = '1_2_hours';
    case TwoToFour = '2_4_hours';
    case FourToSix = '4_6_hours';
    case SevenPlus = '7_plus_hours';
    case Absent = 'absent_support_entry';
    case Discontinued = 'discontinued_support_entry';
    case Disabled = 'disabled_support_entry';

    public function label(): string
    {
        return match ($this) {
            self::OneToTwo => '1-2 hours',
            self::TwoToFour => '2-4 hours',
            self::FourToSix => '4-6 hours',
            self::SevenPlus => '7 hours and above',
            self::Absent => 'Absent (from support entry)',
            self::Discontinued => 'Discontinued (from support entry)',
            self::Disabled => 'Disabled (from support entry)',
        };
    }

    /**
     * Whether the tasker is actually committing to work today.
     */
    public function isWorking(): bool
    {
        return in_array($this, [self::OneToTwo, self::TwoToFour, self::FourToSix, self::SevenPlus], true);
    }

    /**
     * Representative hours for variance reporting.
     *
     * Comparing a bracket against actual hours needs a single number. The
     * midpoint is used for closed brackets; the open-ended "7 and above"
     * takes its floor so the figure is never optimistic. Non-working
     * declarations have no expected hours at all -- NULL, not zero, so they
     * are excluded from averages rather than dragging them down.
     */
    public function expectedHours(): ?float
    {
        return match ($this) {
            self::OneToTwo => 1.5,
            self::TwoToFour => 3.0,
            self::FourToSix => 5.0,
            self::SevenPlus => 7.0,
            default => null,
        };
    }

    /**
     * The attendance status a support-filed declaration implies.
     */
    public function impliedAttendanceStatus(): ?AttendanceStatus
    {
        return match ($this) {
            self::Absent, self::Discontinued, self::Disabled => AttendanceStatus::Absent,
            default => null,
        };
    }
}
