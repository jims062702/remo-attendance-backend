<?php

declare(strict_types=1);

namespace App\Exports;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The tasker roster. Deactivated accounts are included and labelled rather
 * than hidden, since a roster that silently omits people is misleading.
 */
class TaskerListExport extends ReportExport
{
    protected function reportTitle(): string
    {
        return 'Tasker List';
    }

    /**
     * @return array<string, int>
     */
    protected function columnMap(): array
    {
        return [
            'Name' => 28,
            'Email' => 32,
            'Role' => 16,
            'Status' => 14,
            'Date Created' => 20,
            'Deactivated On' => 20,
        ];
    }

    /**
     * @return Collection<int, User>
     */
    protected function rows(): Collection
    {
        return User::query()
            ->withTrashed()
            ->when($this->filters['role'] ?? null, fn (Builder $q, $role) => $q->where('role', $role))
            ->when($this->filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($this->filters['search'] ?? null, fn (Builder $q, $term) => $q->search($term))
            ->orderBy('role')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  User  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        return [
            $row->name,
            $row->email,
            $row->role->label(),
            $row->status->label(),
            $row->created_at?->format('Y-m-d g:i A') ?? 'N/A',
            $row->deleted_at?->format('Y-m-d g:i A') ?? 'N/A',
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function totals(): array
    {
        $rows = $this->resolveRows();

        return [
            1 => 'TOTAL',
            2 => $rows->count().' accounts',
            3 => $rows->where('role', UserRole::Tasker)->count().' taskers',
            4 => $rows->filter(fn (User $u) => $u->canAuthenticate())->count().' active',
        ];
    }
}
