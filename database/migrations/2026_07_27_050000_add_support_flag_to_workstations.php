<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a workstation as belonging to support.
 *
 * A support machine is not "taken tonight" -- it is permanently out of the
 * tasker pool, so this is a property of the workstation rather than a state on
 * a shift. Taskers never see it in their PC picker; it stays visible to admins
 * so PC utilisation still accounts for every machine on the floor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstations', function (Blueprint $table) {
            $table->boolean('is_support')->default(false)->after('is_active');
            $table->index('is_support');
        });
    }

    public function down(): void
    {
        Schema::table('workstations', function (Blueprint $table) {
            $table->dropIndex(['is_support']);
            $table->dropColumn('is_support');
        });
    }
};
