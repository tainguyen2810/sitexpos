<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Actions;

use Carbon\CarbonImmutable;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Media\Contracts\MediaLibrary;
use SiteXPost\Modules\Publishing\Domain\PublishingAttempt;
use SiteXPost\Modules\Publishing\Domain\PublishingBatch;
use SiteXPost\Modules\Publishing\Domain\PublishingJob;
use SiteXPost\Modules\Publishing\Domain\WordPressPostMapping;
use SiteXPost\Modules\Publishing\Jobs\RunPublishingJob;
use SiteXPost\Modules\Publishing\Support\ConflictDetector;
use SiteXPost\Modules\Publishing\Support\PublishingGates;
use SiteXPost\Modules\Publishing\Support\PublishingStatus as S;
use SiteXPost\Modules\Seo\Contracts\SeoGate;
use SiteXPost\Modules\Sites\Connector\ConnectorException;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Sites\Contracts\WordPressGateway;

/**
 * The idempotent publisher (WORDPRESS-PUBLISHER.md, PUBLISHING-RECOVERY.md).
 *
 * 1. Atomic claim (`locked_until`) — two workers never send the same job.
 * 2. Every critical gate again, and the render must equal the approved hash.
 * 3. Stable mapping article ⇄ post with a fixed idempotency key; the plugin
 *    also deduplicates by that key → a retry can never create a 2nd post.
 * 4. Existing post: signed state check first; manual edits, slug / SEO /
 *    domain changes or a missing post open a conflict and nothing is sent.
 *    The plugin repeats the check atomically (`expected` → 409).
 * 5. Unknown outcome (timeout, 5xx): reconcile with WordPress (lookup by
 *    key) BEFORE deciding; a write that landed is recorded as published,
 *    otherwise retry with backoff up to max_attempts, then FAILED.
 */
final class ExecutePublish
{
    public function __construct(
        private readonly PublishingGates $gates,
        private readonly SeoGate $seo,
        private readonly SiteDirectory $sites,
        private readonly WordPressGateway $wp,
        private readonly MediaLibrary $media,
    ) {}

