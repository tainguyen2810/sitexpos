<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Research\Analysis\IdeaPlanner;
use SiteXPost\Modules\Research\Analysis\IntentClassifier;
use SiteXPost\Modules\Research\Analysis\OpportunityScorer;
use SiteXPost\Modules\Research\Domain\CampaignSettings;
use SiteXPost\Modules\Research\Domain\ContentCalendarItem;
use SiteXPost\Modules\Research\Domain\ContentIdea;
use SiteXPost\Modules\Research\Domain\ContentIdeaKeyword;
use SiteXPost\Modules\Research\Domain\Keyword;
use SiteXPost\Modules\Research\Domain\ResearchSchedule;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Domain\TopicCluster;
use SiteXPost\Modules\Research\Domain\WebsiteKnowledge;
use SiteXPost\Modules\Research\Domain\WebsiteProfile;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Enums\SiteStatus;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * DEVELOPMENT ONLY Phase 4 demo: a tech store with 3 campaigns, 5 topic
 * clusters, 100 keywords, 50 ideas and one content calendar.
 *
 * Everything is labelled DEMO (campaign names, is_demo = true, source
 * "demo"). No search volume / difficulty is invented: demo keywords have no
 * metrics and show "Chưa có dữ liệu", exactly like production without a
 * provider. Refuses to run when APP_ENV=production.
 */
class ResearchDemoSeeder extends Seeder
{
    public const SITE_URL = 'https://techzone.demo.sitexpost.test';

    /** cluster name => [pillar keyword, intent, keywords...] */
    private const CLUSTERS = [
        'Laptop gaming' => ['laptop gaming', 'commercial', [
            'laptop gaming dưới 20 triệu', 'laptop gaming tốt nhất cho sinh viên', 'so sánh laptop gaming asus và msi', 'cách tản nhiệt laptop gaming',
            'laptop gaming bị nóng phải làm sao', 'nâng cấp ram laptop gaming', 'laptop gaming màn hình 165hz', 'card đồ họa rtx cho laptop gaming',
            'laptop gaming mỏng nhẹ', 'đánh giá laptop gaming rog strix', 'laptop gaming chơi game nặng', 'cách chọn laptop gaming',
            'laptop gaming pin trâu', 'laptop gaming cũ có nên mua', 'laptop gaming cho lập trình', 'laptop gaming và laptop đồ họa khác nhau',
            'giá laptop gaming chính hãng', 'laptop gaming không nhận tai nghe', 'mua laptop gaming trả góp',
        ]],
        'Laptop văn phòng & học tập' => ['laptop văn phòng', 'informational', [
            'laptop cho sinh viên', 'laptop văn phòng giá rẻ', 'laptop mỏng nhẹ pin lâu', 'laptop cho dân kế toán', 'laptop học online',
            'cách chọn laptop cho sinh viên', 'laptop dưới 15 triệu', 'so sánh macbook air và laptop windows', 'laptop chạy chậm phải làm sao',
            'cách sạc pin laptop đúng cách', 'laptop 2 trong 1 có nên mua', 'laptop màn hình cảm ứng', 'laptop cho giáo viên', 'cách vệ sinh laptop tại nhà',
            'laptop không nhận wifi', 'nâng cấp ssd cho laptop', 'laptop văn phòng tốt nhất', 'đánh giá laptop dell inspiron', 'laptop cho người lớn tuổi',
        ]],
        'Linh kiện PC' => ['linh kiện pc', 'informational', [
            'cách build pc gaming', 'cấu hình pc 15 triệu', 'chọn nguồn máy tính', 'so sánh ssd nvme và sata', 'ram ddr5 có cần thiết không',
            'cách lắp ráp máy tính', 'card màn hình cho đồ họa', 'tản nhiệt nước hay tản nhiệt khí', 'mainboard cho intel thế hệ mới',
            'pc không lên màn hình', 'cách chọn case máy tính', 'cpu amd hay intel', 'nâng cấp card màn hình', 'pc bị treo khi chơi game',
            'giá linh kiện pc hôm nay', 'cấu hình pc làm video', 'màn hình 2k cho pc', 'cách đo nhiệt độ cpu', 'mua linh kiện pc chính hãng',
        ]],
        'Tai nghe & âm thanh' => ['tai nghe', 'commercial', [
            'tai nghe chống ồn', 'tai nghe bluetooth giá rẻ', 'tai nghe gaming có mic', 'so sánh tai nghe sony và bose', 'tai nghe true wireless tốt nhất',
            'cách kết nối tai nghe bluetooth với laptop', 'tai nghe bị rè phải làm sao', 'tai nghe chống ồn có hại tai không', 'tai nghe cho dân văn phòng',
            'loa bluetooth nghe nhạc hay', 'cách vệ sinh tai nghe', 'tai nghe có dây hay không dây', 'đánh giá tai nghe sony wh 1000xm6',
            'tai nghe chụp tai cho học online', 'dac và amp cho tai nghe', 'tai nghe bluetooth bị trễ tiếng', 'tai nghe thể thao chống nước',
            'mua tai nghe chính hãng', 'tai nghe cho trẻ em',
        ]],
        'Phụ kiện bàn làm việc' => ['phụ kiện bàn làm việc', 'commercial', [
            'bàn phím cơ cho người mới', 'chuột không dây văn phòng', 'so sánh switch bàn phím cơ', 'cách vệ sinh bàn phím cơ', 'giá đỡ laptop',
            'webcam cho học online', 'bàn phím cơ không dây', 'chuột gaming nhẹ', 'hub usb c cho laptop', 'đèn bàn chống cận',
            'bàn phím bị liệt phím', 'lót chuột cỡ lớn', 'ghế công thái học có đáng mua', 'bàn nâng hạ điện', 'cách setup góc làm việc',
            'đánh giá bàn phím keychron', 'chuột bị double click', 'mua bàn phím cơ chính hãng', 'màn hình phụ cho laptop',
        ]],
    ];

