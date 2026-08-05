<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Site;
use App\Models\Workstation;
use Illuminate\Database\Seeder;

/**
 * The physical floor: 60 machines in four pods plus a side row.
 *
 * Transcribed from the operations floor plan. The numbering serpentines --
 * each pod's back row runs right-to-left and its front row left-to-right --
 * which is why this is a literal map rather than a loop over 1..60. Reversing
 * a row here would put a tasker's name on the wrong side of the room.
 *
 *   Block 1   back  54 53 52 51 50 49 48        Block 5 (side row)
 *             front 41 42 43 44 45 46 47          55
 *   Block 2   back  40 39 38 37 36 35 34          56
 *             front 27 28 29 30 31 32 33          57
 *   Block 3   back  26 25 24 23 22 21 20          58
 *             front 13 14 15 16 17 18 19          59
 *   Block 4   back  12 11 10  9  8  7             60
 *             front  1  2  3  4  5  6
 *
 * Idempotent and additive by design. It creates machines that are missing and
 * positions the ones that already exist; it never deletes, never renames, and
 * never clears a support flag an admin set by hand. Attendance rows hold a
 * foreign key to workstations, so a seeder that removed or renumbered a
 * machine would silently rewrite where people sat on past shifts.
 */
class FloorPlanSeeder extends Seeder
{
    /**
     * Rows per block, back row first, in left-to-right screen order.
     *
     * @var array<int, array<int, array<int, int>>>
     */
    private const BLOCKS = [
        1 => [
            [54, 53, 52, 51, 50, 49, 48],
            [41, 42, 43, 44, 45, 46, 47],
        ],
        2 => [
            [40, 39, 38, 37, 36, 35, 34],
            [27, 28, 29, 30, 31, 32, 33],
        ],
        3 => [
            [26, 25, 24, 23, 22, 21, 20],
            [13, 14, 15, 16, 17, 18, 19],
        ],
        4 => [
            [12, 11, 10, 9, 8, 7],
            [1, 2, 3, 4, 5, 6],
        ],
        // The side row against the wall: one machine per row, not a pod.
        5 => [
            [55], [56], [57], [58], [59], [60],
        ],
    ];

    /** Permanently out of the tasker pool. */
    private const SUPPORT = [1, 19];

    public function run(): void
    {
        // By name, for the same reason OperationsLookupSeeder resolves it by
        // name: "the first active site" is whichever row the database felt
        // like returning, and on PostgreSQL that genuinely varies between
        // runs. Pointing at a different site creates a second set of machines
        // rather than updating the existing ones.
        $siteId = Site::query()->where('name', 'BEAMO 3F C')->value('id')
            ?? Site::query()->orderBy('id')->value('id');

        $created = 0;
        $positioned = 0;

        foreach (self::BLOCKS as $block => $rows) {
            foreach ($rows as $rowIndex => $numbers) {
                foreach ($numbers as $colIndex => $number) {
                    // One source for the convention -- see Workstation::floorName.
                    $name = Workstation::floorName($number);

                    $workstation = Workstation::withoutGlobalScopes()
                        ->where('site_id', $siteId)
                        ->where('name', $name)
                        ->first();

                    if ($workstation === null) {
                        $workstation = new Workstation;
                        $workstation->name = $name;
                        $workstation->site_id = $siteId;
                        $workstation->is_active = true;
                        $workstation->is_support = false;
                        $created++;
                    }

                    $workstation->floor_block = $block;
                    $workstation->floor_row = $rowIndex + 1;
                    $workstation->floor_col = $colIndex + 1;

                    // Only ever set, never cleared: an admin may have marked
                    // another machine as support since the plan was drawn.
                    if (in_array($number, self::SUPPORT, true)) {
                        $workstation->is_support = true;
                    }

                    $workstation->save();
                    $positioned++;
                }
            }
        }

        $this->command?->info("Floor plan: {$positioned} machines positioned, {$created} created.");
    }
}
