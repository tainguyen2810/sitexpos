<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Support;

use RuntimeException;
use SiteXPost\Modules\Writer\Enums\ArticleStatus;

final class InvalidArticleTransition extends RuntimeException
{
    public function __construct(public readonly ArticleStatus $from, public readonly ArticleStatus $to, ?string $reason = null)
    {
        parent::__construct($reason ?? "Article cannot move from {$from->value} to {$to->value}.");
    }
}
