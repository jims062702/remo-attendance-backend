<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Splits a tracker entry into one row per project worked.
 *
 * A tasker routinely works several projects in one night and each carries its
 * own task IDs, its own complexity band and its own screenshot -- three tasks
 * on aloha at SHORT FRAME, two on ego at SHORT FRAME, one screenshot each. A
 * single set of fields on the entry could not express that: the complexity and
 * screenshot would have to be shared across projects that do not share them.
 *
 * The per-entry columns are therefore moved down onto the item, and the entry
 * keeps only the rolled-up counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracker_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tracker_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('total_tasks')->default(0);

            // Comma-separated IDs for THIS project, stored verbatim including
            // any "(SBQ)" markers -- support validates against what was typed.
            $table->text('task_ids')->nullable();

            // Derived at write time so reports never re-parse the text.
            $table->unsignedInteger('task_id_count')->default(0);
            $table->unsignedInteger('sbq_count')->default(0);

            $table->string('task_complexity', 40)->nullable();
            $table->text('screenshot_links')->nullable();

            $table->timestamps();

            // One row per project per entry; adding the same project twice is
            // a mistake, not two separate blocks.
            $table->unique(['tracker_entry_id', 'project_id']);
            $table->index('project_id');
        });

        // Superseded by tracker_items, which carries the same project/total
        // pairing plus the per-project fields.
        Schema::dropIfExists('project_tracker_entry');

        Schema::table('tracker_entries', function (Blueprint $table) {
            // These now live on the item. The entry keeps task_id_count and
            // sbq_count as roll-ups so list views need no joins.
            $table->dropColumn(['task_ids', 'task_complexity', 'screenshot_links']);
        });
    }

    public function down(): void
    {
        Schema::table('tracker_entries', function (Blueprint $table) {
            $table->text('task_ids')->nullable();
            $table->string('task_complexity', 40)->nullable();
            $table->text('screenshot_links')->nullable();
        });

        Schema::create('project_tracker_entry', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracker_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('total_tasks')->default(0);
            $table->unique(['tracker_entry_id', 'project_id']);
        });

        Schema::dropIfExists('tracker_items');
    }
};
