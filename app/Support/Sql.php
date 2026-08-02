<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The few places where one SQL string will not run on both database engines.
 *
 * Development and the test suite run on MariaDB; the deployed application runs
 * on PostgreSQL (Render offers no managed MySQL). That split is a standing
 * hazard -- a green test suite does not prove the deployed query is valid --
 * so the differences are collected here instead of being scattered through the
 * services, where each one would have to be rediscovered separately.
 *
 * Everything else in the codebase is portable and should stay that way. Reach
 * for this class only when the two engines genuinely disagree.
 */
final class Sql
{
    /**
     * Count the rows matching a condition, as an aggregate over a group.
     *
     * MySQL evaluates a boolean to 1 or 0, so `SUM(status = ?)` counts matches
     * and reads pleasantly. PostgreSQL has a real boolean type and no SUM() for
     * it, so the same expression is not merely different -- it is a type error
     * that fails the query outright.
     *
     * CASE WHEN is the portable spelling and runs unchanged on both. It is
     * wrapped here rather than written out at ~25 call sites, where the noise
     * would bury the condition that actually matters.
     *
     * The condition may carry `?` placeholders; bindings are passed through
     * selectRaw() in the usual way and their order is preserved.
     *
     * PostgreSQL also offers COUNT(*) FILTER (WHERE ...), which is tidier and
     * which MySQL does not implement. Not used, for that reason.
     */
    public static function countIf(string $condition): string
    {
        return "SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END)";
    }

    /**
     * A date column formatted into a chart bucket key, e.g. "2026-W31".
     *
     * Date formatting is where the two engines share no common ground at all:
     * MySQL has DATE_FORMAT with % specifiers, PostgreSQL has TO_CHAR with
     * template patterns, and neither understands the other.
     *
     * The week bucket uses ISO week numbering on both sides -- MySQL's %x/%v
     * and PostgreSQL's IYYY/IW -- so a week that starts on Monday is the same
     * week whichever engine answers, and a bucket key does not change meaning
     * when the application is deployed.
     *
     * @param  string  $granularity  day, week or month; anything else is a day
     */
    public static function dateBucket(string $column, string $granularity): string
    {
        if (self::isPostgres()) {
            return match ($granularity) {
                // The double quotes escape literal text inside a TO_CHAR
                // template; without them the W and the dash are read as
                // pattern codes.
                'week' => "TO_CHAR({$column}, 'IYYY\"-W\"IW')",
                'month' => "TO_CHAR({$column}, 'YYYY-MM')",
                default => "TO_CHAR({$column}, 'YYYY-MM-DD')",
            };
        }

        return match ($granularity) {
            'week' => "DATE_FORMAT({$column}, '%x-W%v')",
            'month' => "DATE_FORMAT({$column}, '%Y-%m')",
            default => "DATE_FORMAT({$column}, '%Y-%m-%d')",
        };
    }

    /**
     * The case-insensitive LIKE operator for the current connection.
     *
     * This one is a behaviour difference rather than a syntax error, which
     * makes it the more dangerous of the two: MySQL's default collation
     * compares case-insensitively, so `LIKE '%ana%'` finds "Ana". PostgreSQL
     * compares exactly, so the identical query silently returns nothing and
     * every admin search box quietly stops matching anything typed in the
     * wrong case. ILIKE restores the behaviour the application was written
     * against.
     */
    public static function like(): string
    {
        return self::isPostgres() ? 'ilike' : 'like';
    }

    private static function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
