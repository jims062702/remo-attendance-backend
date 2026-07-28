<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * How a workstation was used during a shift.
 *
 * Recorded per shift rather than on the workstation itself: the same PC is
 * "Used" one night and "Vacant" the next, so the state belongs to the shift.
 */
enum PcStatus: string
{
    use HasEnumValues;

    case Used = 'used';
    case Vacant = 'vacant';
    case Support = 'support';
    case Maintenance = 'maintenance';
    case Reserved = 'reserved';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Used => 'Used',
            self::Vacant => 'Vacant',
            self::Support => 'Support',
            self::Maintenance => 'Maintenance',
            self::Reserved => 'Reserved',
            self::Other => 'Other',
        };
    }

    /** Whether this state means somebody was actually working at the machine. */
    public function isOccupied(): bool
    {
        return in_array($this, [self::Used, self::Support], true);
    }
}
