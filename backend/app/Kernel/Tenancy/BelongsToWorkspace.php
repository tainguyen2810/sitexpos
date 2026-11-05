<?php

declare(strict_types=1);

namespace SiteXPost\Kernel\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Apply to every tenant-owned model. `workspace_id` must NOT be fillable.
 *
 * @mixin Model
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(static function (Model $model): void {
            $context = app(TenantContext::class);
            $current = $model->getAttribute('workspace_id');

            if ($context->has()) {
                if ($current !== null && $current !== $context->workspaceId()) {
                    throw new CrossTenantWriteException($model::class);
                }
                $model->setAttribute('workspace_id', $context->workspaceId());

                return;
            }

            if (! $context->isBypassed() || $current === null) {
                throw new MissingTenantContextException($model::class);
            }
        });

        static::updating(static function (Model $model): void {
            if ($model->isDirty('workspace_id')) {
                throw new CrossTenantWriteException($model::class);
            }
        });
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
