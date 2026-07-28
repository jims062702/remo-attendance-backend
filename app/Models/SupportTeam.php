<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The trainer or support person a tasker reports under.
 *
 * `user_id` is optional: many supports are named on the form without having a
 * login of their own.
 */
class SupportTeam extends Model
{
    protected $fillable = ['name', 'user_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<TrackerEntry, $this> */
    public function trackerEntries(): HasMany
    {
        return $this->hasMany(TrackerEntry::class);
    }

    /** @param Builder<SupportTeam> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
