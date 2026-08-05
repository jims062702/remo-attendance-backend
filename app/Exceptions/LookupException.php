<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * Violations of the reference-data rules.
 */
final class LookupException extends DomainException
{
    /**
     * Something still points at this row, so removing it would take meaning
     * with it.
     *
     * 409 rather than 422: the request is well formed and the caller is
     * allowed to make it -- it simply contradicts the current state of the
     * data. The dependent counts travel in the context so the interface can
     * explain the refusal rather than restate it.
     *
     * @param  array<int, array{noun: string, count: int}>  $dependents
     */
    public static function inUse(string $label, string $summary, array $dependents): self
    {
        return new self(
            "This {$label} cannot be deleted because {$summary} depend on it. "
            .'Deactivate it instead — it will disappear from the pickers while '
            .'those records keep resolving.',
            'lookup.in_use',
            Response::HTTP_CONFLICT,
            ['dependents' => $dependents],
        );
    }
}
