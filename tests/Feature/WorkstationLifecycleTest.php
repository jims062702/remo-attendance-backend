<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Models\Site;
use App\Models\Workstation;
use Carbon\CarbonImmutable;
use Database\Seeders\FloorPlanSeeder;
use Database\Seeders\OperationsLookupSeeder;
use Illuminate\Support\Facades\Date;

beforeEach(function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 22:05'));
});

afterEach(function (): void {
    Date::setTestNow();
});

// ------------------------------------------------------- Seeder idempotence

it('does not grow the floor when the seeders run again', function (): void {
    // Every deploy runs both seeders. Production went from 60 machines to two
    // of every machine because they resolved "the site" implicitly -- one with
    // Site::first(), which has no ORDER BY and so no defined answer -- and
    // pointed at a different site on a later run. Machines are unique on
    // (site_id, name), so a different site does not collide: it duplicates.
    $this->seed(OperationsLookupSeeder::class);
    $this->seed(FloorPlanSeeder::class);

    $afterFirstRun = Workstation::count();

    expect($afterFirstRun)->toBe(60);

    // A second site is what triggered it. An admin adding one must not change
    // where the seeders write.
    Site::create(['name' => 'BEAMO 4F A', 'is_active' => true]);

    $this->seed(OperationsLookupSeeder::class);
    $this->seed(FloorPlanSeeder::class);
    $this->seed(OperationsLookupSeeder::class);
    $this->seed(FloorPlanSeeder::class);

    expect(Workstation::count())->toBe($afterFirstRun);
});

it('names every machine for its floor', function (): void {
    $this->seed(OperationsLookupSeeder::class);
    $this->seed(FloorPlanSeeder::class);

    expect(Workstation::where('name', 'PC-01 3F C')->exists())->toBeTrue()
        ->and(Workstation::where('name', 'PC-60 3F C')->exists())->toBeTrue()
        // The bare form is what the duplicates were named. Nothing should
        // carry it any more.
        ->and(Workstation::where('name', 'PC-01')->exists())->toBeFalse();
});

// ------------------------------------------------- Claims reset each night

it('frees every PC when the business date rolls over', function (): void {
    // The claim is derived from attendance for the business date, never stored
    // on the machine -- so a new night starts with an empty floor without
    // anything having to reset it.
    $this->seed(OperationsLookupSeeder::class);
    $this->seed(FloorPlanSeeder::class);

    $pc = Workstation::where('name', 'PC-06 3F C')->firstOrFail();
    $tasker = tasker();

    $this->actingAs($tasker)->postJson('/api/daily/activate', [
        'commitment_bracket' => '7_plus_hours',
        'tasking_statuses' => ['tasking'],
        'workstation_id' => $pc->id,
        'pc_status' => 'used',
    ])->assertCreated();

    $claimed = fn (): array => collect(
        $this->actingAs(tasker())->getJson('/api/daily/workstations')->json('data'),
    )->firstWhere('id', $pc->id);

    expect($claimed()['is_claimed'])->toBeTrue()
        ->and($claimed()['claimed_by'])->toBe($tasker->name);

    // Past the rollover: a new business date, so a new night.
    Date::setTestNow(CarbonImmutable::parse('2026-07-27 22:05'));

    expect($claimed()['is_claimed'])->toBeFalse()
        ->and($claimed()['claimed_by'])->toBeNull();
});

it('lets a tasker claim the same PC again on the next night', function (): void {
    $this->seed(OperationsLookupSeeder::class);
    $this->seed(FloorPlanSeeder::class);

    $pc = Workstation::where('name', 'PC-06 3F C')->firstOrFail();
    $tasker = tasker();

    $payload = [
        'commitment_bracket' => '7_plus_hours',
        'tasking_statuses' => ['tasking'],
        'workstation_id' => $pc->id,
        'pc_status' => 'used',
    ];

    $this->actingAs($tasker)->postJson('/api/daily/activate', $payload)->assertCreated();

    Date::setTestNow(CarbonImmutable::parse('2026-07-27 22:05'));

    $this->actingAs($tasker)->postJson('/api/daily/activate', $payload)->assertCreated();

    expect(Attendance::where('user_id', $tasker->id)->count())->toBe(2);
});

// ------------------------------------------------------------- Safe delete

it('deletes a machine nobody has ever used', function (): void {
    $site = Site::create(['name' => 'BEAMO 3F C', 'is_active' => true]);
    $pc = Workstation::create(['name' => 'PC-99 3F C', 'site_id' => $site->id, 'is_active' => true]);

    $this->actingAs(admin())
        ->deleteJson("/api/admin/lookups/workstations/{$pc->id}")
        ->assertOk();

    expect(Workstation::find($pc->id))->toBeNull();
});

it('refuses to delete a machine that carries shifts, and says how many', function (): void {
    $site = Site::create(['name' => 'BEAMO 3F C', 'is_active' => true]);
    $pc = Workstation::create(['name' => 'PC-06 3F C', 'site_id' => $site->id, 'is_active' => true]);

    $tasker = tasker();

    Attendance::create([
        'user_id' => $tasker->id,
        'workstation_id' => $pc->id,
        'attendance_date' => '2026-07-26',
        'status' => 'present',
    ]);

    $response = $this->actingAs(admin())
        ->deleteJson("/api/admin/lookups/workstations/{$pc->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'lookup.in_use');

    expect($response->json('message'))->toContain('1 shift');

    // Refusing is only defensible because the row survives intact -- a delete
    // here would blank workstation_id on that shift rather than fail.
    expect(Workstation::find($pc->id))->not->toBeNull();
    expect(Attendance::first()->workstation_id)->toBe($pc->id);
});

it('offers deactivation as the way out, leaving history resolvable', function (): void {
    $site = Site::create(['name' => 'BEAMO 3F C', 'is_active' => true]);
    $pc = Workstation::create(['name' => 'PC-06 3F C', 'site_id' => $site->id, 'is_active' => true]);

    Attendance::create([
        'user_id' => tasker()->id,
        'workstation_id' => $pc->id,
        'attendance_date' => '2026-07-26',
        'status' => 'present',
    ]);

    $this->actingAs(admin())
        ->postJson("/api/admin/lookups/workstations/{$pc->id}/deactivate")
        ->assertOk();

    expect($pc->fresh()->is_active)->toBeFalse()
        ->and(Attendance::first()->workstation_id)->toBe($pc->id);

    // And it is gone from the tasker's picker.
    $ids = collect($this->actingAs(tasker())->getJson('/api/daily/workstations')->json('data'))
        ->pluck('id');

    expect($ids)->not->toContain($pc->id);
});

it('refuses to delete a site that still has machines on it', function (): void {
    $site = Site::create(['name' => 'BEAMO 3F C', 'is_active' => true]);
    Workstation::create(['name' => 'PC-06 3F C', 'site_id' => $site->id, 'is_active' => true]);

    $this->actingAs(admin())
        ->deleteJson("/api/admin/lookups/sites/{$site->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'lookup.in_use');
});
