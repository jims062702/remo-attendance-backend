<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Site;
use App\Models\TrackerEntry;
use App\Models\TrackerItem;

/**
 * An administrator correcting a nightly submission.
 *
 * The rule that matters most here is the one about rules: corrections are
 * validated by the tasker's own request class, so the admin screen cannot file
 * an entry a tasker could not. A second rule set would make this a back door
 * around the checks -- and corrected entries are exactly the ones somebody
 * looks at later.
 */
beforeEach(function (): void {
    $this->admin = admin();
    $this->tasker = tasker(['name' => 'Juan Dela Cruz']);

    Site::create(['name' => 'BEAMO 3F C', 'is_active' => true]);
    $this->project = Project::create(['code' => 'sky_feather', 'is_active' => true]);
    $this->other = Project::create(['code' => 'ego_splits', 'is_active' => true]);

    $this->entry = TrackerEntry::create([
        'user_id' => $this->tasker->id,
        'entry_date' => '2026-07-28',
        'tenurity' => 'expert',
        'task_id_count' => 2,
        'sbq_count' => 1,
    ]);

    TrackerItem::create([
        'tracker_entry_id' => $this->entry->id,
        'project_id' => $this->project->id,
        'tasker_level' => 'l8',
        'total_tasks' => 2,
        'task_ids' => 'A1, A2 (SBQ)',
        'task_id_count' => 2,
        'sbq_count' => 1,
        'screenshot_links' => 'https://drive.example.com/a',
    ]);
});

/** @return array<string, mixed> */
function correction(array $items, array $overrides = []): array
{
    return array_merge([
        'tenurity' => 'expert',
        'items' => $items,
        'remarks' => 'Corrected after review.',
    ], $overrides);
}

it('corrects the blocks and recounts the entry', function (): void {
    $this->actingAs($this->admin)
        ->putJson("/api/admin/tracker-entries/{$this->entry->id}", correction([[
            'project_id' => $this->project->id,
            'tasker_level' => 'l8',
            'total_tasks' => 3,
            'task_ids' => 'A1, A2 (SBQ), A3 (SBQ)',
            'screenshot_links' => 'https://drive.example.com/a',
        ]]))
        ->assertOk();

    $entry = TrackerEntry::with('items')->firstOrFail();

    // The rolled-up counts follow the blocks rather than being trusted from
    // the request -- they are what the list views read.
    expect($entry->task_id_count)->toBe(3)
        ->and($entry->sbq_count)->toBe(2)
        ->and($entry->remarks)->toBe('Corrected after review.')
        ->and($entry->items)->toHaveCount(1)
        ->and($entry->items->first()->total_tasks)->toBe(3);
});

it('replaces the blocks wholesale so a removed project actually goes', function (): void {
    $this->actingAs($this->admin)
        ->putJson("/api/admin/tracker-entries/{$this->entry->id}", correction([[
            'project_id' => $this->other->id,
            'tasker_level' => 'l8',
            'total_tasks' => 1,
            'task_ids' => 'B1',
            'screenshot_links' => 'https://drive.example.com/b',
        ]]))
        ->assertOk();

    $items = TrackerItem::all();

    expect($items)->toHaveCount(1)
        ->and($items->first()->project_id)->toBe($this->other->id);
});

it('repoints a block at a different project', function (): void {
    // The correction an admin most often needs: the right work filed under the
    // wrong project.
    $this->actingAs($this->admin)
        ->putJson("/api/admin/tracker-entries/{$this->entry->id}", correction([[
            'project_id' => $this->other->id,
            'tasker_level' => 'l8',
            'total_tasks' => 2,
            'task_ids' => 'A1, A2 (SBQ)',
            'screenshot_links' => 'https://drive.example.com/a',
        ]]))
        ->assertOk();

    $item = TrackerItem::firstOrFail();

    expect($item->project_id)->toBe($this->other->id)
        // The work itself is untouched; only where it was filed changed.
        ->and($item->total_tasks)->toBe(2)
        ->and($item->task_id_count)->toBe(2)
        ->and($item->sbq_count)->toBe(1);
});

