<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Models\Project;
use App\Models\Site;
use App\Models\Workstation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * Who holds a desk, and for how long.
 *
 * A claim is not a booking for the night. It lasts exactly as long as the shift
 * behind it: the machine is held while someone is clocked in at it and free the
 * moment they clock out. Two taskers sharing one PC across a night is ordinary
 * -- somebody leaves at 1 AM, somebody else sits down -- and the only rule is
 * that they are never there at once.
 */
beforeEach(function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 22:05'));

    $this->site = Site::create(['name' => 'BEAMO 3F C', 'is_active' => true]);
    $this->pc2 = Workstation::create([
        'name' => 'PC-02 3F C', 'site_id' => $this->site->id, 'is_active' => true,
    ]);
    $this->pc5 = Workstation::create([
        'name' => 'PC-05 3F C', 'site_id' => $this->site->id, 'is_active' => true,
    ]);

    Project::create(['code' => 'sky_feather', 'is_active' => true]);
});

afterEach(function (): void {
    Date::setTestNow();
});

/** @return array<string, mixed> */
function claimPayload(int $pcId): array
{
    return [
        'commitment_bracket' => '7_plus_hours',
        'tasking_statuses' => ['tasking'],
        'workstation_id' => $pcId,
        'pc_status' => 'used',
    ];
}

// ------------------------------------------------------------ Switching desks

it('moves the claim when a tasker picks a different PC', function (): void {
    $tasker = tasker();

    $this->actingAs($tasker)->postJson('/api/daily/activate', claimPayload($this->pc2->id))
        ->assertCreated();

    $this->actingAs($tasker)->postJson('/api/daily/activate', claimPayload($this->pc5->id))
        ->assertCreated();

    // One shift, one desk. Switching must move the claim, never file a second
    // night or leave the old machine held.
    expect(Attendance::where('user_id', $tasker->id)->count())->toBe(1);
    expect(Attendance::first()->workstation_id)->toBe($this->pc5->id);

    $floor = $this->actingAs($tasker)->getJson('/api/daily/workstations')->json('data');
    $byId = collect($floor)->keyBy('id');

    expect($byId[$this->pc2->id]['is_claimed'])->toBeFalse()
        ->and($byId[$this->pc5->id]['is_claimed'])->toBeTrue()
        ->and($byId[$this->pc5->id]['claimed_by'])->toBe($tasker->name);
});

it('does not read a tasker as blocking the desk they are moving to', function (): void {
    // The conflict check excludes the mover's own row. Without that exclusion
    // a second submission naming the same PC would refuse itself.
    $tasker = tasker();

    $this->actingAs($tasker)->postJson('/api/daily/activate', claimPayload($this->pc2->id))
        ->assertCreated();

    $this->actingAs($tasker)->postJson('/api/daily/activate', claimPayload($this->pc2->id))
        ->assertCreated();

    expect(Attendance::first()->workstation_id)->toBe($this->pc2->id);
});

// -------------------------------------------------- Sharing a desk across a night

it('holds the desk while the first tasker is still clocked in', function (): void {
    $first = tasker();
    $second = tasker();

    $this->actingAs($first)->postJson('/api/daily/activate', claimPayload($this->pc2->id))
        ->assertCreated();

    $this->actingAs($second)->postJson('/api/daily/activate', claimPayload($this->pc2->id))
        ->assertStatus(409)
        ->assertJsonPath('code', 'attendance.workstation_taken');

    expect(Attendance::where('user_id', $second->id)->count())->toBe(0);
});

it('frees the desk once the first tasker times out', function (): void {
    $first = tasker();
    $second = tasker();

    $this->actingAs($first)->postJson('/api/daily/activate', claimPayload($this->pc2->id))
        ->assertCreated();

    Date::setTestNow(CarbonImmutable::parse('2026-07-27 01:00'));

    $this->actingAs($first)->postJson('/api/attendance/time-out')->assertOk();

    // Free on the floor plan the moment the shift closes.
    $byId = collect(
        $this->actingAs($second)->getJson('/api/daily/workstations')->json('data'),
    )->keyBy('id');

    expect($byId[$this->pc2->id]['is_claimed'])->toBeFalse()
        ->and($byId[$this->pc2->id]['claimed_by'])->toBeNull();

    // And genuinely claimable, not merely displayed as free.
    $this->actingAs($second)->postJson('/api/daily/activate', claimPayload($this->pc2->id))
        ->assertCreated();

    expect(Attendance::where('workstation_id', $this->pc2->id)->count())->toBe(2);
});

