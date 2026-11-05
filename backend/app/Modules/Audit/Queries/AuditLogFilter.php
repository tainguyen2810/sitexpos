<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Audit\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use SiteXPost\Modules\Audit\Domain\AuditLog;

final class AuditLogFilter
{
    /**
     * Supported query params: filter[action] (prefix match), filter[actor_id],
     * filter[from], filter[to] (ISO dates).
     *
     * @param  Builder<AuditLog>  $query
     */
    public static function apply(Builder $query, Request $request): void
    {
        $filter = (array) $request->query('filter', []);

        $action = $filter['action'] ?? null;
        if (is_string($action) && preg_match('/^[a-z0-9_.]{1,100}$/', $action) === 1) {
            $query->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], $action).'%');
        }

        $actor = $filter['actor_id'] ?? null;
        if (is_string($actor) && Str::isUlid($actor)) {
            $query->where('actor_id', $actor);
        }

        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            $value = $filter[$key] ?? null;
            if (is_string($value) && strtotime($value) !== false) {
                $query->where('created_at', $operator, Carbon::parse($value)->utc());
            }
        }
    }
}
