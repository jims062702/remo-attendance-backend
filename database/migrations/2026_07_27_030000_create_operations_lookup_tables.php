<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed reference data: sites, workstations, projects and support teams.
 *
 * These are tables rather than enums because operations changes them without a
 * deploy -- projects come and go every few weeks, PCs are added and retired,
 * and support staff change. Each carries an `is_active` flag rather than being
 * deleted, so a retired project still resolves on the historical entries that
 * reference it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            // e.g. "BEAMO 3F C"
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('workstations', function (Blueprint $table) {
            $table->id();

            // The PC label as written on the machine, e.g. "PC-014".
            $table->string('name');

            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Unique per site: two sites may both have a "PC-01".
            $table->unique(['site_id', 'name']);
            $table->index('is_active');
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();

            // The platform codename, e.g. "aloha_data_collection_v1".
            $table->string('code')->unique();

            // Optional friendlier name for reports.
            $table->string('name')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('support_teams', function (Blueprint $table) {
            $table->id();

            // The trainer or support person a tasker reports under.
            $table->string('name')->unique();

            // Optionally linked to a real account, when that support person
            // also uses the system. Nullable because many are named on the
            // form without having a login.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_teams');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('workstations');
        Schema::dropIfExists('sites');
    }
};
