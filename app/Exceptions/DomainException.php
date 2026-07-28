<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Base class for violations of a business rule (as opposed to a bug or a
 * malformed request). These render themselves as a consistent JSON envelope so
 * controllers never have to catch and translate them.
 *
 * The machine-readable `code` lets the frontend react to a specific rule --
 * e.g. re-enabling the Time Out button -- without string-matching a message
 * written for humans.
 */
abstract class DomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        protected string $errorCode,
        protected int $status = 422,
        protected array $context = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'context' => (object) $this->context,
        ], $this->status);
    }
}
