<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A tracking project, e.g. "aloha_data_collection_v1".
 *
 * Retired by clearing is_active rather than deleting, so historical tracker
 * entries that reference it still resolve.
 */
class Project extends Model
{
    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsToMany<TrackerEntry, $this> */
    public function trackerEntries(): BelongsToMany
    {
        return $this->belongsToMany(TrackerEntry::class)->withPivot('total_tasks');
    }

    /** @param Builder<Project> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function displayName(): string
    {
        return $this->name ? "{$this->code} ({$this->name})" : $this->code;
    }
}