    public function run(TenantContext $context): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('ResearchDemoSeeder is disabled in production.');
        }
        $workspace = Workspace::query()->where('name', 'Cửa hàng A (Demo)')->where('is_demo', true)->first();
        if ($workspace === null) {
            $this->command?->warn('Run DemoSeeder first (workspace "Cửa hàng A (Demo)" not found).');

            return;
        }

        $context->runAs($workspace, null, function (): void {
            if (SeoCampaign::query()->where('is_demo', true)->exists()) {
                $this->command?->info('Research demo data already present.');

                return;
            }
            $site = $this->site();
            $this->profile($site);

            $main = $this->campaign($site, '[DEMO] SEO cửa hàng TechZone', 'active', ['laptop', 'linh kiện pc', 'tai nghe', 'bàn phím cơ', 'phụ kiện'], ['bảo hành', 'build pc theo yêu cầu']);
            $this->campaign($site, '[DEMO] Mùa tựu trường', 'active', ['laptop sinh viên', 'tai nghe học online'], []);
            $this->campaign($site, '[DEMO] Gaming gear Q4', 'paused', ['laptop gaming', 'chuột gaming', 'bàn phím cơ'], []);

            $ideas = $this->plan($main);
            $this->calendar($main, $ideas);
        });

        $this->command?->info('Research demo: 3 campaigns, 5 clusters, 100 keywords, 50 ideas, 1 calendar (all labelled DEMO, no fake metrics).');
    }

    private function site(): Site
    {
        $site = Site::query()->where('url_hash', Site::hashUrl(self::SITE_URL))->first() ?? new Site;
        $site->fill(['name' => 'TechZone (DEMO)']);
        // Never paired: no health checks, no publishing, no calls to the fake domain.
        $site->forceFill(['url' => self::SITE_URL, 'url_hash' => Site::hashUrl(self::SITE_URL), 'status' => SiteStatus::PendingPairing])->save();

        return $site;
    }

    private function profile(Site $site): void
    {
        $profile = WebsiteProfile::query()->where('site_id', $site->id)->first() ?? new WebsiteProfile;
        $profile->forceFill(['site_id' => $site->id, 'title' => 'TechZone (DEMO)', 'tagline' => 'Laptop, linh kiện PC và phụ kiện chính hãng',
            'description' => 'Dữ liệu DEMO: cửa hàng công nghệ bán laptop, linh kiện PC, tai nghe và phụ kiện bàn làm việc.', 'language' => 'vi', 'region' => 'VN',
            'status' => 'ready', 'covered_topics' => ['laptop gaming', 'tai nghe bluetooth'], 'stats' => ['demo' => true], 'analyzed_at' => now()])->save();
        foreach ([['industry', 'Bán lẻ thiết bị công nghệ'], ['product_line', 'Laptop'], ['product_line', 'Linh kiện PC'], ['product_line', 'Tai nghe'],
            ['service', 'Bảo hành 24 tháng'], ['audience', 'Sinh viên'], ['audience', 'Game thủ'], ['audience', 'Dân văn phòng'], ['region', 'Việt Nam']] as [$kind, $value]) {
            $k = new WebsiteKnowledge;
            $k->forceFill(['website_profile_id' => $profile->id, 'kind' => $kind, 'value' => $value, 'source' => 'demo', 'confidence' => 70,
                'status' => 'suggested', 'fingerprint' => Text::fingerprint($kind, Text::fold($value)), 'evidence' => [['type' => 'demo']]])->save();
        }
    }

    /**
     * @param  list<string>  $products
     * @param  list<string>  $services
     */
    private function campaign(Site $site, string $name, string $status, array $products, array $services): SeoCampaign
    {
        $c = new SeoCampaign;
        $c->forceFill(['site_id' => $site->id, 'name' => $name, 'industry' => 'Bán lẻ thiết bị công nghệ', 'market' => 'VN', 'language' => 'vi',
            'products' => $products, 'services' => $services, 'audiences' => ['sinh viên', 'game thủ', 'dân văn phòng'], 'goals' => ['traffic', 'sales'],
            'brand_terms' => ['TechZone'], 'status' => $status, 'is_demo' => true])->save();
        CampaignSettings::defaultsFor($c)->save();
        $sched = new ResearchSchedule;
        $sched->forceFill(['campaign_id' => $c->id, 'frequency' => 'weekly', 'day_of_week' => 1, 'hour_utc' => 2, 'is_enabled' => false])->save();

        return $c;
    }

    /**
     * @return list<ContentIdea>
     */
    private function plan(SeoCampaign $campaign): array
    {
        $classifier = new IntentClassifier;
        $scorer = new OpportunityScorer;
        $vocab = [];
        foreach (array_merge($campaign->products ?? [], array_keys(self::CLUSTERS)) as $p) {
            foreach (Text::topicTokens($p) as $t) {
                $vocab[$t] = true;
            }
        }
        $ideas = [];
        foreach (self::CLUSTERS as $clusterName => [$pillarKw, $clusterIntent, $list]) {
            $cluster = new TopicCluster;
            $cluster->forceFill(['campaign_id' => $campaign->id, 'name' => $clusterName.' (DEMO)', 'fingerprint' => Text::fingerprint('cluster', Text::fold($clusterName)),
                'intent' => $clusterIntent, 'status' => 'active', 'keywords_count' => count($list) + 1])->save();

            $keywords = [];
            foreach (array_merge([$pillarKw], $list) as $i => $text) {
                $cls = $classifier->classify($text, ['TechZone']);
                $score = $scorer->score(['topic_tokens' => Text::topicTokens($text), 'intent' => $cls['intent'], 'sources' => ['demo'], 'vocabulary' => $vocab,
                    'product_vocabulary' => $vocab, 'goals' => ['traffic', 'sales'], 'coverage' => $i % 7 === 3 ? 'partial' : 'none', 'duplicate_same_intent' => false,
                    'gsc' => null, 'provider' => null]);
                $kw = new Keyword;
                $kw->forceFill(['campaign_id' => $campaign->id, 'cluster_id' => $cluster->id, 'keyword' => $text, 'normalized' => Text::normalize($text),
                    'fingerprint' => Text::keywordFingerprint($text), 'language' => 'vi', 'country' => 'VN', 'intent' => $cls['intent'], 'intent_source' => 'rule',
                    'topic_category' => $clusterName, 'sources' => ['demo'], 'primary_source' => 'demo', 'evidence' => [['type' => 'demo', 'note' => 'Dữ liệu DEMO']],
                    'status' => $i < 12 ? 'approved' : 'suggested', 'opportunity_score' => $score['score'],
                    'score_breakdown' => $score + ['coverage' => $i % 7 === 3 ? 'partial' : 'none', 'content_type' => $cls['content_type']],
                    'first_seen_at' => now(), 'data_collected_at' => now()])->save();
                $keywords[] = [$kw, $cls];
            }
            $cluster->forceFill(['pillar_keyword_id' => $keywords[0][0]->id, 'opportunity_score' => (int) round(collect($keywords)->avg(fn ($k) => $k[0]->opportunity_score))])->save();

            // 10 ideas per cluster: the pillar + the 9 best-scoring articles.
            $articles = collect(array_slice($keywords, 1))->sortByDesc(fn ($k) => $k[0]->opportunity_score)->take(9)->values();
            foreach (array_merge([$keywords[0]], $articles->all()) as $n => [$kw, $cls]) {
                $pillar = $n === 0;
                $type = $pillar ? 'pillar' : $cls['content_type'];
                $idea = new ContentIdea;
                $idea->forceFill(['campaign_id' => $campaign->id, 'cluster_id' => $cluster->id, 'primary_keyword_id' => $kw->id,
                    'title' => IdeaPlanner::ruleTitle($kw->keyword, $type, 'vi'), 'search_intent' => $kw->intent, 'content_type' => $type,
                    'audience' => 'Sinh viên, game thủ, dân văn phòng', 'priority' => $kw->opportunity_score >= 70 ? 'high' : ($kw->opportunity_score >= 45 ? 'medium' : 'low'),
                    'opportunity_score' => $kw->opportunity_score, 'score_breakdown' => $kw->score_breakdown,
                    'rationale' => 'Dữ liệu DEMO — minh họa cách SiteX Post giải thích lý do đề xuất.', 'evidence' => ['keyword_sources' => ['demo'], 'outline' => []],
                    'source' => 'demo', 'is_pillar' => $pillar, 'status' => $n < 4 ? 'approved' : 'suggested',
                    'fingerprint' => IdeaPlanner::fingerprint($pillar ? 'pillar' : IntentClassifier::family($type), $kw->keyword)])->save();
                $link = new ContentIdeaKeyword;
                $link->forceFill(['content_idea_id' => $idea->id, 'keyword_id' => $kw->id, 'role' => 'primary'])->save();
                $ideas[] = $idea;
            }
        }

        return $ideas;
    }

    /**
     * @param  list<ContentIdea>  $ideas
     */
    private function calendar(SeoCampaign $campaign, array $ideas): void
    {
        $weekdays = [1, 3, 5];
        $date = now()->startOfWeek()->addWeek();
        $n = 0;
        foreach (array_filter($ideas, fn (ContentIdea $i) => $i->status === 'approved') as $idea) {
            while (! in_array($date->dayOfWeekIso, $weekdays, true)) {
                $date->addDay();
            }
            $item = new ContentCalendarItem;
            $item->forceFill(['campaign_id' => $campaign->id, 'content_idea_id' => $idea->id, 'cluster_id' => $idea->cluster_id,
                'scheduled_for' => $date->toDateString(), 'position' => 0, 'status' => $n < 6 ? 'approved' : 'proposed', 'source' => 'demo',
                'notes' => 'DEMO'])->save();
            $date->addDay();
            $n++;
        }
    }
}
