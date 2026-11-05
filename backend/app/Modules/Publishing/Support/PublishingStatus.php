<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Support;

use SiteXPost\Kernel\Http\ApiException;

/**
 * Publishing job state machine (WORDPRESS-PUBLISHER.md §States). Only the
 * transitions below are allowed; everything else is a 409.
 */
final class PublishingStatus
{
    public const DRAFT = 'DRAFT';

    public const READY_FOR_REVIEW = 'READY_FOR_REVIEW';

    public const APPROVED = 'APPROVED';

    public const SCHEDULED = 'SCHEDULED';

    public const PUBLISHING = 'PUBLISHING';

    public const PUBLISHED = 'PUBLISHED';

    public const UPDATE_PENDING = 'UPDATE_PENDING';

    public const FAILED = 'FAILED';

    public const CANCELLED = 'CANCELLED';

    public const ALL = [self::DRAFT, self::READY_FOR_REVIEW, self::APPROVED, self::SCHEDULED, self::PUBLISHING, self::PUBLISHED, self::UPDATE_PENDING, self::FAILED, self::CANCELLED];

    /** Terminal states free the "one active job per article/site" slot. */
    public const TERMINAL = [self::PUBLISHED, self::CANCELLED];

    private const ALLOWED = [
        self::DRAFT => [self::READY_FOR_REVIEW, self::CANCELLED],
        self::READY_FOR_REVIEW => [self::APPROVED, self::DRAFT, self::CANCELLED],
        self::APPROVED => [self::SCHEDULED, self::PUBLISHING, self::READY_FOR_REVIEW, self::CANCELLED],
        self::SCHEDULED => [self::PUBLISHING, self::SCHEDULED, self::READY_FOR_REVIEW, self::FAILED, self::CANCELLED],
        self::PUBLISHING => [self::PUBLISHED, self::FAILED, self::UPDATE_PENDING, self::READY_FOR_REVIEW, self::PUBLISHING],
        self::UPDATE_PENDING => [self::PUBLISHING, self::CANCELLED, self::READY_FOR_REVIEW],
        self::FAILED => [self::PUBLISHING, self::READY_FOR_REVIEW, self::CANCELLED],
        self::PUBLISHED => [],
        self::CANCELLED => [],
    ];

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public static function assert(string $from, string $to): void
    {
        if (! self::can($from, $to)) {
            throw ApiException::conflict('invalid_transition', __('publishing.errors.invalid_transition', ['from' => $from, 'to' => $to]));
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public static function map(): array
    {
        return self::ALLOWED;
    }
}
