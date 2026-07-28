<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Project;
use App\Models\Site;
use App\Models\SupportTeam;
use App\Models\TrackerEntry;
use App\Models\Workstation;
use App\Services\DailyFlowService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

beforeEach(function (): void {
    $this->tasker = tasker();

    $this->site = Site::create(['name' => 'BEAMO 3F C', 'is_active' => true]);
    $this->pc = Workstation::create(['name' => 'PC-01', 'site_id' => $this->site->id, 'is_active' => true]);
    $this->otherPc = Workstation::create(['name' => 'PC-02', 'site_id' => $this->site->id, 'is_active' => true]);
    $this->project = Project::create(['code' => 'aloha_data_collection_v1', 'is_active' => true]);
    $this->project2 = Project::create(['code' => 'sky_feather', 'is_active' => true]);
    $this->support = SupportTeam::create(['name' => 'Support A', 'is_active' => true]);

    // Inside the shift window, so "now" resolves to a known business date.
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 22:05'));
});

afterEach(function (): void {
    Date::setTestNow();
});

/** @return array<string, mixed> */
function activationPayload(int $pcId, array $overrides = []): array
{
    return array_merge([
        'commitment_bracket' => '7_plus_hours',
        'tasking_statuses' => ['tasking'],
        'workstation_id' => $pcId,
        'pc_status' => 'used',
    ], $overrides);
}

/**
 * One block per project. Each carries its own task IDs, complexity and
 * screenshot, which is the whole point of the item structure.
 *
 * @return array<string, mixed>
 */
function trackerPayload(int $projectId, array $overrides = []): array
{
    return array_merge([
        'tenurity' => 'expert',
        'items' => [[
            'project_id' => $projectId,
            'tasker_level' => 'l8',
            'total_tasks' => 42,
            'task_ids' => 'TASK1, TASK2 (SBQ), TASK3',
            'task_complexity' => 'mid_scene_frames',
            'screenshot_links' => 'https://drive.example.com/a',
        ]],
        'remarks' => 'N/A',
    ], $overrides);
}

// ------------------------------------------------------- Step 1: activation

it('files an activation and clocks in on server time', function (): void {
    $response = $this->actingAs($this->tasker)
        ->postJson('/api/daily/activate', activationPayload($this->pc->id));

    $response->assertCreated()
        ->assertJsonPath('data.attendance_date', '2026-07-26')
        ->assertJsonPath('data.status', 'present')
        ->assertJsonPath('data.is_open', true);

    $attendance = Attendance::firstOrFail();

    expect($attendance->time_in->format('H:i'))->toBe('22:05')
        ->and($attendance->workstation_id)->toBe($this->pc->id)
        // The bracket carries a representative hours figure so the existing
        // variance reporting keeps working.
        ->and($attendance->expected_hours)->toBe(7.0);
});

it('records several tasking statuses at once', function (): void {
    $this->actingAs($this->tasker)->postJson(
        '/api/daily/activate',
        activationPayload($this->pc->id, [
            'tasking_statuses' => ['tasking', 'training', 'bm_linter_issues'],
        ]),
    )->assertCreated();

    expect(Attendance::firstOrFail()->taskingStatuses)->toHaveCount(3);
});

it('requires at least one tasking status', function (): void {
    $this->actingAs($this->tasker)->postJson(
        '/api/daily/activate',
        activationPayload($this->pc->id, ['tasking_statuses' => []]),
    )->assertStatus(422)->assertJsonValidationErrors('tasking_statuses');
});

it('requires a PC when the tasker is committing to work', function (): void {
    $this->actingAs($this->tasker)->postJson(
        '/api/daily/activate',
        ['commitment_bracket' => '4_6_hours', 'tasking_statuses' => ['tasking']],
    )->assertStatus(422)->assertJsonValidationErrors('workstation_id');
});

it('files a support-entry absence without a PC or a clock-in', function (): void {
    $this->actingAs($this->tasker)->postJson('/api/daily/activate', [
        'commitment_bracket' => 'absent_support_entry',
        'tasking_statuses' => ['absent_account_issue'],
    ])->assertCreated();

    $attendance = Attendance::firstOrFail();

    expect($attendance->status)->toBe(AttendanceStatus::Absent)
        // A declared absence is not a shift: no clock, and no expected hours
        // to drag down the averages.
        ->and($attendance->time_in)->toBeNull()
        ->and($attendance->expected_hours)->toBeNull();
});

