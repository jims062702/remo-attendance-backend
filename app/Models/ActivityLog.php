<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only audit trail. Rows are written by App\Services\ActivityLogger and
 * are never updated or deleted from application code.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $description
 * @property array<string, mixed>|null $metadata
 */
class ActivityLog extends Model
{
    /**
     * Only created_at exists; an append-only table has nothing to update.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'description',
        'subject_type',
        'subject_id',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    /**
     * Deactivated accounts included. An audit trail that forgets who did
     * something the moment their account is closed is not an audit trail.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<ActivityLog>  $query
     */
    public function scopeForUser(Builder $query, ?int $userId): void
    {
        $query->when($userId, fn (Builder $q) => $q->where('user_id', $userId));
    }

    /**
     * @param  Builder<ActivityLog>  $query
     */
    public function scopeForAction(Builder $query, ?string $action): void
    {
        $query->when($action, fn (Builder $q) => $q->where('action', $action));
    }

    /**
     * @param  Builder<ActivityLog>  $query
     */
    public function scopeBetweenDates(Builder $query, ?string $from, ?string $to): void
    {
        $query->when($from, fn (Builder $q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('created_at', '<=', $to));
    }
}
