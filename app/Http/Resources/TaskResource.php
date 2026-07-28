<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * The UI convention for "nothing to record here".
     */
    private const NOT_APPLICABLE = 'N/A';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Always present and unique -- the system's own identifier.
            'task_code' => $this->task_code,

            // The optional client/legacy reference. Both the raw value (null
            // when absent, for form fields) and the display form are sent, so
            // the frontend never has to reimplement the "N/A" convention.
            'external_task_id' => $this->external_task_id,
            'external_task_id_display' => $this->external_task_id ?? self::NOT_APPLICABLE,

            'user_id' => $this->user_id,
            'attendance_id' => $this->attendance_id,
            'task_date' => $this->task_date->toDateString(),

            'task_name' => $this->task_name,
            'task_description' => $this->task_description,
            'output_count' => $this->output_count,

            'task_status' => $this->task_status->value,
            'task_status_label' => $this->task_status->label(),

            'screenshot_link' => $this->screenshot_link,
            'screenshot_link_display' => $this->screenshot_link ?? self::NOT_APPLICABLE,

            'notes' => $this->notes,

            'user' => UserResource::make($this->whenLoaded('user')),
            'attendance' => AttendanceResource::make($this->whenLoaded('attendance')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
