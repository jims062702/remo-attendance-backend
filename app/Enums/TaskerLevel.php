<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Tasker level on the platform.
 *
 * The gaps in the sequence (no L2, L3, L5...) are deliberate -- these are the
 * levels the platform actually issues, not a range.
 */
enum TaskerLevel: string
{
    use HasEnumValues;

    case Attempter = 'attempter';
    case L0 = 'l0';
    case L1 = 'l1';
    case L4 = 'l4';
    case L8 = 'l8';
    case L10 = 'l10';
    case L12 = 'l12';

    public function label(): string
    {
        return match ($this) {
            self::Attempter => 'ATTEMPTER',
            self::L0 => 'L0',
            self::L1 => 'L1',
            self::L4 => 'L4',
            self::L8 => 'L8',
            self::L10 => 'L10',
            self::L12 => 'L12',
        };
    }

    /** Sort order for reporting, since the values are not numerically ordered. */
    public function rank(): int
    {
        return match ($this) {
            self::Attempter => 0,
            self::L0 => 1,
            self::L1 => 2,
            self::L4 => 3,
            self::L8 => 4,
            self::L10 => 5,
            self::L12 => 6,
        };
    }
}
