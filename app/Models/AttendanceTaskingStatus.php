<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One filed tasking status on a shift.
 *
 * A model rather than a bare pivot so the value is easy to query and count
 * directly ("how many shifts were Absent - Account Issue this month?").
 */
class AttendanceTaskingStatus extends Model
{
    protected $table = 'attendance_tasking_status';

    public $timestamps = false;

    protected $fillable = ['attendance_id', 'tasking_status'];

    /** @return BelongsTo<Attendance, $this> */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }
}
