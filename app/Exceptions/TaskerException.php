<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * Violations of the account rules an administrator can hit.
 */
final class TaskerException extends DomainException
{
    /**
     * The account still owns records, so it cannot be removed.
     *
     * `attendances.user_id`, `tasks.user_id` and `tracker_entries.user_id` are
     * all restrictOnDelete -- the database refuses outright, and that refusal
     * is deliberate: business rule 10 is that the records outlive the account.
     * Left to reach the driver it surfaces as a 500 with a constraint name in
     * it; caught here it says which records and what to do instead.
     *
     * @param  array<int, array{noun: string, count: int}>  $records
     */
    public static function hasRecords(string $name, array $records): self
    {
        $parts = array_map(
            fn (array $r): string => $r['count'].' '.$r['noun'].($r['count'] === 1 ? '' : 's'),
            $records,
        );

        $last = array_pop($parts);
        $summary = $parts === [] ? $last : implode(', ', $parts).' and '.$last;

        return new self(
            "{$name} cannot be deleted because they own {$summary}. Those records are part of "
            .'the floor\'s history and would lose the person they belong to. Leave the account '
            .'deactivated instead — it stays out of every list and every roster.',
            'tasker.has_records',
            Response::HTTP_CONFLICT,
            ['records' => $records],
        );
    }

    /**
     * Deleting is only offered for an account already taken out of service.
     *
     * Not a formality. Deactivating is reversible and deleting is not, so the
     * irreversible step is never one click away from a live roster -- and by
     * the time an account has been deactivated, somebody has already decided
     * it should not be in use.
     */
    public static function mustDeactivateFirst(string $name): self
    {
        return new self(
            "{$name} is still an active account. Deactivate it first, then delete it — "
            .'deleting cannot be undone.',
            'tasker.still_active',
            Response::HTTP_CONFLICT,
        );
    }
}
