<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * CB tenurity band, selected by the tasker on each tracker entry.
 *
 * Selected rather than derived from a start date, by operational choice --
 * it mirrors the existing Google Form. Because it is self-reported, each
 * entry stores the value that was chosen at the time rather than looking it
 * up later, so a report always reflects what was actually declared.
 */
enum Tenurity: string
{
    use HasEnumValues;

    case Newbie = 'newbie';
    case Trained = 'trained';
    case Expert = 'expert';

    public function label(): string
    {
        return match ($this) {
            self::Newbie => 'NEWBIE (0-10 days old)',
            self::Trained => 'TRAINED (11-20 days old)',
            self::Expert => 'EXPERT (21+ days old)',
        };
    }

    /**
     * The day range this band covers, used to suggest a value from a known
     * start date without forcing it.
     *
     * @return array{0: int, 1: int|null}
     */
    public function dayRange(): array
    {
        return match ($this) {
            self::Newbie => [0, 10],
            self::Trained => [11, 20],
            self::Expert => [21, null],
        };
    }

    /**
     * The band a given number of days on the account falls into. Used to
     * pre-select a sensible default, never to overwrite the tasker's choice.
     */
    public static function fromDays(int $days): self
    {
        return match (true) {
            $days <= 10 => self::Newbie,
            $days <= 20 => self::Trained,
            default => self::Expert,
        };
    }
}
