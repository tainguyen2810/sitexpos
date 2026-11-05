<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use SiteXPost\Kernel\Logging\RedactSecrets;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Audit\Domain\AuditLog;
use SiteXPost\Modules\Platform\Domain\ImpersonationSession;

/**
 * Records security-relevant actions (SECURITY.md §12).
 */
final class Audit
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        array $changes = [],
        ?string $workspaceId = null,
        ?string $actorId = null,
        string $actorType = 'user',
    ): AuditLog {
        $context = app(TenantContext::class);
        /** @var Request|null $request */
        $request = app()->bound('request') ? app('request') : null;

        $workspaceId ??= $context->has() ? $context->workspaceId() : null;
        $actorId ??= $request?->user()?->getAuthIdentifier();

        $impersonatorId = null;
        if ($context->isImpersonating()) {
            $impersonatorId = ImpersonationSession::query()->find($context->impersonationId())?->staff_user_id;
            $actorType = 'platform_staff';
        }

        if ($actorId === null && $actorType === 'user') {
            $actorType = 'system';
        }

        return AuditLog::query()->create([
            'workspace_id' => $workspaceId,
            'actor_type' => $actorType,
            'actor_id' => $actorId !== null ? (string) $actorId : null,
            'impersonator_id' => $impersonatorId,
            'action' => $action,
            'subject_type' => $subject !== null ? class_basename($subject) : null,
            'subject_id' => $subject !== null ? (string) $subject->getKey() : null,
            'changes' => $changes === [] ? null : RedactSecrets::redact($changes),
            'ip' => $request?->ip(),
            'user_agent' => $request !== null ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'request_id' => $request?->attributes->get('request_id'),
        ]);
    }
}
