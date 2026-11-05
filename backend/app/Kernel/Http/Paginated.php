<?php

declare(strict_types=1);

namespace SiteXPost\Kernel\Http;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cursor pagination with the documented envelope:
 * `{ data: [...], meta: { next_cursor, prev_cursor, per_page } }`.
 */
final class Paginated
{
    public const MAX_PER_PAGE = 100;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  must already be ordered
     * @param  class-string<JsonResource>  $resource
     */
    public static function response(Builder $query, string $resource, Request $request, int $default = 25): JsonResponse
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, (int) $request->integer('per_page', $default)));

        /** @var CursorPaginator<int, TModel> $page */
        $page = $query->cursorPaginate($perPage);

        return new JsonResponse([
            'data' => $resource::collection($page->items())->resolve($request),
            'meta' => [
                'next_cursor' => $page->nextCursor()?->encode(),
                'prev_cursor' => $page->previousCursor()?->encode(),
                'per_page' => $perPage,
            ],
        ]);
    }
}
