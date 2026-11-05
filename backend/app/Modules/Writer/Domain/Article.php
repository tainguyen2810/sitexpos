<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;
use SiteXPost\Modules\Writer\Enums\ArticleStatus;

/**
 * An article being researched / written / fact-checked / reviewed.
 * Child rows are reached through these relations so scoped route bindings
 * (`{version:id}`, `{claim:id}`...) can never cross articles or tenants.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property ?string $campaign_id
 * @property ?string $content_idea_id
 * @property ?string $writing_preset_id
 * @property ?string $writing_batch_id
 * @property string $mode
 * @property string $title
 * @property ArticleStatus $status
 * @property ?string $status_before_failure
 * @property string $primary_keyword
 * @property list<string>|null $secondary_keywords
 * @property string $search_intent
 * @property string $content_type
 * @property string $language
 * @property string $country
 * @property array<string, mixed>|null $settings
 * @property string $review_mode
 * @property int $budget_limit_micro_usd
 * @property int $spent_micro_usd
 * @property ?string $current_version_id
 * @property ?string $approved_version_id
 * @property int $revision_rounds
 * @property int $unresolved_critical
 * @property int $unresolved_high
 * @property ?int $quality_score
 * @property array<string, mixed>|null $duplicate_check
 * @property ?string $duplicate_decision
 * @property ?string $blocked_reason
 * @property ?string $last_error_code
 * @property ?string $last_error_message
 * @property ?string $autosave_html
 * @property ?string $autosave_base_version_id
 * @property ?Carbon $autosaved_at
 * @property int $lock_version
 * @property ?string $idempotency_key
 * @property string $fingerprint
 * @property bool $is_demo
 * @property ?string $created_by
 * @property ?string $approved_by
 * @property ?Carbon $approved_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class Article extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'articles';

    protected $guarded = ['*'];

    protected $hidden = ['autosave_html'];

    protected function casts(): array
    {
        return [
            'status' => ArticleStatus::class,
            'secondary_keywords' => 'array',
            'settings' => 'array',
            'duplicate_check' => 'array',
            'budget_limit_micro_usd' => 'integer',
            'spent_micro_usd' => 'integer',
            'revision_rounds' => 'integer',
            'unresolved_critical' => 'integer',
            'unresolved_high' => 'integer',
            'quality_score' => 'integer',
            'lock_version' => 'integer',
            'is_demo' => 'boolean',
            'autosaved_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /** @return HasMany<ArticleVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ArticleVersion::class, 'article_id');
    }

    /** @return BelongsTo<ArticleVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'current_version_id');
    }

    /** @return HasMany<ArticleSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(ArticleSection::class, 'article_id');
    }

    /** @return HasMany<ResearchDossier, $this> */
    public function dossiers(): HasMany
    {
        return $this->hasMany(ResearchDossier::class, 'article_id');
    }

    /** @return HasOne<ResearchDossier, $this> */
    public function dossier(): HasOne
    {
        return $this->hasOne(ResearchDossier::class, 'article_id')->latestOfMany();
    }

    /** @return HasMany<ContentBrief, $this> */
    public function briefs(): HasMany
    {
        return $this->hasMany(ContentBrief::class, 'article_id');
    }

    /** @return HasOne<ContentBrief, $this> */
    public function brief(): HasOne
    {
        return $this->hasOne(ContentBrief::class, 'article_id')->ofMany('version', 'max');
    }

    /** @return HasMany<ArticleOutline, $this> */
    public function outlines(): HasMany
    {
        return $this->hasMany(ArticleOutline::class, 'article_id');
    }

    /** @return HasOne<ArticleOutline, $this> */
    public function outline(): HasOne
    {
        return $this->hasOne(ArticleOutline::class, 'article_id')->ofMany('version', 'max');
    }

    /** @return HasMany<ArticleClaim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(ArticleClaim::class, 'article_id');
    }

    /** @return HasMany<FactCheckRun, $this> */
    public function factCheckRuns(): HasMany
    {
        return $this->hasMany(FactCheckRun::class, 'article_id');
    }

    /** @return HasMany<ArticleRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(ArticleRevision::class, 'article_id');
    }

    /** @return HasMany<QualityReport, $this> */
    public function qualityReports(): HasMany
    {
        return $this->hasMany(QualityReport::class, 'article_id');
    }

    /** @return HasMany<EditorialApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(EditorialApproval::class, 'article_id');
    }

    /** @return HasMany<ArticleWorkflowRun, $this> */
    public function workflowRuns(): HasMany
    {
        return $this->hasMany(ArticleWorkflowRun::class, 'article_id');
    }

    /** @return HasMany<ArticleAiUsage, $this> */
    public function aiUsage(): HasMany
    {
        return $this->hasMany(ArticleAiUsage::class, 'article_id');
    }

    /** @return BelongsTo<WritingPreset, $this> */
    public function preset(): BelongsTo
    {
        return $this->belongsTo(WritingPreset::class, 'writing_preset_id');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function remainingBudget(): int
    {
        return max(0, $this->budget_limit_micro_usd - $this->spent_micro_usd);
    }
}
