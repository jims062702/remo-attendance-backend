<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            // System-generated and globally unique: TASK-20260726-0001.
            // Always present -- never the string "N/A", which would collide on
            // the unique index the moment a second such row was written.
            $table->string('task_code', 32)->unique();

            // The tasker's own or a client's reference, when one exists. This is
            // the field that may legitimately be absent; it renders as "N/A".
            $table->string('external_task_id', 100)->nullable();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // Links the submission to the shift it was produced in, so output
            // can be compared against hours actually rendered. Nullable because
            // a task may be logged for a date with no attendance record.
            $table->foreignId('attendance_id')->nullable()
                ->constrained()->nullOnDelete();

            // Business date, consistent with attendances.attendance_date.
            $table->date('task_date');

            $table->string('task_name');
            $table->text('task_description')->nullable();

            // Units produced (records validated, entries keyed, ...).
            $table->unsignedInteger('output_count')->default(0);

            $table->enum('task_status', [
                'pending',
                'in_progress',
                'completed',
                'on_hold',
                'cancelled',
            ])->default('pending');

            // Stored as a validated URL or NULL. "N/A" is a display concern and
            // is never written here, so URL validation stays meaningful.
            $table->string('screenshot_link', 2048)->nullable();

            $table->string('notes', 1000)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // "My submissions", newest first, is the hottest query.
            $table->index(['user_id', 'task_date']);
            // Admin productivity reporting by day, optionally by status.
            $table->index(['task_date', 'task_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