it('refuses a PC already claimed by someone else for the same shift', function (): void {
    $other = tasker(['name' => 'Ana Reyes']);

    $this->actingAs($other)
        ->postJson('/api/daily/activate', activationPayload($this->pc->id))
        ->assertCreated();

    $this->actingAs($this->tasker)
        ->postJson('/api/daily/activate', activationPayload($this->pc->id))
        ->assertStatus(409)
        ->assertJsonPath('code', 'attendance.workstation_taken');
});

it('lets a tasker correct their own activation without clocking in twice', function (): void {
    $this->actingAs($this->tasker)
        ->postJson('/api/daily/activate', activationPayload($this->pc->id))
        ->assertCreated();

    Date::setTestNow(CarbonImmutable::parse('2026-07-26 22:20'));

    // Picked the wrong desk; re-filing moves the claim.
    $this->actingAs($this->tasker)
        ->postJson('/api/daily/activate', activationPayload($this->otherPc->id, [
            'commitment_bracket' => '4_6_hours',
        ]))
        ->assertCreated();

    $attendance = Attendance::firstOrFail();

    expect(Attendance::count())->toBe(1)
        ->and($attendance->workstation_id)->toBe($this->otherPc->id)
        // The original clock-in time stands -- re-filing is not a new shift.
        ->and($attendance->time_in->format('H:i'))->toBe('22:05');
});

// ---------------------------------------------------- Step 3: tracker entry

it('submits a tracker entry linked to the shift', function (): void {
    $this->actingAs($this->tasker)->postJson('/api/daily/activate', activationPayload($this->pc->id));

    $response = $this->actingAs($this->tasker)->postJson(
        '/api/daily/tracker',
        trackerPayload($this->project->id, [
            'site_id' => $this->site->id,
            'support_team_id' => $this->support->id,
        ]),
    );

    $response->assertCreated()
        ->assertJsonPath('data.entry_date', '2026-07-26')
        // Rendered hours come from the clock, so they are unknown while the
        // shift is still running.
        ->assertJsonPath('data.declared_hours', null)
        ->assertJsonPath('data.total_tasks', 42);

    expect(TrackerEntry::firstOrFail()->attendance_id)->toBe(Attendance::firstOrFail()->id);
});

it('counts task IDs and SBQ markers from the submitted list', function (): void {
    $this->actingAs($this->tasker)->postJson('/api/daily/activate', activationPayload($this->pc->id));

    $this->actingAs($this->tasker)->postJson('/api/daily/tracker', trackerPayload($this->project->id, [
        'items' => [[
            'project_id' => $this->project->id,
            'tasker_level' => 'l8',
            'total_tasks' => 4,
            'task_ids' => 'T1 (SBQ), T2, T3 (SBQ), T4',
        ]],
    ]))->assertCreated();

    $entry = TrackerEntry::with('items')->firstOrFail();
    $item = $entry->items->first();

    expect($item->task_id_count)->toBe(4)
        ->and($item->sbq_count)->toBe(2)
        // Stored verbatim, because support validates against what was typed.
        ->and($item->task_ids)->toBe('T1 (SBQ), T2, T3 (SBQ), T4')
        // Rolled up onto the entry so list views need no joins.
        ->and($entry->task_id_count)->toBe(4)
        ->and($entry->sbq_count)->toBe(2);
});

