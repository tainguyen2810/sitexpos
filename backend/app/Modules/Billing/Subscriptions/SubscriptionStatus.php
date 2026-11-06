<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Subscriptions;

/**
 * SUBSCRIPTION-LIFECYCLE.md. Only the transitions listed in `allowedNext()`
 * are possible; every transition is written to `subscription_events`.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'TRIALING';
    case Active = 'ACTIVE';
    case PastDue = 'PAST_DUE';
    case GracePeriod = 'GRACE_PERIOD';
    case Paused = 'PAUSED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';
    case Suspended = 'SUSPENDED';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Trialing => [self::Active, self::Cancelled, self::Expired, self::Suspended],
            self::Active => [self::Active, self::PastDue, self::GracePeriod, self::Paused, self::Cancelled, self::Expired, self::Suspended],
            self::PastDue => [self::Active, self::GracePeriod, self::Cancelled, self::Expired, self::Suspended],
            self::GracePeriod => [self::Active, self::Cancelled, self::Expired, self::Suspended],
            self::Paused => [self::Active, self::Cancelled, self::Expired, self::Suspended],
            self::Cancelled => [self::Active, self::Expired, self::Suspended],
            self::Expired => [self::Active],
            self::Suspended => [self::Active, self::Trialing, self::PastDue, self::GracePeriod, self::Paused, self::Cancelled, self::Expired],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    /** Ended subscriptions free the workspace's single "live" slot. */
    public function isLive(): bool
    {
        return $this !== self::Expired;
    }

    /**
     * May the workspace start NEW paid work (AI, automation)? Data access is
     * never removed by status — articles, research and websites stay readable.
     */
    public function allowsPaidUsage(bool $graceAllowsAi): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::Cancelled => true, // cancelled = until period end
            self::PastDue, self::GracePeriod => $graceAllowsAi,
            self::Paused, self::Expired, self::Suspended => false,
        };
    }
}
