<?php

declare(strict_types=1);

namespace SiteXPost\Kernel\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Fail-closed global scope: without a tenant context the query throws instead
 * of silently returning every tenant's rows.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final class WorkspaceScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        if (! $context->has()) {
            throw new MissingTenantContextException($model::class);
        }

        $builder->where($model->qualifyColumn('workspace_id'), $context->workspaceId());
    }
}
