<?php

declare(strict_types=1);

namespace SiteXPost\Kernel\Tenancy;

use LogicException;

/**
 * Thrown when a tenant-owned model is queried or written without a tenant
 * context. This is a programming error, never a user error (fail-closed).
 */
final class MissingTenantContextException extends LogicException
{
    public function __construct(string $model)
    {
        parent::__construct("Tenant context is required to access [{$model}].");
    }
}
