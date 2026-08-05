<?php

declare(strict_types=1);

use App\Models\Workstation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Collapses the duplicate floor and renames every machine to "PC-06 3F C".
 *
 * The duplicates were made by the seeders. Both resolved "the site" implicitly
 * -- `Site::first()` in one, "first active" in the other -- and a query with no
 * ORDER BY has no defined order. PostgreSQL means that literally: rows sit in a
 * heap and an UPDATE relocates them, so the answer changed between deploys.
 * Once a second site existed, the seeders began writing against whichever site
 * came back first, and since machines are unique on (site_id, name) that did
 * not collide with the existing rows -- it created a second full set of them.
 * The seeders now resolve the site by name; this repairs what they already did.
 *
 * The rename is folded into the same migration deliberately. Renaming and
 * de-duplicating are the same operation here: both are about deciding which
 * row is the real PC-06, and doing them separately would mean matching on a
 * name that is being changed underneath.
 *
 * Nothing is destroyed silently. For each machine number, the row carrying the
 * most attendance history wins; every other row's shifts are re-pointed onto
 * the winner before it is removed, so no record ever loses the desk it was
 * filed against. `attendances.workstation_id` is nullOnDelete, which means a
 * plain delete would blank that column instead -- quietly losing where people
 * sat rather than failing loudly.
 */
return new class extends Migration
{
    public function up(): void
    {
        $canonicalSiteId = DB::table('sites')->where('name', 'BEAMO 3F C')->value('id')
            ?? DB::table('sites')->orderBy('id')->value('id');

        if ($canonicalSiteId === null) {
            // Nothing seeded yet. The seeders run after migrations on every
            // deploy and will create the floor correctly.
            return;
        }

        DB::transaction(function () use ($canonicalSiteId): void {
            for ($number = 1; $number <= 60; $number++) {
                $this->consolidate($number, $canonicalSiteId);
            }
        });
    }

    /**
     * Reduce every row that means "machine N" to a single canonical row.
     */
    private function consolidate(int $number, int $canonicalSiteId): void
    {
        $target = Workstation::floorName($number);

        // Both spellings: the original "PC-06" and the new "PC-06 3F C". A
        // machine may already carry either, on either site.
        $candidates = DB::table('workstations')
            ->whereIn('name', [sprintf('PC-%02d', $number), $target])
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return;
        }

        // Shift counts decide the winner: the row people actually sat at is the
        // real machine, whatever its id happens to be. Ties fall to the oldest,
        // which is the one the rest of the system has referred to for longest.
        $shiftCounts = DB::table('attendances')
            ->whereIn('workstation_id', $candidates->pluck('id'))
            ->selectRaw('workstation_id, COUNT(*) AS shifts')
            ->groupBy('workstation_id')
            ->pluck('shifts', 'workstation_id');

        $keeper = $candidates
            ->sortByDesc(fn ($row) => [(int) ($shiftCounts[$row->id] ?? 0), -$row->id])
            ->first();

        $duplicates = $candidates->reject(fn ($row) => $row->id === $keeper->id);

        foreach ($duplicates as $duplicate) {
            // Re-point before deleting. Doing it the other way round would let
            // the nullOnDelete constraint blank the column first.
            DB::table('attendances')
                ->where('workstation_id', $duplicate->id)
                ->update(['workstation_id' => $keeper->id]);

            DB::table('workstations')->where('id', $duplicate->id)->delete();
        }

        // The floor plan positions and the support flag may have landed on a
        // duplicate rather than on the keeper, so carry them across instead of
        // losing them with the row.
        $placed = $candidates->first(fn ($row) => $row->floor_block !== null);
        $support = $candidates->contains(fn ($row) => (bool) $row->is_support);

        DB::table('workstations')->where('id', $keeper->id)->update([
            'name' => $target,
            'site_id' => $canonicalSiteId,
            'is_support' => $support,
            'floor_block' => $placed->floor_block ?? $keeper->floor_block,
            'floor_row' => $placed->floor_row ?? $keeper->floor_row,
            'floor_col' => $placed->floor_col ?? $keeper->floor_col,
            'updated_at' => now(),
        ]);
    }

    /**
     * Renames back. The merged duplicates are NOT recreated: they were rows
     * nobody chose to make, and re-creating them would only restore a mess
     * whose contents cannot be reconstructed anyway.
     */
    public function down(): void
    {
        for ($number = 1; $number <= 60; $number++) {
            DB::table('workstations')
                ->where('name', Workstation::floorName($number))
                ->update(['name' => sprintf('PC-%02d', $number)]);
        }
    }
};
