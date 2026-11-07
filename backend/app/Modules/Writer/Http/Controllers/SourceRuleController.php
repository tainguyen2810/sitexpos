<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Actions\ManageSourceRules;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\SourceRule;
use SiteXPost\Modules\Writer\Http\Requests\SourceRuleRequest;
use SiteXPost\Modules\Writer\Support\SourceFilter;
use SiteXPost\Modules\Writer\Support\WriterSettings;

/**
 * Workspace source filter ("Nguồn tin cậy"): preferred and blocked domains
 * used by research and fact-check (FACT-CHECK-POLICY.md §7). Everyone reads;
 * OWNER / ADMIN change it.
 */
final class SourceRuleController
{
    public function index(Request $request, Workspace $workspace, WriterSettings $settings): JsonResponse
    {
        Gate::authorize('viewAny', Article::class);
        $rules = SourceRule::query()->orderBy('mode')->orderBy('domain')->limit(ManageSourceRules::MAX_RULES)->get();
        $platform = array_values(array_filter(array_map(fn ($d) => is_string($d) ? SourceFilter::normalizeDomain($d) : null, (array) $settings->get('blocked_source_domains'))));

        return new JsonResponse(['data' => $rules->map(fn (SourceRule $r) => self::present($r))->all(), 'meta' => [
            'platform_blocked' => $platform,
            'max_rules' => ManageSourceRules::MAX_RULES,
            'high_risk_min_independent_sources' => $settings->int('high_risk_min_independent_sources', $workspace->id),
            'can_manage' => Gate::allows('manageSources', Article::class),
        ]]);
    }

    public function store(SourceRuleRequest $request, Workspace $workspace, ManageSourceRules $action): JsonResponse
    {
        Gate::authorize('manageSources', Article::class);
        $v = $request->validated();
        $rule = $action->save((string) $v['domain'], (string) $v['mode'], isset($v['note']) ? (string) $v['note'] : null, (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => self::present($rule)], $rule->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Workspace $workspace, SourceRule $sourceRule, ManageSourceRules $action): JsonResponse
    {
        Gate::authorize('approve', $sourceRule);
        $action->delete($sourceRule);

        return new JsonResponse(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(SourceRule $r): array
    {
        return ['id' => $r->id, 'domain' => $r->domain, 'mode' => $r->mode, 'note' => $r->note, 'created_at' => $r->created_at?->toIso8601String()];
    }
}
