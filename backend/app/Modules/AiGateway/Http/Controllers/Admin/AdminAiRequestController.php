<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Http\Paginated;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Domain\AiRequest;
use SiteXPost\Modules\AiGateway\Http\Resources\AiRequestResource;

/**
 * Request log across tenants (metadata only — prompts/responses and
 * credentials are never stored).
 */
final class AdminAiRequestController
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        // Platform log spans all tenants: reviewed scope bypass, read-only.
        return $context->bypass(function () use ($request): JsonResponse {
            $query = AiRequest::query()->with('workspace:id,name');

            foreach (['status', 'provider', 'task_key', 'error_code'] as $field) {
                $value = $request->query($field);
                if (is_string($value) && $value !== '' && preg_match('/^[a-z0-9_.\-]{1,64}$/', $value) === 1) {
                    $query->where($field, $value);
                }
            }
            if ($request->query('failed') === '1') {
                $query->where('status', '!=', 'succeeded');
            }
            $ws = $request->query('workspace_id');
            if (is_string($ws) && Str::isUlid($ws)) {
                $query->where('workspace_id', strtolower($ws));
            }

            return Paginated::response($query->orderByDesc('id'), AiRequestResource::class, $request, 50);
        });
    }
}
