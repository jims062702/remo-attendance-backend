<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A daily production submission.
 *
 * Two identifiers, deliberately:
 *   task_code        system-generated, always present, globally unique
 *   external_task_id optional client/legacy reference, rendered "N/A" when null
 *
 * A single nullable-but-unique column could not satisfy both requirements --
 * the second "N/A" would violate the unique index.
 *
 * @property int $id
 * @property string $task_code
 * @property string|null $external_task_id
 * @property int $user_id
 * @property int|null $attendance_id
 * @property \Illuminate\Support\Carbon $task_date
 * @property string $task_name
 * @property string|null $task_description
 * @property int $output_count
 * @property TaskStatus $task_status
 * @property string|null $screenshot_link
 * @property string|null $notes
 */
class Task extends Model
{
    /** @use HasFactory<\Database\Factories\TaskFactory> */
    use HasFactory, SoftDeletes;

    /**
     * `task_code` is excluded: it is minted by TaskService and must never be
     * supplied or overwritten by a request payload.
     */
    protected $fillable = [
        'external_task_id',
        'user_id',
        'attendance_id',
        'task_date',
        'task_name',
        'task_description',
        'output_count',
        'task_status',
        'screenshot_link',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task_date' => 'date',
            'output_count' => 'integer',
            'task_status' => TaskStatus::class,
        ];
    }

    // ---------------------------------------------------------------- Relations

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    // ------------------------------------------------------------------- Scopes

    /**
     * @param  Builder<Task>  $query
     */
    public function scopeBetweenDates(Builder $query, ?string $from, ?string $to): void
    {
        // Direct comparison keeps the (task_date, task_status) index usable.
        $query->when($from, fn (Builder $q) => $q->where('task_date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->where('task_date', '<=', $to));
    }

    /**
     * @param  Builder<Task>  $query
     */
    public function scopeForUser(Builder $query, ?int $userId): void
    {
        $query->when($userId, fn (Builder $q) => $q->where('user_id', $userId));
    }

    /**
     * Free-text match over the fields an admin would search by.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $q) use ($like): void {
            $q->where('task_name', 'like', $like)
                ->orWhere('task_code', 'like', $like)
                ->orWhere('external_task_id', 'like', $like)
                ->orWhere('task_description', 'like', $like);
        });
    }

    /**
     * Excludes cancelled work from production figures.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeCountsTowardProduction(Builder $query): void
    {
        $query->where('task_status', '!=', TaskStatus::Cancelled);
    }
}
