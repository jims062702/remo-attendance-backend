<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

enum UserRole: string
{
    use HasEnumValues;

    case Admin = 'admin';
    case Tasker = 'tasker';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Tasker => 'Tasker',
        };
    }
}
