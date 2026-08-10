<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CommitmentBracket;
use App\Enums\PcStatus;
use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Requests\Daily\ActivateAttendanceRequest;
use App\Http\Requests\Daily\SubmitTrackerRequest;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\TrackerEntryResource;
use App\Models\Site;
use App\Models\SupportTeam;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\DailyFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The tasker's guided daily flow.
 *
 * Every action is scoped to the authenticated user; no route here accepts a
 * user id, so a tasker structurally cannot file for someone else.
 */
class DailyFlowController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly DailyFlowService $daily,
        private readonly AttendanceService $attendance,
    ) {}

    /**
     * Current state of tonight's flow, plus everything the forms need to render.
     */
    public function state(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $state = $this->daily->currentState($user);
        $businessDate = \Carbon\CarbonImmutable::parse($state['business_date']);

        return $this->ok([
            'business_date' => $state['business_date'],
            'scheduled_start' => $this->attendance->scheduledStart($businessDate)->toIso8601String(),
            'scheduled_end' => $this->attendance->scheduledEnd($businessDate)->toIso8601String(),

            // When this shift stops being "tonight" and a new one can be
            // filed. The screen shows it once the night is complete, which is
            // the moment the question gets asked.
            'next_shift_opens_at' => $this->attendance->nextBusinessDateRollover()->toIso8601String(),

            'steps' => $state['steps'],

            // The night is closed as non-attendance: absent, or on leave. The
            // screen shows why instead of a flow, and `can_time_out` below is
            // false for the same reason.
            'settled' => $state['settled'],

            // No shift is rostered tonight. Separate from `settled` because
            // the screen has to say something different: "you were marked
            // absent" and "nobody works tonight" are not the same news.
            'off_duty' => $state['off_duty'],

            'attendance' => $state['attendance']
                ? AttendanceResource::make($state['attendance'])->resolve()
                : null,

            'tracker' => $state['tracker']
                ? TrackerEntryResource::make($state['tracker']->load(['items.project', 'site', 'supportTeam']))->resolve()
                : null,

            // Pre-fills the tracker form from the tasker's previous entry, so
            // unchanged values are not retyped every night.
            'last_entry' => $state['last_entry']
                ? TrackerEntryResource::make($state['last_entry']->load('items.project'))->resolve()
                : null,

            // An unrostered night has no clock to stop. A record can still
            // exist -- an admin may have filed one by hand -- so this is not
            // implied by the absence of attendance.
            'can_time_out' => ! $state['off_duty'] && ($state['attendance']?->isOpen() ?? false),

            // Tonight / this week / this month, so a tasker can see their own
            // output without going to a separate report.
            'totals' => $this->daily->productionTotals($user),
        ]);
    }

    /**
     * Reference data for the flow's pickers.
     *
     * Deliberately does NOT include workstations any more. The two have
     * opposite characteristics and were being fetched on the same schedule as
     * the slower one: projects, sites and teams are lookup tables that change
     * when an admin edits them, while the workstation list carries live claim
     * state for the whole floor.
     *
     * Bundling them meant every tasker re-read the entire floor's claim state
     * on the same cadence as a list of four support teams -- and, because the
     * client polled this endpoint, went on doing so all night from a page
     * nobody was interacting with. Splitting them lets each be fetched, and
     * cached, on the schedule its own data actually justifies.
     */
    public function options(): JsonResponse
    {
        $reference = Cache::remember('daily:reference-lists', 300, fn (): array => [
            'sites' => Site::query()->active()->orderBy('name')->get(['id', 'name'])->all(),
            'support_teams' => SupportTeam::query()->active()->orderBy('name')->get(['id', 'name'])->all(),
        ]);

        return $this->ok([
            'projects' => $this->daily->activeProjects()->values(),
            'sites' => $reference['sites'],
            'support_teams' => $reference['support_teams'],
        ]);
    }

    /**
     * The PC picker: every selectable machine, flagged with who has claimed it.
     *
     * Its own endpoint because it is the only part of the flow's reference data
     * that is genuinely live, and because it is only needed while someone is
     * actually choosing a desk -- which is a minute or two at the start of a
     * shift, not the whole night.
     */
    public function workstations(): JsonResponse
    {
        return $this->ok($this->daily->availableWorkstations()->values());
    }

    /**
     * Step 1 + 2 — file the activation and claim a PC. Clocks in on server time.
     */
    public function activate(ActivateAttendanceRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $attendance = $this->daily->activate(
            $user,
            CommitmentBracket::from($data['commitment_bracket']),
            $data['tasking_statuses'],
            isset($data['workstation_id']) ? (int) $data['workstation_id'] : null,
            isset($data['pc_status']) ? PcStatus::from($data['pc_status']) : null,
        );

        $message = $attendance->time_in
            ? 'Attendance filed. Timed in at '.$attendance->time_in->format('g:i A').'.'
            : 'Attendance filed.';

        return $this->created(AttendanceResource::make($attendance)->resolve(), $message);
    }

    /**
     * Step 3 — the production declaration.
     */
    public function submitTracker(SubmitTrackerRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $entry = $this->daily->submitTracker($user, $data, $data['items']);

        return $this->created(
            TrackerEntryResource::make($entry)->resolve(),
            'Tracker entry saved.',
        );
    }

    /**
     * The tasker's own tracker history.
     */
    public function history(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $entries = \App\Models\TrackerEntry::query()
            ->forUser($user->id)
            ->betweenDates($validated['from'] ?? null, $validated['to'] ?? null)
            ->with(['items.project', 'site', 'supportTeam', 'attendance'])
            ->orderByDesc('entry_date')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        return $this->ok(
            TrackerEntryResource::collection($entries->items())->resolve(),
            null,
            ['pagination' => $this->paginationMeta($entries)],
        );
    }
}