it('shows the current occupant, not the one who left', function (): void {
    $first = tasker();
    $second = tasker();

    $this->actingAs($first)->postJson('/api/daily/activate', claimPayload($this->pc2->id))
        ->assertCreated();

    Date::setTestNow(CarbonImmutable::parse('2026-07-27 01:00'));
    $this->actingAs($first)->postJson('/api/attendance/time-out')->assertOk();

    $this->actingAs($second)->postJson('/api/daily/activate', claimPayload($this->pc2->id))
        ->assertCreated();

    $byId = collect(
        $this->actingAs($first)->getJson('/api/daily/workstations')->json('data'),
    )->keyBy('id');

    expect($byId[$this->pc2->id]['is_claimed'])->toBeTrue()
        ->and($byId[$this->pc2->id]['claimed_by'])->toBe($second->name);
});

it('lets someone else take a desk the first tasker moved away from', function (): void {
    /*
     * The scenario this exists for: PC-02 plays up, the tasker moves to PC-05,
     * and PC-02 is working again an hour later.
     *
     * The mover never timed out -- they changed desk, not shift -- so PC-02 is
     * not a machine two people used tonight. It is a machine ONE person used,
     * and that person is whoever sits down after the move. A tasker who merely
     * passed through leaves nothing behind on the desk they left, because
     * their single attendance row followed them to PC-05.
     */
    $mover = tasker();
    $newcomer = tasker();

    $this->actingAs($mover)->postJson('/api/daily/activate', claimPayload($this->pc2->id))
        ->assertCreated();

    $this->actingAs($mover)->postJson('/api/daily/activate', claimPayload($this->pc5->id))
        ->assertCreated();

    // PC-02 is free again although the mover is still very much on shift.
    expect(Attendance::where('user_id', $mover->id)->first()->time_out)->toBeNull();

    $this->actingAs($newcomer)->postJson('/api/daily/activate', claimPayload($this->pc2->id))
        ->assertCreated();

    $byId = collect(
        $this->actingAs($mover)->getJson('/api/daily/workstations')->json('data'),
    )->keyBy('id');

    expect($byId[$this->pc2->id]['claimed_by'])->toBe($newcomer->name)
        ->and($byId[$this->pc5->id]['claimed_by'])->toBe($mover->name);

    // One shift on PC-02, not two: the mover was never a user of it.
    $onPc2 = Attendance::where('workstation_id', $this->pc2->id)->get();

    expect($onPc2)->toHaveCount(1)
        ->and($onPc2->first()->user_id)->toBe($newcomer->id);
});

it('reports the earlier occupant and when they left', function (): void {
    // A desk two people used tonight has two answers, and the floor plan shows
    // both: who is there now, and who left it at what time.
    $first = tasker();
    $second = tasker();

    $this->actingAs($first)->postJson('/api/daily/activate', claimPayload($this->pc2->id));

    Date::setTestNow(CarbonImmutable::parse('2026-07-27 01:00'));
    $this->actingAs($first)->postJson('/api/attendance/time-out')->assertOk();

    $this->actingAs($second)->postJson('/api/daily/activate', claimPayload($this->pc2->id));

    $pc = collect(
        $this->actingAs($second)->getJson('/api/daily/workstations')->json('data'),
    )->firstWhere('id', $this->pc2->id);

    expect($pc['claimed_by'])->toBe($second->name)
        ->and($pc['previous_by'])->toBe($first->name)
        ->and($pc['previous_time_out'])->not->toBeNull();

    expect(CarbonImmutable::parse($pc['previous_time_out'])->format('H:i'))->toBe('01:00');
});

it('reports no earlier occupant on a desk nobody has left', function (): void {
    $tasker = tasker();

    $this->actingAs($tasker)->postJson('/api/daily/activate', claimPayload($this->pc2->id));

    $byId = collect(
        $this->actingAs($tasker)->getJson('/api/daily/workstations')->json('data'),
    )->keyBy('id');

    // Occupied, but never handed on.
    expect($byId[$this->pc2->id]['previous_by'])->toBeNull()
        // And an untouched desk carries nothing at all.
        ->and($byId[$this->pc5->id]['previous_by'])->toBeNull()
        ->and($byId[$this->pc5->id]['is_claimed'])->toBeFalse();
});

it('keeps both shifts attached to the machine for reporting', function (): void {
    // Sharing a desk must not cost the record of who was where.
    $first = tasker();
    $second = tasker();

    $this->actingAs($first)->postJson('/api/daily/activate', claimPayload($this->pc2->id));

    Date::setTestNow(CarbonImmutable::parse('2026-07-27 01:00'));
    $this->actingAs($first)->postJson('/api/attendance/time-out');

    $this->actingAs($second)->postJson('/api/daily/activate', claimPayload($this->pc2->id));

    $shifts = Attendance::where('workstation_id', $this->pc2->id)
        ->orderBy('time_in')
        ->get();

    expect($shifts)->toHaveCount(2)
        ->and($shifts[0]->user_id)->toBe($first->id)
        ->and($shifts[0]->time_out)->not->toBeNull()
        ->and($shifts[1]->user_id)->toBe($second->id)
        ->and($shifts[1]->time_out)->toBeNull();
});
