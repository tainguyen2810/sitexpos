<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Engine;

use LogicException;
use SiteXPost\Modules\Workflows\Enums\WorkflowStatus;

final class InvalidTransition extends LogicException
{
    public function __construct(public readonly WorkflowStatus $from, public readonly WorkflowStatus $to)
    {
        parent::__construct("Invalid workflow transition {$from->value} → {$to->value}.");
    }
}
