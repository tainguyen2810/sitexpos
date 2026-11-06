<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Audit;

/**
 * One explained rule result. Points: PASS = weight, WARNING = weight / 2,
 * ERROR / BLOCKED = 0.
 */
final readonly class AuditIssue
{
    public const PASS = 'PASS';

    public const WARNING = 'WARNING';

    public const ERROR = 'ERROR';

    public const BLOCKED = 'BLOCKED';

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $rule,
        public string $category,
        public string $severity,
        public int $weight,
        public string $message,
        public string $explanation,
        public ?string $fix = null,
        public array $context = [],
    ) {}

    public function points(): float
    {
        return match ($this->severity) {
            self::PASS => (float) $this->weight,
            self::WARNING => $this->weight / 2,
            default => 0.0,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['rule' => $this->rule, 'category' => $this->category, 'severity' => $this->severity, 'weight' => $this->weight, 'points' => $this->points(),
            'message' => $this->message, 'explanation' => $this->explanation, 'fix' => $this->fix, 'context' => $this->context];
    }
}
