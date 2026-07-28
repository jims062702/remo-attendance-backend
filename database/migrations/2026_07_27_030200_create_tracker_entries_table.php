<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "Centralised Tracker System" entry -- one per tasker per business date.
 *
 * Kept separate from `attendances` rather than widening it: attendance is
 * about presence and is often filed by support on someone's behalf, whereas
 * this is the tasker's own production declaration. Splitting them means an
 * absence needs no tracker row at all, and the tracker can be revised without
 * touching the clock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracker_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // The shift this production belongs to. Nullable because support
            // can file a tracker entry for a day with no clock record.
            $table->foreignId('attendance_id')->nullable()->constrained()->nullOnDelete();

            // Business date, consistent with attendances.attendance_date.
            $table->date('entry_date');

            // Self-reported per entry (an operational decision), so the value
            // that was declared at the time is preserved rather than looked up
            // from the profile later and silently changing historical reports.
            $table->string('tenurity', 20);
            $table->string('tasker_level', 20);
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('support_team_id')->nullable()->constrained()->nullOnDelete();

            // Raw comma-separated task/sub IDs exactly as submitted, including
            // any "(SBQ)" markers. Kept verbatim because it is what support
            // validates against; the parsed counts below are derived from it.
            $table->text('task_ids')->nullable();

            // Derived from task_ids at write time so reports never re-parse.
            $table->unsignedInteger('task_id_count')->default(0);
            $table->unsignedInteger('sbq_count')->default(0);

            $table->string('task_complexity', 40)->nullable();

            // One screenshot per completed task, comma separated.
            $table->text('screenshot_links')->nullable();

            // "Total Work Hours Today" -- the tasker's own figure, with idle,
            // breaks and late logins excluded. This is the reporting figure;
            // attendances.total_hours remains the independent clock record, and
            // the two are shown side by side so a gap is visible.
            $table->decimal('declared_hours', 5, 2)->nullable();

            $table->text('remarks')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // One tracker entry per tasker per business date.
            $table->unique(['user_id', 'entry_date']);
            $table->index(['entry_date', 'tasker_level']);
            $table->index('support_team_id');
        });

        /**
         * Projects worked on, with the task total for each.
         *
         * A pivot rather than a column because a tasker selects several
         * projects in one entry and records a separate total against each --
         * a single "total tasks" number would lose which project produced what.
         */
        Schema::create('project_tracker_entry', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracker_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('total_tasks')->default(0);

            $table->unique(['tracker_entry_id', 'project_id']);
            $table->index('project_id');
        });

        /**
         * Tasking statuses, filed per shift. Several may apply at once.
         *
         * Attached to attendance rather than the tracker entry because a
         * status such as "Absent - Account Issue" must be recordable on a day
         * with no production at all.
         */
        Schema::create('attendance_tasking_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained()->cascadeOnDelete();
            $table->string('tasking_status', 60);

            $table->unique(['attendance_id', 'tasking_status']);
            $table->index('tasking_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_tasking_status');
        Schema::dropIfExists('project_tracker_entry');
        Schema::dropIfExists('tracker_entries');
    }
};
