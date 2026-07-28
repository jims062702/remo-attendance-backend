<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TrackerEntry;
use App\Models\TrackerItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TrackerEntry
 */
class TrackerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'attendance_id' => $this->attendance_id,
            'entry_date' => $this->entry_date->toDateString(),

            'tenurity' => $this->tenurity->value,
            'tenurity_label' => $this->tenurity->label(),
            'site_id' => $this->site_id,
            'site_name' => $this->site?->name,
            'support_team_id' => $this->support_team_id,
            'support_team_name' => $this->supportTeam?->name,

            // Rolled up from the per-project blocks below.
            'task_id_count' => $this->task_id_count,
            'sbq_count' => $this->sbq_count,

            'declared_hours' => $this->declared_hours,
            'remarks' => $this->remarks,
            'remarks_display' => $this->remarks ?? 'N/A',

            // One block per project worked, each with its own task IDs,
            // complexity and screenshot.
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (TrackerItem $item) => [
                'id' => $item->id,
                'project_id' => $item->project_id,
                'project_code' => $item->project?->code,
                'project_name' => $item->project?->name,
                'tasker_level' => $item->tasker_level?->value,
                'tasker_level_label' => $item->tasker_level?->label(),
                'total_tasks' => $item->total_tasks,
                'task_ids' => $item->task_ids,
                'task_ids_display' => $item->task_ids ?? 'N/A',
                'task_id_count' => $item->task_id_count,
                'sbq_count' => $item->sbq_count,
                'task_complexity' => $item->task_complexity?->value,
                'task_complexity_label' => $item->task_complexity?->label(),
                'screenshot_links' => $item->screenshot_links,
                'screenshot_links_display' => $item->screenshot_links ?? 'N/A',
            ])->values()),

            'total_tasks' => $this->whenLoaded('items', fn () => $this->totalTasks()),

            // Declared productive hours against the clock. Expected to be
            // negative -- breaks and idle time are excluded by design; a large
            // gap is what warrants a look.
            'hours_gap' => $this->whenLoaded('attendance', fn () => $this->hoursGap()),

            'user' => UserResource::make($this->whenLoaded('user')),
            'attendance' => AttendanceResource::make($this->whenLoaded('attendance')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