it('refuses the same project twice in one submission', function (): void {
    $this->actingAs($this->admin)
        ->putJson("/api/admin/tracker-entries/{$this->entry->id}", correction([
            [
                'project_id' => $this->project->id,
                'tasker_level' => 'l8',
                'total_tasks' => 1,
                'task_ids' => 'A1',
                'screenshot_links' => 'https://drive.example.com/a',
            ],
            [
                'project_id' => $this->project->id,
                'tasker_level' => 'l8',
                'total_tasks' => 1,
                'task_ids' => 'B1',
                'screenshot_links' => 'https://drive.example.com/b',
            ],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.project_id');
});

it('holds a correction to the same rules a tasker submits under', function (): void {
    // Three declared, two listed. If this passed, the admin screen would be a
    // way round the check the tasker cannot get past.
    $this->actingAs($this->admin)
        ->putJson("/api/admin/tracker-entries/{$this->entry->id}", correction([[
            'project_id' => $this->project->id,
            'tasker_level' => 'l8',
            'total_tasks' => 3,
            'task_ids' => 'A1, A2',
            'screenshot_links' => 'https://drive.example.com/a',
        ]]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.task_ids');

    // And a missing screenshot is still a missing screenshot.
    $this->actingAs($this->admin)
        ->putJson("/api/admin/tracker-entries/{$this->entry->id}", correction([[
            'project_id' => $this->project->id,
            'tasker_level' => 'l8',
            'total_tasks' => 1,
            'task_ids' => 'A1',
        ]]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.screenshot_links');

    // Untouched throughout.
    expect(TrackerEntry::firstOrFail()->task_id_count)->toBe(2);
});

it('never moves the entry to another tasker or another night', function (): void {
    $other = tasker(['email' => 'someone.else@test.local']);

    $this->actingAs($this->admin)
        ->putJson("/api/admin/tracker-entries/{$this->entry->id}", correction([[
            'project_id' => $this->project->id,
            'tasker_level' => 'l8',
            'total_tasks' => 1,
            'task_ids' => 'A1',
            'screenshot_links' => 'https://drive.example.com/a',
        ]], [
            // Both ignored: an entry belongs to a person and a night, and
            // changing either is moving the record rather than fixing it.
            'user_id' => $other->id,
            'entry_date' => '2026-07-29',
        ]))
        ->assertOk();

    $entry = TrackerEntry::firstOrFail();

    expect($entry->user_id)->toBe($this->tasker->id)
        ->and($entry->entry_date->toDateString())->toBe('2026-07-28');
});

it('records what changed in the audit trail', function (): void {
    $this->actingAs($this->admin)
        ->putJson("/api/admin/tracker-entries/{$this->entry->id}", correction([[
            'project_id' => $this->project->id,
            'tasker_level' => 'l8',
            'total_tasks' => 5,
            'task_ids' => 'A1, A2, A3, A4, A5',
            'screenshot_links' => 'https://drive.example.com/a',
        ]]))
        ->assertOk();

    $log = App\Models\ActivityLog::where('action', 'tracker.corrected')->firstOrFail();

    expect($log->description)->toContain('2026-07-28')
        ->and($log->metadata)->not->toBeNull();
});

it('refuses a tasker the correction endpoint', function (): void {
    $this->actingAs($this->tasker)
        ->putJson("/api/admin/tracker-entries/{$this->entry->id}", correction([[
            'project_id' => $this->project->id,
            'tasker_level' => 'l8',
            'total_tasks' => 9,
            'task_ids' => 'X1, X2, X3, X4, X5, X6, X7, X8, X9',
            'screenshot_links' => 'https://drive.example.com/x',
        ]]))
        ->assertForbidden();

    expect(TrackerEntry::firstOrFail()->task_id_count)->toBe(2);
});
