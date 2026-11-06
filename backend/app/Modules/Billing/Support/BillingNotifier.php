<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use SiteXPost\Modules\Billing\Domain\BillingNotification;
use SiteXPost\Modules\Billing\Notifications\BillingNotice;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Sends a billing notice to the workspace owner at most once per dedupe key
 * (UNIQUE `billing_notifications.dedupe_key`): a re-run scheduler, a webhook
 * replay or a retried job can never send the same notice twice.
 */
final class BillingNotifier
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function send(string $workspaceId, string $type, string $dedupeKey, array $data = []): bool
    {
        try {
            $sent = InTenant::run($workspaceId, function () use ($type, $dedupeKey, $data): bool {
                $row = new BillingNotification;
                $row->forceFill(['type' => $type, 'dedupe_key' => mb_substr($dedupeKey, 0, 191), 'data' => $data, 'sent_at' => now()])->save();

                return true;
            });
        } catch (QueryException) {
            return false; // already sent
        }
        $workspace = Workspace::withTrashed()->with('owner')->find($workspaceId);
        $owner = $workspace?->owner;
        if ($workspace !== null && $owner !== null) {
            try {
                $owner->notify(new BillingNotice($type, (string) $workspace->name, $workspace->id, $data));
            } catch (\Throwable $e) {
                Log::warning('Billing notice could not be queued', ['type' => $type, 'workspace' => $workspaceId, 'error' => $e::class]);
            }
        }

        return $sent;
    }
}
