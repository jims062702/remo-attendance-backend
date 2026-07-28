<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();

            // restrictOnDelete: a user with attendance history cannot be hard
            // deleted. Deactivation (users.status) is the supported path, which
            // keeps historical records attributable.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // The BUSINESS date of the shift, not the calendar date of time_in.
            // For the 22:00 -> 06:00 shift these differ for anyone who clocks in
            // after midnight; both belong to the business date the shift started.
            // Resolved by AttendanceService::resolveBusinessDate().
            $table->date('attendance_date');

            // Full datetimes, never TIME columns: a shift crossing midnight must
            // stay a simple subtraction rather than a special case.
            $table->dateTime('time_in')->nullable();
            $table->dateTime('time_out')->nullable();

            // Server-computed from time_in/time_out. NULL (never 0) while a shift
            // is open or was never closed, so averages ignore it instead of being
            // dragged down by a phantom zero.
            $table->decimal('total_hours', 6, 2)->nullable();

            // "For today's production, how many hours can we expect you to
            // commit?" -- asked once per shift, so it lives here and not on
            // tasks. Variance = total_hours - expected_hours.
            $table->decimal('expected_hours', 5, 2)->nullable();

            $table->enum('status', [
                'present',
                'late',
                'incomplete',
                'absent',
                'on_leave',
            ])->default('present');

            $table->string('notes', 1000)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The database-level guarantee that one tasker cannot open two
            // shifts on the same business date. The service layer relies on this
            // firing rather than doing a check-then-insert, which would race.
            $table->unique(['user_id', 'attendance_date']);

            // Admin views filter by date range, optionally narrowed by status.
            $table->index(['attendance_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
