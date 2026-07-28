<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\CommitmentBracket;
use App\Enums\PcStatus;
use App\Enums\TaskingStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One tasker's shift on one business date.
 *
 * `attendance_date` is the business date the shift *started*, which for the
 * overnight 22:00 -> 06:00 shift is not necessarily the calendar date of
 * `time_in` (a 00:30 arrival belongs to the previous business date). See
 * App\Services\AttendanceService::resolveBusinessDate().
 *
 * @property int $id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $attendance_date
 * @property \Illuminate\Support\Carbon|null $time_in
 * @property \Illuminate\Support\Carbon|null $time_out
 * @property float|null $total_hours
 * @property float|null $expected_hours
 * @property AttendanceStatus $status
 * @property string|null $notes
 */
class Attendance extends Model
{
    /** @use HasFactory<\Database\Factories\AttendanceFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'workstation_id',
        'pc_status',
        'attendance_date',
        'time_in',
        'time_out',
        'total_hours',
        'expected_hours',
        'commitment_bracket',
        'status',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'time_in' => 'datetime',
            'time_out' => 'datetime',
            'total_hours' => 'float',
            'expected_hours' => 'float',
            'status' => AttendanceStatus::class,
            'commitment_bracket' => CommitmentBracket::class,
            'pc_status' => PcStatus::class,
        ];
    }

    // ---------------------------------------------------------------- Relations

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return BelongsTo<Workstation, $this>
     */
    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    /**
     * @return HasOne<TrackerEntry, $this>
     */
    public function trackerEntry(): HasOne
    {
        return $this->hasOne(TrackerEntry::class);
    }

    /**
     * Tasking statuses filed for this shift.
     *
     * Stored in a pivot rather than on the row because several apply at once,
     * and because a status such as "Absent - Account Issue" must be recordable
     * on a day with no production at all.
     *
     * @return HasMany<AttendanceTaskingStatus, $this>
     */
    public function taskingStatuses(): HasMany
    {
        return $this->hasMany(AttendanceTaskingStatus::class);
    }

    /**
     * The filed tasking statuses as enum cases.
     *
     * @return array<int, TaskingStatus>
     */
    public function taskingStatusEnums(): array
    {
        return $this->taskingStatuses
            ->map(fn (AttendanceTaskingStatus $row) => TaskingStatus::tryFrom($row->tasking_status))
            ->filter()
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------- Scopes

    /**
     * Inclusive business-date range. Either bound may be omitted.
     *
     * @param  Builder<Attendance>  $query
     */
    public function scopeBetweenDates(Builder $query, ?string $from, ?string $to): void
    {
        // Compared directly rather than via whereDate(): attendance_date is
        // already a DATE column, and wrapping it in DATE() would stop the
        // (attendance_date, status) index from being used.
        $query->when($from, fn (Builder $q) => $q->where('attendance_date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->where('attendance_date', '<=', $to));
    }

    /**
     * @param  Builder<Attendance>  $query
     */
    public function scopeForUser(Builder $query, ?int $userId): void
    {
        $query->when($userId, fn (Builder $q) => $q->where('user_id', $userId));
    }

    /**
     * Shifts that were clocked into but never clocked out of.
     *
     * @param  Builder<Attendance>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotNull('time_in')->whereNull('time_out');
    }

    // ---------------------------------------------------------------- Accessors

    /**
     * A shift in progress: clocked in, not yet clocked out.
     */
    public function isOpen(): bool
    {
        return $this->time_in !== null && $this->time_out === null;
    }

    public function isClosed(): bool
    {
        return $this->time_in !== null && $this->time_out !== null;
    }

    /**
     * Actual hours minus committed hours. Negative means the tasker fell short
     * of their commitment. NULL when either side is unknown -- an open shift
     * has no meaningful variance yet.
     */
    public function variance(): ?float
    {
        if ($this->total_hours === null || $this->expected_hours === null) {
            return null;
        }

        return round($this->total_hours - $this->expected_hours, 2);
    }

    /**
     * The moment this shift was scheduled to begin, derived from the record's
     * business date and the configured shift start.
     */
    public function scheduledStart(): CarbonInterface
    {
        [$hour, $minute] = array_map(
            'intval',
            explode(':', (string) config('attendance.shift_start')),
        );

        return $this->attendance_date->copy()->setTime($hour, $minute);
    }
}