it('keeps task IDs, complexity and screenshots separate per project', function (): void {
    // The case that drove the item structure: aloha and ego on the same night,
    // each with their own IDs and their own screenshot.
    $this->actingAs($this->tasker)->postJson('/api/daily/activate', activationPayload($this->pc->id));

    $this->actingAs($this->tasker)->postJson('/api/daily/tracker', trackerPayload($this->project->id, [
        'items' => [
            [
                'project_id' => $this->project->id,
                'tasker_level' => 'l8',
                'total_tasks' => 30,
                'task_ids' => 'A1, A2, A3',
                'task_complexity' => 'short_frame',
                'screenshot_links' => 'https://drive.example.com/aloha',
            ],
            [
                'project_id' => $this->project2->id,
                // A different level on the same night is exactly what a single
                // entry-level field could not express.
                'tasker_level' => 'attempter',
                'total_tasks' => 12,
                'task_ids' => 'E1, E2',
                'task_complexity' => 'super_dense_8k',
                'screenshot_links' => 'https://drive.example.com/ego',
            ],
        ],
    ]))->assertCreated();

    $entry = TrackerEntry::with('items')->firstOrFail();
    $aloha = $entry->items->firstWhere('project_id', $this->project->id);
    $ego = $entry->items->firstWhere('project_id', $this->project2->id);

    expect($entry->items)->toHaveCount(2)
        ->and($entry->totalTasks())->toBe(42)
        ->and($aloha->tasker_level->value)->toBe('l8')
        ->and($ego->tasker_level->value)->toBe('attempter')
        ->and($aloha->task_ids)->toBe('A1, A2, A3')
        ->and($aloha->task_complexity->value)->toBe('short_frame')
        ->and($aloha->screenshot_links)->toBe('https://drive.example.com/aloha')
        ->and($ego->task_ids)->toBe('E1, E2')
        // A different complexity on the same night is exactly what a single
        // shared field could not express.
        ->and($ego->task_complexity->value)->toBe('super_dense_8k')
        ->and($ego->screenshot_links)->toBe('https://drive.example.com/ego')
        // Counts roll up across both blocks.
        ->and($entry->task_id_count)->toBe(5);
});

it('rejects the same project listed twice', function (): void {
    $this->actingAs($this->tasker)->postJson('/api/daily/activate', activationPayload($this->pc->id));

    $this->actingAs($this->tasker)->postJson('/api/daily/tracker', trackerPayload($this->project->id, [
        'items' => [
            ['project_id' => $this->project->id, 'tasker_level' => 'l8', 'total_tasks' => 10],
            ['project_id' => $this->project->id, 'tasker_level' => 'l8', 'total_tasks' => 20],
        ],
    ]))->assertStatus(422)->assertJsonValidationErrors('items.0.project_id');
});

it('requires at least one project block', function (): void {
    $this->actingAs($this->tasker)->postJson('/api/daily/activate', activationPayload($this->pc->id));

    $this->actingAs($this->tasker)->postJson('/api/daily/tracker', [
        'tenurity' => 'newbie',
        'items' => [],
    ])->assertStatus(422)->assertJsonValidationErrors('items');
});

it('normalises "N/A" free-text fields to null', function (): void {
    $this->actingAs($this->tasker)->postJson('/api/daily/activate', activationPayload($this->pc->id));

    $this->actingAs($this->tasker)->postJson('/api/daily/tracker', trackerPayload($this->project->id, [
        'items' => [[
            'project_id' => $this->project->id,
            'tasker_level' => 'l0',
            'total_tasks' => 0,
            'task_ids' => 'N/A',
            'screenshot_links' => 'n/a',
        ]],
        'remarks' => '  ',
    ]))->assertCreated();

    $entry = TrackerEntry::with('items')->firstOrFail();
    $item = $entry->items->first();

    expect($item->task_ids)->toBeNull()
        ->and($item->screenshot_links)->toBeNull()
        ->and($entry->remarks)->toBeNull()
        ->and($entry->task_id_count)->toBe(0);
});

it('revises the same day rather than creating a second entry', function (): void {
    $this->actingAs($this->tasker)->postJson('/api/daily/activate', activationPayload($this->pc->id));

    $this->actingAs($this->tasker)
        ->postJson('/api/daily/tracker', trackerPayload($this->project->id))
        ->assertCreated();

    $this->actingAs($this->tasker)
        ->postJson('/api/daily/tracker', trackerPayload($this->project->id, [
            'items' => [['project_id' => $this->project->id, 'tasker_level' => 'l8', 'total_tasks' => 99]],
        ]))
        ->assertCreated();

    expect(TrackerEntry::count())->toBe(1)
        ->and(TrackerEntry::with('items')->firstOrFail()->totalTasks())->toBe(99);
});

