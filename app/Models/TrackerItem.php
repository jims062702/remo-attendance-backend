<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskComplexity;
use App\Enums\TaskerLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One project's worth of work inside a nightly tracker entry.
 *
 * Each block carries its own task IDs, complexity and screenshot, because a
 * tasker working aloha and ego on the same night has different IDs and a
 * different screenshot for each -- even when the complexity happens to match.
 *
 * @property string|null $task_ids
 * @property TaskComplexity|null $task_complexity
 */
class TrackerItem extends Model
{
    protected $fillable = [
        'tracker_entry_id',
        'project_id',
        'tasker_level',
        'total_tasks',
        'task_ids',
        'task_id_count',
        'sbq_count',
        'task_complexity',
        'screenshot_links',
    ];

    protected function casts(): array
    {
        return [
            'total_tasks' => 'integer',
            'task_id_count' => 'integer',
            'sbq_count' => 'integer',
            'task_complexity' => TaskComplexity::class,
            // Level is per project: the same tasker can be L8 on one and an
            // Attempter on another they have just started.
            'tasker_level' => TaskerLevel::class,
        ];
    }

    /** @return BelongsTo<TrackerEntry, $this> */
    public function trackerEntry(): BelongsTo
    {
        return $this->belongsTo(TrackerEntry::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
