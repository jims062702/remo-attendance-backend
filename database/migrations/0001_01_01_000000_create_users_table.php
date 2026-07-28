<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // The identity key. Sign-in is Google-only, and a Google account is
            // matched to an account here by email, so this column is what
            // decides who may enter the system at all.
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();

            // Google's immutable subject id, captured on first sign-in. Email
            // addresses can be reassigned within a Workspace; this cannot, so
            // it is what detects a mismatched account afterwards.
            $table->string('google_id')->nullable()->unique();
            $table->string('avatar_url', 2048)->nullable();

            // No password is ever set: there is no password sign-in path. The
            // column is kept nullable because Laravel's Authenticatable
            // contract still reads it (session hash checks, remember-me), and
            // dropping it would mean fighting the framework for no gain.
            $table->string('password')->nullable();

            // Two fixed roles; see App\Enums\UserRole.
            $table->enum('role', ['admin', 'tasker'])->default('tasker');

            // Account state. Only "active" users may authenticate -- see
            // App\Enums\UserStatus. Deactivating is preferred over deleting so
            // historical attendance and task records keep a valid owner.
            $table->enum('status', ['active', 'inactive', 'suspended'])
                ->default('active');

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            // Admin tasker lists filter on both columns together.
            $table->index(['role', 'status']);
        });

        // No password_reset_tokens table: with Google-only sign-in there is no
        // password to reset, and account recovery is Google's responsibility.

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
