<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/**
 * Shared helpers for the backed enums that mirror the database ENUM columns.
 *
 * Validation rules are derived from values() rather than being typed out in
 * Form Requests, so adding a case cannot drift out of sync with what the API
 * will accept.
 */
trait HasEnumValues
{
    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Options for a select control: [['value' => ..., 'label' => ...], ...].
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
