<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Console;

use Illuminate\Console\Command;
use SiteXPost\Modules\AiGateway\Registry\AiRegistry;

final class SyncAiRegistry extends Command
{
    protected $signature = 'sitex:ai:sync-registry';

    protected $description = 'Insert missing default AI providers, models, price versions and task routes (never overwrites admin changes)';

    public function handle(AiRegistry $registry): int
    {
        $created = $registry->sync();
        $this->info(sprintf('AI registry synced: %d providers, %d models, %d prices, %d routes created.', ...array_values($created)));

        return self::SUCCESS;
    }
}
