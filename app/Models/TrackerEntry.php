<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Tenurity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One tasker's production declaration for one business date.
 *
 * @property \Illuminate\Support\Carbon $entry_date
 * @property Tenurity $tenurity
 * @property float|null $declared_hours
 */
class TrackerEntry extends Model
{
    /** @use HasFactory<\Database\Factories\TrackerEntryFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'attendance_id',
        'entry_date',
        'tenurity',
        'site_id',
        'support_team_id',
        'task_id_count',
        'sbq_count',
        'declared_hours',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'tenurity' => Tenurity::class,
            'declared_hours' => 'float',
            'task_id_count' => 'integer',
            'sbq_count' => 'integer',
        ];
    }

    // ---------------------------------------------------------------- Relations

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Attendance, $this> */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<SupportTeam, $this> */
    public function supportTeam(): BelongsTo
    {
        return $this->belongsTo(SupportTeam::class);
    }

    /**
     * One block per project worked, each with its own task IDs, complexity
     * and screenshot.
     *
     * @return HasMany<TrackerItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TrackerItem::class);
    }

    // ------------------------------------------------------------------- Scopes

    /** @param Builder<TrackerEntry> $query */
    public function scopeBetweenDates(Builder $query, ?string $from, ?string $to): void
    {
        $query->when($from, fn (Builder $q) => $q->where('entry_date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->where('entry_date', '<=', $to));
    }

    /** @param Builder<TrackerEntry> $query */
    public function scopeForUser(Builder $query, ?int $userId): void
    {
        $query->when($userId, fn (Builder $q) => $q->where('user_id', $userId));
    }

    // ---------------------------------------------------------------- Accessors

    /**
     * Total tasks across every project on this entry.
     */
    public function totalTasks(): int
    {
        return (int) $this->items->sum('total_tasks');
    }

    /**
     * Declared hours against the shift's clocked hours.
     *
     * Negative means the tasker declared fewer productive hours than they were
     * clocked in for -- expected, since breaks and idle time are excluded. A
     * large gap is what an admin should look at, not a small one.
     */
    public function hoursGap(): ?float
    {
        $clocked = $this->attendance?->total_hours;

        if ($clocked === null || $this->declared_hours === null) {
            return null;
        }

        return round($this->declared_hours - $clocked, 2);
    }
}
