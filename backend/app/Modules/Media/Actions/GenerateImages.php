<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Actions;

use Illuminate\Database\QueryException;
use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Modules\AiGateway\Domain\AiModel;
use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;
use SiteXPost\Modules\AiGateway\Gateway\AiGateway;
use SiteXPost\Modules\AiGateway\Gateway\AiGatewayException;
use SiteXPost\Modules\AiGateway\Gateway\AiTask;
use SiteXPost\Modules\AiGateway\Gateway\CostCalculator;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Media\Domain\ImageGenerationJob;
use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Media\Jobs\MediaJob;
use SiteXPost\Modules\Media\Jobs\RunImageGeneration;
use SiteXPost\Modules\Media\Support\AltText;
use SiteXPost\Modules\Media\Support\ImagePromptBuilder;
use SiteXPost\Modules\Media\Support\MediaLimits;
use SiteXPost\Modules\Media\Support\MediaPipeline;
use SiteXPost\Modules\Media\Support\MediaRejected;
use SiteXPost\Modules\Research\Contracts\PlannerDirectory;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;
use SiteXPost\Modules\Writer\Support\ArticleDocument;

/**
 * AI image generation through the Phase 3 AI Gateway, task `media.image`.
 * Only models with the `image_generation` capability are routed (the
 * gateway's TaskRouter filters them) — text models such as DeepSeek are
 * never assumed to draw. Flow: prompt preview (editable) → estimate vs the
 * job budget cap and the workspace/global AI budgets → queued call with a
 * stable call key (a duplicate delivery never pays twice) → each returned
 * image is validated and processed like an upload, labelled AI-generated
 * (license `ai_generated`, caption "Ảnh minh họa tạo bằng AI").
 */
final class GenerateImages
{
    public const TASK = 'media.image';

    public function __construct(
        private readonly ImagePromptBuilder $prompts,
        private readonly MediaLimits $limits,
        private readonly MediaPipeline $pipeline,
        private readonly ArticleCatalog $articles,
        private readonly SiteDirectory $sites,
        private readonly PlannerDirectory $planner,
        private readonly CostCalculator $costs,
    ) {}

