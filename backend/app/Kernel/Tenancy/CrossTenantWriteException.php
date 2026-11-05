<?php

declare(strict_types=1);

namespace SiteXPost\Kernel\Tenancy;

use LogicException;

final class CrossTenantWriteException extends LogicException
{
    public function __construct(string $model)
    {
        parent::__construct("Refusing to write [{$model}] into a workspace other than the current tenant.");
    }
}
