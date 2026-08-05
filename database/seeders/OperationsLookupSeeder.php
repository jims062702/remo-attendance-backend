<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Site;
use App\Models\SupportTeam;
use App\Models\Workstation;
use Illuminate\Database\Seeder;

/**
 * Reference data operations actually uses.
 *
 * A BOOTSTRAP, not a source of truth. Each list is seeded only while it is
 * empty, and never written to again.
 *
 * It used to run updateOrCreate on every deploy, which meant the seeder was
 * quietly the authority on these tables forever. Two things followed, and the
 * second is the worse one:
 *
 *  - A project an admin DELETED came back on the next deploy.
 *  - A project an admin DEACTIVATED was silently switched back on, because
 *    `['is_active' => true]` is written every single time.
 *
 * Nobody was told either had happened. Reference data belongs to the people
 * running the floor the moment there is a floor to run; this exists so that
 * the first night is possible, and then gets out of the way.
 *
 * Consequence worth knowing: adding a code to the list below will NOT appear
 * on an installation that already has projects. Add it from Lookups instead --
 * that screen is the authority now.
 */
class OperationsLookupSeeder extends Seeder
{
    /** The tracking projects, as they appear on the form. */
    private const PROJECTS = [
        'aloha_data_collection_v1',
        'duck_napkin_folding',
        'robotics_ego_annotation',
        'sky_feather',
        'bronze_luggage',
        'shadowbox_attachment',
        'crane_gamer',
        'ophiuchus_group',
        'ursa_majoris',
        'gondola_kimono',
        'inch_buyer',
        'scene_pyramid',
        'stylus_surprise',
        'fortuna_rating_backfill',
        'ego_splits',
        'duck_napkin_foldingv8.6',
        'second_jute',
        'sweet_yam',
        'aether',
        'mammoth_lss_v2',
        'venus_ltd_2024',
        'metronome_amendment',
        'whale_keypoint_dense_secured',
        'selection_vacation',
        'fill_metro',
    ];

    private const SITES = [
        'BEAMO 3F C',
    ];

    public function run(): void
    {
        if (Site::query()->withoutGlobalScopes()->count() === 0) {
            foreach (self::SITES as $name) {
                Site::create(['name' => $name, 'is_active' => true]);
            }
        }

        /*
         * Resolved BY NAME, never by "the first one".
         *
         * This was `Site::first()`, and that produced duplicate machines in
         * production. A query with no ORDER BY has no defined order, and
         * PostgreSQL means it: rows live in a heap and an UPDATE physically
         * relocates them, so `first()` genuinely returns different rows on
         * different runs. MySQL hides this -- its clustered index hands back
         * primary-key order for a simple scan -- which is why development
         * never saw it.
         *
         * Once a second site existed, this seeder began picking whichever site
         * Postgres happened to return. Machines are unique on (site_id, name),
         * so pointing at the other site did not collide with the existing rows
         * -- it created a second full set of them.
         */
        $site = Site::where('name', self::SITES[0])->firstOrFail();

        // A starting bank of workstations. Operations adds or deletes these
        // from the admin screens; this is only so the flow is usable on day one.
        if (Workstation::query()->withoutGlobalScopes()->count() === 0) {
            for ($i = 1; $i <= 30; $i++) {
                Workstation::create([
                    'site_id' => $site->id,
                    'name' => Workstation::floorName($i),
                    'is_active' => true,
                    // PC-01 belongs to support and is outside the tasker pool.
                    'is_support' => $i === 1,
                ]);
            }
        }

        // "duck_napkin_folding" and "duck_napkin_foldingv8.6" are genuinely
        // different projects on the form, so both are kept. The submitted list
        // contained duck_napkin_foldingv8.6 twice; updateOrCreate collapses
        // that to one row rather than failing on the unique index.
        if (Project::query()->withoutGlobalScopes()->count() === 0) {
            foreach (self::PROJECTS as $code) {
                Project::create(['code' => $code, 'is_active' => true]);
            }
        }

        if (SupportTeam::query()->withoutGlobalScopes()->count() === 0) {
            foreach (['Support A', 'Support B', 'Support C'] as $name) {
                SupportTeam::create(['name' => $name, 'is_active' => true]);
            }
        }

        $this->command?->info(sprintf(
            'Lookup data ready: %d projects, %d workstations, %d sites, %d support teams.',
            Project::count(),
            Workstation::count(),
            Site::count(),
            SupportTeam::count(),
        ));
    }
}
