<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves tasker level from the entry down onto each project block.
 *
 * Level is per project, not per person per night: the same tasker can be L8 on
 * one project and an Attempter on another they have just picked up. Holding one
 * level on the entry forced a single answer onto a question that genuinely has
 * several, and any report grouped by level would have been wrong.
 *
 * Tenurity stays on the entry -- it describes how long the person has been
 * working, which is the same whichever project they touch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracker_items', function (Blueprint $table) {
            $table->string('tasker_level', 20)->nullable()->after('project_id');
            $table->index('tasker_level');
        });

        // Carry any existing entry-level value down to its blocks so nothing
        // is lost, then drop the column.
        if (Schema::hasColumn('tracker_entries', 'tasker_level')) {
            // A cross-table UPDATE is one of the places where the two engines
            // share no syntax: MySQL joins in the UPDATE clause, PostgreSQL
            // uses a FROM clause and neither parses the other's version.
            //
            // This runs on a fresh database too, not only on one carrying real
            // rows -- the preceding migration creates tracker_entries WITH
            // tasker_level, so the branch is always taken and a MySQL-only
            // statement here would fail the very first deploy.
            DB::statement(
                DB::connection()->getDriverName() === 'pgsql'
                    ? 'UPDATE tracker_items
                       SET tasker_level = e.tasker_level
                       FROM tracker_entries e
                       WHERE e.id = tracker_items.tracker_entry_id'
                    : 'UPDATE tracker_items i
                       JOIN tracker_entries e ON e.id = i.tracker_entry_id
                       SET i.tasker_level = e.tasker_level',
            );

            Schema::table('tracker_entries', function (Blueprint $table) {
                $table->dropIndex(['entry_date', 'tasker_level']);
                $table->dropColumn('tasker_level');
            });

            Schema::table('tracker_entries', function (Blueprint $table) {
                $table->index('entry_date');
            });
        }
    }

    public function down(): void
    {
        Schema::table('tracker_entries', function (Blueprint $table) {
            $table->string('tasker_level', 20)->nullable();
        });

        Schema::table('tracker_items', function (Blueprint $table) {
            $table->dropIndex(['tasker_level']);
            $table->dropColumn('tasker_level');
        });
    }
};
