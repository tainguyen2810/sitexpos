<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Enums;

enum RiskLevel: string
{
    case Low = 'LOW';
    case Medium = 'MEDIUM';
    case High = 'HIGH';
    case Critical = 'CRITICAL';

    public function weight(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }

    public static function max(self $a, self $b): self
    {
        return $a->weight() >= $b->weight() ? $a : $b;
    }
}
