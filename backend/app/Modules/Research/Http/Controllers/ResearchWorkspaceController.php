<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\Paginated;
use SiteXPost\Modules\Research\Domain\ResearchNotification;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Http\Resources\NotificationResource;
use SiteXPost\Modules\Research\Support\QuotaManager;
use SiteXPost\Modules\Research\Support\ResearchSettings;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Workspace-level research: notifications, usage vs limits, source availability.
 */
final class ResearchWorkspaceController
{
    public function notifications(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', SeoCampaign::class);
        $q = ResearchNotification::query();
        if ($request->query('unread') === '1') {
            $q->whereNull('read_at');
        }

        $response = Paginated::response($q->orderByDesc('created_at')->orderByDesc('id'), NotificationResource::class, $request, 20);
        $payload = $response->getData(true);
        $payload['meta']['unread'] = ResearchNotification::query()->whereNull('read_at')->count();

        return $response->setData($payload);
    }

    public function markRead(Workspace $workspace, ResearchNotification $notification): NotificationResource
    {
        Gate::authorize('viewAny', SeoCampaign::class);
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return new NotificationResource($notification);
    }

    public function usage(Workspace $workspace, QuotaManager $quota, ResearchSettings $settings): JsonResponse
    {
        Gate::authorize('viewAny', SeoCampaign::class);
        $sources = [];
        foreach (ResearchSettings::SOURCES as $key => $def) {
            $row = $settings->source($key);
            $sources[] = [
                'source' => $key, 'label' => $def['label'], 'enabled' => $settings->enabled($key),
                'requires_credentials' => $def['requires_credentials'],
                'configured' => match ($key) {
                    'search_console' => (string) config('services.google.client_id') !== '',
                    'dataforseo', 'google_trends' => $row !== null && $row->hasSecret(),
                    default => true,
                },
            ];
        }

        return new JsonResponse(['data' => [
            'usage' => $quota->usage($workspace->id),
            'limits' => $settings->limitsFor($workspace->id),
            'campaigns' => SeoCampaign::query()->where('status', '!=', 'archived')->count(),
            'sources' => $sources,
        ]]);
    }
}
