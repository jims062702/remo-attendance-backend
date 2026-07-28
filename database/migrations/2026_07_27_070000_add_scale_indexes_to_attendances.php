<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One index for the hottest concurrent lookup on the floor.
 *
 * Deliberately just the one. Every extra index is paid for on every insert and
 * update, and this table takes two writes per tasker per night plus a row lock
 * during the activation transaction -- so an index that does not earn its place
 * makes the contention it was added to relieve slightly worse.
 *
 * The tracker_entries pre-fill lookup was considered and rejected: that table
 * already carries `unique(user_id, entry_date)`, which is a B-tree over exactly
 * those columns in exactly that order, so a second index on the same pair would
 * be pure write cost for a lookup that is already served.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            /*
             * The workstation claim check, run inside the activation
             * transaction ("is this desk already taken for this business
             * date"), and the PC picker's claim scan.
             *
             * The existing single-column index on workstation_id matches every
             * shift that machine has EVER been used for and then filters by
             * date. Leading on attendance_date narrows to one night first,
             * and carrying workstation_id as the second column lets the
             * picker's lookup be served from the index rather than the rows.
             */
            $table->index(['attendance_date', 'workstation_id'], 'attendances_date_workstation_index');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropIndex('attendances_date_workstation_index');
        });
    }
};
