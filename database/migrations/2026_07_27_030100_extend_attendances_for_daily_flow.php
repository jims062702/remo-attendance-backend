<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the attendance record into the "Centralised Attendance Activation"
 * and "PC Utilisation" steps of the daily flow.
 *
 * Attendance stays one row per tasker per business date -- the PC claim is
 * part of clocking in rather than a separate session, so there is still
 * exactly one clock per shift and no second set of times to disagree with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // "For today's production, how many hours can we expect you to
            // commit?" -- a bracket, not a number. expected_hours is still
            // populated (from the bracket's representative value) so all the
            // existing variance reporting keeps working unchanged.
            $table->string('commitment_bracket', 40)->nullable()->after('expected_hours');

            // PC utilisation: which machine, and in what state.
            $table->foreignId('workstation_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
            $table->string('pc_status', 20)->nullable()->after('workstation_id');

            $table->index('workstation_id');
            $table->index('commitment_bracket');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['workstation_id']);
            $table->dropIndex(['workstation_id']);
            $table->dropIndex(['commitment_bracket']);
            $table->dropColumn(['commitment_bracket', 'workstation_id', 'pc_status']);
        });
    }
};
