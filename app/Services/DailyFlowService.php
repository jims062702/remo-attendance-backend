<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\CommitmentBracket;
use App\Enums\PcStatus;
use App\Enums\TaskingStatus;
use App\Exceptions\AttendanceException;
use App\Models\Attendance;
use App\Models\AttendanceTaskingStatus;
use App\Models\Project;
use App\Models\TrackerEntry;
use App\Models\User;
use App\Models\Workstation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The tasker's guided daily flow.
 *
 *   1. Attendance activation  commitment bracket + tasking statuses
 *   2. PC utilisation         claim a workstation; clock in on server time
 *   3. Tracker entry          production declaration for the shift
 *   4. Time out               manual, on server time
 *
 * Steps 1 and 2 write to the single attendance row for the business date, so
 * there is still exactly one clock per shift. Step 3 writes a separate tracker
 * row, because an absence needs no production record at all.
 */
class DailyFlowService
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly ActivityLogger $logger,
    ) {}

    // ------------------------------------------------------- Step 1: activation

    /**
     * File the commitment bracket and tasking statuses, and claim a PC.
     *
     * Creates the shift record if it does not exist. Idempotent: re-running it
     * before clocking out updates the same row rather than failing, so a tasker
     * can correct a mis-tap without an admin.
     *
     * @param  array<int, string>  $taskingStatuses
     */
    public function activate(
        User $user,
        CommitmentBracket $bracket,
        array $taskingStatuses,
        ?int $workstationId = null,
        ?PcStatus $pcStatus = null,
        ?CarbonInterface $at = null,
    ): Attendance {
        $this->assertActive($user);

        $now = $at ? CarbonImmutable::instance($at) : CarbonImmutable::now();
        $businessDate = $this->attendance->resolveBusinessDate($now);

        // Activation is the other door into the same clock, so it needs the
        // same guard as timeIn(): no rostered shift, nothing to activate.
        if (! $this->attendance->isWorkingDate($businessDate)) {
            throw AttendanceException::notRostered($businessDate);
        }

        // Set inside the transaction, read after it. Activation is re-submitted
        // every time a tasker changes desk or fixes a bracket, and only the
        // submission that actually starts the clock is worth an email -- one
        // per PC switch would make the confirmation worthless.
        $startedTheClock = false;

        $attendance = DB::transaction(function () use (
            $user, $bracket, $taskingStatuses, $workstationId, $pcStatus, $now, $businessDate,
            &$startedTheClock
        ): Attendance {
            $record = Attendance::query()
                ->where('user_id', $user->id)
                ->where('attendance_date', $businessDate->toDateString())
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                $record = new Attendance;
                $record->user_id = $user->id;
                $record->attendance_date = $businessDate->toDateString();
            } elseif ($record->time_in === null && ! $record->status->isWorked()) {
                // The night was already settled as non-attendance, by
                // attendance:mark-absent at the cutoff or by an admin.
                //
                // AttendanceService::timeIn() refuses this, but activation is a
                // second door into the same clock -- claiming a machine IS
                // clocking in -- and it was walking straight past the rule.
                // The flow hides itself once `settled` is true, so nothing in
                // the UI offers this; that is exactly why the service has to
                // enforce it rather than trust the screen.
                throw AttendanceException::markedAbsent(
                    $businessDate,
                    $record->status->label(),
                );
            }

            // A support-filed declaration (Absent / Discontinued / Disabled)
            // is not a commitment and never starts a clock.
            $isWorking = $bracket->isWorking();

            $record->commitment_bracket = $bracket;
            $record->expected_hours = $bracket->expectedHours();

            if ($isWorking) {
                $record->workstation_id = $workstationId;
                $record->pc_status = $pcStatus ?? PcStatus::Used;

                // Clock in now, unless already clocked in for this shift.
                if ($record->time_in === null) {
                    $record->time_in = $now;
                    $record->status = $this->attendance->resolveClockInStatus($now, $businessDate);
                    $startedTheClock = true;
                }
            } else {
                $record->status = $bracket->impliedAttendanceStatus() ?? AttendanceStatus::Absent;
            }

            $this->saveClaimingWorkstation($record, $businessDate);
            $this->syncTaskingStatuses($record, $taskingStatuses);

            return $record;
        });

        // A desk just changed hands, so the shared picker is now wrong for
        // everyone. Dropping it here is what keeps the ten-second TTL from
        // being the floor on how long a taken machine still looks free.
        $this->forgetWorkstationCache($businessDate->toDateString());

        $this->logger->log(
            'daily.activated',
            "Filed attendance activation for {$businessDate->toDateString()}",
            $attendance,
            [
                'commitment' => $bracket->value,
                'tasking_statuses' => $taskingStatuses,
                'workstation_id' => $attendance->workstation_id,
            ],
            $user,
        );

        if ($startedTheClock) {
            $this->attendance->mailClockIn($attendance);
        }

        return $attendance->load(['workstation.site', 'taskingStatuses']);
    }

    /**
     * Save, translating a workstation double-booking into a clear message.
     *
     * Two taskers claiming the same PC for the same shift is a real mistake
     * worth catching -- somebody is at the wrong desk, and the PC utilisation
     * report would be wrong either way.
     */
    private function saveClaimingWorkstation(Attendance $record, CarbonImmutable $businessDate): void
    {
        if ($record->workstation_id !== null) {
            // Only an OPEN shift blocks the desk. Once the previous occupant
            // has timed out the machine is genuinely free, and refusing it
            // would leave a tasker standing at an empty seat the system
            // insists is taken.
            //
            // whereKeyNot excludes the tasker's own row, which is what makes
            // switching desks work: moving from PC-02 to PC-05 must not read
            // the mover's own record as the thing occupying PC-05.
            $conflict = Attendance::query()
                ->where('attendance_date', $businessDate->toDateString())
                ->where('workstation_id', $record->workstation_id)
                ->whereNull('time_out')
                ->when($record->exists, fn ($q) => $q->whereKeyNot($record->getKey()))
                ->with('user')
                ->first();

            if ($conflict !== null) {
                $name = $conflict->user?->name ?? 'another tasker';
                $pc = Workstation::find($record->workstation_id)?->name ?? 'that PC';

                throw new AttendanceException(
                    "{$pc} is already claimed by {$name} for this shift. Pick the PC you are actually using.",
                    'attendance.workstation_taken',
                    409,
                    ['workstation_id' => $record->workstation_id],
                );
            }
        }

        try {
            $record->save();
        } catch (UniqueConstraintViolationException) {
            throw AttendanceException::alreadyTimedIn($businessDate);
        }
    }

    /**
     * Replace the filed statuses with exactly what was submitted.
     *
     * @param  array<int, string>  $statuses
     */
    private function syncTaskingStatuses(Attendance $attendance, array $statuses): void
    {
        $valid = collect($statuses)
            ->map(fn (string $value) => TaskingStatus::tryFrom($value)?->value)
            ->filter()
            ->unique()
            ->values();

        AttendanceTaskingStatus::where('attendance_id', $attendance->id)->delete();

        foreach ($valid as $value) {
            AttendanceTaskingStatus::create([
                'attendance_id' => $attendance->id,
                'tasking_status' => $value,
            ]);
        }

        $attendance->setRelation(
            'taskingStatuses',
            AttendanceTaskingStatus::where('attendance_id', $attendance->id)->get(),
        );
    }

    // ---------------------------------------------------- Step 3: tracker entry

    /**
     * Record (or revise) the production declaration for the shift.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items  one block per project
     */
    public function submitTracker(
        User $user,
        array $data,
        array $items,
        ?CarbonInterface $at = null,
    ): TrackerEntry {
        $this->assertActive($user);

        $businessDate = $this->attendance->resolveBusinessDate($at);

        $entry = DB::transaction(function () use ($user, $data, $items, $businessDate): TrackerEntry {
            $attendance = Attendance::query()
                ->where('user_id', $user->id)
                ->where('attendance_date', $businessDate->toDateString())
                ->first();

            ['blocks' => $prepared, 'task_ids' => $totalIds, 'sbq' => $totalSbq]
                = $this->prepareItems($items);

            $entry = TrackerEntry::updateOrCreate(
                ['user_id' => $user->id, 'entry_date' => $businessDate->toDateString()],
                [
                    'attendance_id' => $attendance?->id,
                    'tenurity' => $data['tenurity'],
                    'site_id' => $data['site_id'] ?? $this->defaultSiteId(),
                    'support_team_id' => $data['support_team_id'] ?? null,
                    'task_id_count' => $totalIds,
                    'sbq_count' => $totalSbq,
                    // Derived from the clock, never submitted. Null until the
                    // tasker times out, then filled by syncDeclaredHours().
                    'declared_hours' => $this->renderedHoursFor($attendance),
                    'remarks' => $data['remarks'] ?? null,
                ],
            );

            // Replaced wholesale, so removing a project on a revision actually
            // removes it rather than leaving an orphan block behind.
            $entry->items()->delete();

            foreach ($prepared as $row) {
                $entry->items()->create($row);
            }

            return $entry;
        });

        $this->logger->log(
            'daily.tracker_submitted',
            "Submitted tracker entry for {$businessDate->toDateString()}",
            $entry,
            [
                'declared_hours' => $entry->declared_hours,
                'task_id_count' => $entry->task_id_count,
                'projects' => count($items),
            ],
            $user,
        );

        return $entry->load(['items.project', 'site', 'supportTeam', 'attendance']);
    }

    /**
     * The tasker's own production totals for tonight, this week and this month.
     *
     * All three come from one grouped query rather than three round trips.
     * Periods are measured in BUSINESS dates, so a shift that ran past midnight
     * on a Sunday counts against the week it started in -- the week a tasker
     * thinks of themselves as having worked.
     *
     * @return array<string, array<string, int|float|null>>
     */
    public function productionTotals(User $user, ?CarbonInterface $at = null): array
    {
        $today = $this->attendance->resolveBusinessDate($at);
        // The working week, not the calendar one: it begins on the first
        // rostered night rather than on a Monday nobody works.
        $weekStart = $this->attendance->startOfWorkWeek($today);
        $monthStart = $today->startOfMonth();

        $entries = TrackerEntry::query()
            ->where('user_id', $user->id)
            ->where('entry_date', '>=', $monthStart->toDateString())
            ->with('items')
            ->get();

        $window = function (CarbonImmutable $from) use ($entries, $today): array {
            $slice = $entries->filter(
                fn (TrackerEntry $e) => $e->entry_date->betweenIncluded($from, $today),
            );

            return [
                'days' => $slice->count(),
                'tasks' => (int) $slice->sum(fn (TrackerEntry $e) => $e->items->sum('total_tasks')),
                'task_ids' => (int) $slice->sum('task_id_count'),
                'sbq' => (int) $slice->sum('sbq_count'),
                'hours' => round((float) $slice->sum(fn (TrackerEntry $e) => $e->declared_hours ?? 0), 2),
            ];
        };

        return [
            'today' => $window($today),
            'week' => $window($weekStart),
            'month' => $window($monthStart),
            'week_starts' => $weekStart->toDateString(),
            'month_starts' => $monthStart->toDateString(),
        ];
    }

    /**
     * Hours rendered for a shift, taken from the clock.
     *
     * Total rendered hours are whatever time in to time out works out to, so
     * they are computed rather than asked for -- a typed figure could disagree
     * with the clock, and then neither number could be trusted. Null while the
     * shift is still open, because the answer is not known yet.
     */
    public function renderedHoursFor(?Attendance $attendance): ?float
    {
        return $attendance?->total_hours;
    }

    /**
     * Copy the final clocked hours onto the tracker entry.
     *
     * Called after a clock-out, since the tracker is usually submitted while
     * the shift is still running and its hours cannot be known until it ends.
     */
    public function syncDeclaredHours(Attendance $attendance): void
    {
        if ($attendance->total_hours === null) {
            return;
        }

        TrackerEntry::query()
            ->where('attendance_id', $attendance->id)
            ->update(['declared_hours' => $attendance->total_hours]);
    }

    /**
     * The site every entry defaults to.
     *
     * Operations runs from a single site, so asking the tasker to pick it every
     * night is a question with one answer. Resolved from the active sites
     * rather than hardcoded, so opening a second site is a data change.
     */
    public function defaultSiteId(): ?int
    {
        return \App\Models\Site::query()->active()->orderBy('id')->value('id');
    }

    private function blankToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return ($trimmed === '' || preg_match('/^n\/?a$/i', $trimmed)) ? null : $trimmed;
    }

    /**
     * Count task IDs in a comma-separated list, and how many are marked (SBQ).
     *
     * The submitted string is stored verbatim for support to validate against;
     * these counts are derived once at write time so reports never re-parse it.
     *
     * Handles the documented formats:
     *   TaskID1, TaskID2, TaskID3
     *   TaskID1 (SBQ), TaskID2 (SBQ)
     *   TaskID1 (SBQ), TaskID2, TaskID3 (SBQ)
     *
     * @return array{total: int, sbq: int}
     */
    /**
     * Turn submitted project blocks into rows, and roll their counts up.
     *
     * Extracted so the admin correction path writes blocks exactly the way the
     * tasker's own submission does. Two parsers for one shape is how a
     * corrected entry ends up counting its task IDs differently from the entry
     * it corrected.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{blocks: array<int, array<string, mixed>>, task_ids: int, sbq: int}
     */
    private function prepareItems(array $items): array
    {
        $blocks = [];
        $totalIds = 0;
        $totalSbq = 0;

        foreach ($items as $row) {
            $parsed = $this->parseTaskIds((string) ($row['task_ids'] ?? ''));
            $totalIds += $parsed['total'];
            $totalSbq += $parsed['sbq'];

            $blocks[] = [
                'project_id' => (int) $row['project_id'],
                'tasker_level' => $row['tasker_level'] ?? null,
                'total_tasks' => (int) ($row['total_tasks'] ?? 0),
                'task_ids' => $this->blankToNull($row['task_ids'] ?? null),
                'task_id_count' => $parsed['total'],
                'sbq_count' => $parsed['sbq'],
                'task_complexity' => $row['task_complexity'] ?? null,
                'screenshot_links' => $this->blankToNull($row['screenshot_links'] ?? null),
            ];
        }

        return ['blocks' => $blocks, 'task_ids' => $totalIds, 'sbq' => $totalSbq];
    }

    /**
     * Correct an existing entry, from the admin screens.
     *
     * Deliberately narrow. The tasker and the shift date are not touched: an
     * entry belongs to a person and a night, and "correcting" either would be
     * moving the record rather than fixing it -- delete and re-file is the
     * honest way to do that, and it leaves a trail.
     *
     * `declared_hours` is not touched either. It is derived from the clock, so
     * an admin who needs it changed corrects the attendance record and lets it
     * follow.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function reviseEntry(TrackerEntry $entry, array $data, array $items): TrackerEntry
    {
        return DB::transaction(function () use ($entry, $data, $items): TrackerEntry {
            ['blocks' => $blocks, 'task_ids' => $totalIds, 'sbq' => $totalSbq]
                = $this->prepareItems($items);

            $entry->forceFill([
                'tenurity' => $data['tenurity'],
                'site_id' => $data['site_id'] ?? $entry->site_id,
                'support_team_id' => $data['support_team_id'] ?? null,
                'task_id_count' => $totalIds,
                'sbq_count' => $totalSbq,
                'remarks' => $data['remarks'] ?? null,
            ])->save();

            // Replaced wholesale, for the same reason the tasker's own revision
            // replaces them: dropping a project has to actually drop it.
            $entry->items()->delete();

            foreach ($blocks as $row) {
                $entry->items()->create($row);
            }

            return $entry->load(['items.project', 'site', 'supportTeam', 'user']);
        });
    }

    public function parseTaskIds(string $raw): array
    {
        $parts = collect(explode(',', $raw))
            ->map(fn (string $part) => trim($part))
            ->filter(fn (string $part) => $part !== '' && strcasecmp($part, 'n/a') !== 0);

        return [
            'total' => $parts->count(),
            'sbq' => $parts->filter(fn (string $part) => (bool) preg_match('/\(\s*SBQ\s*\)/i', $part))->count(),
        ];
    }

    // ------------------------------------------------------------------ Queries

    /**
     * Everything the daily flow needs to render its current state.
     *
     * @return array<string, mixed>
     */
    public function currentState(User $user, ?CarbonInterface $at = null): array
    {
        $businessDate = $this->attendance->resolveBusinessDate($at);

        $attendance = Attendance::query()
            ->where('user_id', $user->id)
            ->where('attendance_date', $businessDate->toDateString())
            ->with(['workstation.site', 'taskingStatuses'])
            ->first();

        $tracker = TrackerEntry::query()
            ->where('user_id', $user->id)
            ->where('entry_date', $businessDate->toDateString())
            ->with(['items.project', 'site', 'supportTeam'])
            ->first();

        // The night was settled as non-attendance -- by attendance:mark-absent
        // at the cutoff, or by an admin. There is nothing left for the tasker
        // to file, and the flow says so rather than walking them through
        // activation, a PC claim, a tracker entry and a time out for a shift
        // they did not work. Filing a tracker entry for a night you were absent
        // is not a form to be completed; it is a contradiction.
        $settled = $attendance !== null && ! $attendance->status->isWorked();

        // No shift is rostered tonight. Distinct from `settled`, which is about
        // a night that WAS scheduled and has been closed as non-attendance --
        // the two need different words on screen, because "you were marked
        // absent" and "nobody is working tonight" are not the same news.
        $offDuty = ! $this->attendance->isWorkingDate($businessDate);

        // The one condition every step is gated on.
        $open = ! $settled && ! $offDuty;

        return [
            'business_date' => $businessDate->toDateString(),
            'attendance' => $attendance,
            'tracker' => $tracker,
            'settled' => $settled,
            'off_duty' => $offDuty,
            'steps' => [
                // Every step reads false once the night is closed -- settled as
                // non-attendance, or not rostered at all -- so a client that
                // predates these fields still renders an untouched flow rather
                // than a half-completed one.
                'activation' => $open && $attendance?->commitment_bracket !== null,
                'clocked_in' => $open && $attendance?->time_in !== null,
                'tracker' => $open && $tracker !== null,
                'clocked_out' => $open && $attendance?->time_out !== null,
            ],
            // Pre-fill the next entry from the last one, so a tasker who works
            // the same project at the same level is not re-picking identical
            // values every night.
            'last_entry' => $this->lastTrackerEntry($user, $businessDate),
        ];
    }

    /**
     * The most recent tracker entry before the current business date, used to
     * pre-fill the form.
     */
    private function lastTrackerEntry(User $user, CarbonImmutable $businessDate): ?TrackerEntry
    {
        return TrackerEntry::query()
            ->where('user_id', $user->id)
            ->where('entry_date', '<', $businessDate->toDateString())
            ->with('items.project')
            ->orderByDesc('entry_date')
            ->first();
    }

    /**
     * How long the PC picker may be stale.
     *
     * The claim list is IDENTICAL for every tasker -- it is global state about
     * which desks are taken tonight, not anything personal -- so one cached
     * copy serves the whole floor. At full scale that is the difference between
     * one query per interval and one query per tasker per poll.
     *
     * Ten seconds is chosen against the real-world race it guards: two people
     * sitting down at the same desk within the same ten seconds. That is
     * already impossible to prevent from the client, which is why the unique
     * claim is enforced in the transaction on activate() -- the second person
     * gets a clear "already claimed by X" either way. The cache only affects
     * how quickly the dropdown greys the machine out, and it is busted
     * immediately on every successful claim regardless.
     */
    private const WORKSTATION_CACHE_SECONDS = 10;

    /** Reference lists change when an admin edits them, which is close to never. */
    private const REFERENCE_CACHE_SECONDS = 300;

    public static function workstationCacheKey(string $businessDate): string
    {
        return "daily:workstations:{$businessDate}";
    }

    /**
     * Workstations available for a business date, each flagged with whether it
     * is already claimed and by whom.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function availableWorkstations(?CarbonInterface $at = null): \Illuminate\Support\Collection
    {
        $businessDate = $this->attendance->resolveBusinessDate($at);
        $date = $businessDate->toDateString();

        $rows = Cache::remember(
            self::workstationCacheKey($date),
            self::WORKSTATION_CACHE_SECONDS,
            fn (): array => $this->buildWorkstationList($date),
        );

        return collect($rows);
    }

    /**
     * The uncached build.
     *
     * The claim lookup is a join returning two scalar columns rather than
     * `->with('user')->get()`. The old form hydrated an Attendance model and a
     * User model for every person on shift just to read one name off each --
     * on a full floor that is tens of thousands of objects built and thrown
     * away on every request, which is both the memory and the time cost.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildWorkstationList(string $businessDate): array
    {
        /*
         * A desk is held by whoever is SITTING at it, not by everyone who sat
         * at it tonight.
         *
         * The time_out condition is the whole rule. Without it a machine stayed
         * claimed for the rest of the night the moment anyone touched it, so a
         * tasker arriving at 1 AM found a floor of occupied desks with nobody
         * in them. Two people using one PC across a night is normal -- somebody
         * leaves, somebody else sits down -- and the only thing that has to be
         * true is that they are not there at the same time.
         *
         * Ordered so the newest open claim wins if two ever overlap: pluck()
         * keeps the last value for a repeated key, which makes the current
         * occupant the one displayed rather than a stale earlier row.
         */
        $claimedBy = Attendance::query()
            ->join('users', 'users.id', '=', 'attendances.user_id')
            ->where('attendances.attendance_date', $businessDate)
            ->whereNotNull('attendances.workstation_id')
            ->whereNull('attendances.time_out')
            ->orderBy('attendances.time_in')
            ->pluck('users.name', 'attendances.workstation_id');

        /*
         * The shift that ended on each machine, most recent last.
         *
         * Ordered by time_out so a desk passed along twice reports the person
         * who left most recently rather than whoever happened to be first --
         * `keyBy` keeps the last row for a repeated key, which is what makes
         * the ordering do the selecting.
         */
        $previous = Attendance::query()
            ->join('users', 'users.id', '=', 'attendances.user_id')
            ->where('attendances.attendance_date', $businessDate)
            ->whereNotNull('attendances.workstation_id')
            ->whereNotNull('attendances.time_out')
            ->orderBy('attendances.time_out')
            ->get(['attendances.workstation_id', 'users.name', 'attendances.time_out'])
            ->keyBy('workstation_id')
            ->map(fn ($row): array => [
                'name' => $row->name,
                // ISO 8601 with offset, so the client renders a local time
                // without guessing the server's timezone -- the same contract
                // every other timestamp in this API follows.
                'time_out' => CarbonImmutable::parse($row->time_out)->toIso8601String(),
            ]);

        /*
         * Support machines are now INCLUDED, flagged rather than omitted.
         *
         * They used to be filtered out entirely, on the reasoning that a tasker
         * has no reason to know a machine exists if they can never use it. That
         * holds for a dropdown. It stops holding the moment the picker is drawn
         * as the room: a gap where PC-19 physically sits makes the map disagree
         * with the floor in front of you, and the natural reading of a hole
         * between 18 and 20 is that the map is broken, not that the desk is
         * spoken for. The operations floor plan itself draws them, labelled.
         *
         * They remain unselectable, and that is still enforced server-side on
         * activate() rather than by their absence from this list -- hiding a
         * machine was never the thing preventing it from being claimed.
         */
        return Workstation::query()
            ->active()
            ->with('site:id,name')
            ->inFloorOrder()
            ->orderBy('name')
            ->get()
            ->map(fn (Workstation $pc): array => [
                'id' => $pc->id,
                'name' => $pc->name,
                'site' => $pc->site?->name,
                'site_id' => $pc->site_id,
                'is_claimed' => $claimedBy->has($pc->id),
                'claimed_by' => $claimedBy->get($pc->id),

                // Who had the desk before the current occupant, and when they
                // left. Once two people can share a machine across a night,
                // "who is here" stops being the whole answer -- a desk showing
                // one name has a second story behind it, and an admin looking
                // at the floor needs both to make sense of the night.
                'previous_by' => $previous->get($pc->id)['name'] ?? null,
                'previous_time_out' => $previous->get($pc->id)['time_out'] ?? null,

                'is_support' => $pc->is_support,
                // Null until an admin places the machine. The map skips these;
                // the list view still shows them.
                'floor_block' => $pc->floor_block,
                'floor_row' => $pc->floor_row,
                'floor_col' => $pc->floor_col,
            ])
            ->all();
    }

    /**
     * Drop the cached picker so a claim is reflected immediately.
     *
     * Called on every successful activation. Without this the ten-second TTL
     * would be the floor on how long a just-taken desk still looks free.
     */
    public function forgetWorkstationCache(string $businessDate): void
    {
        Cache::forget(self::workstationCacheKey($businessDate));
    }

    /**
     * Active projects for the picker.
     *
     * Cached: this is a lookup table read by every tasker on every visit to the
     * tracker step, and it changes only when an admin edits it.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function activeProjects(): \Illuminate\Support\Collection
    {
        $rows = Cache::remember(
            'daily:projects',
            self::REFERENCE_CACHE_SECONDS,
            fn (): array => Project::query()
                ->active()
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Project $p): array => [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                ])
                ->all(),
        );

        return collect($rows);
    }

    // ------------------------------------------------------------------ Helpers

    private function assertActive(User $user): void
    {
        if (! $user->canAuthenticate()) {
            throw AttendanceException::accountNotActive();
        }
    }

}