it('fills the tracker hours from the clock when the tasker times out', function (): void {
    // Total rendered hours are whatever time in to time out works out to, so
    // the tracker never asks for them -- it is filled in on clock-out.
    $this->actingAs($this->tasker)->postJson('/api/daily/activate', activationPayload($this->pc->id));

    $this->actingAs($this->tasker)
        ->postJson('/api/daily/tracker', trackerPayload($this->project->id))
        ->assertCreated();

    // Unknown while the shift is open.
    expect(TrackerEntry::firstOrFail()->declared_hours)->toBeNull();

    Date::setTestNow(CarbonImmutable::parse('2026-07-27 06:05'));
    $this->actingAs($this->tasker)->postJson('/api/attendance/time-out')->assertOk();

    $entry = TrackerEntry::with('attendance')->firstOrFail();

    expect($entry->attendance->total_hours)->toBe(8.0)
        ->and($entry->declared_hours)->toBe(8.0)
        ->and($entry->hoursGap())->toBe(0.0);
});

it('reports production totals for tonight, this week and this month', function (): void {
    $this->actingAs($this->tasker)->postJson('/api/daily/activate', activationPayload($this->pc->id));
    $this->actingAs($this->tasker)->postJson('/api/daily/tracker', trackerPayload($this->project->id, [
        'items' => [[
            'project_id' => $this->project->id,
            'tasker_level' => 'l8',
            'total_tasks' => 25,
            'task_ids' => 'A, B (SBQ)',
        ]],
    ]))->assertCreated();

    $response = $this->actingAs($this->tasker)->getJson('/api/daily/state')->assertOk();

    expect($response->json('data.totals.today.tasks'))->toBe(25)
        ->and($response->json('data.totals.today.task_ids'))->toBe(2)
        ->and($response->json('data.totals.today.sbq'))->toBe(1)
        // Tonight is inside both windows.
        ->and($response->json('data.totals.week.tasks'))->toBe(25)
        ->and($response->json('data.totals.month.tasks'))->toBe(25);
});

// -------------------------------------------------------------------- State

it('reports which steps of the flow are done', function (): void {
    $before = $this->actingAs($this->tasker)->getJson('/api/daily/state')->assertOk();

    expect($before->json('data.steps'))->toBe([
        'activation' => false, 'clocked_in' => false, 'tracker' => false, 'clocked_out' => false,
    ]);

    $this->actingAs($this->tasker)->postJson('/api/daily/activate', activationPayload($this->pc->id));
    $this->actingAs($this->tasker)->postJson('/api/daily/tracker', trackerPayload($this->project->id));

    $after = $this->actingAs($this->tasker)->getJson('/api/daily/state')->assertOk();

    expect($after->json('data.steps'))->toBe([
        'activation' => true, 'clocked_in' => true, 'tracker' => true, 'clocked_out' => false,
    ]);
});

it('flags which PCs are already claimed tonight', function (): void {
    $other = tasker();
    $this->actingAs($other)->postJson('/api/daily/activate', activationPayload($this->pc->id));

    $response = $this->actingAs($this->tasker)->getJson('/api/daily/workstations')->assertOk();

    $claimed = collect($response->json('data'))->firstWhere('id', $this->pc->id);
    $free = collect($response->json('data'))->firstWhere('id', $this->otherPc->id);

    expect($claimed['is_claimed'])->toBeTrue()
        ->and($claimed['claimed_by'])->toBe($other->name)
        ->and($free['is_claimed'])->toBeFalse();
});

it('does not serve a stale picker after a PC is claimed', function (): void {
    // The picker is cached, because it is identical for every tasker and is
    // otherwise rebuilt from the whole floor's attendance on every request.
    // Reading it before the claim is what puts the pre-claim answer in the
    // cache, so this fails if activate() does not invalidate it.
    $this->actingAs($this->tasker)->getJson('/api/daily/workstations')->assertOk();

    $other = tasker();
    $this->actingAs($other)->postJson('/api/daily/activate', activationPayload($this->pc->id));

    $response = $this->actingAs($this->tasker)->getJson('/api/daily/workstations')->assertOk();
    $claimed = collect($response->json('data'))->firstWhere('id', $this->pc->id);

    expect($claimed['is_claimed'])->toBeTrue()
        ->and($claimed['claimed_by'])->toBe($other->name);
});

