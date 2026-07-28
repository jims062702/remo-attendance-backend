<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Enums\CommitmentBracket;
use App\Enums\PcStatus;
use App\Enums\TaskComplexity;
use App\Enums\TaskStatus;
use App\Enums\TaskerLevel;
use App\Enums\TaskingStatus;
use App\Enums\Tenurity;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Concerns\ApiResponses;
use Illuminate\Http\JsonResponse;

/**
 * Enum options and shift settings for the frontend.
 *
 * Serving these rather than hardcoding them in TypeScript means a status added
 * to a PHP enum appears in every dropdown without a matching frontend edit, so
 * the two cannot drift apart.
 */
class MetaController extends Controller
{
    use ApiResponses;

    public function options(): JsonResponse
    {
        return $this->ok([
            'attendance_statuses' => AttendanceStatus::options(),
            'task_statuses' => TaskStatus::options(),
            'user_roles' => UserRole::options(),
            'user_statuses' => UserStatus::options(),
            'admin_assignable_attendance_statuses' => array_map(
                fn (AttendanceStatus $s) => ['value' => $s->value, 'label' => $s->label()],
                AttendanceStatus::adminAssignable(),
            ),

            // Daily flow vocabularies.
            'commitment_brackets' => CommitmentBracket::options(),
            // Grouped, because 24 flat options is unusable as one list.
            'tasking_statuses' => TaskingStatus::grouped(),
            'tenurities' => Tenurity::options(),
            'tasker_levels' => TaskerLevel::options(),
            'task_complexities' => TaskComplexity::options(),
            'pc_statuses' => PcStatus::options(),
            'shift' => [
                'start' => config('attendance.shift_start'),
                'end' => config('attendance.shift_end'),
                'standard_hours' => (float) config('attendance.standard_hours'),
                'grace_minutes' => (int) config('attendance.grace_minutes'),
                'max_shift_hours' => (float) config('attendance.max_shift_hours'),
                'commitment_min' => (float) config('attendance.commitment.min'),
                'commitment_max' => (float) config('attendance.commitment.max'),
                'timezone' => config('app.timezone'),
            ],
        ]);
    }
}
