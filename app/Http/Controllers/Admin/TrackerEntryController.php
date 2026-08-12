<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTrackerEntryRequest;
use App\Http\Resources\TrackerEntryResource;
use App\Models\TrackerEntry;
use App\Services\ActivityLogger;
use App\Services\DailyFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Removing a nightly submission.
 *
 * Listing them lives in ReportController, which is read-only by design; a
 * destructive action does not belong beside the reporting endpoints, so it has
 * its own home. The route sits behind the `admin` middleware -- there is no
 * TrackerEntry policy because there is no record-level question to answer:
 * either you administer the floor or you do not.
 */
class TrackerEntryController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly DailyFlowService $daily,
    ) {}

    /**
     * Correct a submission.
     *
     * Validated by UpdateTrackerEntryRequest, which extends the tasker's own
     * submission rules -- so an admin cannot file an entry a tasker could not.
     *
     * The tasker and the shift date are not editable. An entry belongs to a
     * person and a night; changing either is moving the record, not correcting
     * it, and delete-and-refile is the honest way to do that.
     */
    public function update(UpdateTrackerEntryRequest $request, TrackerEntry $entry): JsonResponse
    {
        $data = $request->validated();

        $entry->loadMissing(['user', 'items.project']);

        // Captured before the write, because items are replaced wholesale and
        // the previous shape is otherwise unrecoverable.
        $before = [
            'task_id_count' => $entry->task_id_count,
            'sbq_count' => $entry->sbq_count,
            'tenurity' => $entry->tenurity->value,
            'remarks' => $entry->remarks,
            'projects' => $entry->items->map(fn ($item): array => [
                'project' => $item->project?->code,
                'total_tasks' => $item->total_tasks,
                'task_ids' => $item->task_ids,
            ])->all(),
        ];

        $entry = $this->daily->reviseEntry($entry, $data, $data['items']);

        $after = [
            'task_id_count' => $entry->task_id_count,
            'sbq_count' => $entry->sbq_count,
            'tenurity' => $entry->tenurity->value,
            'remarks' => $entry->remarks,
            'projects' => $entry->items->map(fn ($item): array => [
                'project' => $item->project?->code,
                'total_tasks' => $item->total_tasks,
                'task_ids' => $item->task_ids,
            ])->all(),
        ];

        $this->logger->logChanges(
            'tracker.corrected',
            "Corrected the submission of {$entry->entry_date->toDateString()} for {$entry->user?->email}",
            $entry,
            $before,
            $after,
            $request->user(),
        );

        return $this->ok(
            TrackerEntryResource::make($entry)->resolve(),
            'Submission updated.',
        );
    }

    /**
     * Delete a submission outright.
     *
     * Not a soft delete, for the same reason the shift endpoint is not:
     * `tracker_entries` is unique on (user_id, entry_date), and a soft-deleted
     * row keeps holding that pair. The tasker would then be unable to file
     * that night again, and updateOrCreate would not find the hidden row to
     * revise -- it would collide with it.
     *
     * The per-project blocks are removed with it by the database
     * (tracker_items.tracker_entry_id cascades). That is right: an item
     * describes one project inside one submission and means nothing on its
     * own.
     */
    public function destroy(Request $request, TrackerEntry $entry): JsonResponse
    {
        $entry->loadMissing(['user', 'items.project']);

        // The whole submission, kept in the audit trail -- because after this
        // the row is gone and the log is the only place it survives.
        $snapshot = [
            'user' => $entry->user?->email,
            'entry_date' => $entry->entry_date->toDateString(),
            'task_id_count' => $entry->task_id_count,
            'sbq_count' => $entry->sbq_count,
            'declared_hours' => $entry->declared_hours,
            'projects' => $entry->items->map(fn ($item): array => [
                'project' => $item->project?->code,
                'total_tasks' => $item->total_tasks,
            ])->all(),
        ];

        $entry->forceDelete();

        $this->logger->log(
            'tracker.deleted',
            "Deleted the submission of {$snapshot['entry_date']} for {$snapshot['user']}",
            null,
            $snapshot,
            $request->user(),
        );

        return $this->ok($snapshot, 'Submission deleted.');
    }
}
