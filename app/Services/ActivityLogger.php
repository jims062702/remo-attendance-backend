<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes the audit trail.
 *
 * Logging must never break the operation it is recording, so failures here are
 * swallowed after being reported -- an audit write failing is a problem for
 * operations to investigate, not a reason to reject a tasker's clock-in.
 */
class ActivityLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        string $action,
        ?string $description = null,
        ?Model $subject = null,
        array $metadata = [],
        ?User $actor = null,
    ): ?ActivityLog {
        try {
            return ActivityLog::create([
                'user_id' => ($actor ?? Auth::user())?->getKey(),
                'action' => $action,
                'description' => $description,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'metadata' => $metadata === [] ? null : $metadata,
                'ip_address' => Request::ip(),
                'user_agent' => substr((string) Request::userAgent(), 0, 500),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Record a field-level change set, skipping attributes that did not move.
     * Used by admin correction endpoints so a reviewer can see exactly what a
     * given admin altered.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function logChanges(
        string $action,
        string $description,
        Model $subject,
        array $before,
        array $after,
        ?User $actor = null,
    ): ?ActivityLog {
        $changes = [];

        foreach ($after as $key => $newValue) {
            $oldValue = $before[$key] ?? null;

            if ($oldValue instanceof \BackedEnum) {
                $oldValue = $oldValue->value;
            }

            if ($newValue instanceof \BackedEnum) {
                $newValue = $newValue->value;
            }

            if ($oldValue != $newValue) {
                $changes[$key] = ['from' => $oldValue, 'to' => $newValue];
            }
        }

        if ($changes === []) {
            return null;
        }

        return $this->log($action, $description, $subject, ['changes' => $changes], $actor);
    }
}
