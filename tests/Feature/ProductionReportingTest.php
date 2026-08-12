<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Site;
use App\Models\Workstation;
use App\Services\AttendanceService;

/**
 * Production filed through the nightly flow has to reach the numbers.
 *
 * The bug these cover: a tasker completed the whole nightly flow -- attendance,
 * PC, tracker entry with six tasks -- and both the admin dashboard and the
 * tasker's own history reported zero submissions and zero output. Every
 * production figure was read from the `tasks` table, the separate and optional
 * "Extra Tasks" page, so the tracker entry was never counted anywhere.
 *
 * Nothing about that was visible from the UI: the dashboard was not broken, it
 * was faithfully reporting a number nobody was writing to.
 */
beforeEach(function (): void {
    $this->admin = admin();
    $this->tasker = tasker(['name' => 'Juan Dela Cruz']);

    $this->site = Site::create(['name' => 'BEAMO 3F', 'is_active' => true]);
    $this->pc = Workstation::create([
        'name' => 'PC-01', 'site_id' => $this->site->id, 'is_active' => true, 'is_support' => false,
    ]);
    $this->project = Project::create(['code' => 'aloha', 'is_active' => true]);

    $this->businessDate = app(AttendanceService::class)->resolveBusinessDate()->toDateString();
});

/** Complete the nightly flow: file attendance, then declare production. */
/*
 * Task IDs are generated to match the declared total, because the tracker
 * now requires one ID per task -- a fixture that declared six and listed two
 * would be testing the validation rather than the reporting.
 */
function fileNight(int $tasks, int $pcId, int $projectId, ?array $taskIds = null): void
{
    $taskIds ??= array_map(fn (int $n): string => "T{$n}", range(1, max($tasks, 1)));

    test()->postJson('/api/daily/activate', [
        'commitment_bracket' => '7_plus_hours',
        'tasking_statuses' => ['tasking'],
        'workstation_id' => $pcId,
        'pc_status' => 'used',
    ])->assertCreated();

    test()->postJson('/api/daily/tracker', [
        'tenurity' => 'trained',
        'items' => [[
            'project_id' => $projectId,
            'tasker_level' => 'l8',
            'total_tasks' => $tasks,
            'task_ids' => implode(', ', $taskIds),
            'screenshot_links' => 'https://drive.example.com/night',
        ]],
    ])->assertCreated();
}

it('counts a nightly tracker entry on the admin dashboard', function (): void {
    $this->actingAs($this->tasker);
    fileNight(6, $this->pc->id, $this->project->id);

    $response = $this->actingAs($this->admin)->getJson('/api/admin/dashboard')->assertOk();

    // This is the exact assertion that would have caught the reported bug:
    // one entry filed, six tasks declared, and the dashboard said zero.
    expect($response->json('data.summary.submissions_today'))->toBe(1)
        ->and($response->json('data.summary.output_today'))->toBe(6)
        ->and($response->json('data.summary.task_ids_today'))->toBe(6);
});

it('sums production across several taskers on the dashboard', function (): void {
    $second = tasker();
    $otherPc = Workstation::create([
        'name' => 'PC-02', 'site_id' => $this->site->id, 'is_active' => true, 'is_support' => false,
    ]);

    $this->actingAs($this->tasker);
    fileNight(6, $this->pc->id, $this->project->id);

    $this->actingAs($second);
    fileNight(9, $otherPc->id, $this->project->id);

    $response = $this->actingAs($this->admin)->getJson('/api/admin/dashboard')->assertOk();

    expect($response->json('data.summary.submissions_today'))->toBe(2)
        ->and($response->json('data.summary.output_today'))->toBe(15)
        ->and($response->json('data.summary.task_ids_today'))->toBe(15);
});

it('keeps extra tasks separate from nightly production', function (): void {
    $this->actingAs($this->tasker);
    fileNight(6, $this->pc->id, $this->project->id);

    // An Extra Task is a different thing and must not be added into the
    // production totals, or one submission would count twice.
    $this->postJson('/api/tasks', [
        'task_name' => 'Ad-hoc cleanup',
        'output_count' => 40,
        'task_status' => 'completed',
    ])->assertCreated();

    $response = $this->actingAs($this->admin)->getJson('/api/admin/dashboard')->assertOk();

    expect($response->json('data.summary.output_today'))->toBe(6)
        ->and($response->json('data.summary.extra_tasks_today'))->toBe(1)
        ->and($response->json('data.summary.extra_output_today'))->toBe(40);
});

it('reports nightly production on the tasker’s own summary', function (): void {
    $this->actingAs($this->tasker);
    fileNight(6, $this->pc->id, $this->project->id);

    $response = $this->getJson('/api/attendance/summary')->assertOk();

    expect($response->json('data.production.total_tasks'))->toBe(6)
        ->and($response->json('data.production.nights_filed'))->toBe(1)
        ->and($response->json('data.production.task_ids'))->toBe(6);
});

it('ranks taskers by nightly production on the summary report', function (): void {
    /*
     * The third place this was wrong. The report read `tasks` -- the optional
     * Extra Tasks screen -- for its output column, so a floor of nineteen
     * showed a total output of 1 and the chart ranked the single person who
     * had used that page. Everyone who filed through the nightly flow, which
     * is everyone, sat at zero.
     */
    $second = tasker(['name' => 'Ana Reyes']);
    $otherPc = App\Models\Workstation::create([
        'name' => 'PC-09 3F C', 'site_id' => $this->site->id, 'is_active' => true,
    ]);

    $this->actingAs($this->tasker);
    fileNight(6, $this->pc->id, $this->project->id);

    $this->actingAs($second);
    fileNight(9, $otherPc->id, $this->project->id);

    $rows = collect(
        $this->actingAs($this->admin)
            ->getJson("/api/admin/reports/tasker-summary?from={$this->businessDate}&to={$this->businessDate}")
            ->assertOk()
            ->json('data'),
    )->keyBy('name');

    expect($rows)->toHaveCount(2)
        ->and($rows[$this->tasker->name]['total_output'])->toBe(6)
        ->and($rows[$this->tasker->name]['nights_filed'])->toBe(1)
        ->and($rows[$this->tasker->name]['task_ids'])->toBe(6)
        ->and($rows['Ana Reyes']['total_output'])->toBe(9)
        // And the Extra Tasks page is reported separately, still empty.
        ->and($rows['Ana Reyes']['extra_tasks'])->toBe(0)
        ->and($rows['Ana Reyes']['extra_output'])->toBe(0);
});

it('reports nightly production on the admin tasker detail', function (): void {
    $this->actingAs($this->tasker);
    fileNight(6, $this->pc->id, $this->project->id);

    $this->actingAs($this->admin)
        ->getJson("/api/admin/taskers/{$this->tasker->id}/summary")
        ->assertOk()
        ->assertJsonPath('data.summary.production.total_tasks', 6)
        ->assertJsonPath('data.summary.production.nights_filed', 1);
});

it('leaves production at zero before anything is filed', function (): void {
    $response = $this->actingAs($this->admin)->getJson('/api/admin/dashboard')->assertOk();

    expect($response->json('data.summary.submissions_today'))->toBe(0)
        ->and($response->json('data.summary.output_today'))->toBe(0);
});

it('does not count another night’s production as tonight’s', function (): void {
    $this->actingAs($this->tasker);
    fileNight(6, $this->pc->id, $this->project->id);

    // Move past the business-day rollover into the next shift.
    $this->travel(1)->days();

    $response = $this->actingAs($this->admin)->getJson('/api/admin/dashboard')->assertOk();

    expect($response->json('data.summary.output_today'))->toBe(0);
});
