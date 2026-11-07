<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Actions;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Modules\AiGateway\Gateway\AiGatewayException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Writer\Domain\WritingPreset;
use SiteXPost\Modules\Writer\Jobs\LearnWritingStyle;
use SiteXPost\Modules\Writer\Pipeline\StyleLearner;
use Throwable;

/**
 * "Learn writing style" for a preset: HTTP validates and queues (202 +
 * job_run); the queue fetches the samples and asks the AI for a style
 * guide, stored as `style_draft`. Nothing is applied to articles until the
 * user reviews the draft and saves it as `style_profile`.
 */
final class LearnStyle
{
    /** Where the samples came from (v1.0.9): one post, a category, an author, the whole blog, products or pasted text. */
    public const ORIGINS = ['post', 'category', 'author', 'blog', 'product', 'paste'];

    public function __construct(private readonly StyleLearner $learner) {}

    /**
     * @param  list<string>  $itemIds
     * @param  list<array{title?: string|null, text: string}>  $samples
     * @param  array{type: string, label?: string|null}|null  $origin
     */
    public function start(WritingPreset $preset, ?string $siteId, array $itemIds, array $samples, ?string $userId, ?array $origin = null): JobRun
    {
        $siteId ??= $preset->site_id;
        if ($itemIds !== [] && $siteId === null) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['site_id' => [__('writer.errors.style_site_required')]]);
        }
        $total = count($itemIds) + count($samples);
        if ($total < StyleLearner::MIN_SAMPLES || $total > StyleLearner::MAX_SAMPLES) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['samples' => [__('writer.errors.style_sample_count', ['min' => StyleLearner::MIN_SAMPLES, 'max' => StyleLearner::MAX_SAMPLES])]]);
        }
        $active = JobRun::query()->where('type', 'writer.learn_style')->where('subject_id', $preset->id)->whereIn('status', ['queued', 'running'])
            ->where('created_at', '>=', now()->subMinutes(15))->first();
        if ($active !== null) {
            return $active;
        }
        $run = new JobRun;
        $run->forceFill(['type' => 'writer.learn_style', 'subject_type' => 'writing_preset', 'subject_id' => $preset->id, 'created_by' => $userId])->save();
        Audit::record('writer.style_learning_requested', $preset, ['site_items' => count($itemIds), 'pasted' => count($samples)]);
        LearnWritingStyle::dispatch($preset->id, $run->id, $siteId, array_values($itemIds), array_values($samples), self::origin($origin));

        return $run;
    }

    /**
     * @param  list<string>  $itemIds
     * @param  list<array{title?: string|null, text: string}>  $samples
     * @param  array{type: string, label: string|null}|null  $origin
     */
    public function run(string $presetId, string $runId, ?string $siteId, array $itemIds, array $samples, ?array $origin = null): void
    {
        $run = JobRun::query()->find($runId);
        $preset = WritingPreset::query()->find($presetId);
        if ($run === null || $preset === null) {
            return;
        }
        $run->forceFill(['status' => 'running', 'started_at' => now(), 'progress' => 10, 'step' => 'collect'])->save();
        try {
            $got = $this->learner->collect($siteId, $itemIds, $samples);
            if (count($got['samples']) < StyleLearner::MIN_SAMPLES) {
                $this->fail($run, 'not_enough_samples', __('writer.errors.style_not_enough', ['n' => count($got['samples']), 'min' => StyleLearner::MIN_SAMPLES]));
                $preset->forceFill(['style_sources' => ['skipped' => $got['skipped'], 'used' => [], 'origin' => $origin]])->save();

                return;
            }
            $run->forceFill(['progress' => 40, 'step' => 'analyse'])->save();
            $res = $this->learner->analyse($got['samples'], 'style:'.$preset->id.':'.$run->id);
            if (! StyleLearner::isUsable($res['profile'])) {
                $this->fail($run, 'invalid_output', __('writer.errors.style_empty'));

                return;
            }
            $used = array_map(fn (array $s) => ['type' => $s['type'], 'title' => $s['title'], 'url' => $s['url'],
                'words' => count(preg_split('/\s+/u', trim($s['text'])) ?: [])], $got['samples']);
            $preset->forceFill([
                'style_draft' => $res['profile'] + ['generated_at' => now()->toIso8601ZuluString(), 'model' => $res['model'], 'cost_micro_usd' => $res['cost_micro_usd'], 'sample_count' => count($used)],
                'style_sources' => ['used' => $used, 'skipped' => $got['skipped'], 'origin' => $origin],
            ])->save();
            $run->forceFill(['status' => 'succeeded', 'progress' => 100, 'step' => 'done', 'finished_at' => now()])->save();
            Audit::record('writer.style_learned', $preset, ['samples' => count($used), 'cost_micro_usd' => $res['cost_micro_usd'], 'model' => $res['model']], actorType: 'system');
        } catch (AiGatewayException $e) {
            $this->fail($run, $e->errorCode, $e->getMessage());
        } catch (Throwable $e) {
            $this->fail($run, 'server_error', __('api.server_error'));
            throw $e;
        }
    }

    /**
     * @param  array{type: string, label?: string|null}|null  $origin
     * @return array{type: string, label: string|null}|null
     */
    private static function origin(?array $origin): ?array
    {
        if ($origin === null || ! in_array($origin['type'], self::ORIGINS, true)) {
            return null;
        }
        $label = isset($origin['label']) ? Text::clean((string) $origin['label'], 200) : '';

        return ['type' => $origin['type'], 'label' => $label !== '' ? $label : null];
    }

    private function fail(JobRun $run, string $code, string $message): void
    {
        $run->forceFill(['status' => 'failed', 'finished_at' => now(), 'error_code' => mb_substr($code, 0, 64), 'error_message' => mb_substr($message, 0, 500)])->save();
    }
}
