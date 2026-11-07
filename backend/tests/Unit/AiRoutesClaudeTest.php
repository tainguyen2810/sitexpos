<?php

use SiteXPost\Modules\AiGateway\Registry\AiRegistry;

/*
 * v1.0.7: a platform with only an Anthropic key must be able to run every
 * text task, so each text route lists a Claude model (primary, economy or
 * fallback). Image routes are exempt (Claude does not generate images).
 */
it('gives every text route a Claude model', function () {
    $kinds = collect(AiRegistry::models())->mapWithKeys(fn ($m) => [$m['provider'].'/'.$m['name'] => $m['kind'] ?? 'text']);
    $missing = [];
    foreach (AiRegistry::routes() as $task => $r) {
        if (($kinds[$r['primary']] ?? 'text') !== 'text') {
            continue;
        }
        $all = array_filter([$r['primary'], $r['economy'] ?? null, ...($r['fallbacks'] ?? [])]);
        if (! collect($all)->contains(fn ($k) => str_starts_with($k, 'anthropic/'))) {
            $missing[] = $task;
        }
    }
    expect($missing)->toBe([]);
});
