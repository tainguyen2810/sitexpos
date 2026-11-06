<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Contracts;

final class NullUsageMeter implements UsageMeter
{
    public function reserve(string $key, string $workspaceId, string $taskKey, int $estimateMicroUsd): void {}

    public function settle(string $key, int $actualMicroUsd, string $outcome = 'settled'): bool
    {
        return false;
    }

    public function release(string $key): bool
    {
        return false;
    }
}
