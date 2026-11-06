<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Support;

use Carbon\CarbonImmutable;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Media\Contracts\MediaLibrary;
use SiteXPost\Modules\Publishing\Domain\PublishingConflict;
use SiteXPost\Modules\Publishing\Domain\PublishingJob;
use SiteXPost\Modules\Publishing\Domain\WordPressPostMapping;
use SiteXPost\Modules\Seo\Contracts\SeoGate;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;

/**
 * The publishing gates (WORDPRESS-PUBLISHER.md §Gates). Every gate is
 * evaluated server-side on create, on approval and AGAIN right before the
 * connector call; critical gates can never be bypassed (there is no
 * "force" flag for them).
 *
 * Gates: site (ownership + connection + publish right), article_approved,
 * fact_check, seo, media, quota, policy, conflict. Permission is checked
 * by the policy layer and recorded as the approval source.
 */
final class PublishingGates
{
    public function __construct(
        private readonly ArticleCatalog $articles,
        private readonly SiteDirectory $sites,
        private readonly SeoGate $seo,
        private readonly MediaLibrary $media,
        private readonly ScheduleRules $schedule,
    ) {}

    /**
     * @param  string  $stage  create | approve | execute
     * @return array{ok: bool, blocking: string|null, gates: list<array{gate: string, ok: bool, code: string|null, message: string, details?: array<string, mixed>}>, audit: array<string, mixed>|null}
     */
    public function evaluate(PublishingJob $job, string $stage): array
    {
        $gates = [];
        $audit = null;
        $article = $this->articles->find($job->article_id);
        $profile = $this->sites->publishingProfile($job->site_id);
        $policy = SitePolicy::for($job->site_id);
        $limits = PublishingLimits::for($job->workspace_id);

        // site: ownership (tenant-scoped lookup), connection, publish rights.
        if ($profile === null) {
            $gates[] = $this->fail('site', 'site_not_found');
        } elseif (! $profile['paired']) {
            $gates[] = $this->fail('site', 'site_not_connected');
        } elseif ($profile['can_publish'] === false) {
            $gates[] = $this->fail('site', 'wp_user_cannot_publish');
        } elseif ($article !== null && $article['site_id'] !== $job->site_id) {
            $gates[] = $this->fail('site', 'article_site_mismatch');
        } else {
            $gates[] = $this->pass('site', ['host' => $profile['host'], 'seo_adapter' => $profile['seo_adapter']]);
        }

        // article approval (human) + the version frozen at job approval must still be the approved one.
        if ($article === null) {
            $gates[] = $this->fail('article_approved', 'article_not_found');
        } elseif ($article['status'] !== 'APPROVED' || $article['approved_version_id'] === null) {
            $gates[] = $this->fail('article_approved', 'article_not_approved');
        } elseif ($job->article_version_id !== null && $job->article_version_id !== $article['approved_version_id'] && $stage === 'execute') {
            $gates[] = $this->fail('article_approved', 'content_changed_since_approval');
        } else {
            $gates[] = $this->pass('article_approved', ['version_id' => $article['approved_version_id']]);
        }

        // fact-check: no unresolved critical / high claim.
        if ($article !== null && ($article['unresolved_critical'] > 0 || $article['unresolved_high'] > 0)) {
            $gates[] = $this->fail('fact_check', 'unresolved_claims', ['critical' => $article['unresolved_critical'], 'high' => $article['unresolved_high']]);
        } else {
            $gates[] = $this->pass('fact_check');
        }

        // SEO: BLOCKED always blocks; ERROR blocks public publishing when the site requires an SEO pass.
        if ($article !== null && $article['status'] === 'APPROVED') {
            try {
                $audit = $this->seo->audit($job->article_id, null);
                $errorsBlock = $policy['require_seo_pass'] && $job->wp_target_status === 'publish';
                if ($audit['blocked'] > 0) {
                    $gates[] = $this->fail('seo', 'seo_blocked', ['audit_id' => $audit['id'], 'blocked' => $audit['blocked']]);
                } elseif ($errorsBlock && $audit['errors'] > 0) {
                    $gates[] = $this->fail('seo', 'seo_errors', ['audit_id' => $audit['id'], 'errors' => $audit['errors']]);
                } else {
                    $gates[] = $this->pass('seo', ['audit_id' => $audit['id'], 'score' => $audit['score'], 'errors' => $audit['errors'], 'warnings' => $audit['warnings']]);
                }
            } catch (ApiException $e) {
                $gates[] = $this->fail('seo', $e->errorCode);
            }
        } else {
            $gates[] = $this->fail('seo', 'article_not_approved');
        }

        // media readiness.
        $notReady = [];
        $featured = false;
        foreach ($this->media->placements($job->article_id, true) as $pl) {
            if ($pl['asset'] === null || $pl['asset']['status'] !== 'ready') {
                $notReady[] = $pl['id'];
            }
            if ($pl['asset'] !== null && trim((string) $pl['alt_text']) === '') {
                $notReady[] = $pl['id'];
            }
            $featured = $featured || $pl['slot'] === 'featured';
        }
        if ($notReady !== []) {
            $gates[] = $this->fail('media', 'media_not_ready', ['placements' => array_values(array_unique($notReady))]);
        } elseif ($policy['require_media_ready'] && ! $featured && $job->wp_target_status === 'publish') {
            $gates[] = $this->fail('media', 'featured_image_missing');
        } else {
            $gates[] = $this->pass('media');
        }

        // policy (mode allowed, kill switch, auto-publish explicitly enabled, update allowed).
        $mapping = WordPressPostMapping::query()->where('site_id', $job->site_id)->where('article_id', $job->article_id)->where('is_current', true)->first();
        $policyCode = null;
        if (! $limits['publishing_enabled']) {
            $policyCode = 'publishing_disabled';
        } elseif ($job->mode === 'auto' && (! $policy['auto_publish_enabled'] || ! $limits['auto_publish_allowed'])) {
            $policyCode = 'auto_publish_not_enabled';
        } elseif ($job->mode === 'update' && ($mapping === null || $mapping->wp_post_id === null)) {
            $policyCode = 'nothing_to_update';
        } elseif ($job->mode === 'update' && ! $policy['allow_update_published']) {
            $policyCode = 'update_not_allowed';
        } elseif ($job->mode !== 'update' && $mapping !== null && $mapping->wp_post_id !== null && $stage === 'create') {
            $policyCode = 'already_published_use_update';
        }
        $gates[] = $policyCode !== null ? $this->fail('policy', $policyCode) : $this->pass('policy', ['mode' => $job->mode]);

        // quota / window at the target time (site timezone).
        if ($profile !== null && $job->wp_target_status === 'publish') {
            $at = $job->mode === 'scheduled' || $job->mode === 'auto' ? CarbonImmutable::parse($job->scheduled_at ?? 'now') : CarbonImmutable::now('UTC');
            $problems = $this->schedule->problems($at, $profile['timezone'], $job->site_id, $job->campaign_id, $policy, $limits, $job->id,
                in_array($job->mode, ['scheduled', 'auto'], true) && $stage !== 'execute');
            $gates[] = $problems === [] ? $this->pass('quota') : $this->fail('quota', $problems[0], ['problems' => $problems, 'timezone' => $profile['timezone']]);
        } else {
            $gates[] = $this->pass('quota');
        }

        // unresolved conflict on the mapped post.
        if ($mapping !== null && PublishingConflict::query()->where('mapping_id', $mapping->id)->where('status', 'open')->exists()) {
            $gates[] = $this->fail('conflict', 'unresolved_conflict');
        } else {
            $gates[] = $this->pass('conflict');
        }

        $blocking = null;
        foreach ($gates as $g) {
            if (! $g['ok']) {
                $blocking = $g['code'];
                break;
            }
        }

        return ['ok' => $blocking === null, 'blocking' => $blocking, 'gates' => $gates, 'audit' => $audit];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{gate: string, ok: bool, code: string|null, message: string, details?: array<string, mixed>}
     */
    private function pass(string $gate, array $details = []): array
    {
        return ['gate' => $gate, 'ok' => true, 'code' => null, 'message' => __('publishing.gates.'.$gate.'_ok'), 'details' => $details];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{gate: string, ok: bool, code: string|null, message: string, details?: array<string, mixed>}
     */
    private function fail(string $gate, string $code, array $details = []): array
    {
        return ['gate' => $gate, 'ok' => false, 'code' => $code, 'message' => __('publishing.codes.'.$code), 'details' => $details];
    }
}
