<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Support;

use Illuminate\Database\QueryException;
use SiteXPost\Modules\Research\Analysis\IntentClassifier;
use SiteXPost\Modules\Research\Domain\Keyword;
use SiteXPost\Modules\Research\Domain\KeywordMetric;
use SiteXPost\Modules\Research\Domain\SeoCampaign;

/**
 * Idempotent keyword upsert keyed by (campaign, folded keyword fingerprint):
 * re-running a step or a whole research never duplicates keywords; sources
 * and evidence are merged. A keyword the user rejected stays rejected.
 */
final class KeywordStore
{
    public const MAX_KEYWORDS_PER_CAMPAIGN = 3000;

    public function __construct(private readonly IntentClassifier $classifier) {}

    /**
     * @param  array{keyword: string, source: string, evidence?: array<string, mixed>|null, topic?: string|null}  $candidate
     */
    public function upsert(SeoCampaign $campaign, array $candidate, ?string $runId): ?Keyword
    {
        $display = Text::clean($candidate['keyword'], 190);
        $normalized = Text::normalize($display);
        $tokens = Text::tokens($normalized);
        if ($normalized === '' || $tokens === [] || count($tokens) > 10 || mb_strlen($normalized) < 3) {
            return null;
        }
        $excluded = $campaign->settingsOrDefault()->excluded_topics ?? [];
        if (Text::matchesAny($normalized, $excluded)) {
            return null;
        }

        $fp = Text::keywordFingerprint($normalized);
        $source = $candidate['source'];
        $evidence = $candidate['evidence'] ?? null;

        $kw = Keyword::query()->where('campaign_id', $campaign->id)->where('fingerprint', $fp)->first();
        if ($kw === null) {
            if (Keyword::query()->where('campaign_id', $campaign->id)->count() >= self::MAX_KEYWORDS_PER_CAMPAIGN) {
                return null;
            }
            $intent = $this->classifier->classify($normalized, $campaign->brand_terms ?? []);
            $kw = new Keyword;
            $kw->forceFill([
                'campaign_id' => $campaign->id,
                'keyword' => $normalized,
                'normalized' => $normalized,
                'fingerprint' => $fp,
                'language' => $campaign->language,
                'country' => $campaign->market,
                'intent' => $intent['intent'],
                'intent_source' => 'rule',
                'topic_category' => isset($candidate['topic']) ? Text::clean($candidate['topic'], 120) : null,
                'sources' => [$source],
                'primary_source' => $source,
                'evidence' => $evidence !== null ? [$evidence] : [],
                'status' => 'suggested',
                'last_seen_run_id' => $runId,
                'first_seen_at' => now(),
                'data_collected_at' => now(),
            ]);
            try {
                $kw->save();
            } catch (QueryException) {
                // concurrent insert of the same fingerprint: fall through to merge
                $kw = Keyword::query()->where('campaign_id', $campaign->id)->where('fingerprint', $fp)->first();
                if ($kw === null) {
                    return null;
                }
            }

            if ($kw->wasRecentlyCreated) {
                return $kw;
            }
        }

        $sources = array_values(array_unique(array_merge($kw->sources ?? [], [$source])));
        $ev = $kw->evidence ?? [];
        if ($evidence !== null && count($ev) < 8 && ! in_array($evidence, $ev, true)) {
            $ev[] = $evidence;
        }
        $kw->forceFill([
            'sources' => $sources,
            'evidence' => $ev,
            'last_seen_run_id' => $runId,
            'data_collected_at' => now(),
            'topic_category' => $kw->topic_category ?? (isset($candidate['topic']) ? Text::clean($candidate['topic'], 120) : null),
        ])->save();

        return $kw;
    }

    /**
     * Verified metrics from a real source (Search Console, keyword provider).
     *
     * @param  array<string, mixed>  $values
     */
    public function metric(Keyword $keyword, string $source, string $periodStart, string $periodEnd, array $values): KeywordMetric
    {
        $metric = KeywordMetric::query()->where(['keyword_id' => $keyword->id, 'source' => $source, 'period_start' => $periodStart])->first() ?? new KeywordMetric;
        $metric->forceFill([
            'keyword_id' => $keyword->id,
            'source' => $source,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'search_volume' => $values['search_volume'] ?? null,
            'difficulty' => $values['difficulty'] ?? null,
            'cpc_micro_usd' => $values['cpc_micro_usd'] ?? null,
            'impressions' => $values['impressions'] ?? null,
            'clicks' => $values['clicks'] ?? null,
            'ctr' => $values['ctr'] ?? null,
            'position' => $values['position'] ?? null,
            'trend' => $values['trend'] ?? null,
            'collected_at' => now(),
        ])->save();

        return $metric;
    }
}
