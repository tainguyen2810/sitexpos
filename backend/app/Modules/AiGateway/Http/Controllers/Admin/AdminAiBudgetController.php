<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\AiGateway\Domain\AiBudget;
use SiteXPost\Modules\AiGateway\Gateway\BudgetManager;
use SiteXPost\Modules\AiGateway\Http\Requests\UpsertAiBudgetRequest;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Budget caps for the whole platform and per workspace.
 */
final class AdminAiBudgetController
{
    public function __construct(private readonly BudgetManager $budgets) {}

    public function index(): JsonResponse
    {
        $rows = AiBudget::query()->orderByRaw("CASE WHEN scope = 'global' THEN 0 ELSE 1 END")->orderBy('scope_key')->orderBy('period')->get();
        $names = Workspace::query()->whereIn('id', $rows->pluck('workspace_id')->filter()->all())->pluck('name', 'id');

        return new JsonResponse(['data' => $rows->map(fn (AiBudget $b): array => $this->present($b, $names->get((string) $b->workspace_id)))->all()]);
    }

    public function upsert(UpsertAiBudgetRequest $request): JsonResponse
    {
        $workspaceId = $request->input('scope') === 'workspace' ? $request->string('workspace_id')->toString() : null;
        $scopeKey = AiBudget::scopeKeyFor($workspaceId);

        $budget = AiBudget::query()->where('scope_key', $scopeKey)->where('period', $request->string('period')->toString())->first() ?? new AiBudget;
        $created = ! $budget->exists;
        $budget->forceFill([
            'scope' => $workspaceId === null ? 'global' : 'workspace',
            'workspace_id' => $workspaceId,
            'scope_key' => $scopeKey,
            'period' => $request->string('period')->toString(),
            'limit_micro_usd' => $request->integer('limit_micro_usd'),
            'alert_threshold_pct' => $request->integer('alert_threshold_pct', $budget->alert_threshold_pct ?? 80),
            'action_on_exceed' => $request->input('action_on_exceed', $budget->action_on_exceed ?? 'pause_all'),
            'is_enabled' => $request->boolean('is_enabled', $budget->is_enabled ?? true),
            'updated_by' => (string) $request->user()?->getAuthIdentifier(),
        ])->save();

        Audit::record($created ? 'platform.ai.budget.created' : 'platform.ai.budget.updated', $budget, [
            'scope_key' => $scopeKey, 'period' => $budget->period, 'limit_micro_usd' => $budget->limit_micro_usd, 'action_on_exceed' => $budget->action_on_exceed,
        ], workspaceId: $workspaceId, actorType: 'platform_staff');

        $name = $workspaceId !== null ? Workspace::query()->whereKey($workspaceId)->value('name') : null;

        return new JsonResponse(['data' => $this->present($budget, is_string($name) ? $name : null)], $created ? 201 : 200);
    }

    public function destroy(string $budget): JsonResponse
    {
        $row = Str::isUlid($budget) ? AiBudget::query()->find($budget) : null;
        if ($row === null) {
            throw ApiException::notFound();
        }
        $row->delete();
        Audit::record('platform.ai.budget.deleted', $row, ['scope_key' => $row->scope_key, 'period' => $row->period], workspaceId: $row->workspace_id, actorType: 'platform_staff');

        return new JsonResponse(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AiBudget $b, ?string $workspaceName): array
    {
        return [
            'id' => $b->id,
            'scope' => $b->scope,
            'workspace' => $b->workspace_id !== null ? ['id' => $b->workspace_id, 'name' => $workspaceName] : null,
            'period' => $b->period,
            'limit_micro_usd' => $b->limit_micro_usd,
            'alert_threshold_pct' => $b->alert_threshold_pct,
            'action_on_exceed' => $b->action_on_exceed,
            'is_enabled' => $b->is_enabled,
            'usage' => $this->budgets->status($b),
            'updated_at' => $b->updated_at->toIso8601ZuluString(),
        ];
    }
}
