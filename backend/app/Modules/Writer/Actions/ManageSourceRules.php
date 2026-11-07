<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Actions;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Writer\Domain\SourceRule;
use SiteXPost\Modules\Writer\Support\SourceFilter;

/**
 * Add / change / remove a domain in the workspace source filter. Adding a
 * domain that already exists switches its mode (one row per domain).
 */
final class ManageSourceRules
{
    public const MAX_RULES = 300;

    public function __construct(private readonly SourceFilter $filter) {}

    public function save(string $domain, string $mode, ?string $note, ?string $userId): SourceRule
    {
        $domain = SourceFilter::normalizeDomain($domain) ?? throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['domain' => [__('writer.errors.invalid_domain')]]);
        $rule = SourceRule::query()->where('domain', $domain)->first();
        if ($rule === null && SourceRule::query()->count() >= self::MAX_RULES) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['domain' => [__('writer.errors.too_many_source_rules', ['max' => self::MAX_RULES])]]);
        }
        $before = $rule?->mode;
        $rule ??= new SourceRule;
        $rule->forceFill(['domain' => $domain, 'mode' => $mode, 'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 300) : null]);
        if (! $rule->exists) {
            $rule->created_by = $userId;
        }
        $rule->save();
        $this->filter->flush();
        Audit::record('writer.source_rule_saved', $rule, ['domain' => $domain, 'mode' => $mode, 'before' => $before]);

        return $rule;
    }

    public function delete(SourceRule $rule): void
    {
        Audit::record('writer.source_rule_deleted', $rule, ['domain' => $rule->domain, 'mode' => $rule->mode]);
        $rule->delete();
        $this->filter->flush();
    }
}