    public function run(string $jobId): string
    {
        $now = now();
        $lock = (int) config('sitex.publishing.lock_seconds', 300);
        $batchId = PublishingJob::query()->whereKey($jobId)->value('publishing_batch_id');
        if ($batchId !== null && PublishingBatch::query()->whereKey($batchId)->whereIn('status', ['paused', 'canceled'])->exists()) {
            return 'batch_paused';
        }
        $claimed = PublishingJob::query()->whereKey($jobId)->where('status', S::PUBLISHING)
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', $now))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now->copy()->addSeconds(5)))
            ->update(['locked_until' => $now->copy()->addSeconds($lock), 'updated_at' => $now]);
        if ($claimed !== 1) {
            return 'not_claimed';
        }
        /** @var PublishingJob $job */
        $job = PublishingJob::query()->findOrFail($jobId);
        $attemptNo = $job->attempts + 1;
        $job->forceFill(['attempts' => $attemptNo])->save();
        $started = CarbonImmutable::now('UTC');
        $t0 = microtime(true);
        $attempt = new PublishingAttempt;
        $attempt->forceFill(['publishing_job_id' => $job->id, 'attempt_no' => $attemptNo, 'operation' => 'create', 'outcome' => 'failed', 'retryable' => false]);
        $mapping = null;

        try {
            $result = $this->gates->evaluate($job, 'execute');
            $job->gate_results = array_map(fn ($g) => ['gate' => $g['gate'], 'ok' => $g['ok'], 'code' => $g['code'], 'message' => $g['message'], 'details' => $g['details'] ?? []], $result['gates']);
            if (! $result['ok']) {
                return $this->blocked($job, $attempt, (string) $result['blocking']);
            }
            $render = $this->seo->render($job->article_id, true);
            if ($job->content_hash !== null && $render['hash'] !== $job->content_hash) {
                return $this->blocked($job, $attempt, 'content_changed_since_approval');
            }
            $profile = $this->sites->publishingProfile($job->site_id) ?? throw new ConnectorException('site_not_found');
            $mapping = $this->mapping($job, $profile['host']);
            $force = (bool) ($job->payload_meta['force_overwrite'] ?? false);

            // Reconcile a post whose creation outcome is unknown (earlier timeout).
            if ($mapping->wp_post_id === null && ($attemptNo > 1 || $mapping->last_payload_hash !== null)) {
                $found = $this->wp->lookup($job->site_id, $mapping->idempotency_key);
                if ($found !== null) {
                    $this->adopt($mapping, $found);
                    $attempt->details = ['reconciled_post' => (int) $found['id']];
                }
            }

            if ($mapping->wp_post_id !== null) {
                $attempt->operation = 'update';
                $state = $this->wp->postState($job->site_id, (int) $mapping->wp_post_id);
                $types = ConflictDetector::types($mapping, $state, $profile['host']);
                $blocking = $force ? array_values(array_diff($types, ['manual_edit', 'seo_modified', 'slug_changed', 'revision_changed'])) : $types;
                ConflictDetector::record($mapping, $state, $types === [] ? 'in_sync' : $types[0], ['types' => $types, 'job_id' => $job->id]);
                if ($blocking !== []) {
                    return $this->conflict($job, $attempt, $mapping, $types, $state);
                }
            }

            // Images: upload (idempotent per mapping key + asset), then replace placeholders.
            $html = $render['html'];
            $featuredId = null;
            $featuredUrl = null;
            foreach ([...$render['images'], ...($render['featured'] !== null ? [$render['featured']] : [])] as $img) {
                [$id, $url] = $this->ensureUploaded($job->site_id, $mapping->idempotency_key, $img);
                $html = str_replace('{{sitex-image:'.$img['key'].'}}', $url, $html);
                if ($img['slot'] === 'featured') {
                    $featuredId = $id;
                    $featuredUrl = $url;
                }
            }

            $meta = $this->seo->metadata($job->article_id);
            $schema = $this->seo->schema($job->article_id, ['url' => $mapping->url, 'image_url' => $featuredUrl,
                'published_at' => ($mapping->last_published_at ?? now())->toIso8601String(), 'modified_at' => now()->toIso8601String()]);
            $doc = [
                'idempotency_key' => $mapping->idempotency_key, 'post_type' => 'post', 'title' => $render['title'], 'content_html' => $html,
                'excerpt' => (string) ($meta['meta_description'] ?? ''), 'slug' => (string) ($meta['slug'] ?? ''), 'status' => $job->wp_target_status,
                'seo' => $this->seo->wordpressSeo($job->article_id), 'featured_wp_media_id' => $featuredId, 'source_type' => 'article', 'source_id' => $job->article_id,
                'schema' => $schema['enabled'] && $schema['graph'] !== [] ? $schema['graph'] : null,
            ];
            if ($mapping->wp_post_id !== null) {
                $doc['expected'] = ['content_hash' => $mapping->last_synced_hash, 'slug' => $mapping->slug, 'seo_hash' => $mapping->last_seo_hash];
                if ($force) {
                    $doc['force'] = true;
                }
            }
            $payloadHash = hash('sha256', (string) json_encode([$doc['title'], $html, $doc['slug'], $doc['seo'], $doc['status'], $featuredId, $doc['schema']]));
            $attempt->payload_hash = $payloadHash;
            $mapping->forceFill(['last_payload_hash' => $payloadHash])->save();

            $response = $this->wp->publish($job->site_id, $doc);
            $state = is_array($response['state'] ?? null) ? $response['state'] : ($this->wp->postState($job->site_id, (int) ($response['id'] ?? 0)) ?? []);

            return $this->succeeded($job, $attempt, $mapping, $response + ['state' => $state], $render['version_id'], 'succeeded', $html);
        } catch (ConnectorException $e) {
            return $this->connectorFailure($job, $attempt, $mapping, $e, $started);
        } catch (ApiException $e) {
            return $this->blocked($job, $attempt, $e->errorCode);
        } finally {
            $attempt->duration_ms = (int) round((microtime(true) - $t0) * 1000);
            $attempt->wp_post_id = $mapping?->wp_post_id;
            $attempt->save();
            PublishingJob::query()->whereKey($job->id)->update(['locked_until' => null]);
        }
    }

    private function mapping(PublishingJob $job, string $host): WordPressPostMapping
    {
        $m = WordPressPostMapping::query()->where('site_id', $job->site_id)->where('article_id', $job->article_id)->where('is_current', true)->first();
        if ($m === null) {
            $n = WordPressPostMapping::query()->where('site_id', $job->site_id)->where('article_id', $job->article_id)->count();
            $external = 'sxa-'.strtolower($job->article_id).($n > 0 ? '-r'.($n + 1) : '');
            $m = new WordPressPostMapping;
            $m->forceFill(['site_id' => $job->site_id, 'article_id' => $job->article_id, 'external_content_id' => $external,
                'idempotency_key' => $external, 'is_current' => true, 'site_host' => $host])->save();
        }
        if ($job->mapping_id !== $m->id) {
            $job->forceFill(['mapping_id' => $m->id])->save();
        }

        return $m;
    }

