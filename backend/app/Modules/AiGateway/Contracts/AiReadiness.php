<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Contracts;

/**
 * Read-only view of the AI Gateway configuration for the Setup Wizard and
 * System Health (Phase 9). Never exposes keys — only whether one is set.
 */
interface AiReadiness
{
    /**
     * @return list<array{code: string, name: string, driver: string, enabled: bool, has_key: bool, key_source: string, circuit: string, ready: bool}>
     */
    public function providers(): array;

    /**
     * @param  list<string>  $taskKeys
     * @return list<array{task_key: string, configured: bool, enabled: bool, primary: ?string, primary_kind: ?string, ready_models: list<string>, ready: bool}>
     */
    public function routes(array $taskKeys): array;

    /**
     * Provider cost of AI calls since the given time (micro-USD), from the request log.
     */
    public function spendSince(\DateTimeInterface $since): int;

    /**
     * @return array{total: int, failed: int}
     */
    public function requestsSince(\DateTimeInterface $since): array;
}
