<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * Shared filter rules for every list, report and export endpoint, so a date
 * range means the same thing everywhere and cannot be inverted.
 */
trait ValidatesDateRange
{
    /**
     * @return array<string, mixed>
     */
    protected function dateRangeRules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function paginationRules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            // Capped so a client cannot request the entire table in one page.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'sort' => ['nullable', 'string', 'max:50'],
            'direction' => ['nullable', 'in:asc,desc'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function dateRangeMessages(): array
    {
        return [
            'to.after_or_equal' => 'The "to" date must not be earlier than the "from" date.',
            'from.date_format' => 'Dates must be in YYYY-MM-DD format.',
            'to.date_format' => 'Dates must be in YYYY-MM-DD format.',
        ];
    }

    /**
     * Normalised filter payload for the service layer.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter([
            'from' => $this->query('from'),
            'to' => $this->query('to'),
            'user_id' => $this->query('user_id'),
            'status' => $this->query('status'),
            'task_status' => $this->query('task_status'),
            'search' => $this->query('search'),
            'group_by' => $this->query('group_by'),
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
