<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Enums;

enum StepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