it('keeps the reference lists free of live claim state', function (): void {
    // Static lookups and live floor state are fetched on very different
    // schedules; bundling them is what made every tasker re-read the whole
    // floor's claims all night.
    $response = $this->actingAs($this->tasker)->getJson('/api/daily/options')->assertOk();

    expect($response->json('data'))->not->toHaveKey('workstations')
        ->and($response->json('data.projects'))->toBeArray()
        ->and($response->json('data.sites'))->toBeArray()
        ->and($response->json('data.support_teams'))->toBeArray();
});

it('scopes tracker history to the authenticated tasker', function (): void {
    $other = tasker();

    foreach ([$this->tasker, $other] as $user) {
        $this->actingAs($user)->postJson('/api/daily/activate', activationPayload(
            $user->is($this->tasker) ? $this->pc->id : $this->otherPc->id,
        ));
        $this->actingAs($user)->postJson('/api/daily/tracker', trackerPayload($this->project->id));
    }

    $response = $this->actingAs($this->tasker)->getJson('/api/daily/tracker/history')->assertOk();

    expect($response->json('meta.pagination.total'))->toBe(1)
        ->and($response->json('data.0.user_id'))->toBe($this->tasker->id);
});

it('parses task id lists correctly', function (string $raw, int $total, int $sbq): void {
    expect(app(DailyFlowService::class)->parseTaskIds($raw))->toBe(['total' => $total, 'sbq' => $sbq]);
})->with([
    ['T1, T2, T3, T4', 4, 0],
    ['T1 (SBQ), T2 (SBQ)', 2, 2],
    ['T1 (SBQ), T2, T3, T4 (SBQ)', 4, 2],
    ['T1', 1, 0],
    ['', 0, 0],
    ['N/A', 0, 0],
    ['T1,  , T2', 2, 0],
]);

// ------------------------------------------------------------- Support PCs

it('marks support PCs as support in the tasker picker', function (): void {
    $supportPc = Workstation::create([
        'name' => 'PC-SUPPORT',
        'site_id' => $this->site->id,
        'is_active' => true,
        'is_support' => true,
    ]);

    $response = $this->actingAs($this->tasker)->getJson('/api/daily/workstations')->assertOk();

    $rows = collect($response->json('data'));

    /*
     * Present and flagged, not omitted.
     *
     * These used to be filtered out of the payload entirely. That was right
     * for a dropdown and wrong for a floor map: leaving a hole where a machine
     * physically sits makes the map disagree with the room, and reads as a bug
     * rather than as "that desk is support". Not being claimable is enforced
     * on activate(), which is the assertion directly below this one.
     */
    expect($rows->firstWhere('id', $supportPc->id)['is_support'])->toBeTrue()
        ->and($rows->firstWhere('id', $this->pc->id)['is_support'])->toBeFalse();
});

it('exposes floor-plan positions so the picker can be drawn as the room', function (): void {
    $this->pc->update(['floor_block' => 3, 'floor_row' => 2, 'floor_col' => 4]);

    $response = $this->actingAs($this->tasker)->getJson('/api/daily/workstations')->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $this->pc->id);

    expect($row['floor_block'])->toBe(3)
        ->and($row['floor_row'])->toBe(2)
        ->and($row['floor_col'])->toBe(4);
});

it('still lists a machine that has never been placed on the plan', function (): void {
    // An unplaced machine is a normal state, not an error. It drops off the
    // map but must stay selectable in the list, or adding a PC would make it
    // unclaimable until somebody positioned it.
    $unplaced = Workstation::create([
        'name' => 'PC-NEW',
        'site_id' => $this->site->id,
        'is_active' => true,
        'is_support' => false,
    ]);

    $response = $this->actingAs($this->tasker)->getJson('/api/daily/workstations')->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $unplaced->id);

    expect($row)->not->toBeNull()
        ->and($row['floor_block'])->toBeNull();

    $this->actingAs($this->tasker)
        ->postJson('/api/daily/activate', activationPayload($unplaced->id))
        ->assertCreated();
});

it('refuses a support PC even when its id is posted directly', function (): void {
    $supportPc = Workstation::create([
        'name' => 'PC-SUPPORT',
        'site_id' => $this->site->id,
        'is_active' => true,
        'is_support' => true,
    ]);

    // Hiding it in the UI is not the enforcement point.
    $this->actingAs($this->tasker)
        ->postJson('/api/daily/activate', activationPayload($supportPc->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('workstation_id');
});
