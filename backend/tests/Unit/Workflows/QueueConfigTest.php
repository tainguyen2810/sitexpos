<?php

use SiteXPost\Modules\AiGateway\Registry\AiRegistry;
use SiteXPost\Modules\Workflows\Jobs\ProcessWorkflow;
use SiteXPost\Modules\Workflows\Support\Queues;

it('has a Horizon supervisor for every queue a job can use', function () {
    $listened = collect(config('horizon.defaults'))->flatMap(fn (array $s) => (array) $s['queue'])->unique()->values()->all();

    foreach (Queues::ALL as $queue) {
        expect($listened)->toContain($queue);
    }
    foreach (['research', 'writing', 'factcheck', 'media', 'publishing', 'analytics', 'billing', 'notifications'] as $required) {
        expect(Queues::ALL)->toContain($required);
    }
});

it('keeps job timeout < queue retry_after < workflow lease', function () {
    $retryAfter = (int) config('queue.connections.redis.retry_after');
    $lease = (int) config('sitex.workflows.lease_seconds');
    $timeouts = collect(config('horizon.defaults'))->pluck('timeout')->max();

    expect($timeouts)->toBeLessThan($retryAfter)
        ->and($retryAfter)->toBeLessThan($lease)
        ->and((new ReflectionClass(ProcessWorkflow::class))->getProperty('timeout')->getDefaultValue())->toBeLessThan($retryAfter);
});

it('routes every AI task to a known queue', function () {
    foreach (AiRegistry::routes() as $key => $route) {
        expect(in_array($route['queue'], Queues::ALL, true))->toBeTrue("route {$key}");
    }
});
