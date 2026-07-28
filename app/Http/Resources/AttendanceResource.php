<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Attendance;
use App\Services\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attendance
 */
class AttendanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AttendanceService $service */
        $service = app(AttendanceService::class);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,

            // The business date of the shift. For the overnight shift this is
            // the date the shift STARTED, which is not necessarily the calendar
            // date of time_in -- the frontend labels it "Shift date" for that
            // reason.
            'attendance_date' => $this->attendance_date->toDateString(),

            // Full ISO 8601 with offset, so a client can render local times
            // without guessing the server's timezone, and so a shift that
            // crosses midnight is unambiguous.
            'time_in' => $this->time_in?->toIso8601String(),
            'time_out' => $this->time_out?->toIso8601String(),

            'total_hours' => $this->total_hours,
            'expected_hours' => $this->expected_hours,
            'variance' => $this->variance(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            // Daily-flow fields.
            'commitment_bracket' => $this->commitment_bracket?->value,
            'commitment_bracket_label' => $this->commitment_bracket?->label(),
            'workstation_id' => $this->workstation_id,
            'workstation_name' => $this->whenLoaded('workstation', fn () => $this->workstation?->name),
            'pc_status' => $this->pc_status?->value,
            'pc_status_label' => $this->pc_status?->label(),

            // The filed tasking statuses. Several apply at once, which is why
            // this is a list rather than a single value.
            'tasking_statuses' => $this->whenLoaded(
                'taskingStatuses',
                fn () => collect($this->taskingStatusEnums())
                    ->map(fn ($status) => ['value' => $status->value, 'label' => $status->label()])
                    ->values(),
            ),

            'is_open' => $this->isOpen(),
            'notes' => $this->notes,

            'scheduled_start' => $service->scheduledStart($this->attendance_date)->toIso8601String(),
            'scheduled_end' => $service->scheduledEnd($this->attendance_date)->toIso8601String(),
            'minutes_late' => $this->time_in
                ? $service->minutesLate($this->time_in, $this->attendance_date)
                : null,

            'user' => UserResource::make($this->whenLoaded('user')),
            'tasks' => TaskResource::collection($this->whenLoaded('tasks')),
            'tasks_count' => $this->whenCounted('tasks'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
