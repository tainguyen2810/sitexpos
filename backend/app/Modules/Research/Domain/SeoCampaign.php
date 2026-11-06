<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * An SEO research campaign for one site (industry, market, language, goals).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $name
 * @property string $industry
 * @property string $market
 * @property string $language
 * @property list<string>|null $products
 * @property list<string>|null $services
 * @property list<string>|null $audiences
 * @property list<string>|null $goals
 * @property list<string>|null $brand_terms
 * @property string $status
 * @property bool $is_demo
 * @property ?string $created_by
 * @property ?Carbon $last_run_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SeoCampaign extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'seo_campaigns';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'products' => 'array',
            'services' => 'array',
            'audiences' => 'array',
            'goals' => 'array',
            'brand_terms' => 'array',
            'is_demo' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * @return HasOne<CampaignSettings, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(CampaignSettings::class, 'campaign_id');
    }

    /**
     * @return HasOne<ResearchSchedule, $this>
     */
    public function schedule(): HasOne
    {
        return $this->hasOne(ResearchSchedule::class, 'campaign_id');
    }

    /**
     * @return HasMany<ResearchRun, $this>
     */
    public function researchRuns(): HasMany
    {
        return $this->hasMany(ResearchRun::class, 'campaign_id');
    }

    /**
     * @return HasMany<Keyword, $this>
     */
    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class, 'campaign_id');
    }

    /**
     * @return HasMany<ContentIdea, $this>
     */
    public function ideas(): HasMany
    {
        return $this->hasMany(ContentIdea::class, 'campaign_id');
    }

    /**
     * @return HasMany<TopicCluster, $this>
     */
    public function clusters(): HasMany
    {
        return $this->hasMany(TopicCluster::class, 'campaign_id');
    }

    /**
     * @return HasMany<ContentGap, $this>
     */
    public function gaps(): HasMany
    {
        return $this->hasMany(ContentGap::class, 'campaign_id');
    }

    /**
     * @return HasMany<CompetitorWebsite, $this>
     */
    public function competitors(): HasMany
    {
        return $this->hasMany(CompetitorWebsite::class, 'campaign_id');
    }

    /**
     * @return HasMany<ContentCalendarItem, $this>
     */
    public function calendarItems(): HasMany
    {
        return $this->hasMany(ContentCalendarItem::class, 'campaign_id');
    }

    /**
     * @return HasMany<InternalLinkSuggestion, $this>
     */
    public function linkSuggestions(): HasMany
    {
        return $this->hasMany(InternalLinkSuggestion::class, 'campaign_id');
    }

    /**
     * Knowledge statements of the campaign's site (route binding scope).
     *
     * @return HasManyThrough<WebsiteKnowledge, WebsiteProfile, $this>
     */
    public function knowledge(): HasManyThrough
    {
        return $this->hasManyThrough(WebsiteKnowledge::class, WebsiteProfile::class, 'site_id', 'website_profile_id', 'site_id', 'id');
    }

    public function settingsOrDefault(): CampaignSettings
    {
        return $this->settings ?? CampaignSettings::defaultsFor($this);
    }
}
