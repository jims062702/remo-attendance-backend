<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each machine physically sits on the floor.
 *
 * A tasker picking their PC is not choosing from a list, they are identifying
 * the desk they are already sitting at. Position is what lets the picker be
 * drawn as the room instead of as an alphabetical index.
 *
 * Stored as block/row/column rather than pixel coordinates. The floor is
 * genuinely laid out as pods of desks in a grid, so a grid is the honest
 * model -- and it survives a screen of any width, which absolute coordinates
 * would not. Re-arranging the room is then a data edit, not a code change.
 *
 * All three are nullable on purpose. A machine that has not been placed yet is
 * a normal state, not an error: it still appears in the list view and stays
 * fully selectable, it simply does not appear on the map until someone says
 * where it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstations', function (Blueprint $table): void {
            // Which pod of desks. Blocks are drawn as separate clusters with
            // aisles between them, matching how the room is actually divided.
            $table->unsignedSmallInteger('floor_block')->nullable()->after('is_support');
            $table->unsignedSmallInteger('floor_row')->nullable()->after('floor_block');
            $table->unsignedSmallInteger('floor_col')->nullable()->after('floor_row');

            // The picker reads every placed machine for the site in one go and
            // draws them in order.
            $table->index(['floor_block', 'floor_row', 'floor_col'], 'workstations_floor_position_index');
        });
    }

    public function down(): void
    {
        Schema::table('workstations', function (Blueprint $table): void {
            $table->dropIndex('workstations_floor_position_index');
            $table->dropColumn(['floor_block', 'floor_row', 'floor_col']);
        });
    }
};
