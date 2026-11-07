<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A draft finished the writer pipeline (DraftWorkflow::finalize). Other
 * modules react through this event only (Media: auto images). `minImages`
 * is the article's image target from its writing preset or the platform
 * default; 0 means "do not add images automatically".
 */
final class ArticleDraftReady
{
    use Dispatchable;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $articleId,
        public readonly ?string $versionId,
        public readonly int $minImages,
    ) {}
}
