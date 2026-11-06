<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Analytics\Actions\GenerateReport;
use SiteXPost\Modules\Analytics\Domain\SeoReport;
use SiteXPost\Modules\Analytics\Http\AnalyticsPresenter;
use SiteXPost\Modules\Analytics\Http\Requests\CreateReportRequest;
use SiteXPost\Modules\Analytics\Http\Requests\EmailReportRequest;
use SiteXPost\Modules\Analytics\Notifications\SeoReportNotice;
use SiteXPost\Modules\Analytics\Support\DashboardQuery;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\MemberStatus;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Weekly / monthly / custom SEO reports built only from stored tenant data
 * (no AI call, no external request). CSV export; e-mail goes only to
 * members of this workspace.
 */
final class ReportController
{
    public function index(Request $request, Workspace $workspace, DashboardQuery $dash): JsonResponse
    {
        Gate::authorize('viewAny', SeoReport::class);
        $q = SeoReport::query()->orderByDesc('period_end')->orderByDesc('id')->limit(100);
        $site = $request->query('site_id');
        if (is_string($site) && $site !== '') {
            $q->where('site_id', $dash->site($site));
        }

        return new JsonResponse(['data' => $q->get()->map(fn ($r) => AnalyticsPresenter::report($r))->all()]);
    }

    public function store(CreateReportRequest $request, Workspace $workspace, GenerateReport $action, DashboardQuery $dash): JsonResponse
    {
        Gate::authorize('create', SeoReport::class);
        $d = $request->validated();
        $siteId = ! empty($d['site_id']) ? $dash->site((string) $d['site_id']) : null;
        $today = CarbonImmutable::now('UTC')->startOfDay();
        [$from, $to] = match ($d['period_type']) {
            'weekly' => [$today->subWeek()->startOfWeek(), $today->subWeek()->endOfWeek()->startOfDay()],
            'monthly' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            default => [CarbonImmutable::parse((string) $d['period_start']), CarbonImmutable::parse((string) $d['period_end'])],
        };
        if ($from->diffInDays($to) > 400) {
            throw ApiException::conflict('range_too_long', __('analytics.errors.invalid_range'));
        }
        $report = new SeoReport;
        $report->forceFill(['site_id' => $siteId, 'period_type' => $d['period_type'], 'period_start' => $from->toDateString(), 'period_end' => $to->toDateString(),
            'status' => 'queued', 'generated_by' => (string) $request->user()?->getAuthIdentifier()])->save();
        $action->build($report);
        Audit::record('analytics.report_generated', $report, ['period_type' => $d['period_type']]);

        return new JsonResponse(['data' => AnalyticsPresenter::report($report->refresh(), true)], 201);
    }

    public function show(Workspace $workspace, SeoReport $report): JsonResponse
    {
        Gate::authorize('view', $report);

        return new JsonResponse(['data' => AnalyticsPresenter::report($report, true)]);
    }

    public function csv(Workspace $workspace, SeoReport $report): StreamedResponse
    {
        Gate::authorize('view', $report);
        $rows = GenerateReport::csv($report);

        return new StreamedResponse(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                // Neutralise spreadsheet formulas in exported cells.
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'".$v : $v, $row), ',', '"', '\\');
            }
            fclose($out);
        }, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="seo-report-'.$report->period_start.'-'.$report->period_end.'.csv"']);
    }

    public function email(EmailReportRequest $request, Workspace $workspace, SeoReport $report, TenantContext $context): JsonResponse
    {
        Gate::authorize('operate', $report);
        if ($report->status !== 'ready') {
            throw ApiException::conflict('report_not_ready', __('analytics.errors.report_not_ready'));
        }
        $ids = array_values(array_unique((array) $request->validated('recipient_ids')));
        // Only active members of THIS workspace can receive the report.
        $memberIds = WorkspaceMember::query()->where('workspace_id', $context->workspaceId())->whereIn('user_id', $ids)->where('status', MemberStatus::Active)->pluck('user_id')->all();
        if (count($memberIds) !== count($ids)) {
            throw ApiException::forbidden('recipient_not_member', __('analytics.errors.recipient_not_member'));
        }
        $perf = $report->payload['performance'] ?? null;
        $notice = new SeoReportNotice($workspace->id, $report->id, $workspace->name, $report->period_start.' → '.$report->period_end,
            $perf !== null ? (int) ($perf['current']['clicks'] ?? 0) : null, $perf['clicks_change_pct'] ?? null);
        User::query()->whereIn('id', $memberIds)->get()->each(fn (User $u) => $u->notify($notice));
        $report->forceFill(['emailed_at' => now(), 'recipients_count' => count($memberIds)])->save();
        Audit::record('analytics.report_emailed', $report, ['recipients' => count($memberIds)]);

        return new JsonResponse(['data' => AnalyticsPresenter::report($report)]);
    }
}
