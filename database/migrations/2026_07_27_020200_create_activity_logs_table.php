<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            // The actor. nullOnDelete (not restrict) because the log must
            // outlive the account: an audit trail that can be erased by
            // deleting the actor is not an audit trail.
            $table->foreignId('user_id')->nullable()
                ->constrained()->nullOnDelete();

            // Machine-readable verb, e.g. "attendance.corrected".
            $table->string('action', 100);
            $table->string('description', 500)->nullable();

            // What was acted upon, when applicable.
            $table->nullableMorphs('subject');

            // Before/after values and request context. JSON on MariaDB 10.4 is
            // stored as LONGTEXT with a validity check; Laravel casts it.
            $table->json('metadata')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            // Append-only: rows are never updated, so updated_at would be dead
            // weight on the hottest-growing table.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
