<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A PC a tasker claims for a shift.
 */
class Workstation extends Model
{
    protected $fillable = [
        'name', 'site_id', 'notes', 'is_active', 'is_support',
        'floor_block', 'floor_row', 'floor_col',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_support' => 'boolean',
            'floor_block' => 'integer',
            'floor_row' => 'integer',
            'floor_col' => 'integer',
        ];
    }

    /**
     * The floor's naming convention: machine 6 is "PC-06 3F C".
     *
     * One function so the seeders, the deduplication migration and any future
     * caller cannot drift apart. Machines are unique on (site_id, name), so a
     * seeder that spells a name differently from the rows already in the table
     * does not update them -- it creates a parallel set, which is exactly how
     * this floor ended up with two of every PC.
     *
     * The suffix names the physical location, matching the site it belongs to,
     * because "PC-06" alone is ambiguous the moment a second room exists.
     */
    public const FLOOR_SUFFIX = '3F C';

    public static function floorName(int $number): string
    {
        return sprintf('PC-%02d %s', $number, self::FLOOR_SUFFIX);
    }

    /** True once the machine has been placed on the floor plan. */
    public function isPlaced(): bool
    {
        return $this->floor_block !== null
            && $this->floor_row !== null
            && $this->floor_col !== null;
    }

    /**
     * Placed machines, in the order they are drawn.
     *
     * @param  Builder<Workstation>  $query
     */
    public function scopeInFloorOrder(Builder $query): void
    {
        $query->orderBy('floor_block')->orderBy('floor_row')->orderBy('floor_col');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<Attendance, $this> */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /** @param Builder<Workstation> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The machines a tasker may claim.
     *
     * Support PCs are permanently outside the pool, so they are filtered out
     * here rather than shown as "taken" -- a tasker has no reason to know a
     * machine exists if they can never use it.
     *
     * @param  Builder<Workstation>  $query
     */
    public function scopeSelectableByTaskers(Builder $query): void
    {
        $query->where('is_active', true)->where('is_support', false);
    }

    /** "PC-014 — BEAMO 3F C" */
    public function displayName(): string
    {
        return $this->site ? "{$this->name} — {$this->site->name}" : $this->name;
    }
}
