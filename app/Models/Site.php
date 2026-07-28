<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical location, e.g. "BEAMO 3F C".
 */
class Site extends Model
{
    protected $fillable = ['name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Workstation, $this> */
    public function workstations(): HasMany
    {
        return $this->hasMany(Workstation::class);
    }

    /** @param Builder<Site> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