    /**
     * @param  array<string, mixed>  $found
     */
    private function adopt(WordPressPostMapping $m, array $found): void
    {
        $m->forceFill([
            'wp_post_id' => (int) $found['id'], 'wp_status' => $found['status'] ?? null, 'url' => $found['url'] ?? null,
            'slug' => $found['slug'] ?? null, 'last_synced_hash' => ($found['written_hash'] ?? '') !== '' ? $found['written_hash'] : null,
            'last_seo_hash' => $found['seo_hash'] ?? null, 'last_modified_gmt' => $found['modified_gmt'] ?? null,
            'last_revision_id' => isset($found['revision_id']) ? (int) $found['revision_id'] : null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $img
     * @return array{0: int, 1: string}
     */
    private function ensureUploaded(string $siteId, string $key, array $img): array
    {
        if (! empty($img['wp_media_id']) && ! empty($img['remote_url'])) {
            return [(int) $img['wp_media_id'], (string) $img['remote_url']];
        }
        $asset = $this->media->asset((string) $img['asset_id']);
        $prev = $asset['wp_uploads'][$siteId] ?? null;
        if (is_array($prev) && (int) ($prev['id'] ?? 0) > 0 && ($prev['url'] ?? '') !== '') {
            return [(int) $prev['id'], (string) $prev['url']];
        }
        $bytes = $this->media->bytes((string) $img['asset_id']) ?? throw new ConnectorException('media_missing', __('publishing.codes.media_missing'));
        $res = $this->wp->uploadMedia($siteId, $key.':'.$img['asset_id'], $bytes['bytes'], $bytes['filename'],
            ['alt' => (string) $img['alt'], 'title' => (string) ($img['title'] ?? ''), 'caption' => (string) ($img['caption'] ?? '')]);
        $this->media->markUploaded((string) $img['asset_id'], $siteId, $res['id'], $res['url']);

        return [$res['id'], $res['url']];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function succeeded(PublishingJob $job, PublishingAttempt $attempt, WordPressPostMapping $m, array $response, ?string $versionId, string $outcome, ?string $html): string
    {
        $state = is_array($response['state'] ?? null) ? $response['state'] : [];
        $m->forceFill([
            'wp_post_id' => (int) ($response['id'] ?? $state['id'] ?? $m->wp_post_id), 'wp_status' => $state['status'] ?? ($response['status'] ?? null),
            'url' => $state['url'] ?? ($response['url'] ?? $m->url), 'slug' => $state['slug'] ?? $m->slug, 'site_host' => (string) ($job->payload_meta['site_host'] ?? $m->site_host),
            'last_published_version_id' => $versionId, 'last_synced_hash' => $state['content_hash'] ?? null, 'last_seo_hash' => $state['seo_hash'] ?? null,
            'last_modified_gmt' => $state['modified_gmt'] ?? null, 'last_revision_id' => isset($state['revision_id']) ? (int) $state['revision_id'] : null,
            'last_published_at' => now(), 'last_checked_at' => now(),
        ])->save();
        ConflictDetector::record($m, $state, 'in_sync', ['job_id' => $job->id, 'outcome' => $outcome]);
        $meta = $job->payload_meta ?? [];
        unset($meta['force_overwrite']);
        $job->forceFill([
            'status' => S::PUBLISHED, 'active_key' => null, 'published_at' => now(), 'next_attempt_at' => null, 'last_error_code' => null, 'last_error_message' => null,
            'mapping_id' => $m->id, 'rendered_html' => $html ?? $job->rendered_html,
            'payload_meta' => $meta + ['wp_post_id' => $m->wp_post_id, 'url' => $m->url, 'wp_status' => $m->wp_status],
        ])->save();
        $attempt->forceFill(['outcome' => $outcome, 'error_code' => null])->save();
        Audit::record('publishing.published', $job, ['wp_post_id' => $m->wp_post_id, 'status' => $m->wp_status, 'attempt' => $attempt->attempt_no, 'outcome' => $outcome], actorType: 'system');

        return 'published';
    }

    /**
     * @param  list<string>  $types
     * @param  array<string, mixed>|null  $state
     */
    private function conflict(PublishingJob $job, PublishingAttempt $attempt, WordPressPostMapping $m, array $types, ?array $state): string
    {
        $c = ConflictDetector::open($m, $job->id, $types, $state);
        $job->forceFill(['status' => S::UPDATE_PENDING, 'last_error_code' => 'conflict', 'last_error_message' => __('publishing.codes.conflict'), 'next_attempt_at' => null, 'blocked_reason' => 'unresolved_conflict'])->save();
        $attempt->forceFill(['operation' => 'state_check', 'outcome' => 'conflict', 'error_code' => 'conflict', 'details' => ['types' => $types, 'conflict_id' => $c->id]])->save();
        Audit::record('publishing.conflict_detected', $c, ['types' => $types, 'job_id' => $job->id], actorType: 'system');

        return 'conflict';
    }

    private function blocked(PublishingJob $job, PublishingAttempt $attempt, string $code): string
    {
        $needsReview = in_array($code, ['content_changed_since_approval', 'article_not_approved', 'unresolved_claims', 'seo_blocked', 'seo_errors', 'media_not_ready', 'featured_image_missing'], true);
        $to = $code === 'unresolved_conflict' ? S::UPDATE_PENDING : ($needsReview ? S::READY_FOR_REVIEW : S::FAILED);
        $job->forceFill(['status' => $to, 'blocked_reason' => $code, 'last_error_code' => $code, 'last_error_message' => mb_substr(__('publishing.codes.'.$code), 0, 500), 'next_attempt_at' => null])->save();
        $attempt->forceFill(['operation' => 'state_check', 'outcome' => 'skipped', 'error_code' => $code])->save();
        Audit::record('publishing.blocked', $job, ['code' => $code, 'status' => $to], actorType: 'system');

        return 'blocked';
    }

    private function connectorFailure(PublishingJob $job, PublishingAttempt $attempt, ?WordPressPostMapping $m, ConnectorException $e, CarbonImmutable $started): string
    {
        $attempt->forceFill(['error_code' => $e->errorCode, 'http_status' => $e->httpStatus, 'retryable' => $e->retryable]);

        if ($e->errorCode === 'conflict' && $m !== null && $m->wp_post_id !== null) {
            // The plugin refused atomically. Its error body is unsigned → re-read the signed state.
            try {
                $state = $this->wp->postState($job->site_id, (int) $m->wp_post_id);
            } catch (ConnectorException) {
                $state = null;
            }
            $types = ConflictDetector::types($m, $state, (string) ($job->payload_meta['site_host'] ?? $m->site_host));

            return $this->conflict($job, $attempt, $m, $types !== [] ? $types : ['manual_edit'], $state);
        }

        // Unknown outcome: the request may have been applied. Reconcile before deciding.
        if ($m !== null && in_array($e->errorCode, ['unreachable', 'site_error'], true) && $attempt->payload_hash !== null) {
            $attempt->outcome = 'uncertain';
            try {
                $found = $this->wp->lookup($job->site_id, $m->idempotency_key);
            } catch (ConnectorException) {
                $found = null;
            }
            if ($found !== null) {
                $modified = isset($found['modified_gmt']) ? CarbonImmutable::parse((string) $found['modified_gmt'], 'UTC') : null;
                $ours = ($found['written_hash'] ?? '') !== '' && $found['written_hash'] === ($found['content_hash'] ?? null);
                if ($ours && $modified !== null && $modified->greaterThanOrEqualTo($started->subSeconds(120))) {
                    $attempt->operation = 'reconcile';

                    return $this->succeeded($job, $attempt, $m, ['id' => $found['id'], 'state' => $found], $job->article_version_id, 'reconciled', null);
                }
                $this->adopt($m, $found);
            }
        }

        $codeIsRetryable = $e->retryable || in_array($e->errorCode, ['unreachable', 'site_error', 'rate_limited'], true);
        if ($codeIsRetryable && $job->attempts < $job->max_attempts) {
            $backoff = (array) config('sitex.publishing.backoff_minutes', [1, 5, 15, 60, 180]);
            $minutes = (int) ($backoff[min(count($backoff) - 1, max(0, $job->attempts - 1))] ?? 60);
            $job->forceFill(['next_attempt_at' => now()->addMinutes($minutes), 'last_error_code' => $e->errorCode, 'last_error_message' => mb_substr($e->getMessage(), 0, 500)])->save();
            if ($attempt->outcome !== 'uncertain') {
                $attempt->outcome = 'failed';
            }
            RunPublishingJob::dispatch($job->id)->delay(now()->addMinutes($minutes));

            return 'retry_scheduled';
        }
        $job->forceFill(['status' => S::FAILED, 'next_attempt_at' => null, 'last_error_code' => $e->errorCode, 'last_error_message' => mb_substr($e->getMessage(), 0, 500)])->save();
        Audit::record('publishing.failed', $job, ['code' => $e->errorCode, 'attempts' => $job->attempts], actorType: 'system');

        return 'failed';
    }
}
