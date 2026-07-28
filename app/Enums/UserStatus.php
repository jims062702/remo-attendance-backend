<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

enum UserStatus: string
{
    use HasEnumValues;

    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Suspended => 'Suspended',
        };
    }

    /**
     * Only active accounts may authenticate. Inactive and suspended users keep
     * all their historical records but cannot log in or clock in.
     */
    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }
}
