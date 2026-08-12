<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Site;
use App\Models\Workstation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * The admin's view of the floor.
 *
 * Deliberately the same payload the tasker picker reads, from the same service.
 * An administrator asking "which desks are free" and a tasker asking "where do
 * I sit" are reading the same room, and two answers to that would be one answer
 * too many.
 */
beforeEach(function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-28 22:05'));

    $this->admin = admin();
    $this->site = Site::create(['name' => 'BEAMO 3F C', 'is_active' => true]);

    $this->pc = Workstation::create([
        'name' => 'PC-06 3F C', 'site_id' => $this->site->id, 'is_active' => true,
        'floor_block' => 1, 'floor_row' => 1, 'floor_col' => 1,
    ]);
    $this->free = Workstation::create([
        'name' => 'PC-07 3F C', 'site_id' => $this->site->id, 'is_active' => true,
        'floor_block' => 1, 'floor_row' => 1, 'floor_col' => 2,
    ]);
    $this->support = Workstation::create([
        'name' => 'PC-01 3F C', 'site_id' => $this->site->id, 'is_active' => true,
        'is_support' => true, 'floor_block' => 1, 'floor_row' => 2, 'floor_col' => 1,
    ]);

    Project::create(['code' => 'sky_feather', 'is_active' => true]);
});

afterEach(function (): void {
    Date::setTestNow();
});

it('reports every machine with its position and claim state', function (): void {
    $tasker = tasker(['name' => 'Juan Dela Cruz']);

    $this->actingAs($tasker)->postJson('/api/daily/activate', [
        'commitment_bracket' => '7_plus_hours',
        'tasking_statuses' => ['tasking'],
        'workstation_id' => $this->pc->id,
        'pc_status' => 'used',
    ])->assertCreated();

    $byId = collect(
        $this->actingAs($this->admin)->getJson('/api/admin/floor')->assertOk()->json('data'),
    )->keyBy('id');

    expect($byId)->toHaveCount(3);

    expect($byId[$this->pc->id]['is_claimed'])->toBeTrue()
        ->and($byId[$this->pc->id]['claimed_by'])->toBe('Juan Dela Cruz')
        // The coordinates are what let the admin screen draw the same room.
        ->and($byId[$this->pc->id]['floor_block'])->toBe(1)
        ->and($byId[$this->pc->id]['floor_row'])->toBe(1)
        ->and($byId[$this->pc->id]['floor_col'])->toBe(1);

    expect($byId[$this->free->id]['is_claimed'])->toBeFalse()
        ->and($byId[$this->free->id]['claimed_by'])->toBeNull();

    // Support desks are drawn so the map matches the room, flagged rather than
    // hidden -- a gap where a machine physically sits reads as a broken map.
    expect($byId[$this->support->id]['is_support'])->toBeTrue();
});

it('shows a desk as free again once its occupant times out', function (): void {
    // The question this page exists to answer, and the one a stale claim list
    // gets wrong: an admin looking for a spare machine at 2 AM.
    $tasker = tasker();

    $this->actingAs($tasker)->postJson('/api/daily/activate', [
        'commitment_bracket' => '7_plus_hours',
        'tasking_statuses' => ['tasking'],
        'workstation_id' => $this->pc->id,
        'pc_status' => 'used',
    ])->assertCreated();

    Date::setTestNow(CarbonImmutable::parse('2026-07-29 02:00'));
    $this->actingAs($tasker)->postJson('/api/attendance/time-out')->assertOk();

    $row = collect(
        $this->actingAs($this->admin)->getJson('/api/admin/floor')->assertOk()->json('data'),
    )->firstWhere('id', $this->pc->id);

    expect($row['is_claimed'])->toBeFalse()
        // And who had it, so the night is still readable after they leave.
        ->and($row['previous_by'])->toBe($tasker->name);
});

it('refuses the floor to a tasker', function (): void {
    // Not because the data is secret -- the tasker picker returns it -- but
    // because an admin route that any signed-in account could call is an
    // authorisation boundary that exists only in the navigation.
    $this->actingAs(tasker())->getJson('/api/admin/floor')->assertForbidden();
});

it('refuses the floor to a guest', function (): void {
    $this->getJson('/api/admin/floor')->assertUnauthorized();
});
