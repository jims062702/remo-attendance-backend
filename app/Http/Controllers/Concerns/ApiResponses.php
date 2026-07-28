<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;

/**
 * One response envelope for the whole API, so the frontend has a single shape
 * to unwrap: { data, message } on success, { message, code } on failure.
 */
trait ApiResponses
{
    /**
     * @param  array<string, mixed>  $meta
     */
    protected function ok(mixed $data = null, ?string $message = null, array $meta = [], int $status = 200): JsonResponse
    {
        $payload = ['data' => $data];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function created(mixed $data = null, ?string $message = null, array $meta = []): JsonResponse
    {
        return $this->ok($data, $message, $meta, 201);
    }

    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * Pagination meta in a fixed shape. Laravel's own paginator JSON is not
     * used because the payload here is a Resource collection plus separate
     * meta, and every list endpoint should look identical to the client.
     *
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, mixed>  $paginator
     * @return array<string, int>
     */
    protected function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
