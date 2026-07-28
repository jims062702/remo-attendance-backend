<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Concerns\ApiResponses;
use App\Models\Attendance;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Aggregate floor figures for the public landing page.
 *
 * This endpoint is UNAUTHENTICATED, and everything about it is shaped by that.
 *
 * It returns counts and nothing else -- no names, no email addresses, no
 * per-person rows, no identifiers of any kind. A visitor learns how many people
 * are on shift, never who. That distinction is the whole security boundary
 * here, so the payload is assembled field by field rather than by handing back
 * a slice of the admin dashboard's response and trusting nobody adds a name to
 * it later.
 *
 * Be aware of what it does still reveal: headcount, and how staffed the floor
 * is right now. That is a deliberate trade for showing real figures on a public
 * page. If that is not wanted, the landing page should go back to illustrative
 * numbers rather than this being quietly locked down -- a public endpoint that
 * pretends to be private is the worse outcome.
 */
class PublicStatsController extends Controller
{
    use ApiResponses;

    /**
     * Cached because every visitor gets the identical answer.
     *
     * The figures are the same for everyone, so one computation serves all of
     * them; sixty seconds is well inside how often a roll call meaningfully
     * changes, and it means a link doing the rounds cannot turn into load on
     * the attendance tables.
     */
    private const CACHE_SECONDS = 60;

    public function __construct(private readonly AttendanceService $attendance) {}

    public function floor(): JsonResponse
    {
        $businessDate = $this->attendance->resolveBusinessDate()->toDateString();

        $stats = Cache::remember(
            "public:floor:{$businessDate}",
            self::CACHE_SECONDS,
            fn (): array => $this->build($businessDate),
        );

        return $this->ok($stats);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(string $businessDate): array
    {
        $activeTaskers = User::query()
            ->where('role', UserRole::Tasker)
            ->where('status', UserStatus::Active)
            ->count();

        $today = Attendance::query()
            ->where('attendance_date', $businessDate)
            ->selectRaw('SUM(time_in IS NOT NULL AND time_out IS NULL) AS currently_in')
            ->selectRaw('SUM(status = ?) AS present', [AttendanceStatus::Present->value])
            ->selectRaw('SUM(status = ?) AS late', [AttendanceStatus::Late->value])
            ->first();

        $present = (int) ($today->present ?? 0);
        $late = (int) ($today->late ?? 0);

        // Never negative: an admin can file more attendance rows than there are
        // currently-active taskers (someone deactivated mid-month still has
        // last week's shifts), and a negative bar renders as a broken one.
        $notYetIn = max(0, $activeTaskers - $present - $late);

        $share = static fn (int $n): int => $activeTaskers > 0
            ? (int) round($n / $activeTaskers * 100)
            : 0;

        return [
            'business_date' => $businessDate,
            'active_taskers' => $activeTaskers,
            'currently_timed_in' => (int) ($today->currently_in ?? 0),
            'roll_call' => [
                'present' => ['count' => $present, 'percent' => $share($present)],
                'late' => ['count' => $late, 'percent' => $share($late)],
                'not_yet_in' => ['count' => $notYetIn, 'percent' => $share($notYetIn)],
            ],
        ];
    }
}
