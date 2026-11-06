<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SiteXPost\Modules\System\Monitoring\AlertDispatcher;

final class SendSystemAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $checkKey, public readonly string $severity, public readonly string $summary, public readonly bool $force = false)
    {
        $this->onQueue('notifications');
    }

    public function handle(AlertDispatcher $alerts): void
    {
        $alerts->send($this->checkKey, $this->severity, $this->summary, $this->force);
    }
}
