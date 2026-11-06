<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;

/**
 * Staff (platform) access to one tenant billing row: the owning workspace is
 * looked up first, then the row is loaded INSIDE that workspace context, so
 * the tenant scope is never disabled. Every staff mutation is audited.
 */
final class AdminLookup
{
    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public static function find(string $class, string $id): Model
    {
        /** @var T $model */
        $model = new $class;
        $row = DB::table($model->getTable())->where($model->getKeyName(), $id)->first(['workspace_id']);
        if ($row === null || $row->workspace_id === null) {
            throw ApiException::notFound();
        }

        /** @var T */
        return InTenant::run((string) $row->workspace_id, fn () => $class::query()->findOrFail($id));
    }
}
