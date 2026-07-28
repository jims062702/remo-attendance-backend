<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

enum TaskStatus: string
{
    use HasEnumValues;

    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case OnHold = 'on_hold';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In Progress',
            self::Completed => 'Completed',
            self::OnHold => 'On Hold',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Cancelled work is excluded from output and completion-rate figures so a
     * cancelled task cannot drag down a tasker's numbers.
     */
    public function countsTowardProduction(): bool
    {
        return $this !== self::Cancelled;
    }
}
