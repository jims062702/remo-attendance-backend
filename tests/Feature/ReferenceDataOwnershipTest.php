<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Site;
use App\Models\SupportTeam;
use App\Models\Workstation;

/**
 * Who owns the reference lists once the floor is running.
 *
 * The seeders run on every deploy, and they used to run updateOrCreate -- which
 * made them the permanent authority over these tables. An admin who deleted a
 * project got it back the next morning, and an admin who DEACTIVATED one got it
 * switched back on, silently, because `['is_active' => true]` was rewritten
 * every time.
 *
 * They are bootstraps now: they build a floor that does not exist and then stop
 * touching it. These tests are what keeps them that way.
 */
it('builds the reference lists on an empty database', function (): void {
    $this->artisan('db:seed', ['--class' => 'OperationsLookupSeeder'])->assertSuccessful();

    expect(Project::count())->toBeGreaterThan(0)
        ->and(Site::count())->toBeGreaterThan(0)
        ->and(SupportTeam::count())->toBeGreaterThan(0)
        ->and(Workstation::count())->toBeGreaterThan(0);
});

it('does not bring back a project an admin deleted', function (): void {
    $this->artisan('db:seed', ['--class' => 'OperationsLookupSeeder'])->assertSuccessful();

    $before = Project::count();
    $doomed = Project::firstOrFail();
    $code = $doomed->code;
    $doomed->delete();

    $this->artisan('db:seed', ['--class' => 'OperationsLookupSeeder'])->assertSuccessful();

    expect(Project::where('code', $code)->exists())->toBeFalse()
        ->and(Project::count())->toBe($before - 1);
});

it('does not reactivate a project an admin switched off', function (): void {
    // The quieter of the two faults: nothing reappears, so nobody notices --
    // the project simply starts showing up in the picker again.
    $this->artisan('db:seed', ['--class' => 'OperationsLookupSeeder'])->assertSuccessful();

    $project = Project::firstOrFail();
    $project->update(['is_active' => false]);

    $this->artisan('db:seed', ['--class' => 'OperationsLookupSeeder'])->assertSuccessful();

    expect($project->fresh()->is_active)->toBeFalse();
});

it('does not bring back a support team an admin deleted', function (): void {
    // Reported from the floor: a team deleted in the evening was back the next
    // morning. Same line, same cause as the projects.
    $this->artisan('db:seed', ['--class' => 'OperationsLookupSeeder'])->assertSuccessful();

    $before = SupportTeam::count();
    $doomed = SupportTeam::firstOrFail();
    $name = $doomed->name;
    $doomed->delete();

    $this->artisan('db:seed', ['--class' => 'OperationsLookupSeeder'])->assertSuccessful();

    expect(SupportTeam::where('name', $name)->exists())->toBeFalse()
        ->and(SupportTeam::count())->toBe($before - 1);
});

it('rebuilds a list only when it has been emptied completely', function (): void {
    // The one case where the seeder still acts: an empty list is indistinguishable
    // from a fresh installation, and a floor with no support teams at all cannot
    // file a tracker entry. Worth knowing before deleting the last row.
    $this->artisan('db:seed', ['--class' => 'OperationsLookupSeeder'])->assertSuccessful();

    SupportTeam::query()->delete();

    $this->artisan('db:seed', ['--class' => 'OperationsLookupSeeder'])->assertSuccessful();

    expect(SupportTeam::count())->toBeGreaterThan(0);
});

it('leaves deactivated sites and support teams alone too', function (): void {
    $this->artisan('db:seed', ['--class' => 'OperationsLookupSeeder'])->assertSuccessful();

    $site = Site::firstOrFail();
    $team = SupportTeam::firstOrFail();

    $site->update(['is_active' => false]);
    $team->update(['is_active' => false]);

    $this->artisan('db:seed', ['--class' => 'OperationsLookupSeeder'])->assertSuccessful();

    expect($site->fresh()->is_active)->toBeFalse()
        ->and($team->fresh()->is_active)->toBeFalse();
});

it('does not rebuild a machine an admin removed from the floor', function (): void {
    $this->artisan('db:seed', ['--class' => 'OperationsLookupSeeder'])->assertSuccessful();
    $this->artisan('db:seed', ['--class' => 'FloorPlanSeeder'])->assertSuccessful();

    $before = Workstation::count();
    $removed = Workstation::where('name', Workstation::floorName(33))->firstOrFail();
    $removed->delete();

    $this->artisan('db:seed', ['--class' => 'FloorPlanSeeder'])->assertSuccessful();

    expect(Workstation::where('name', Workstation::floorName(33))->exists())->toBeFalse()
        ->and(Workstation::count())->toBe($before - 1);
});

it('still positions the machines that remain', function (): void {
    // Layout is not an admin decision in the way activation is, so the plan
    // keeps applying to whatever machines are actually there.
    $this->artisan('db:seed', ['--class' => 'OperationsLookupSeeder'])->assertSuccessful();
    $this->artisan('db:seed', ['--class' => 'FloorPlanSeeder'])->assertSuccessful();

    Workstation::query()->update(['floor_block' => null, 'floor_row' => null, 'floor_col' => null]);

    $this->artisan('db:seed', ['--class' => 'FloorPlanSeeder'])->assertSuccessful();

    expect(Workstation::whereNull('floor_block')->count())->toBe(0);
});
