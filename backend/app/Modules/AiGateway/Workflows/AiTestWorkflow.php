<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Workflows;

use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;
use SiteXPost\Modules\AiGateway\Gateway\AiGateway;
use SiteXPost\Modules\AiGateway\Gateway\AiGatewayException;
use SiteXPost\Modules\AiGateway\Gateway\AiTask;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Engine\StepContext;
use SiteXPost\Modules\Workflows\Engine\StepFailed;
use SiteXPost\Modules\Workflows\Engine\WorkflowDefinition;

/**
 * "AI task test" (Phase 3 acceptance): one paid step that calls the gateway
 * through the normal routing/budget/retry path, then a bookkeeping step.
 */
final class AiTestWorkflow implements WorkflowDefinition
{
    public const TYPE = 'ai.test';

    public const TASK_KEY = 'system.test';

    public const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'summary' => ['type' => 'string'],
            'keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
            'sentiment' => ['type' => 'string', 'enum' => ['positive', 'neutral', 'negative']],
        ],
        'required' => ['summary', 'keywords', 'sentiment'],
        'additionalProperties' => false,
    ];

    private const SYSTEM = 'Bạn là trợ lý kiểm thử của SiteX Post. Trả lời ngắn gọn bằng ngôn ngữ của người dùng.';

    public function __construct(private readonly AiGateway $gateway) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function queue(array $input): string
    {
        return $this->route()->queue ?? 'writing';
    }

    public function maxAttempts(array $input): int
    {
        return $this->route()->max_attempts ?? 3;
    }

    public function steps(): array
    {
        return ['generate', 'finalize'];
    }

    public function isPaidStep(string $step): bool
    {
        return $step === 'generate';
    }

    public function allowsPaidRetry(WorkflowRun $run): bool
    {
        return (bool) $this->route()?->allow_paid_retry;
    }

    public function runStep(string $step, WorkflowRun $run, StepContext $context): array
    {
        $input = $run->input ?? [];

        if ($step === 'finalize') {
            $generated = $context->outputs['generate'] ?? [];

            return ['completed_at' => now()->toIso8601ZuluString(), 'cost_micro_usd' => (int) ($generated['cost_micro_usd'] ?? 0)];
        }

        $structured = (bool) ($input['structured'] ?? false);

        try {
            $result = $this->gateway->execute(new AiTask(
                taskKey: self::TASK_KEY,
                prompt: (string) ($input['prompt'] ?? ''),
                callKey: $context->callKey(),
                system: self::SYSTEM,
                schema: $structured ? self::SCHEMA : null,
                schemaName: 'test_result',
                webSearch: (bool) ($input['web_search'] ?? false),
                workflowRunId: $run->id,
            ));
        } catch (AiGatewayException $e) {
            throw new StepFailed($e->errorCode, $e->getMessage(), $e->retryable, $e->requiresPaidRetry);
        }

        return [
            'text' => mb_substr($result->text, 0, 4000),
            'json' => $result->json,
            ...$result->summary(),
        ];
    }

    public function output(WorkflowRun $run, array $outputs): array
    {
        return $outputs['generate'] ?? [];
    }

    private function route(): ?AiTaskRoute
    {
        return AiTaskRoute::query()->where('task_key', self::TASK_KEY)->first();
    }
}
