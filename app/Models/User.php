<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * `role` and `status` are deliberately omitted: privilege escalation must
     * go through an explicit, authorized code path, never a mass-assigned
     * request payload. Admin controllers set them individually.
     *
     * `google_id` is likewise excluded -- it is written only by
     * GoogleAuthService after Google has vouched for the identity.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'google_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
        ];
    }

    /**
     * Whether this account has completed a Google sign-in yet.
     *
     * An account exists from the moment an admin authorises the address; it is
     * only "linked" once the person has actually signed in, which is what the
     * tasker list surfaces as Invited vs. Active.
     */
    public function hasLinkedGoogleAccount(): bool
    {
        return $this->google_id !== null;
    }

    // ---------------------------------------------------------------- Relations

    /**
     * @return HasMany<Attendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<ActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    // ------------------------------------------------------------------- Scopes

    /**
     * @param  Builder<User>  $query
     */
    public function scopeTaskers(Builder $query): void
    {
        $query->where('role', UserRole::Tasker);
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', UserStatus::Active);
    }

    /**
     * Case-insensitive match across name and email.
     *
     * @param  Builder<User>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $q) use ($like): void {
            $q->where('name', Sql::like(), $like)->orWhere('email', Sql::like(), $like);
        });
    }

    // ------------------------------------------------------------------ Helpers

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isTasker(): bool
    {
        return $this->role === UserRole::Tasker;
    }

    public function canAuthenticate(): bool
    {
        return $this->status->canAuthenticate();
    }
}