    /**
     * @param  array<string, mixed>  $in
     * @return array{prompt: string, estimate_micro_usd: int, models: list<array{model_key: string, provider: string}>, limits: array<string, mixed>}
     */
    public function preview(string $workspaceId, array $in): array
    {
        $prompt = isset($in['prompt']) && trim((string) $in['prompt']) !== '' ? $this->prompts->scrub((string) $in['prompt']) : $this->prompts->build($this->context($in));

        return [
            'prompt' => $prompt,
            'estimate_micro_usd' => $this->estimate($prompt, (int) ($in['image_count'] ?? 1)),
            'models' => $this->imageModels(),
            'limits' => $this->limits->for($workspaceId),
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array{job: ImageGenerationJob, job_run: JobRun|null}
     *
     * @throws MediaRejected
     */
    public function create(string $workspaceId, array $in, ?string $userId, ?string $idempotencyKey, ?ImageGenerationJob $parent = null): array
    {
        $count = (int) ($in['image_count'] ?? 1);
        $this->limits->assertCanGenerate($workspaceId, $count);
        $context = $this->context($in);
        $edited = isset($in['prompt']) && trim((string) $in['prompt']) !== '';
        $prompt = $edited ? $this->prompts->scrub((string) $in['prompt']) : $this->prompts->build($context);
        $limits = $this->limits->for($workspaceId);
        $cap = min((int) ($in['budget_cap_micro_usd'] ?? $limits['max_ai_cost_per_job_micro_usd']), $limits['max_ai_cost_per_job_micro_usd']);
        $estimate = $this->estimate($prompt, $count);
        if ($this->imageModels() === []) {
            throw new MediaRejected('no_image_model');
        }
        if ($estimate > $cap) {
            throw new MediaRejected('budget_cap_exceeded', '', ['estimate_micro_usd' => $estimate, 'cap_micro_usd' => $cap]);
        }

        $key = $idempotencyKey !== null ? 'img:'.$workspaceId.':'.$idempotencyKey : null;
        if ($key !== null && ($existing = ImageGenerationJob::query()->where('call_key', $key)->first()) !== null) {
            return ['job' => $existing, 'job_run' => $existing->job_run_id !== null ? JobRun::query()->find($existing->job_run_id) : null];
        }

        $job = new ImageGenerationJob;
        $job->forceFill([
            'site_id' => $context['site_id'], 'article_id' => $context['article_id'], 'parent_job_id' => $parent?->id,
            'version' => $parent !== null ? $parent->version + 1 : 1, 'image_type' => $in['image_type'], 'section_key' => $in['section_key'] ?? null,
            'aspect_ratio' => $in['aspect_ratio'] ?? '16:9', 'style' => $in['style'] ?? 'flat_illustration', 'quality' => $in['quality'] ?? 'standard',
            'image_count' => $count, 'brand_colors' => $in['brand_colors'] ?? null, 'prompt_template' => $in['template'] ?? null,
            'prompt' => $prompt, 'prompt_edited' => $edited, 'budget_cap_micro_usd' => $cap, 'estimated_cost_micro_usd' => $estimate,
            'call_key' => $key ?? 'img:'.$workspaceId.':'.bin2hex(random_bytes(10)), 'created_by' => $userId,
        ]);
        try {
            $job->save();
        } catch (QueryException) {
            $existing = ImageGenerationJob::query()->where('call_key', $job->call_key)->firstOrFail();

            return ['job' => $existing, 'job_run' => null];
        }
        $run = MediaJob::startRun('media.generate', 'image_generation_job', $job->id, $userId);
        $job->forceFill(['job_run_id' => $run->id])->save();
        Audit::record('media.ai_generation_requested', $job, ['count' => $count, 'estimate_micro_usd' => $estimate, 'regenerate_of' => $parent?->id]);
        RunImageGeneration::dispatch($job->id, $run->id);

        return ['job' => $job->refresh(), 'job_run' => $run->refresh()];
    }

    /**
     * @return array{job: ImageGenerationJob, job_run: JobRun|null}
     */
    public function regenerate(string $workspaceId, ImageGenerationJob $parent, ?string $prompt, ?string $userId): array
    {
        return $this->create($workspaceId, [
            'site_id' => $parent->site_id, 'article_id' => $parent->article_id, 'image_type' => $parent->image_type, 'section_key' => $parent->section_key,
            'aspect_ratio' => $parent->aspect_ratio, 'style' => $parent->style, 'quality' => $parent->quality, 'image_count' => $parent->image_count,
            'brand_colors' => $parent->brand_colors, 'template' => $parent->prompt_template, 'prompt' => $prompt ?? $parent->prompt,
            'budget_cap_micro_usd' => $parent->budget_cap_micro_usd,
        ], $userId, null, $parent);
    }

    /**
     * Queue body. Claims the job atomically (queued → running): a second
     * delivery of the same message does nothing.
     */
    public function execute(string $jobId): ?ImageGenerationJob
    {
        $claimed = ImageGenerationJob::query()->whereKey($jobId)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
        $job = ImageGenerationJob::query()->find($jobId);
        if ($claimed === 0 || $job === null) {
            return $job;
        }

        try {
            $result = app(AiGateway::class)->execute(new AiTask(
                taskKey: self::TASK, prompt: $job->prompt.($job->quality === 'high' ? ' High detail.' : ''), callKey: $job->call_key,
                kind: 'image', imageCount: $job->image_count, imageSize: ImagePromptBuilder::RATIOS[$job->aspect_ratio] ?? '1024x1024',
            ));
        } catch (AiGatewayException $e) {
            $job->forceFill(['status' => $e->errorCode === 'budget_exceeded' ? 'blocked' : 'failed', 'error_code' => $e->errorCode,
                'error_message' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()])->save();

            return $job;
        }

        $assets = [];
        $invalid = 0;
        foreach ($result->images as $i => $b64) {
            $bytes = base64_decode((string) $b64, true);
            $asset = new MediaAsset;
            $asset->forceFill([
                'site_id' => $job->site_id, 'source_type' => 'ai', 'original_filename' => 'ai-'.$job->id.'-'.($i + 1).'.png', 'license' => 'ai_generated',
                'is_ai_generated' => true, 'ai_provider' => $result->provider, 'ai_model' => $result->model, 'ai_prompt' => $job->prompt,
                'generation_job_id' => $job->id, 'title' => mb_substr(($job->image_type === 'featured' ? 'Ảnh đại diện AI' : 'Minh họa AI').' v'.$job->version.'.'.($i + 1), 0, 200),
                'caption' => __('media.labels.ai_caption'), 'status' => 'processing', 'created_by' => $job->created_by,
                'metadata' => ['image_type' => $job->image_type, 'article_id' => $job->article_id, 'section_key' => $job->section_key, 'aspect_ratio' => $job->aspect_ratio],
            ])->save();
            try {
                if ($bytes === false) {
                    throw new MediaRejected('invalid_image');
                }
                $this->pipeline->ingest($asset, $bytes, $asset->original_filename !== null ? preg_replace('/\.png$/', '.'.$this->ext($bytes), $asset->original_filename) : null);
                $asset->forceFill(['alt_text' => AltText::suggest($asset, $job->section_key ?? $job->prompt)])->save();
                $assets[] = $asset->id;
            } catch (MediaRejected $e) {
                $invalid++;
                $asset->forceFill(['status' => 'rejected', 'error_code' => 'provider_invalid_image'])->save();
            }
        }

        $job->forceFill([
            'status' => $assets !== [] ? 'succeeded' : 'failed', 'cost_micro_usd' => $result->costMicroUsd, 'provider' => $result->provider,
            'model' => $result->model, 'ai_request_id' => $result->requestId, 'result_asset_ids' => $assets, 'finished_at' => now(),
            'error_code' => $assets === [] ? 'provider_invalid_image' : null,
            'error_message' => $invalid > 0 ? __('media.errors.provider_invalid_image') : null,
        ])->save();
        Audit::record('media.ai_generation_finished', $job, ['assets' => count($assets), 'cost_micro_usd' => $result->costMicroUsd, 'model' => $result->modelKey], actorType: 'system');

        return $job;
    }

    /**
     * Worst-case cost on the `media.image` route (most expensive candidate).
     */
    public function estimate(string $prompt, int $count): int
    {
        $max = 0;
        foreach ($this->candidateModels() as $model) {
            $price = $model->priceAt();
            if ($price !== null) {
                $tokens = (int) ceil(mb_strlen($prompt) / max(1, (int) config('sitex.ai.chars_per_token', 3)));
                $max = max($max, $this->costs->estimate($price, $tokens, 1, 0, $count));
            }
        }

        return $max;
    }

    /**
     * @return list<array{model_key: string, provider: string}>
     */
    public function imageModels(): array
    {
        return array_values(array_map(fn (AiModel $m) => ['model_key' => $m->model_key, 'provider' => (string) $m->provider?->code], $this->candidateModels()));
    }

    /**
     * @return list<AiModel>
     */
    private function candidateModels(): array
    {
        $route = AiTaskRoute::query()->where('task_key', self::TASK)->where('is_enabled', true)->first();
        if ($route === null) {
            return [];
        }
        $ids = array_values(array_filter([$route->primary_model_id, $route->economy_model_id, ...($route->fallback_model_ids ?? [])], 'is_string'));

        return AiModel::query()->with('provider')->whereIn('id', $ids)->where('is_enabled', true)->get()
            ->filter(fn (AiModel $m) => $m->supports('image_generation') && ($m->provider?->code !== 'fake' || (bool) config('sitex.ai.fake_provider', false)))
            ->values()->all();
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     *
     * @throws MediaRejected
     */
    private function context(array $in): array
    {
        $siteId = (string) ($in['site_id'] ?? '');
        $article = null;
        if (! empty($in['article_id'])) {
            $article = $this->articles->find((string) $in['article_id']) ?? throw new MediaRejected('article_not_found');
            $siteId = $article['site_id'];
        }
        $site = $siteId !== '' ? $this->sites->find($siteId) : null;
        if ($site === null) {
            throw new MediaRejected('site_not_found');
        }
        $heading = null;
        $text = null;
        if ($article !== null && ! empty($in['section_key'])) {
            $content = $this->articles->currentContent($article['id']);
            foreach (ArticleDocument::split((string) ($content['html'] ?? '')) as $s) {
                if ($s['key'] === $in['section_key']) {
                    $heading = $s['heading'];
                    $text = $s['text'];
                }
            }
        }
        $audience = null;
        if ($article !== null && $article['campaign_id'] !== null) {
            $campaign = $this->planner->campaign($article['campaign_id']);
            $audience = $campaign !== null ? implode(', ', array_slice($campaign['audiences'], 0, 3)) : null;
        }

        return [
            'site_id' => $siteId, 'article_id' => $article['id'] ?? null,
            'title' => (string) ($article['title'] ?? ($in['title'] ?? $site['name'])), 'primary_keyword' => $article['primary_keyword'] ?? null,
            'audience' => $audience, 'section_heading' => $heading, 'section_text' => $text, 'brand_name' => $site['name'],
            'image_type' => (string) ($in['image_type'] ?? 'featured'), 'style' => (string) ($in['style'] ?? 'flat_illustration'),
            'brand_colors' => is_array($in['brand_colors'] ?? null) ? $in['brand_colors'] : [], 'template' => $in['template'] ?? null,
            'context' => isset($in['context']) ? (string) $in['context'] : null,
        ];
    }

    private function ext(string $bytes): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';

        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };
    }
}
