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
 * Written with updateOrCreate so it is safe to re-run against a live database:
 * adding a project to this list and re-seeding will not duplicate or reset the
 * existing rows.
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
        foreach (self::SITES as $name) {
            Site::updateOrCreate(['name' => $name], ['is_active' => true]);
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
        for ($i = 1; $i <= 30; $i++) {
            Workstation::updateOrCreate(
                ['site_id' => $site->id, 'name' => Workstation::floorName($i)],
                [
                    'is_active' => true,
                    // PC-01 belongs to support and is outside the tasker pool.
                    'is_support' => $i === 1,
                ],
            );
        }

        // "duck_napkin_folding" and "duck_napkin_foldingv8.6" are genuinely
        // different projects on the form, so both are kept. The submitted list
        // contained duck_napkin_foldingv8.6 twice; updateOrCreate collapses
        // that to one row rather than failing on the unique index.
        foreach (self::PROJECTS as $code) {
            Project::updateOrCreate(['code' => $code], ['is_active' => true]);
        }

        foreach (['Support A', 'Support B', 'Support C'] as $name) {
            SupportTeam::updateOrCreate(['name' => $name], ['is_active' => true]);
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
